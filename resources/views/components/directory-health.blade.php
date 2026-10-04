@props(['total','active','assigned'])
@php
    $segments = [
        ['Contact assigned', $assigned, '#0071e3', ['status' => 'active', 'contact' => 'ready']],
        ['Active, needs contact', $active - $assigned, '#c48a24', ['status' => 'active', 'contact' => 'missing']],
        ['Inactive', $total - $active, '#98989f', ['status' => 'inactive']],
    ];
@endphp
<figure aria-labelledby="directory-health-title">
<figcaption id="directory-health-title" class="text-sm font-semibold">Directory health</figcaption>
<p class="mt-4 flex items-baseline gap-2"><span class="text-[44px] font-semibold leading-none tracking-tight">{{ $assigned }}</span><span class="text-sm text-slate-500">of {{ $total }} vendors</span></p>
<p class="mt-2 text-xs leading-5 text-slate-600">Active with a primary quotation contact</p>
<div class="mt-5 flex h-3 overflow-hidden rounded-full bg-slate-200" aria-hidden="true">
@foreach($segments as [$label,$count,$color,$filters])@if($total && $count)<span style="width:{{ round($count / $total * 100,4) }}%;background:{{ $color }}"></span>@endif @endforeach
</div>
<ul class="mt-4 space-y-2.5">
@foreach($segments as [$label,$count,$color,$filters])<li><a href="{{ route('vendors.index',$filters) }}" class="flex items-center justify-between gap-3 rounded-md text-xs text-slate-600 hover:text-accent"><span class="flex items-center gap-2"><span class="h-2 w-2 rounded-full" style="background:{{ $color }}" aria-hidden="true"></span>{{ $label }}</span><span class="font-semibold tabular-nums text-ink">{{ $count }}</span></a></li>@endforeach
</ul>
@if(! $total)<p class="mt-4 text-xs leading-5 text-slate-500">Add a vendor to see your directory’s health.</p>@endif
</figure>
