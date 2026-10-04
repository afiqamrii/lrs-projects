<?php

namespace App\Http\Controllers;

use App\Http\Requests\MailboxSettingsRequest;
use App\Jobs\SyncMailbox;
use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use App\Support\Audit;
use App\Support\GraphFailure;
use App\Support\GraphMail;
use App\Support\InquiryWorkflow;
use App\Support\Mailboxes;
use App\Support\MailboxFixture;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;

class MailboxSettingsController extends Controller
{
    public function show(): View
    {
        return view('mailbox.settings', ['connection' => MailboxConnection::current(), 'folders' => MailboxConnection::current()->folders()->orderByDesc('id')->get(), 'configured' => config('mailbox.client_id') && config('mailbox.client_secret') && config('mailbox.tenant_id')]);
    }

    public function update(MailboxSettingsRequest $request, Mailboxes $mailboxes): RedirectResponse
    {
        $data = $request->validated();
        $from = InquiryWorkflow::utc($data['import_from']);
        if ($from->isFuture() || $from->lt(now()->subDays(config('mailbox.history_days')))) {
            throw ValidationException::withMessages(['import_from' => 'Choose now or an explicit historical boundary within the past 90 days. Historical import sends no mail.']);
        }
        if ($data['mailbox_type'] === 'personal' && ($data['account_id'] !== $data['target_id'] || $data['account_email'] !== $data['target_email'] || $data['send_mode'] !== 'send_as')) {
            throw ValidationException::withMessages(['target_id' => 'For a personal mailbox, expected account and target must match and use Send As.']);
        }
        unset($data['transport_limit_mb']);
        $data['transport_limit'] = (int) $request->input('transport_limit_mb') * 1024 * 1024;
        $data['import_from'] = $from;
        $data['rights_confirmed'] = true;
        $data['account_sent_items'] = $request->boolean('account_sent_items');
        $mailboxes->configure($data);

        return back()->with('status', 'Pinned mailbox configuration saved. Authorize Microsoft access next; no messages were sent.');
    }

    public function connect(Request $request, Mailboxes $mailboxes): RedirectResponse
    {
        return redirect()->away($mailboxes->authorization($request));
    }

    public function callback(Request $request, Mailboxes $mailboxes): RedirectResponse
    {
        try {
            $mailboxes->callback($request);

            return to_route('settings.mailbox')->with('status', 'Pinned Outlook account connected. Incoming sync can start; approved drafts require explicit release authorization and enqueueing.');
        } catch (ValidationException $e) {
            return to_route('settings.mailbox')->withErrors($e->errors());
        } catch (GraphFailure $e) {
            return to_route('settings.mailbox')->withErrors(['connection' => $e->getMessage()]);
        }
    }

    public function action(Request $request, Mailboxes $mailboxes): RedirectResponse
    {
        $action = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'disconnect', 'sync', 'demo', 'fixtures'])], 'confirm' => ['accepted']])['action'];
        if ($action === 'demo') {
            $mailboxes->demo();
        } elseif ($action === 'fixtures') {
            app(MailboxFixture::class)->loadIncoming();
        } elseif ($action !== 'sync') {
            $mailboxes->changeState($action);
        }
        if (in_array($action, ['sync', 'fixtures'], true)) {
            $c = MailboxConnection::current();
            foreach ($c->folders()->where('identity_hash', $c->identity_hash)->where('enabled', true)->get() as $f) {
                $f->update(['next_attempt_at' => null]);
                SyncMailbox::dispatch($f->id)->onQueue('mail');
            }
        }

        return back()->with('status', match ($action) {
            'demo' => 'Synthetic transport connected. No Microsoft access or real sending.','fixtures' => 'Synthetic incoming sources queued for import; clearly labelled as fixtures.','sync' => 'Selected folder sync queued. A running mail worker is required.',default => 'Mailbox '.$action.' recorded. Historical evidence is preserved.'
        });
    }

    public function folder(Request $request): RedirectResponse
    {
        $data = $request->validate(['provider_id' => ['required', 'string', 'max:512'], 'kind' => ['required', Rule::in(['incoming', 'sent'])], 'confirm' => ['accepted']]);
        $c = MailboxConnection::current();
        try {
            $folder = app(GraphMail::class)->call($c, 'GET', '/users/'.$c->target_id.'/mailFolders/'.rawurlencode($data['provider_id']));
            $sent = app(GraphMail::class)->call($c, 'GET', '/users/'.$c->target_id.'/mailFolders/sentitems');
            if ($data['kind'] === 'incoming' && $folder['id'] === $sent['id']) {
                throw ValidationException::withMessages(['kind' => 'Sent Items is reconciliation evidence only.']);
            }
            $f = MailboxFolder::firstOrCreate(['identity_hash' => $c->identity_hash, 'mailbox_id' => $c->target_id, 'provider_id' => $folder['id']], ['mailbox_connection_id' => 1, 'name' => $folder['displayName'], 'kind' => $data['kind'], 'import_from' => $c->import_from ?? now()]);
            Audit::record('Mailbox folder selected', $f, details: ['folder' => ['before' => null, 'after' => $f->kind]]);
        } catch (GraphFailure $e) {
            return back()->withErrors(['folder' => $e->getMessage()]);
        }

        return back()->with('status', 'Folder access checked and selected. Only selected incoming folders create cases.');
    }

    public function folderAction(Request $request, MailboxFolder $folder): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['enable', 'disable', 'resync'])], 'reason' => ['required', 'string', 'max:2000'], 'import_from' => ['nullable', 'date_format:Y-m-d\TH:i'], 'confirm' => ['accepted']]);
        abort_unless($folder->identity_hash === MailboxConnection::current()->identity_hash, 422);
        DB::transaction(function () use ($data, $folder): void {
            $folder = MailboxFolder::whereKey($folder->id)->lockForUpdate()->firstOrFail();
            if ($folder->lease_until?->isFuture() && $data['action'] !== 'disable') {
                throw ValidationException::withMessages(['folder' => 'The folder worker is still finishing its retained page. Retry recovery after its lease is released.']);
            }
            if ($data['action'] === 'resync') {
                $from = InquiryWorkflow::utc($data['import_from'] ?? null);
                if (! $from || $from->isFuture() || $from->lt(now()->subDays(90))) {
                    throw ValidationException::withMessages(['import_from' => 'Explicitly choose a boundary within the past 90 days. Anything older needs a separate controlled migration.']);
                }
                $folder->update(['cursor' => null, 'page' => null, 'offset' => 0, 'import_from' => $from, 'resync_count' => 0, 'failure_count' => 0, 'cycle_count' => 0, 'enabled' => true, 'last_error' => null, 'next_attempt_at' => null]);
            } else {
                $folder->update(['enabled' => $data['action'] === 'enable', 'failure_count' => 0, 'cycle_count' => 0, 'next_attempt_at' => null]);
            }
            Audit::record('Mailbox folder recovery', $folder, details: ['recovery' => ['before' => null, 'after' => ['action' => $data['action'], 'reason' => $data['reason'], 'import_from' => $folder->import_from->toIso8601String()]]]);
        });

        return back()->with('status', 'Folder recovery recorded. Deduplication and original evidence remain intact.');
    }
}
