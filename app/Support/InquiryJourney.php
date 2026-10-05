<?php

namespace App\Support;

use App\Models\Inquiry;
use App\Models\MailDispatch;
use App\Models\OfferSelection;
use App\Models\VendorOffer;
use Illuminate\Support\Facades\DB;

class InquiryJourney
{
    /** @return array{stage: int, title: string, description: string, action: string, url: string, reasons: array} */
    public static function current(Inquiry $inquiry): array
    {
        $shipment = route('inquiries.show', ['inquiry' => $inquiry, 'section' => 'shipment']).'#shipment-review';
        if (in_array($inquiry->status, ['closed', 'on_hold'], true)) {
            return self::step(1, $inquiry->status === 'closed' ? 'This inquiry is closed' : 'This inquiry is on hold', $inquiry->status_reason ?: 'Review the reason before reopening this shipment.', 'Review inquiry status', route('inquiries.show', $inquiry).'#manage-inquiry');
        }
        if (! $inquiry->eligible()) {
            $gaps = $inquiry->gaps();
            $contactAssessment = $inquiry->source_channel === 'website' && (! $inquiry->client_id || ! $inquiry->client_contact_id);

            return self::step(1, $gaps ? 'Complete the shipment details' : 'Review and confirm shipment details', $gaps ? 'Save what you know. Resolve the missing details before asking vendors for prices.' : 'Check the cargo, route and customer contact. Confirmation means the details are ready for pricing.', $contactAssessment ? 'Check customer contact' : ($gaps ? 'Complete details' : 'Review shipment'), $contactAssessment ? route('inquiries.public-contact.edit', $inquiry) : ($gaps ? route('inquiries.edit', $inquiry) : $shipment), array_values($gaps));
        }

        $selection = OfferSelection::where('inquiry_id', $inquiry->id)->whereNull('superseded_at')->latest('id')->first();
        if (! $selection || ($reasons = OfferEligibility::selectionReasons($selection))) {
            $hasOffers = VendorOffer::where('inquiry_id', $inquiry->id)->whereHas('version', fn ($query) => $query->where('number', $inquiry->shipment_revision))->exists();

            return self::step($hasOffers ? 3 : 2, $hasOffers ? 'Review and choose a vendor price' : 'Ask vendors for prices', $hasOffers ? 'Compare the same services, check missing charges and choose a reviewed offer.' : 'Choose suitable logistics partners, then review and send a separate price request to each.', $hasOffers ? 'Compare vendor prices' : 'Choose vendors', route($hasOffers ? 'offers.index' : 'inquiries.sourcing', $inquiry), $selection ? $reasons : []);
        }

        $quote = LifecycleEligibility::quote($inquiry);
        if (! $quote) {
            return self::step(4, 'Prepare the customer quotation', 'Use the selected vendor cost, set your markup and review the price your customer will receive.', 'Set customer price', route('quotations.index', $inquiry));
        }
        if ($reasons = LifecycleEligibility::replacementReasons($quote)) {
            return self::step(3, 'Vendor terms changed', 'Review the new vendor price before revising the customer quotation and obtaining fresh acceptance.', 'Review vendor prices', route('offers.index', $inquiry), $reasons);
        }
        if ($reasons = QuotationEligibility::reasons($quote)) {
            return self::step(4, 'Update the customer quotation', 'Resolve the current pricing or validity checks, then review the saved quotation again.', 'Review customer quotation', route('quotations.index', $inquiry), $reasons);
        }
        if (! $quote->approval) {
            return self::step(4, 'Review and approve the quotation', 'Check the saved customer price, PDF, recipients and email. Approval does not send it.', 'Review quotation', route('quotations.review', [$inquiry, $quote]));
        }

        $decision = LifecycleEligibility::decision($quote);
        if (! $decision) {
            $dispatch = MailDispatch::whereHas('envelope', fn ($query) => $query->where('client_quotation_approval_id', $quote->approval->id))->latest('id')->first();
            $manual = DB::table('quotation_manual_sends')->where('client_quotation_approval_id', $quote->approval->id)->exists();
            if ($dispatch && ! in_array($dispatch->status, ['accepted', 'observed', 'cancelled'], true)) {
                return self::step(4, 'Check the quotation email', 'The sending attempt needs attention. Check its status before trying to send again.', 'Check sending status', route('mail.dispatch', $dispatch));
            }
            if (! $manual && (! $dispatch || $dispatch->status === 'cancelled')) {
                return self::step(4, 'Send the approved quotation', 'Review the sending details and explicitly release the approved email. It has not been sent yet.', 'Review and send', route('quotations.review', [$inquiry, $quote]));
            }

            return self::step(5, 'Waiting for the customer’s decision', 'Record acceptance, a decline or requested changes against this exact quotation.', 'Record customer decision', route('lifecycle.decision', [$inquiry, $quote]));
        }
        if ($decision->outcome === 'revision_requested') {
            return self::step(4, 'The customer requested changes', 'Review the requested changes and prepare a new quotation. Earlier versions remain available.', 'Revise quotation', route('quotations.index', $inquiry));
        }
        if ($decision->outcome !== 'accepted') {
            return self::step(5, $decision->outcome === 'declined' ? 'The customer declined this quotation' : 'Review the customer’s reply', 'Check the recorded response and decide whether to clarify, revise or close the inquiry.', 'Review customer decision', route('lifecycle.decision', [$inquiry, $quote]));
        }
        if ($reasons = LifecycleEligibility::acceptanceReasons($decision)) {
            return self::step(5, 'Customer acceptance needs review', 'The accepted quotation or its supporting evidence changed. Resolve the checks before proceeding.', 'Review acceptance', route('lifecycle.decision', [$inquiry, $quote]), $reasons);
        }

        $status = LifecycleEligibility::statuses($inquiry);
        if (! $status['r']?->current() || $status['r']->current()->status !== 'confirmed') {
            return self::step(5, 'Reconfirm with the selected vendor', 'Check that the agreed price, services, dates and space are still available. Customer acceptance alone is not a booking.', 'Reconfirm vendor', $status['r'] ? route('lifecycle.vendor', [$inquiry, $status['r']]) : route('lifecycle.index', $inquiry));
        }
        if ($status['handoff'] === 'Handed to operations') {
            return self::step(5, match ($status['booking']) {
                'Booking confirmed' => 'Vendor booking recorded',
                'Booking requested' => 'Waiting for vendor booking confirmation',
                default => 'Record the actual vendor booking',
            }, 'Keep the vendor reference and actual confirmation evidence with this shipment.', 'Review booking evidence', route('lifecycle.handoff', $inquiry).'#record-event');
        }
        if ($status['handoff'] === 'Handoff approved') {
            return self::step(5, 'Hand the approved shipment to operations', 'The handoff is approved. Record when operations actually receives it; booking confirmation remains a separate step.', 'Record operations handoff', route('lifecycle.handoff', $inquiry).'#record-event');
        }

        return self::step(5, $status['handoff'] === 'Handoff ready' ? 'Review and approve the operations handoff' : 'Complete the operations checklist', 'Check contacts, cargo readiness and required documents, then review the internal handoff.', 'Open operations checklist', route('lifecycle.handoff', $inquiry));
    }

    /** @return array{stage: int, title: string, description: string, action: string, url: string, reasons: array} */
    private static function step(int $stage, string $title, string $description, string $action, string $url, array $reasons = []): array
    {
        return compact('stage', 'title', 'description', 'action', 'url', 'reasons');
    }
}
