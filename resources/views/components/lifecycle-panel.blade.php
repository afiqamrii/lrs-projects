@props(['inquiry'])
@php($l=\App\Support\LifecycleEligibility::statuses($inquiry))
<section class="panel panel-pad">
<div class="flex flex-wrap items-start justify-between gap-4"><div><p class="eyebrow">Decision & handoff</p><h2 class="section-title mt-3">{{ $l['next'] }}</h2><p class="hint">Commercial agreement, operations release and vendor booking have separate evidence.</p></div><x-button variant="secondary" :href="route('lifecycle.index',$inquiry)">Open lifecycle</x-button></div>
<div class="mt-6 grid gap-4 md:grid-cols-3">
@foreach([['Client decision',$l['q']?\App\Support\LifecycleEligibility::outcome($l['q']):'Awaiting quotation','file-text'],['Vendor confirmation',ucfirst($l['r']?->current()?->status??'Pending'),'check-circle'],['Handoff',$l['handoff'],'clipboard-text']] as [$label,$value,$icon])
<div class="rounded-2xl border border-slate-200 bg-slate-50/60 p-5"><x-icon :name="$icon" class="text-accent" /><p class="eyebrow mt-4">{{ $label }}</p><p class="mt-2 text-sm font-medium">{{ $value }}</p></div>@endforeach</div><p class="mt-5 text-xs text-slate-500">{{ $l['booking'] }}@if($l['h']) · handoff v{{ $l['h']->number }} / quote r{{ $l['h']->decision->revision->number }}@endif · Responsible agent: {{ $inquiry->owner?->name??'Unassigned' }}</p>
</section>