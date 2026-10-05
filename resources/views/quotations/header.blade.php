<a class="text-link mb-6 inline-block text-xs" href="{{ route('inquiries.show',$inquiry) }}">← Inquiry workspace</a>
<x-page-header :title="$heading" :eyebrow="$inquiry->reference" :description="$inquiry->client?->company_name"><x-button variant="secondary" :href="route('offers.index',$inquiry)">Vendor cost comparison</x-button><x-button variant="secondary" :href="route('inquiries.mail',$inquiry)">Email timeline</x-button></x-page-header>
@if($inquiry->is_demo)<div class="alert alert-warning mb-6">Fictional business record · no real client mail is sent through fixture transport.</div>@endif
<x-inquiry-navigation :inquiry="$inquiry" /><p class="stage-purpose mb-6"><strong>Your goal:</strong> Turn the vendor cost into a customer price, then review, approve and send the quotation.</p>
