<div class="flex h-full flex-col px-4 py-7">
<div class="px-2"><x-brand /></div>
<div class="mt-11 mb-3 px-3 text-xs font-medium text-slate-500">Workspace</div>
<nav class="space-y-1.5" aria-label="Primary navigation">
@foreach([['overview','squares-four','Overview'],['inquiries.index','clipboard-text','Inquiries'],['mail.index','file-text','Email workspace'],['clients.index','users-three','Clients'],['vendors.index','buildings','Vendor directory']] as [$route,$icon,$label])
<a href="{{ route($route) }}" class="nav-link {{ request()->routeIs(str_ends_with($route,'.index') ? str_replace('.index','.*',$route) : $route) ? 'active' : '' }}" @if(request()->routeIs(str_ends_with($route,'.index') ? str_replace('.index','.*',$route) : $route)) aria-current="page" @endif><x-icon :name="$icon" />{{ $label }}</a>
@endforeach
@can('manage-company')
<p class="pt-7 pb-2 px-3 text-xs font-medium text-slate-500">Administration</p>
<a class="nav-link {{ request()->routeIs('staff.*') ? 'active' : '' }}" href="{{ route('staff.index') }}" @if(request()->routeIs('staff.*')) aria-current="page" @endif><x-icon name="users-three" />Staff & access</a>
<a class="nav-link {{ (request()->routeIs('settings*') && !request()->routeIs('settings.mailbox*')) ? 'active' : '' }}" href="{{ route('settings') }}" @if((request()->routeIs('settings*') && !request()->routeIs('settings.mailbox*'))) aria-current="page" @endif><x-icon name="gear-six" />Company settings</a>
<a class="nav-link {{ request()->routeIs('settings.mailbox*')?'active':'' }}" href="{{ route('settings.mailbox') }}" @if(request()->routeIs('settings.mailbox*')) aria-current="page" @endif><x-icon name="gear-six" />Outlook connection</a>
@endcan
</nav>
<div class="mt-auto pt-12">
<div class="mb-5 rounded-2xl border border-white bg-white/60 p-4"><div class="mb-2 flex items-center gap-2 text-xs font-medium text-ink"><span class="h-1.5 w-1.5 rounded-full bg-[#34a853]"></span>Your operations workspace</div><p class="text-xs leading-relaxed text-slate-500">Shared clients, inquiries<br>and logistics partners.</p></div>
<a href="{{ route('profile') }}" class="nav-link {{ request()->routeIs('profile*') ? 'active' : '' }}"><x-icon name="user-circle" />My profile</a>
<form method="POST" action="{{ route('logout') }}">@csrf<button class="nav-link w-full" type="submit"><x-icon name="sign-out" />Sign out</button></form>
<div class="mt-5 border-t border-slate-200 pt-4 px-3 text-[11px] text-slate-500">LRS · Staff workspace</div>
</div></div>
