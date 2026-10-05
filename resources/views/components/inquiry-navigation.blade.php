@props(['inquiry'])
@php
$current = match (true) {
    request()->routeIs('inquiries.sourcing*', 'rfqs.*') => 2,
    request()->routeIs('offers.*') => 3,
    request()->routeIs('quotations.*') => 4,
    request()->routeIs('lifecycle.*') => 5,
    default => 1,
};
$onSummary = request()->routeIs('inquiries.show') && request('section', 'overview')==='overview';
if ($onSummary && isset($journey)) { $current = $journey['stage']; }
$support = request()->routeIs('inquiries.extraction*', 'inquiries.ai.*', 'inquiries.mail') || in_array(request('section'), ['documents', 'activity'], true);
$steps = [
    [1, 'Shipment details', route('inquiries.show', ['inquiry'=>$inquiry, 'section'=>'shipment'])],
    [2, 'Ask vendors', route('inquiries.sourcing', $inquiry)],
    [3, 'Compare prices', route('offers.index', $inquiry)],
    [4, 'Customer quote', route('quotations.index', $inquiry)],
    [5, 'Confirm & book', route('lifecycle.index', $inquiry)],
];
@endphp
<div class="mb-6" data-inquiry-navigation>
    <nav class="journey-navigation" aria-label="Shipment workflow">
        @foreach($steps as [$number,$label,$url])
        <a href="{{ $url }}" class="journey-step {{ !$support && $current===$number?'active':'' }}" @if(!$support && $current===$number) aria-current="{{ $onSummary?'step':'page' }}" @endif><span class="journey-number" aria-hidden="true">{{ $number }}</span><span>{{ $label }}</span></a>
        @endforeach
    </nav>
    <div class="mt-3 flex flex-wrap items-start justify-between gap-3 text-xs">
        <a class="text-link py-2" href="{{ route('inquiries.show',$inquiry) }}">Inquiry summary</a>
        <details class="support-navigation" @if($support) open @endif>
            <summary>Files, email & history</summary>
            <nav aria-label="Supporting inquiry sections" class="mt-3 flex flex-wrap gap-2">
                @foreach([['Documents',route('inquiries.show',['inquiry'=>$inquiry,'section'=>'documents'])],['Read document details',route('inquiries.extraction',$inquiry)],['Email timeline',route('inquiries.mail',$inquiry)],['Activity',route('inquiries.show',['inquiry'=>$inquiry,'section'=>'activity'])]] as [$label,$url])
                <a class="workspace-tab" href="{{ $url }}">{{ $label }}</a>
                @endforeach
            </nav>
        </details>
    </div>
</div>
