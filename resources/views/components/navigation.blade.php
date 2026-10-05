<div class="flex h-full flex-col px-4 py-7">
<div class="px-2"><x-brand /></div>
<p class="mt-8 mb-3 px-3 text-xs font-medium text-slate-500">Daily work</p>
<nav class="space-y-1.5" aria-label="Primary navigation">
@foreach([['overview','squares-four','Home'],['inquiries.index','clipboard-text','Inquiries'],['mail.index','envelope-simple','Email'],['followups.index','clock','Tasks & follow-ups']] as [$route,$icon,$label])
@php($active = request()->routeIs(str_ends_with($route,'.index') ? str_replace('.index','.*',$route) : $route) || ($route==='inquiries.index' && request()->routeIs('offers.*','rfqs.*','quotations.*','lifecycle.*')))
<a href="{{ route($route) }}" class="nav-link {{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
@endforeach
<details class="nav-group pt-3" @if(request()->routeIs('reports.*','clients.*','vendors.*')) open @endif><summary>Contacts & reports</summary><div class="mt-2 space-y-1">
@foreach([['clients.index','users-three','Customers'],['vendors.index','buildings','Vendors'],['reports.index','file-text','Reports']] as [$route,$icon,$label])
@php($active=request()->routeIs(str_replace('.index','.*',$route)))
<a href="{{ route($route) }}" class="nav-link {{ $active?'active':'' }}" @if($active) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
@endforeach
</div></details>
@can('manage-company')
<details class="nav-group pt-3" @if(request()->routeIs('operations.*','staff.*','settings*')) open @endif><summary>Administration</summary><div class="mt-2 space-y-1">
@foreach([['operations.health','clock','System health','operations.*'],['staff.index','users-three','Staff & access','staff.*'],['settings','gear-six','Company settings','settings'],['settings.mailbox','envelope-simple','Email connections','settings.mailbox*'],['settings.followups','clock','Follow-up policies','settings.followups*'],['settings.handoff','clipboard-text','Handoff requirements','settings.handoff*']] as [$route,$icon,$label,$pattern])
@php($active=request()->routeIs($pattern) || ($route==='settings' && request()->routeIs('settings.ai*')))
<a href="{{ route($route) }}" class="nav-link {{ $active?'active':'' }}" @if($active) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
@endforeach
</div></details>
@endcan
</nav>
<div class="mt-auto pt-7">
<a href="{{ route('profile') }}" class="nav-link {{ request()->routeIs('profile*') ? 'active' : '' }}"><x-icon name="user-circle" />My profile</a>
<form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link w-full" type="submit"><x-icon name="sign-out" />Sign out</button></form>
<div class="mt-5 border-t border-slate-200 pt-4 px-3 text-[11px] leading-5 text-slate-500">LRS · Your team's shipping workspace</div>
</div></div>
