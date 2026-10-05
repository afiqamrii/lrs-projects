@props(['title' => 'Workspace'])
<!doctype html><html lang="en"><head><meta charset="utf-8"><link rel="icon" href="/favicon.svg" type="image/svg+xml"><meta name="viewport" content="width=device-width,initial-scale=1"><meta name="csrf-token" content="{{ csrf_token() }}"><title>{{ $title }} · LRS</title>@vite(['resources/css/app.css','resources/js/app.js'])</head>
<body><a href="#main-content" class="fixed top-2 left-2 z-50 -translate-y-20 rounded-full bg-accent p-3 text-white focus:translate-y-0">Skip to content</a>
<aside class="sidebar z-20"><x-navigation /></aside>
<dialog id="mobile-nav" aria-label="Navigation"><button type="button" data-close-dialog class="absolute top-6 right-4 rounded-full p-2 text-slate-500 hover:bg-white hover:text-ink" aria-label="Close navigation"><x-icon name="x" /></button><x-navigation /></dialog>
<div class="page-shell min-h-screen min-w-0">
<header class="workspace-bar flex min-h-[72px] items-center justify-between gap-4 border-b border-slate-200/70 px-5 sm:px-9">
<div class="flex min-w-0 items-center gap-3"><button hidden type="button" data-open-dialog="mobile-nav" class="rounded-full p-2 text-accent hover:bg-slate-100 lg:!hidden" aria-label="Open navigation"><x-icon name="list" /></button><span class="hidden max-w-40 truncate text-[13px] text-slate-500 sm:block">{{ $company->display_name }}</span><x-icon name="caret-right" class="hidden !h-3 !w-3 text-slate-400 sm:inline-flex" /><span class="truncate text-[13px] font-medium">{{ $title }}</span></div>
<div class="flex shrink-0 items-center gap-2 sm:gap-4"><button hidden type="button" data-open-dialog="workflow-help" class="btn btn-secondary btn-small">How it works</button><a href="{{ route('profile') }}" class="flex shrink-0 items-center gap-3 rounded-xl py-2" aria-label="My profile: {{ auth()->user()->name }}"><span class="hidden text-right sm:block"><span class="block max-w-40 truncate text-xs font-medium">{{ auth()->user()->name }}</span><span class="block text-[11px] capitalize text-slate-500">{{ auth()->user()->role }}</span></span><span class="avatar !h-9 !w-9 !rounded-full">{{ mb_strtoupper(mb_substr(auth()->user()->name,0,2)) }}</span></a></div>
</header>
<main id="main-content" tabindex="-1" class="mx-auto max-w-[1480px] p-5 pb-12 sm:p-9">
@if($company->workspace_data_mode==='samples')<p class="mb-5 flex flex-wrap items-center gap-2 text-xs text-slate-500"><span class="badge tint-blue">Business preview</span> Fictional companies, shipments and prices. <a class="text-link ml-auto" href="{{ route('inquiries.index',['data'=>'real']) }}">View real records</a></p>@endif
@if($company->outbound_paused || config('operations.restore_lockdown'))<div class="alert alert-warning mb-6"><x-icon name="warning-circle" /><p><strong>Outgoing business mail paused.</strong> Incoming capture and manual review remain available. Earlier queued requests require explicit recovery and reminder plans require a new activation after resumption.@can('manage-company') <a class="text-link" href="{{ route('operations.health') }}">Review controls →</a>@endcan</p></div>@endif
<x-flash />{{ $slot }}
<footer class="mt-10 flex flex-wrap justify-between gap-3 border-t border-slate-200 pt-5 text-[11px] text-slate-500"><span>LRS · Logistics operations, thoughtfully organized</span><span>Times displayed in {{ $company->timezone }}</span></footer>
</main></div>
<x-dialog /><x-workflow-help />
</body></html>
