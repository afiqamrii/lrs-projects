<?php

namespace App\Http\Controllers;

use App\Actions\MailIngest;
use App\Actions\MailOutbox;
use App\Http\Requests\MailReviewRequest;
use App\Jobs\ImportMailAttachments;
use App\Models\Inquiry;
use App\Models\MailAttachment;
use App\Models\MailboxConnection;
use App\Models\MailDispatch;
use App\Models\MailEnvelope;
use App\Models\MailMessage;
use App\Models\RfqApproval;
use App\Support\MailRelease;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class MailController extends Controller
{
    public function index(Request $request): View
    {
        $data = $request->validate(['q' => ['nullable', 'string', 'max:160'], 'state' => ['nullable', Rule::in(['unmatched', 'matched', 'automated', 'ignored'])], 'classification' => ['nullable', Rule::in(array_keys(MailMessage::CLASSES))], 'data' => ['nullable', Rule::in(['real', 'fixtures', 'all'])]]);
        $data['data'] ??= 'real';
        $q = MailMessage::with('inquiry', 'revision')->where('direction', 'incoming');
        if ($data['q'] ?? null) {
            $q->where(fn ($query) => $query->where('subject', 'ilike', '%'.$data['q'].'%')->orWhere('sender_email', 'ilike', '%'.$data['q'].'%'));
        }
        if ($data['state'] ?? null) {
            $q->where('match_state', $data['state']);
        }
        if ($data['classification'] ?? null) {
            $q->where('classification', $data['classification']);
        }
        if ($data['data'] !== 'all') {
            $q->where('is_demo', $data['data'] === 'fixtures');
        }

        return view('mail.index', ['messages' => $q->latest('id')->paginate(20)->withQueryString(), 'connection' => MailboxConnection::current(), 'dataSource' => $data['data'], 'attention' => MailMessage::where('direction', 'incoming')->when($data['data'] !== 'all', fn ($q) => $q->where('is_demo', $data['data'] === 'fixtures'))->where('match_state', 'unmatched')->count()]);
    }

    public function show(Request $request, MailMessage $message): View
    {
        $search = $request->validate(['case_q' => ['nullable', 'string', 'max:150']])['case_q'] ?? '';
        $cases = Inquiry::where('is_demo', $message->is_demo)->when($search, fn ($q) => $q->where(fn ($q) => $q->where('reference', 'ilike', '%'.$search.'%')->orWhere('title', 'ilike', '%'.$search.'%')))->latest('id')->limit(150)->get();
        $ids = array_filter([$message->inquiry_id, ...array_column($message->candidates, 'inquiry_id')]);
        $cases = $cases->merge(Inquiry::where('is_demo', $message->is_demo)->whereIn('id', $ids)->get());

        return view('mail.message', ['message' => $message->load('inquiry', 'revision.rfq.round.version', 'attachments'), 'inquiries' => $cases, 'caseSearch' => $search, 'approvals' => RfqApproval::with('revision.rfq')->whereHas('revision.rfq', fn ($q) => $q->whereIn('inquiry_id', $cases->modelKeys()))->latest('id')->get(), 'events' => DB::table('mail_events')->where('mail_message_id', $message->id)->latest('id')->get()]);
    }

    public function review(MailReviewRequest $request, MailMessage $message, MailIngest $ingest): RedirectResponse
    {
        $ingest->review($message, $request->user(), $request->validated());

        return back()->with('status', 'Classification and exact case/request association recorded. Shipment fields remain subject to human review.');
    }

    public function retry(MailMessage $message): RedirectResponse
    {
        abort_unless($message->direction === 'incoming', 422);
        ImportMailAttachments::dispatch($message->id, DB::table('mail_folder_message')->where('mail_message_id', $message->id)->value('mailbox_folder_id'))->onQueue('mail');

        return back()->with('status', 'Private attachment retry queued. Original message and existing files are retained.');
    }

    public function attachment(MailMessage $message, MailAttachment $attachment): BinaryFileResponse
    {
        abort_unless($attachment->mail_message_id === $message->id && $attachment->state === 'stored' && $attachment->storage_path, 404);
        $path = Storage::disk('mailbox')->path($attachment->storage_path);
        abort_unless(is_file($path) && hash_file('sha256', $path) === $attachment->checksum, 404);

        return response()->download($path, $attachment->name, ['Content-Type' => $attachment->mime, 'Content-Security-Policy' => "sandbox; default-src 'none'"]);
    }

    public function timeline(Inquiry $inquiry): View
    {
        Gate::authorize('view', $inquiry);

        return view('mail.timeline', ['inquiry' => $inquiry, 'messages' => MailMessage::where('inquiry_id', $inquiry->id)->latest('received_at')->paginate(20), 'dispatches' => MailDispatch::whereHas('envelope', fn ($q) => $q->where('inquiry_id', $inquiry->id))->with('envelope')->latest('id')->get()]);
    }

    public function preview(Request $request, string $kind, int $id, MailRelease $release): View
    {
        $source = null;
        $snapshot = null;
        $reasons = [];
        try {
            $source = $release->source($kind, $id, $request->user());
            $snapshot = $release->preview($source, MailboxConnection::current());
        } catch (ValidationException $e) {
            $reasons = array_merge(...array_values($e->errors()));
        }
        $key = $kind.':'.$id;

        return view('mail.preview', ['source' => $source, 'snapshot' => $snapshot, 'reasons' => $reasons, 'kind' => $kind, 'sourceId' => $id, 'connection' => MailboxConnection::current(), 'envelopes' => MailEnvelope::where('source_key', $key)->latest('id')->get(), 'dispatches' => MailDispatch::where('source_key', $key)->latest('id')->get()]);
    }

    public function authorizeEnvelope(Request $request, string $kind, int $id, MailOutbox $outbox): RedirectResponse
    {
        $data = $request->validate(['digest' => ['required', 'string', 'size:64'], 'authorize_exact' => ['accepted']]);
        $e = $outbox->authorize($kind, $id, $request->user(), $data['digest']);

        return to_route('mail.preview', [$kind, $id])->with('status', 'Exact sender envelope authorized. Nothing has been sent; explicitly enqueue this authorized message next.');
    }

    public function enqueue(Request $request, MailEnvelope $envelope, MailOutbox $outbox): RedirectResponse
    {
        $data = $request->validate(['action_key' => ['required', 'uuid'], 'digest' => ['required', 'string', 'size:64'], 'send_exact' => ['accepted']]);
        $d = $outbox->enqueue($envelope, $request->user(), $data['action_key'], $data['digest']);

        return to_route('mail.dispatch', $d)->with('status', 'Exact message queued. Provider acceptance, delivery and reading remain separate.');
    }

    public function outgoing(Request $request): View
    {
        $data = $request->validate(['status' => ['nullable', Rule::in(array_keys(MailDispatch::STATES))]]);

        return view('mail.outgoing', ['dispatches' => MailDispatch::with('envelope.inquiry')->when($data['status'] ?? null, fn ($q, $s) => $q->where('status', $s))->latest('id')->paginate(20)->withQueryString()]);
    }

    public function dispatch(MailDispatch $dispatch): View
    {
        Gate::authorize('view', $dispatch->envelope->inquiry);

        return view('mail.dispatch', ['dispatch' => $dispatch, 'events' => DB::table('mail_events')->where('mail_dispatch_id', $dispatch->id)->latest('id')->get()]);
    }

    public function recover(Request $request, MailDispatch $dispatch, MailOutbox $outbox): RedirectResponse
    {
        $data = $request->validate(['action' => ['required', Rule::in(['cancel', 'recover'])], 'reason' => ['required', 'string', 'max:2000'], 'confirm' => ['accepted']]);
        if ($data['action'] === 'cancel') {
            $outbox->cancel($dispatch, $request->user());
        } else {
            $outbox->recover($dispatch, $request->user(), $data['reason']);
        }

        return back()->with('status', 'Recovery action recorded. An uncertain or submitted message is never blindly resent.');
    }
}
