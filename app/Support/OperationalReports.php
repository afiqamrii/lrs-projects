<?php

namespace App\Support;

use App\Models\CompanySetting;
use App\Models\Inquiry;
use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

class OperationalReports
{
    /** @return array{from:string,to:string,start:string,end:string,data:string,owner:?int,status:?string,source:?string,vendor:?int,tab:string} */
    public function filters(Request $request): array
    {
        $d = $request->validate([
            'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'data' => ['nullable', Rule::in(['real', 'samples', 'all'])],
            'owner' => ['nullable', 'integer', 'min:0', function (string $attribute, mixed $value, \Closure $fail): void {
                if ((int) $value !== 0 && ! DB::table('users')->where('id', $value)->exists()) {
                    $fail('Choose an existing owner or Unassigned.');
                }
            }],
            'status' => ['nullable', Rule::in(array_keys(Inquiry::STATUSES))],
            'source' => ['nullable', Rule::in(array_keys(Inquiry::CHANNELS))],
            'vendor' => ['nullable', 'integer', Rule::exists('vendors', 'id')],
            'tab' => ['nullable', Rule::in(['inquiries', 'rfqs', 'ai'])],
        ]);
        $timezone = CompanySetting::current()->timezone;
        $from = $d['from'] ?? now()->setTimezone($timezone)->subDays(89)->format('Y-m-d');
        $to = $d['to'] ?? now()->setTimezone($timezone)->format('Y-m-d');
        $start = CarbonImmutable::parse($from, $timezone)->startOfDay();
        $end = CarbonImmutable::parse($to, $timezone)->addDay()->startOfDay();
        if ($start->gte($end) || $start->diffInDays($end) > 366) {
            throw ValidationException::withMessages(['from' => 'Choose an ordered period of at most 366 days. Export longer history in separate periods.']);
        }

        return ['from' => $from, 'to' => $to, 'start' => $start->utc()->toIso8601String(), 'end' => $end->utc()->toIso8601String(),
            'data' => $d['data'] ?? (WorkspaceData::preview() ? 'samples' : 'real'), 'owner' => isset($d['owner']) ? (int) $d['owner'] : null,
            'status' => $d['status'] ?? null, 'source' => $d['source'] ?? null, 'vendor' => isset($d['vendor']) ? (int) $d['vendor'] : null, 'tab' => $d['tab'] ?? 'inquiries'];
    }

    public function cohort(array $filters): Builder
    {
        return DB::table('inquiries as i')->where('i.received_at', '>=', $filters['start'])->where('i.received_at', '<', $filters['end'])
            ->when($filters['data'] !== 'all', fn (Builder $q) => $q->where('i.is_demo', $filters['data'] === 'samples'))
            ->when($filters['owner'] !== null, fn (Builder $q) => $filters['owner'] === 0 ? $q->whereNull('i.owner_id') : $q->where('i.owner_id', $filters['owner']))
            ->when($filters['status'], fn (Builder $q, string $value) => $q->where('i.status', $value))
            ->when($filters['source'], fn (Builder $q, string $value) => $q->where('i.source_channel', $value))
            ->when($filters['vendor'], fn (Builder $q, int $value) => $q->whereExists(fn (Builder $s) => $s->selectRaw('1')->from('rfqs')->whereColumn('rfqs.inquiry_id', 'i.id')->where('vendor_id', $value)));
    }

    public function cases(array $filters): Builder
    {
        $q = $this->cohort($filters)
            ->leftJoin('users as u', 'u.id', '=', 'i.owner_id')
            ->leftJoin('client_quotations as cq', 'cq.inquiry_id', '=', 'i.id')
            ->leftJoin('client_quotation_revisions as qr', fn ($j) => $j->on('qr.client_quotation_id', '=', 'cq.id')->on('qr.number', '=', 'cq.current_number'))
            ->leftJoin('client_quotation_approvals as qa', 'qa.client_quotation_revision_id', '=', 'qr.id')
            ->leftJoin('client_decisions as cd', fn ($j) => $j->on('cd.client_quotation_revision_id', '=', 'qr.id')->whereRaw('cd.number = (SELECT MAX(d.number) FROM client_decisions d WHERE d.client_quotation_revision_id = qr.id)'))
            ->leftJoin('booking_handoffs as bh', 'bh.inquiry_id', '=', 'i.id')
            ->leftJoin('handoff_revisions as hr', fn ($j) => $j->on('hr.booking_handoff_id', '=', 'bh.id')->on('hr.number', '=', 'bh.current_number')->on('hr.client_quotation_revision_id', '=', 'qr.id')->on('hr.client_decision_id', '=', 'cd.id'))
            ->leftJoin('handoff_approvals as ha', 'ha.handoff_revision_id', '=', 'hr.id')
            ->select(['i.id', 'i.reference', 'i.title', 'i.source_channel', 'i.status', 'i.received_at', 'i.response_due_at', 'i.is_demo', 'u.name as owner_name',
                'cq.reference as quotation_reference', 'qr.id as quote_id', 'qr.number as quote_number', 'qr.state as quote_state', 'qa.approved_at', 'qr.expires_at',
                'cd.decided_at', 'cd.channel as decision_channel', 'ha.id as handoff_approval_id']);
        $q->selectRaw("CASE WHEN qr.id IS NULL THEN 'no_quote' WHEN cd.outcome IS NOT NULL THEN cd.outcome WHEN qr.expires_at <= ? THEN 'expired' ELSE 'pending' END AS outcome", [now()])
            ->selectRaw("qr.pricing->>'currency' AS currency, CASE WHEN qr.pricing->'gaps' = '[]'::jsonb THEN qr.pricing->>'cost_subtotal' END AS quoted_cost, CASE WHEN qr.pricing->'gaps' = '[]'::jsonb THEN qr.pricing->>'selling_subtotal' END AS quoted_selling, CASE WHEN qr.pricing->'gaps' = '[]'::jsonb THEN qr.pricing->>'total' END AS quoted_total, CASE WHEN qr.pricing->'gaps' = '[]'::jsonb THEN qr.pricing->>'estimated_profit' END AS estimated_profit")
            ->selectRaw("(SELECT MIN(e.occurred_at) FROM handoff_events e WHERE e.handoff_approval_id = ha.id AND e.kind = 'handed_to_operations') AS handed_at")
            ->selectRaw("(SELECT MAX(e.occurred_at) FROM handoff_events e WHERE e.handoff_approval_id = ha.id AND e.kind = 'booking_confirmed') AS booked_at")
            ->selectRaw("(SELECT a.title FROM attention_tasks a WHERE a.inquiry_id = i.id AND a.state = 'open' ORDER BY a.id DESC LIMIT 1) AS attention")
            ->selectRaw("(SELECT v.complete_total FROM offer_selections s JOIN vendor_offer_revisions v ON v.id = s.vendor_offer_revision_id WHERE s.inquiry_id = i.id AND s.superseded_at IS NULL AND s.kind = 'final' ORDER BY s.id DESC LIMIT 1) AS selected_cost")
            ->selectRaw("(SELECT v.currency FROM offer_selections s JOIN vendor_offer_revisions v ON v.id = s.vendor_offer_revision_id WHERE s.inquiry_id = i.id AND s.superseded_at IS NULL AND s.kind = 'final' ORDER BY s.id DESC LIMIT 1) AS selected_currency")
            ->selectRaw("(SELECT CASE WHEN m.is_demo THEN 'Fictional provider evidence' ELSE INITCAP(c.provider) || ' acceptance / observation' END FROM mail_dispatches m JOIN mail_envelopes e ON e.id = m.mail_envelope_id JOIN mailbox_connections c ON c.id = e.mailbox_connection_id WHERE e.client_quotation_approval_id = qa.id AND m.status IN ('accepted','observed') ORDER BY m.id DESC LIMIT 1) AS provider_evidence")
            ->selectRaw('(SELECT ms.recorded_at FROM quotation_manual_sends ms WHERE ms.client_quotation_approval_id = qa.id LIMIT 1) AS manual_sent_at');

        return $q;
    }

    public function requests(array $filters): Builder
    {
        $manual = DB::table('rfq_revisions as r')->join('rfq_approvals as a', 'a.rfq_revision_id', '=', 'r.id')
            ->join('rfq_dispatches as d', 'd.rfq_approval_id', '=', 'a.id')
            ->selectRaw("r.rfq_id, r.id AS revision_id, r.number, d.sent_at, 'Manually recorded send' AS send_basis");
        $provider = DB::table('rfq_revisions as r')->join('rfq_approvals as a', 'a.rfq_revision_id', '=', 'r.id')
            ->join('mail_envelopes as e', 'e.rfq_approval_id', '=', 'a.id')->join('mail_dispatches as d', 'd.mail_envelope_id', '=', 'e.id')
            ->join('mailbox_connections as c', 'c.id', '=', 'e.mailbox_connection_id')
            ->whereIn('d.status', ['accepted', 'observed'])->whereRaw('COALESCE(d.accepted_at,d.observed_at) IS NOT NULL')
            ->selectRaw("r.rfq_id, r.id AS revision_id, r.number, COALESCE(d.accepted_at,d.observed_at) AS sent_at, CASE WHEN d.is_demo THEN 'Fictional provider send' ELSE INITCAP(c.provider) || ' accepted / observed' END AS send_basis");
        $sends = DB::query()->fromSub($manual->unionAll($provider), 's')->selectRaw('DISTINCT ON (rfq_id) *')->orderBy('rfq_id')->orderByDesc('number')->orderByDesc('sent_at');
        $q = $this->cohort($filters)->join('rfqs as r', 'r.inquiry_id', '=', 'i.id')
            ->join('sourcing_rounds as sr', 'sr.id', '=', 'r.sourcing_round_id')
            ->join('shipment_versions as sv', fn ($j) => $j->on('sv.id', '=', 'sr.shipment_version_id')->on('sv.number', '=', 'i.shipment_revision'))
            ->join('vendors as v', 'v.id', '=', 'r.vendor_id')
            ->leftJoinSub($sends, 'sent', 'sent.rfq_id', '=', 'r.id')
            ->when($filters['vendor'], fn ($q, $v) => $q->where('r.vendor_id', $v))
            ->select(['r.id', 'r.reference', 'i.id as inquiry_id', 'i.reference as inquiry_reference', 'i.is_demo', DB::raw('COALESCE(v.display_name, v.company_name) AS vendor_name'), 'sent.revision_id', 'sent.number', 'sent.sent_at', 'sent.send_basis'])
            ->selectRaw("(SELECT MIN(m.received_at) FROM mail_messages m WHERE m.rfq_revision_id = sent.revision_id AND m.direction = 'incoming' AND m.match_state = 'matched' AND m.classification NOT IN ('out_of_office','bounce','automated','noise') AND m.received_at >= sent.sent_at) AS responded_at");

        return DB::query()->fromSub($q, 'requests')->select('*')
            ->selectRaw('EXTRACT(EPOCH FROM (responded_at - sent_at))/3600 AS response_hours');
    }

    public function ai(array $filters): Builder
    {
        return $this->cohort($filters)->join('ai_runs as a', 'a.inquiry_id', '=', 'i.id')
            ->where('a.created_at', '>=', $filters['start'])->where('a.created_at', '<', $filters['end'])
            ->select(['a.id', 'i.id as inquiry_id', 'i.reference', 'a.is_demo', 'a.purpose', 'a.model', 'a.state', 'a.usage', 'a.estimated_cost', 'a.cost_uncertain', 'a.reservation', 'a.created_at']);
    }

    public function query(array $filters): Builder
    {
        return match ($filters['tab']) {
            'rfqs' => $this->requests($filters), 'ai' => $this->ai($filters), default => $this->cases($filters),
        };
    }

    public function summary(array $filters): array
    {
        $cases = DB::query()->fromSub($this->cases($filters), 'cases');
        $totals = (clone $cases)->selectRaw("COUNT(*) AS inquiries, COUNT(quote_id) AS quotations, COUNT(*) FILTER (WHERE outcome = 'accepted') AS accepted, COUNT(*) FILTER (WHERE outcome = 'declined') AS declined, COUNT(*) FILTER (WHERE outcome = 'pending') AS pending, COUNT(*) FILTER (WHERE outcome = 'expired') AS expired, COUNT(*) FILTER (WHERE outcome = 'revision_requested') AS revised, COUNT(*) FILTER (WHERE outcome IN ('review_required','question')) AS review, COUNT(handed_at) AS handed, COUNT(booked_at) AS booked, COUNT(*) FILTER (WHERE status != 'closed' AND response_due_at < ?) AS overdue", [now()])->first();
        $money = (clone $cases)->whereNotNull('approved_at')->whereNotNull('currency')->selectRaw('currency, COUNT(*) AS quotations, SUM(quoted_cost::numeric) AS cost, SUM(quoted_selling::numeric) AS selling, SUM(quoted_total::numeric) AS total, SUM(estimated_profit::numeric) AS profit, COUNT(*) FILTER (WHERE quoted_total IS NULL) AS unknown')->groupBy('currency')->orderBy('currency')->get();
        $breakdowns = [];
        foreach (['source_channel', 'status', 'owner_name'] as $field) {
            $breakdowns[$field] = (clone $cases)->select($field)->selectRaw('COUNT(*) AS count')->groupBy($field)->orderByDesc('count')->get();
        }
        $rfqs = DB::query()->fromSub($this->requests($filters), 'r')->selectRaw('COUNT(*) AS requests, COUNT(sent_at) AS sent, COUNT(responded_at) AS answered, COUNT(*) FILTER (WHERE sent_at IS NOT NULL AND responded_at IS NULL) AS unanswered, AVG(response_hours) AS mean_hours')->first();
        $ai = DB::query()->fromSub($this->ai($filters), 'a')->selectRaw("is_demo, COUNT(*) AS runs, COUNT(*) FILTER (WHERE usage IS NULL) AS unknown_usage, COUNT(*) FILTER (WHERE estimated_cost IS NULL) AS unknown_cost, SUM((usage->>'input_tokens')::bigint) AS input_tokens, SUM((usage->>'output_tokens')::bigint) AS output_tokens, SUM(estimated_cost) AS cost, SUM(reservation) AS held, COUNT(*) FILTER (WHERE cost_uncertain) AS uncertain")->groupBy('is_demo')->get();

        return compact('totals', 'money', 'breakdowns', 'rfqs', 'ai');
    }

    public static function time(mixed $value, string $timezone): string
    {
        return $value === null ? 'Unknown / not observed' : CarbonImmutable::parse($value)->setTimezone($timezone)->format('d M Y, H:i');
    }

    public static function nextAction(object $row): string
    {
        return match (true) {
            $row->status === 'closed' => 'Closed · history retained',
            $row->status === 'on_hold' => 'Review hold reason',
            $row->booked_at !== null => 'Review recorded booking and current release',
            $row->handed_at !== null => 'Obtain actual vendor booking evidence',
            $row->outcome === 'accepted' => 'Review reconfirmation and handoff prerequisites',
            $row->outcome === 'declined' => 'Review decline and decide whether to close',
            $row->outcome === 'expired' => 'Prepare a renewed approved quotation if appropriate',
            in_array($row->outcome, ['question', 'review_required', 'revision_requested'], true) => 'Review client response and required changes',
            $row->quote_id !== null => 'Review exact quotation and decision',
            $row->status === 'ready_for_sourcing' => 'Review vendor sourcing and costs',
            default => 'Review source, responsibility and shipment gaps',
        };
    }

    public static function csvCell(mixed $value): string
    {
        $text = str_replace("\0", '', (string) ($value ?? ''));
        if (preg_match('/^[\s\x00-\x20\x{FEFF}]*[=+@-]/u', $text)) {
            return "'".$text;
        }

        return $text;
    }

    public function csvRow(string $tab, object $row): array
    {
        $usage = $tab === 'ai' ? json_decode($row->usage ?? 'null', true, flags: JSON_THROW_ON_ERROR) : [];

        return match ($tab) {
            'rfqs' => [$row->reference, $row->inquiry_reference, $row->is_demo ? 'Fictional' : 'Real', $row->vendor_name, $row->number, $row->sent_at, $row->send_basis, $row->responded_at, $row->response_hours],
            'ai' => [$row->id, $row->reference, $row->is_demo ? 'Fictional' : 'Live run', $row->created_at, $row->purpose, $row->model, $row->state, $usage['input_tokens'] ?? null, $usage['output_tokens'] ?? null, $row->estimated_cost, 'USD', $row->reservation, $row->cost_uncertain ? 'Uncertain' : ($row->estimated_cost === null ? 'Unknown estimate' : 'Recorded estimate')],
            default => [$row->reference, $row->title, $row->is_demo ? 'Fictional' : 'Real', $row->received_at, $row->owner_name, $row->source_channel, $row->status, $row->response_due_at, $row->quotation_reference, $row->quote_number, $row->outcome, $row->decided_at, $row->decision_channel, $row->selected_cost, $row->selected_currency, $row->quoted_cost, $row->quoted_selling, $row->quoted_total, $row->estimated_profit, $row->currency, $row->approved_at, $row->provider_evidence ?? ($row->manual_sent_at ? 'Manually recorded send' : 'No actual send evidence'), $row->handed_at, $row->booked_at, self::nextAction($row)],
        };
    }
}
