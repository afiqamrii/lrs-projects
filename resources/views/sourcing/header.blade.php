<a class="text-link mb-6 inline-flex items-center gap-2 text-xs" href="{{ route('inquiries.show',$inquiry) }}"><x-icon name="arrow-left" />{{ $inquiry->reference }} · Inquiry</a>
<x-page-header :title="$heading" eyebrow="Vendor sourcing" :description="$description" />
<x-inquiry-navigation :inquiry="$inquiry" />
<ol class="review-steps mb-6" aria-label="Sourcing sequence"><li><span>1</span>Confirm inquiry</li><li><span>2</span>Select vendors</li><li><span>3</span>Prepare requests</li><li><span>4</span>Review & approve</li></ol>
