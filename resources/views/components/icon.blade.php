@props(['name'])
@php($allowed = ['squares-four','buildings','users-three','gear-six','user-circle','globe','arrow-right','arrow-left','plus','magnifying-glass','warning-circle','check-circle','caret-right','list','x','sign-out','envelope-simple','pencil-simple','clock','lock-key','check','eye','eye-slash','clipboard-text','file-text'])
<span {{ $attributes->class(['icon']) }} aria-hidden="true">@if(in_array($name,$allowed,true)){!! file_get_contents(resource_path('icons/'.$name.'.svg')) !!}@endif</span>
