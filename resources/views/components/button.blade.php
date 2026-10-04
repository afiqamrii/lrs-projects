@props(['href' => null,'variant' => 'primary'])
@if($href)<a href="{{ $href }}" {{ $attributes->class(['btn','btn-'.$variant]) }}>{{ $slot }}</a>@else<button {{ $attributes->class(['btn','btn-'.$variant])->merge(['type' => 'submit']) }}>{{ $slot }}</button>@endif
