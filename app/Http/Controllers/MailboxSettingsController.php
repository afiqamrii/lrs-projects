<?php

namespace App\Http\Controllers;

use App\Http\Requests\MailboxSettingsRequest;
use App\Jobs\SyncMailbox;
use App\Models\CompanySetting;
use App\Models\MailboxConnection;
use App\Models\MailboxFolder;
use App\Support\Audit;
use App\Support\GmailFixture;
use App\Support\GmailMail;
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
    public function show(Request $request): View
    {
        $c = $this->selected($request);

        return view('mailbox.settings', ['connection' => $c, 'connections' => MailboxConnection::orderBy('id')->get(), 'outbound' => MailboxConnection::current(), 'folders' => $c->folders()->orderByDesc('id')->get(), 'configured' => $c->provider === 'gmail' ? config('mailbox.google_client_id') && config('mailbox.google_client_secret') : config('mailbox.client_id') && config('mailbox.client_secret') && config('mailbox.tenant_id')]);
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
        $mailboxes->configure($data, $this->selected($request));

        return back()->with('status', 'Pinned mailbox configuration saved. Authorize Microsoft access next; no messages were sent.');
    }

    public function connect(Request $request, Mailboxes $mailboxes): RedirectResponse
    {
        return redirect()->away($mailboxes->authorization($request, $this->selected($request)));
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
        $action = $request->validate(['action' => ['required', Rule::in(['pause', 'resume', 'disconnect', 'sync', 'demo', 'fixtures', 'followup_response', 'default', 'incoming_enable', 'incoming_disable', 'aliases', 'gmail_demo', 'gmail_fixtures', 'gmail_response'])], 'confirm' => ['accepted'], 'plan_id' => ['required_if:action,followup_response,gmail_response', 'nullable', 'integer', 'exists:followup_plans,id']])['action'];
        $selected = $this->selected($request);
        if (in_array($action, ['demo', 'fixtures', 'followup_response'], true) && ($selected->provider !== 'outlook' || $selected->id !== MailboxConnection::current()->id)) {
            throw ValidationException::withMessages(['connection' => 'Legacy Outlook fixture controls require the selected outbound Outlook connection.']);
        }
        if ($action === 'default') {
            if (! $selected->usable() || ($selected->provider === 'gmail' && ! $selected->verifiedGmailFrom())) {
                throw ValidationException::withMessages(['connection' => 'Connect and verify the selected mailbox before choosing the outbound default.']);
            }
            DB::transaction(function () use ($selected): void {
                $company = CompanySetting::whereKey(1)->lockForUpdate()->firstOrFail();
                $before = $company->outbound_mailbox_id ?? 1;
                $company->forceFill(['outbound_mailbox_id' => $selected->id])->save();
                Audit::record('Default outbound mailbox changed', $company, details: ['mailbox' => ['before' => $before, 'after' => $selected->id]]);
            });
        } elseif (in_array($action, ['incoming_enable', 'incoming_disable'], true)) {
            $selected->update(['incoming_enabled' => $action === 'incoming_enable']);
            Audit::record('Incoming mailbox polling changed', $selected, details: ['incoming' => ['before' => null, 'after' => $selected->incoming_enabled]]);
        } elseif ($action === 'aliases') {
            abort_unless($selected->provider === 'gmail', 422);
            app(GmailMail::class)->checkAlias($selected);
        } elseif ($action === 'gmail_demo') {
            app(GmailFixture::class)->connect($selected);
        } elseif ($action === 'gmail_fixtures' || $action === 'gmail_response') {
            app(GmailFixture::class)->incoming($selected, $action === 'gmail_response' ? (int) $request->input('plan_id') : null);
        } elseif ($action === 'demo') {
            if ($selected->id !== MailboxConnection::current()->id || $selected->provider !== 'outlook') {
                throw ValidationException::withMessages(['connection' => 'The legacy Outlook fixture action requires the selected outbound Outlook connection.']);
            }
            $mailboxes->demo();
        } elseif ($action === 'followup_response') {
            app(MailboxFixture::class)->loadFollowupResponse((int) $request->input('plan_id'));
        } elseif ($action === 'fixtures') {
            app(MailboxFixture::class)->loadIncoming();
        } elseif ($action !== 'sync') {
            $mailboxes->changeState($action, $selected);
        }
        if (in_array($action, ['sync', 'fixtures', 'followup_response', 'gmail_fixtures', 'gmail_response'], true)) {
            $c = $selected->fresh();
            foreach ($c->folders()->where('identity_hash', $c->identity_hash)->where('enabled', true)->get() as $f) {
                $f->update(['next_attempt_at' => null]);
                SyncMailbox::dispatch($f->id)->onQueue('mail');
            }
        }

        return back()->with('status', match ($action) {
            'default' => 'Outbound default saved for new work. Existing approvals, queued sends and reminder plans retain their authorized mailbox.', 'incoming_enable', 'incoming_disable' => 'Incoming polling preference recorded. Original evidence remains available.', 'aliases' => 'Verified Gmail sending identities checked. Changing From requires renewed envelope authorization.', 'gmail_demo' => 'Fictional Gmail transport connected. No Google access or real mail.', 'gmail_fixtures', 'gmail_response' => 'Fictional Gmail source evidence queued for the shared incoming workflow.', 'demo' => 'Synthetic transport connected. No Microsoft access or real sending.','fixtures' => 'Synthetic incoming sources queued for import; clearly labelled as fixtures.','sync' => 'Selected folder sync queued. A running mail worker is required.','followup_response' => 'Synthetic question queued for import. The mail worker will hold the exact plan for staff review.',default => 'Mailbox '.$action.' recorded. Historical evidence is preserved.'
        });
    }

    public function folder(Request $request): RedirectResponse
    {
        $data = $request->validate(['provider_id' => ['required', 'string', 'max:512'], 'kind' => ['required', Rule::in(['incoming', 'sent'])], 'confirm' => ['accepted']]);
        $c = $this->selected($request);
        if ($c->provider === 'gmail') {
            $label = app(GmailMail::class)->call($c, 'GET', '/labels/'.rawurlencode($data['provider_id']));
            if ($data['kind'] !== 'incoming' || in_array($label['id'] ?? '', ['SENT', 'DRAFT', 'SPAM', 'TRASH'], true) || (($label['id'] ?? '') !== 'INBOX' && ($label['type'] ?? '') !== 'user')) {
                throw ValidationException::withMessages(['folder' => 'Select Inbox or an explicit user label for incoming mail. Sent, drafts, spam and trash never create inquiries.']);
            }
            $f = MailboxFolder::firstOrCreate(['identity_hash' => $c->identity_hash, 'mailbox_id' => $c->target_id, 'provider_id' => $label['id']], ['mailbox_connection_id' => $c->id, 'name' => $label['name'], 'kind' => 'incoming', 'import_from' => $c->import_from ?? now()]);
            Audit::record('Gmail incoming label selected', $f);

            return back()->with('status', 'Incoming label access verified and selected. Label-only changes create no new cases.');
        }
        try {
            $folder = app(GraphMail::class)->call($c, 'GET', '/users/'.$c->target_id.'/mailFolders/'.rawurlencode($data['provider_id']));
            $sent = app(GraphMail::class)->call($c, 'GET', '/users/'.$c->target_id.'/mailFolders/sentitems');
            if ($data['kind'] === 'incoming' && $folder['id'] === $sent['id']) {
                throw ValidationException::withMessages(['kind' => 'Sent Items is reconciliation evidence only.']);
            }
            $f = MailboxFolder::firstOrCreate(['identity_hash' => $c->identity_hash, 'mailbox_id' => $c->target_id, 'provider_id' => $folder['id']], ['mailbox_connection_id' => $c->id, 'name' => $folder['displayName'], 'kind' => $data['kind'], 'import_from' => $c->import_from ?? now()]);
            Audit::record('Mailbox folder selected', $f, details: ['folder' => ['before' => null, 'after' => $f->kind]]);
        } catch (GraphFailure $e) {
            return back()->withErrors(['folder' => $e->getMessage()]);
        }

        return back()->with('status', 'Folder access checked and selected. Only selected incoming folders create cases.');
    }

    public function folderAction(Request $request, MailboxFolder $folder): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['enable', 'disable', 'resync'])], 'reason' => ['required', 'string', 'max:2000'], 'import_from' => ['nullable', 'date_format:Y-m-d\TH:i'], 'confirm' => ['accepted']]);
        abort_unless($folder->identity_hash === $folder->mailbox->identity_hash, 422);
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
                $folder->update(['gmail_history_id' => null, 'gmail_initial_anchor' => null, 'gmail_phase' => 'initial', 'gmail_page_token' => null, 'last_sync_at' => null, 'cursor' => null, 'page' => null, 'offset' => 0, 'import_from' => $from, 'resync_count' => 0, 'failure_count' => 0, 'cycle_count' => 0, 'enabled' => true, 'last_error' => null, 'next_attempt_at' => null]);
            } else {
                $folder->update(['enabled' => $data['action'] === 'enable', 'failure_count' => 0, 'cycle_count' => 0, 'next_attempt_at' => null]);
            }
            Audit::record('Mailbox folder recovery', $folder, details: ['recovery' => ['before' => null, 'after' => ['action' => $data['action'], 'reason' => $data['reason'], 'import_from' => $folder->import_from->toIso8601String()]]]);
        });

        return back()->with('status', 'Folder recovery recorded. Deduplication and original evidence remain intact.');
    }

    private function selected(Request $request): MailboxConnection
    {
        $data = $request->validate(['connection' => ['nullable', 'integer', 'exists:mailbox_connections,id']]);

        return isset($data['connection']) ? MailboxConnection::findOrFail($data['connection']) : MailboxConnection::current();
    }
}
