<?php

namespace App\Http\Controllers;

use App\Http\Requests\GmailSettingsRequest;
use App\Models\MailboxConnection;
use App\Support\Audit;
use App\Support\GmailFailure;
use App\Support\GmailOAuth;
use App\Support\InquiryWorkflow;
use App\Support\Mailboxes;
use App\Support\Processing;
use Illuminate\Database\QueryException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class GmailSettingsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate(['provider' => ['required', Rule::in(['outlook', 'gmail'])], 'name' => ['required', 'string', 'max:120', 'not_regex:/[\\r\\n]/'], 'email' => ['required', 'email:rfc', 'max:254']]);
        MailboxConnection::current();
        $c = MailboxConnection::create(['provider' => $data['provider'], 'target_name' => $data['name'], 'account_email' => mb_strtolower($data['email']), 'target_email' => mb_strtolower($data['email']), 'incoming_enabled' => true, 'import_from' => now()]);
        Audit::record('Mailbox connection added', $c, details: ['provider' => ['before' => null, 'after' => $c->provider]]);

        return to_route('settings.mailbox', ['connection' => $c->id])->with('status', 'Connection added. Configure and authorize this account next. No sending or reminder activation.');
    }

    public function update(GmailSettingsRequest $request, MailboxConnection $connection): RedirectResponse
    {
        abort_unless($connection->provider === 'gmail', 422);
        $data = $request->validated();
        $from = InquiryWorkflow::utc($data['import_from']);
        if ($from->isFuture() || $from->lt(now()->subDays(90))) {
            Processing::fail('Choose an explicit import boundary within the past 90 days.');
        }
        DB::transaction(function () use ($connection, $data, $from): void {
            $c = MailboxConnection::whereKey($connection->id)->lockForUpdate()->firstOrFail();
            if ($c->google_subject && ! $c->is_demo && $c->account_email !== $data['account_email']) {
                Processing::fail('This connection already belongs to a verified Google mailbox. Add a new connection for another account; original provenance remains pinned.');
            }
            $same = $c->account_email === $data['account_email'] && ! $c->is_demo;
            $c->fill(['account_email' => $data['account_email'], 'target_email' => $data['account_email'], 'target_name' => $data['target_name'], 'from_alias' => $data['from_alias'] ?: $data['account_email'], 'import_from' => $from, 'transport_limit' => (int) $data['transport_limit_mb'] * 1024 * 1024, 'rights_confirmed' => true, 'state' => 'disconnected', 'is_demo' => false, 'tenant_id' => 'google', 'generation' => $c->generation + 1, 'access_token' => null, 'refresh_token' => $same ? $c->refresh_token : null, 'google_subject' => $same ? $c->google_subject : null, 'account_id' => $same ? $c->account_id : null, 'target_id' => $same ? $c->target_id : null, 'aliases' => null, 'aliases_checked_at' => null, 'expires_at' => null, 'refresh_lease' => null, 'refresh_until' => null, 'last_error' => null]);
            $c->identity_hash = app(Mailboxes::class)->identity($c);
            $c->save();
            Audit::record('Gmail sender configuration changed', $c, details: ['connection' => ['before' => null, 'after' => 'Prior envelope authorization invalidated. Reconnect and approve changed work.']]);
        });

        return back()->with('status', 'Gmail identity saved. Authorize the mailbox through its own Google account; no mail sent.');
    }

    public function connect(Request $request, MailboxConnection $connection): RedirectResponse
    {
        return redirect()->away(app(GmailOAuth::class)->authorization($request, $connection));
    }

    public function callback(Request $request): RedirectResponse
    {
        try {
            $c = app(GmailOAuth::class)->callback($request);

            return to_route('settings.mailbox', ['connection' => $c->id])->with('status', 'Configured Google account verified and connected. Incoming polling is ready; sending still requires exact staff authorization.');
        } catch (ValidationException $e) {
            return to_route('settings.mailbox')->withErrors($e->errors());
        } catch (QueryException $e) {
            if (($e->errorInfo[0] ?? '') !== '23505') {
                throw $e;
            }

            return to_route('settings.mailbox')->withErrors(['connection' => 'This Google account is already connected. Use its existing connection; no second identity was authorized.']);
        } catch (GmailFailure $e) {
            return to_route('settings.mailbox')->withErrors(['connection' => $e->getMessage()]);
        }
    }
}
