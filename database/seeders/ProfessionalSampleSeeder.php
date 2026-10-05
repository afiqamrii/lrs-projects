<?php

namespace Database\Seeders;

use App\Actions\ManageOffer;
use App\Actions\ManageRfq;
use App\Actions\PrepareRfqs;
use App\Actions\StartDocumentExtraction;
use App\Actions\StoreInquiryDocuments;
use App\Actions\TransitionInquiry;
use App\Models\AiRun;
use App\Models\Client;
use App\Models\CompanySetting;
use App\Models\Inquiry;
use App\Models\MailMessage;
use App\Models\PublicSubmission;
use App\Models\Rfq;
use App\Models\SourcingRound;
use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorOfferRevision;
use App\Support\Audit;
use App\Support\InquiryWorkflow;
use App\Support\OfferProposal;
use App\Support\Processing;
use App\Support\RfqContent;
use App\Support\Shipment;
use App\Support\WorkspaceData;
use Illuminate\Database\Seeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class ProfessionalSampleSeeder extends Seeder
{
    public function run(): void
    {
        $this->seed(User::where('role', 'admin')->where('is_active', true)->firstOrFail());
    }

    public function seed(User $staff): array
    {
        if (! app()->environment('local', 'testing')) {
            throw new \RuntimeException('Preview data is local only.');
        }
        Auth::login($staff);

        return DB::transaction(function () use ($staff): array {
            if (in_array($staff->name, ['Browser QA', 'Admin Browser QA'], true)) {
                $before = Audit::snapshot($staff);
                $staff->update(['name' => 'Operations Administrator']);
                Audit::record('Preview administrator display name updated', $staff, $before, actor: $staff);
            }
            CompanySetting::current()->update(['display_name' => 'Straits Logistics', 'workspace_data_mode' => 'samples', 'rfq_reply_name' => 'Straits Logistics · Operations', 'rfq_reply_email' => 'operations@straitslogistics.example', 'rfq_signature' => "Straits Logistics\nOperations team\noperations@straitslogistics.example\nFictional business preview", 'public_contact_email' => 'operations@straitslogistics.example', 'public_contact_address' => 'Klang Valley, Malaysia · fictional business preview', 'public_intake_owner_id' => $staff->id, 'receipt_mail_enabled' => false]);
            if (Inquiry::where('sample_set', WorkspaceData::SAMPLE_SET)->exists()) {
                return ['existing' => true, 'inquiries' => Inquiry::where('sample_set', WorkspaceData::SAMPLE_SET)->count()];
            }
            foreach ([Vendor::class, Client::class] as $class) {
                foreach ($class::where('company_name', 'ilike', '%Demo%')->orWhere('company_name', 'ilike', '%Browser%QA%')->orWhere('company_name', 'ilike', '%Harbor Bridge%')->get() as $old) {
                    if ($old->contacts()->where('email', 'not like', '%@example.test')->exists()) {
                        continue;
                    }
                    $before = Audit::snapshot($old);
                    $old->update(['is_active' => false, 'is_demo' => true]);
                    Audit::record('Legacy preview record archived', $old, $before, actor: $staff, details: ['preview_archive' => ['before' => 'legacy', 'after' => 'history retained']]);
                }
            }
            $vendors = [];
            foreach ([
                ['Straits Cargo Partners', 'freight_forwarder', 'Aina Rahman', 'aina@straitscargo.example', 'Port Klang · Singapore · Penang'],
                ['Meridian Ocean Logistics', 'consolidator', 'Daniel Lim', 'daniel@meridianocean.example', 'Malaysia · Singapore · Hong Kong'],
                ['Harbour Bridge Freight', 'freight_forwarder', 'Mei Tan', 'mei@harbourbridge.example', 'Port Klang · Singapore · Bangkok'],
                ['Northstar Maritime Services', 'shipping_line', 'Jasper Wong', 'jasper@northstarmaritime.example', 'Asia Pacific · Europe'],
                ['Coastal Link Transport', 'transporter', 'Nadia Ismail', 'nadia@coastallink.example', 'Klang Valley · Johor · Penang'],
            ] as [$name,$type,$contactName,$email,$coverage]) {
                $v = Vendor::create(['company_name' => $name, 'type' => $type, 'services' => $type === 'transporter' ? ['Pickup', 'Delivery'] : ['LCL', 'FCL', 'Pickup', 'Delivery', 'Clearance'], 'coverage' => $coverage, 'minimum_notes' => 'Minimums depend on the route and vendor quotation; review each offer.', 'communication_channel' => 'email', 'is_active' => true, 'is_demo' => true, 'internal_notes' => 'Fictional business preview. No real company, partnership or vendor performance rating is claimed.']);
                $v->contacts()->create(['name' => $contactName, 'role' => 'Commercial desk', 'email' => $email, 'is_primary' => true, 'is_active' => true]);
                $vendors[] = $v;
            }
            $clients = [];
            foreach ([
                ['Peninsula Precision Industries', 'PPI', 'Farah Ahmad', 'farah@peninsulaprecision.example', 'Shah Alam, Selangor'],
                ['Bayfront Homeware Trading', 'BHT', 'Marcus Lee', 'marcus@bayfronthomeware.example', 'Bayan Lepas, Penang'],
                ['Orchard Retail Supply', 'ORS', 'Sarah Chen', 'sarah@orchardretail.example', 'Jurong, Singapore'],
                ['Kencana Engineering Supplies', 'KES', 'Hafiz Razak', 'hafiz@kencanaengineering.example', 'Senai, Johor'],
            ] as [$name,$ref,$person,$email,$address]) {
                $c = Client::create(['company_name' => $name, 'reference_identifier' => 'PREVIEW-'.$ref, 'address' => $address, 'internal_notes' => 'Fictional company and contact for local business preview.', 'is_demo' => true]);
                $c->contacts()->create(['name' => $person, 'email' => $email, 'role' => 'Purchasing / logistics', 'is_active' => true, 'is_primary' => true]);
                $clients[] = $c;
            }
            $lcl = Shipment::normalize(['mode' => 'LCL', 'scope' => 'port_to_door', 'cargo_description' => 'Non-hazardous machined aluminium components on two export pallets', 'origin_country' => 'Malaysia', 'origin_location' => 'Port Klang', 'destination_country' => 'Singapore', 'destination_location' => 'Singapore port', 'delivery_address' => 'Receiving warehouse, Jurong, Singapore · fictional address', 'cargo_ready_date' => now()->addDays(10)->toDateString(), 'arrival_date' => now()->addDays(17)->toDateString(), 'incoterm' => 'DAP', 'named_place' => 'Jurong receiving warehouse', 'packages' => [['packaging_type' => 'pallets', 'quantity' => 2, 'gross_weight' => '450', 'weight_unit' => 'kg', 'length' => '100', 'width' => '100', 'height' => '100', 'dimension_unit' => 'cm']]]);
            $a = $this->inquiry($staff, $clients[0], 'Precision components · Port Klang → Singapore', 'website', $lcl, true);
            $round = $this->requests($a, $staff, array_slice($vendors, 0, 3));
            foreach ($round->rfqs as $i => $rfq) {
                $lines = [$this->line('line-1', 'Main sea freight', 'freight', $i === 2 ? '600' : ($i === 1 ? '525' : '1050'), $i === 0 ? 'flat' : ($i === 1 ? 'cbm' : 'wm'), 'MYR')];
                if ($i === 2) {
                    $lines[0] += ['wm_kg' => '1000', 'wm_cbm' => '1', 'unit_definition' => 'One revenue unit is the greater of 1000 kg or 1 CBM.'];
                }
                $lines[] = $this->line('line-2', 'Warehouse delivery · Jurong', 'delivery', $i === 0 ? null : '100', 'flat', 'MYR');
                $lines[] = $this->line('line-3', 'Optional cargo insurance', 'insurance', '75', 'flat', 'MYR') + ['optional' => true];
                $this->offer($a, $staff, $rfq, 'Standard weekly sailing', $lines, $i === 0 ? '1050' : ($i === 1 ? '1150' : '1300'), $i === 0);
            }
            app(ManageOffer::class)->comparison($a, $staff, ['expected_comparison' => 0, 'currency' => 'MYR', 'reason' => 'Same two pallets and required Singapore warehouse delivery.']);
            $fcl = Shipment::normalize(['mode' => 'FCL', 'scope' => 'door_to_port', 'cargo_description' => 'Cartons of non-hazardous homeware', 'origin_country' => 'Malaysia', 'origin_location' => 'Penang port', 'destination_country' => 'Australia', 'destination_location' => 'Melbourne port', 'pickup_address' => 'Bayan Lepas export warehouse · fictional address', 'cargo_ready_date' => now()->addDays(15)->toDateString(), 'arrival_date' => now()->addDays(45)->toDateString(), 'containers' => [['type' => '40HC', 'quantity' => 2, 'gross_weight' => '12000', 'weight_unit' => 'kg']]]);
            $b = $this->inquiry($staff, $clients[1], 'Homeware export · 2 × 40HC to Melbourne', 'email', $fcl, true);
            $round = $this->requests($b, $staff, [$vendors[3]]);
            $lines = [$this->line('line-1', 'Ocean freight · 40HC', 'freight', '1200', 'container', 'USD') + ['container_type' => '40HC'], $this->line('line-2', 'Penang warehouse pickup', 'pickup', '100', 'flat', 'USD')];
            $this->offer($b, $staff, $round->rfqs->first(), 'Direct sailing · 2 × 40HC', $lines, '2500');
            $lines[0]['rate'] = '1100';
            $this->offer($b, $staff, $round->rfqs->first(), 'Later sailing · 2 × 40HC', $lines, '2300');
            app(ManageOffer::class)->comparison($b, $staff, ['expected_comparison' => 0, 'currency' => 'MYR', 'reason' => 'Fictional indicative conversion for training; review before real use.', 'fx' => ['USD' => ['from' => 'USD', 'to' => 'MYR', 'rate' => '4.5', 'direction' => 'multiply', 'date' => now()->toDateString(), 'source' => 'Fictional business-preview FX fixture; not a market rate', 'confirmed' => '1']]]);
            $c = $this->inquiry($staff, $clients[2], 'Retail display fixtures · Singapore → Port Klang', 'website', array_replace($lcl, ['scope' => 'port_to_port', 'origin_country' => 'Singapore', 'origin_location' => 'Singapore port', 'destination_country' => 'Malaysia', 'destination_location' => 'Port Klang', 'delivery_address' => null, 'cargo_description' => 'Flat-packed retail display fixtures']), true);
            $round = $this->requests($c, $staff, [$vendors[1]]);
            $lines = [$this->line('line-1', 'LCL freight · minimum MYR 400', 'freight', '150', 'cbm', 'MYR') + ['minimum_charge' => '400']];
            $expired = $this->offer($c, $staff, $round->rfqs->first(), 'Previous rate window', $lines, '400', false, true);
            app(ManageOffer::class)->comparison($c, $staff, ['expected_comparison' => 0, 'currency' => 'MYR', 'reason' => 'Expired offer stays visible and outside current ranking.']);
            $pending = array_replace($lcl, ['scope' => 'unknown', 'declared_volume' => null, 'cargo_description' => 'Spare pump assemblies; final packing dimensions pending', 'packages' => [['packaging_type' => 'crates', 'quantity' => 3, 'gross_weight' => '680', 'weight_unit' => 'kg']]]);
            $d = $this->inquiry($staff, $clients[3], 'Pump assemblies · dimensions needed', 'email', $pending, false);
            $d->update(['status' => 'needs_client_information', 'priority' => 'urgent', 'response_due_at' => now()->subHours(4)]);
            $e = $this->inquiry($staff, $clients[0], 'Monthly replenishment · Port Klang → Singapore', 'website', $lcl, false);
            $e->update(['internal_notes' => 'Assess the next shipment independently; do not reuse the earlier confirmed quantities without review.']);
            Audit::record('Professional fictional workspace prepared', $a, actor: $staff, details: ['sample_set' => ['before' => null, 'after' => WorkspaceData::SAMPLE_SET]]);

            return ['inquiries' => 5, 'vendors' => 5, 'clients' => 4, 'offers' => 6, 'mode' => 'fictional business preview'];
        });
    }

    private function inquiry(User $staff, Client $client, string $title, string $channel, array $shipment, bool $confirm): Inquiry
    {
        $contact = $client->primaryContact;
        $text = "Dear Operations Team,\nPlease quote ".$shipment['cargo_description'].' from '.$shipment['origin_location'].' to '.$shipment['destination_location'].'. Cargo ready '.$shipment['cargo_ready_date'].". Please advise included services and any additional charges.\nRegards,\n".$contact->name."\n".$client->company_name."\n[Fictional business preview]";
        $case = Inquiry::create(['reference' => InquiryWorkflow::reference(), 'client_id' => $client->id, 'client_contact_id' => $contact->id, 'owner_id' => $staff->id, 'title' => $title, 'priority' => 'normal', 'status' => 'needs_review', 'received_at' => now()->subHours(3), 'response_due_at' => now()->addDays(3), 'source_channel' => $channel, 'original_source_text' => $text, 'shipment' => $shipment, 'shipment_revision' => 1, 'lock_version' => 0, 'is_demo' => true, 'sample_set' => WorkspaceData::SAMPLE_SET, 'public_contact' => $channel === 'website' ? ['name' => $contact->name, 'email' => $contact->email, 'company' => $client->company_name] : null]);
        if ($channel === 'website') {
            PublicSubmission::create(['inquiry_id' => $case->id, 'idempotency_hash' => hash('sha256', 'preview-'.$case->id), 'session_hash' => hash('sha256', 'preview-session-'.$case->id), 'snapshot' => ['contact' => $case->public_contact, 'shipment' => $shipment, 'additional_notes' => $text, 'documents' => [], 'privacy' => ['acknowledged' => true, 'version' => CompanySetting::current()->public_privacy_version, 'notice' => CompanySetting::current()->public_privacy_notice, 'acknowledged_at' => now()->toIso8601String()], 'sample' => true], 'received_at' => $case->received_at]);
        } else {
            $this->message($case, $contact->email, $contact->name, $title, $text, 'customer');
        }
        Audit::record('Fictional '.$channel.' inquiry prepared', $case, actor: $staff, details: ['preview' => ['before' => null, 'after' => true]]);

        return $confirm ? app(TransitionInquiry::class)->handle($case, ['lock_version' => 0, 'target' => 'ready_for_sourcing']) : $case;
    }

    private function requests(Inquiry $case, User $staff, array $vendors): SourcingRound
    {
        $round = app(PrepareRfqs::class)->handle($case, $staff, array_map(fn (Vendor $v): int => $v->id, $vendors), $case->lock_version);
        foreach ($round->rfqs as $rfq) {
            $p = $rfq->current()->payload;
            $data = $p + ['expected_revision' => $rfq->current_number, 'to_contact_id' => $p['to']['id'], 'cc_contact_ids' => [], 'document_ids' => [], 'change_reason' => 'Prepared fictional preview request; no outbound transmission'];
            $data['attachments_reviewed'] = true;
            $data['disclose_addresses'] = true;
            $data['disclosure_notes'] = 'Only the relevant fictional service address is needed for an equivalent quote.';
            $data['response_due_at'] = InquiryWorkflow::local(now()->addDay());
            app(ManageRfq::class)->save($rfq, $staff, $data);
            $rfq->refresh();
            $revision = $rfq->current();
            app(ManageRfq::class)->approve($rfq, $staff, $revision->number, Processing::hash(RfqContent::snapshot($revision)));
        }

        return $round->fresh('rfqs');
    }

    private function line(string $key, string $description, string $service, ?string $rate, string $basis, string $currency): array
    {
        return ['key' => $key, 'description' => $description, 'category' => $service === 'freight' ? 'main' : ($service === 'insurance' ? 'insurance' : 'pickup_delivery'), 'service' => $service, 'state' => $rate === null ? 'unpriced' : 'priced', 'rate' => $rate, 'basis' => $basis, 'currency' => $currency, 'tax_treatment' => 'inclusive', 'confirmed' => '1', 'source_ref' => 'Quotation '.$key, 'raw_text' => $description.' '.($rate === null ? 'amount to be confirmed' : $currency.' '.$rate.' per '.$basis)];
    }

    private function message(Inquiry $case, string $email, string $name, string $subject, string $body, string $classification, ?int $revisionId = null): MailMessage
    {
        $source = ['id' => 'preview-'.Str::uuid(), 'subject' => $subject, 'from' => ['emailAddress' => ['address' => $email, 'name' => $name]], 'toRecipients' => [['emailAddress' => ['address' => 'operations@straitslogistics.example']]], 'body' => ['contentType' => 'Text', 'content' => $body], 'receivedDateTime' => now()->toIso8601String(), 'hasAttachments' => false, 'preview_only' => true];

        return MailMessage::create(['mailbox_key' => hash('sha256', WorkspaceData::SAMPLE_SET), 'provider_id' => $source['id'], 'is_demo' => true, 'direction' => 'incoming', 'sender_email' => $email, 'subject' => $subject, 'received_at' => now(), 'source' => $source, 'source_hash' => Processing::hash($source), 'classification' => $classification, 'match_state' => 'matched', 'attachment_state' => 'complete', 'inquiry_id' => $case->id, 'rfq_revision_id' => $revisionId, 'match_reason' => 'Fictional locally prepared evidence; no mailbox import occurred']);
    }

    private function offer(Inquiry $case, User $staff, Rfq $rfq, string $alternative, array $lines, string $total, bool $gap = false, bool $expired = false): VendorOfferRevision
    {
        $currency = $lines[0]['currency'];
        $valid = ($expired ? now()->subDay() : now()->addDays(20))->setTimezone(CompanySetting::current()->timezone)->setTime(18, 0);
        $reference = 'Q-'.now()->format('ym').'-'.$rfq->vendor_id.'-'.Str::upper(Str::substr(hash('sha256', $alternative.$case->id), 0, 4));
        $body = "Dear Operations Team,\nQuotation ".$reference.' for '.$rfq->reference."\nOption: ".$alternative."\n";
        foreach ($lines as $l) {
            $body .= $l['source_ref'].': '.$l['raw_text']."\n";
        }
        $body .= 'Quoted baseline '.($gap ? 'subtotal only' : 'total').': '.$currency.' '.$total."\nValidity: ".$valid->format('d M Y')." at 18:00 Malaysia time.\nPayment: prepaid before cargo release.\nEstimated transit: 5–7 days for regional LCL; FCL 22–28 days. Subject to equipment and vessel space.\nRegards,\n".$rfq->vendor->primaryContact->name."\n".$rfq->vendor->company_name."\n[Fictional business preview]";
        $mail = $this->message($case, $rfq->vendor->primaryContact->email, $rfq->vendor->primaryContact->name, $reference.' · '.$alternative, $body, 'quote', $rfq->current()->id);
        $temporary = tempnam(storage_path('app/private'), 'vendor-quote-');
        $stream = fopen($temporary, 'wb');
        fputcsv($stream, ['Vendor quotation · fictional business preview'], ',', '"', '');
        fputcsv($stream, [$body], ',', '"', '');
        fclose($stream);
        try {
            app(StoreInquiryDocuments::class)->handle($case, [new UploadedFile($temporary, $reference.'.csv', 'text/csv', null, true)], 'freight_quote');
        } finally {
            unlink($temporary);
        }
        $document = $case->documents()->where('original_name', $reference.'.csv')->firstOrFail();
        app(StartDocumentExtraction::class)->handle($case, $document, $staff, ['pages' => [], 'ocr_pages' => []]);
        $offer = app(ManageOffer::class)->capture($case, $staff, ['rfq_revision_id' => $rfq->current()->id, 'alternative' => $alternative, 'source_kind' => 'email', 'message_id' => $mail->id, 'document_ids' => [$document->id], 'association_confirmed' => '1', 'association_reason' => 'Locally prepared fictional quotation from the named vendor contact for this exact request.']);
        $p = ManageOffer::blank($offer);
        $valid = ($expired ? now()->subDay() : now()->addDays(20))->setTimezone(CompanySetting::current()->timezone)->setTime(18, 0);
        $p = array_replace($p, ['expected_revision' => 0, 'change_reason' => 'Reviewed fictional business-preview commercial terms', 'reference' => $reference, 'currency' => $currency, 'quantity_statement' => $case->shipment['mode'] === 'FCL' ? '2 × 40HC; 12000 kg total' : '2 pallets; 2 CBM; confirmed gross weight', 'payment_terms' => 'Prepaid before cargo release', 'validity_statement' => 'dated', 'valid_until' => $valid->toIso8601String(), 'timing_assessment' => 'meets_requested', 'timing_note' => 'Vendor-stated estimated service fits the requested window, subject to vessel space and equipment.', 'transit' => $case->shipment['mode'] === 'FCL' ? '22–28 days estimated' : '5–7 days estimated', 'conditions' => 'Rates subject to vessel space and equipment. No booking is confirmed.', 'inclusions' => 'Quoted tax included in stated rates.', 'exclusions' => $gap ? 'Warehouse delivery awaiting rate confirmation' : 'Optional insurance charged only if requested.', 'quoted_total' => $gap ? null : $total, 'total_not_stated_reason' => $gap ? 'Only the known freight subtotal was quoted; delivery remains unpriced' : null, 'review' => ['scope' => '1', 'quantities' => '1', 'terms' => '1', 'validity' => '1'], 'lines' => $lines]);
        $revision = app(ManageOffer::class)->save($offer, $staff, $p, true);
        if (($lines[0]['basis'] ?? null) === 'cbm' && ! $expired && ! $gap) {
            $sources = OfferProposal::sources($revision);
            $result = ['proposals' => [['field' => 'lines.0.rate', 'value' => $lines[0]['rate'], 'source_id' => $sources[0]['id'], 'snippet' => $lines[0]['raw_text'], 'uncertainty' => ''], ['field' => 'valid_until', 'value' => null, 'source_id' => $sources[0]['id'], 'snippet' => 'Validity:', 'uncertainty' => 'Keep the exact vendor deadline; do not infer time zones.']]];
            AiRun::create(['inquiry_id' => $case->id, 'requested_by' => $staff->id, 'purpose' => 'vendor_quotation', 'vendor_offer_revision_id' => $revision->id, 'identity' => Processing::hash(['preview_proposals' => $revision->id]), 'generation' => 1, 'shipment_hash' => $case->snapshotHash(), 'shipment_revision' => $case->shipment_revision, 'model' => 'fictional-proposal-fixture', 'prompt_version' => config('offers.prompt_version'), 'schema_version' => config('offers.schema_version'), 'settings' => [], 'sources' => $sources, 'working_snapshot' => ['origin' => 'fictional proposal fixture; no provider request'], 'input_characters' => mb_strlen(OfferProposal::input($sources)), 'input_bound' => 0, 'is_demo' => true, 'state' => 'needs_review', 'result' => $result, 'proposals' => null, 'attempts' => 0, 'reservation' => '0', 'estimated_cost' => '0', 'completed_at' => now()]);
        }

        return $revision;
    }
}
