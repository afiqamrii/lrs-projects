@props(['name','label','type'=>'text','value'=>'','hint'=>null,'required'=>false,'review'=>null,'id'=>null])
@php
$fieldKey=preg_replace('/\[([^\]]+)\]/', '.$1',$name);
$fieldKey=preg_replace('/\[\]$/','',$fieldKey);
$fieldId=$id??rtrim(str_replace(['[',']','.'],['-','','-'],$name),'-');
$hasFieldErrors=$errors->has($fieldKey) || ($type==='file' && $errors->has($fieldKey.'.*'));
@endphp
<div><label class="label" for="{{ $fieldId }}">{{ $label }}@if($required)<span class="text-accent" aria-hidden="true"> *</span>@endif</label>
@if($type==='textarea')<textarea id="{{ $fieldId }}" name="{{ $name }}" {{ $attributes->class(['input'])->merge(['rows'=>3]) }} @required($required) @if($hasFieldErrors) aria-invalid="true" @endif aria-describedby="{{ $fieldId }}-help">{{ old($fieldKey,$value) }}</textarea>
@elseif($type==='select')<select id="{{ $fieldId }}" name="{{ $name }}" {{ $attributes->class(['input']) }} @required($required) @if($hasFieldErrors) aria-invalid="true" @endif aria-describedby="{{ $fieldId }}-help">{{ $slot }}</select>
@else<input id="{{ $fieldId }}" name="{{ $name }}" type="{{ $type }}" value="{{ in_array($type,['password','file'])?'':old($fieldKey,$value) }}" {{ $attributes->class(['input']) }} @required($required) @if($hasFieldErrors) aria-invalid="true" @endif aria-describedby="{{ $fieldId }}-help">
@endif
<div id="{{ $fieldId }}-help">@if($hint)<p class="hint">{{ $hint }}</p>@endif @if($review)<p class="mt-2 text-xs leading-5 text-[#855600]">{{ $review }}</p>@endif @error($fieldKey)<p class="field-error">{{ $message }}</p>@enderror @if($type==='file')@foreach($errors->get($fieldKey.'.*') as $messages)@foreach($messages as $message)<p class="field-error">{{ $message }}</p>@endforeach @endforeach @endif</div></div>
