@props(['active'])
<span class="badge {{ $active ? 'badge-active' : 'badge-inactive' }}"><span class="h-1.5 w-1.5 rounded-full bg-current" aria-hidden="true"></span>{{ $active ? 'Active' : 'Inactive' }}</span>
