@props(['title','description','icon' => 'buildings'])
<div class="px-5 py-16 text-center"><span class="tint-blue mb-5 inline-flex rounded-[22px] p-5"><x-icon :name="$icon" class="!h-8 !w-8" /></span><h2 class="text-xl">{{ $title }}</h2><p class="mx-auto mt-3 max-w-md text-sm leading-6 text-slate-500">{{ $description }}</p><div class="mt-6">{{ $slot }}</div></div>
