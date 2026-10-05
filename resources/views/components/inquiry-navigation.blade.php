@props(['inquiry'])
@php
$tabs = [
    ['overview', 'Overview', route('inquiries.show', $inquiry)],
    ['shipment', 'Shipment', route('inquiries.show', ['inquiry'=>$inquiry, 'section'=>'shipment'])],
    ['documents', 'Documents', route('inquiries.show', ['inquiry'=>$inquiry, 'section'=>'documents'])],
    ['extraction', 'Evidence review', route('inquiries.extraction', $inquiry)],
    ['sourcing', 'Vendor requests', route('inquiries.sourcing', $inquiry)],
    ['offers', 'Vendor offers', route('offers.index', $inquiry)],
    ['quotations', 'Client quotation', route('quotations.index', $inquiry)],
    ['mail', 'Email timeline', route('inquiries.mail', $inquiry)],
    ['lifecycle', 'Decision & handoff', route('lifecycle.index', $inquiry)],
    ['activity', 'Activity', route('inquiries.show', ['inquiry'=>$inquiry, 'section'=>'activity'])],
];
$current = match (true) {
    request()->routeIs('inquiries.extraction*', 'inquiries.ai.*') => 'extraction',
    request()->routeIs('inquiries.sourcing*', 'rfqs.*') => 'sourcing',
    request()->routeIs('offers.*') => 'offers',
    request()->routeIs('quotations.*') => 'quotations',
    request()->routeIs('inquiries.mail') => 'mail',
    request()->routeIs('lifecycle.*') => 'lifecycle',
    default => request('section', 'overview'),
};
@endphp
<nav class="inquiry-navigation mb-6" aria-label="Inquiry sections" data-inquiry-navigation>
@foreach($tabs as [$key,$label,$url])
<a href="{{ $url }}" class="workspace-tab {{ $current===$key?'active':'' }}" @if($current===$key) aria-current="page" @endif>{{ $label }}</a>
@endforeach
</nav>
