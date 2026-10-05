<a class="text-link mb-6 inline-flex items-center gap-2 text-xs" href="{{ route('inquiries.show',$inquiry) }}"><x-icon name="arrow-left" />{{ $inquiry->reference }} · Inquiry</a>
<x-page-header :title="$heading" eyebrow="Vendor sourcing" :description="$description" />
<x-inquiry-navigation :inquiry="$inquiry" />
<p class="stage-purpose mb-6"><strong>Your goal:</strong> Get a price for the same shipment from each selected vendor.</p>
