<?php

namespace Database\Factories;

use App\Actions\ManageOffer;
use App\Models\User;
use App\Models\VendorOffer;
use App\Support\OfferCosts;
use App\Support\Processing;
use Illuminate\Database\Eloquent\Factories\Factory;

class VendorOfferRevisionFactory extends Factory
{
    public function definition(): array
    {
        return ['vendor_offer_id' => VendorOffer::factory(), 'number' => 1, 'status' => 'needs_review', 'payload' => fn (array $a): array => array_replace(ManageOffer::blank(VendorOffer::findOrFail($a['vendor_offer_id'])), ['reference' => 'FACTORY-Q', 'lines' => [array_replace(ManageOffer::blankLine(), ['description' => 'Unreviewed freight'])]]), 'calculation' => fn (array $a): array => OfferCosts::calculate($a['payload'], VendorOffer::findOrFail($a['vendor_offer_id'])->version->snapshot['shipment']), 'gaps' => fn (array $a): array => $a['calculation']['gaps'], 'known_total' => fn (array $a): string => $a['calculation']['known_total'], 'complete_total' => null, 'currency' => fn (array $a): string => $a['payload']['currency'], 'digest' => fn (array $a): string => Processing::hash(['payload' => $a['payload'], 'calculation' => $a['calculation']]), 'change_reason' => 'Fictional unreviewed fixture', 'created_by' => User::factory()];
    }
}
