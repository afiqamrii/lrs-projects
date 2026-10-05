<div class="flex h-full flex-col px-4 py-7">
<div class="px-2"><x-brand /></div>
<div class="mt-8 mb-3 px-3 text-xs font-medium text-slate-500">Workspace</div>
<nav class="space-y-1.5" aria-label="Primary navigation">
@foreach([['overview','squares-four','Overview'],['inquiries.index','clipboard-text','Inquiries'],['mail.index','file-text','Email workspace'],['followups.index','clipboard-text','Attention queue'],['reports.index','file-text','Operational reports'],['clients.index','users-three','Clients'],['vendors.index','buildings','Vendor directory']] as [$route,$icon,$label])
@php($active = request()->routeIs(str_ends_with($route,'.index') ? str_replace('.index','.*',$route) : $route) || ($route==='inquiries.index' && request()->routeIs('offers.*','rfqs.*','quotations.*','lifecycle.*')))
<a href="{{ route($route) }}" class="nav-link {{ $active ? 'active' : '' }}" @if($active) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
@endforeach
@can('manage-company')
<p class="pt-7 pb-2 px-3 text-xs font-medium text-slate-500">Administration</p>
<a class="nav-link {{ request()->routeIs('operations.*')?'active':'' }}" href="{{ route('operations.health') }}" @if(request()->routeIs('operations.*')) aria-current="page" @endif><x-icon name="clock" />Operations health</a>
<a class="nav-link {{ request()->routeIs('staff.*') ? 'active' : '' }}" href="{{ route('staff.index') }}" @if(request()->routeIs('staff.*')) aria-current="page" @endif><x-icon name="users-three" />Staff & access</a>
<a class="nav-link {{ (request()->routeIs('settings*') && !request()->routeIs('settings.mailbox*') && !request()->routeIs('settings.followups*') && !request()->routeIs('settings.handoff*')) ? 'active' : '' }}" href="{{ route('settings') }}" @if((request()->routeIs('settings*') && !request()->routeIs('settings.mailbox*') && !request()->routeIs('settings.followups*') && !request()->routeIs('settings.handoff*'))) aria-current="page" @endif><x-icon name="gear-six" />Company settings</a>
<a class="nav-link {{ request()->routeIs('settings.mailbox*')?'active':'' }}" href="{{ route('settings.mailbox') }}" @if(request()->routeIs('settings.mailbox*')) aria-current="page" @endif><x-icon name="gear-six" />Email connections</a>
<a class="nav-link {{ request()->routeIs('settings.followups*')?'active':'' }}" href="{{ route('settings.followups') }}" @if(request()->routeIs('settings.followups*')) aria-current="page" @endif><x-icon name="gear-six" />Follow-up policies</a>
<a class="nav-link {{ request()->routeIs('settings.handoff*')?'active':'' }}" href="{{ route('settings.handoff') }}" @if(request()->routeIs('settings.handoff*')) aria-current="page" @endif><x-icon name="gear-six" />Handoff requirements</a>
@endcan
</nav>
<div class="mt-auto pt-7">
<div class="mb-4 flex items-start gap-2 px-3 text-xs leading-6 text-slate-500"><x-icon name="lock-key" class="mt-1 !h-4 !w-4" /><p>Private staff workspace<br>Shared by your company</p></div>
<a href="{{ route('profile') }}" class="nav-link {{ request()->routeIs('profile*') ? 'active' : '' }}"><x-icon name="user-circle" />My profile</a>
<form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link w-full" type="submit"><x-icon name="sign-out" />Sign out</button></form>
<div class="mt-5 border-t border-slate-200 pt-4 px-3 text-[11px] text-slate-500">LRS · Staff workspace</div>
</div></div>
