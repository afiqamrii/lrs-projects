@php($fxInput=old('fx',$fx??[]))
<p class="hint mb-4">Enter conversions only for currencies used by the quotation. Rates remain unknown until explicitly entered and checked.</p><div class="space-y-4">
@foreach($fxCurrencies as $from)
@php($f=$fxInput[$from]??[])
<details data-fx-source="{{ $from }}" class="rounded-2xl border border-slate-200 p-4" @if($f) open @endif>
<summary class="cursor-pointer text-xs font-medium">{{ $from }} → <span data-fx-target-label>{{ $targetCurrency }}</span> · indicative conversion</summary>
<div class="mt-4 grid gap-4 sm:grid-cols-2">
<input type="hidden" name="fx[{{ $from }}][from]" value="{{ $from }}"><input type="hidden" data-fx-target name="fx[{{ $from }}][to]" value="{{ $targetCurrency }}">
<x-field :name="'fx['.$from.'][rate]'" label="Reviewed exchange rate" :value="$f['rate']??''" inputmode="decimal" />
<x-field :name="'fx['.$from.'][direction]'" label="Direction" type="select">@foreach(['multiply'=>'Target units per source unit · multiply','divide'=>'Source units per target unit · divide'] as $key=>$label)<option value="{{ $key }}" @selected(($f['direction']??'multiply')===$key)>{{ $label }}</option>@endforeach</x-field>
<x-field :name="'fx['.$from.'][date]'" label="Rate date" type="date" :value="$f['date']??''" />
<x-field :name="'fx['.$from.'][source]'" label="Rate source / evidence" :value="$f['source']??''" maxlength="1000" />
<label class="flex items-start gap-3 text-xs leading-6 sm:col-span-2"><input type="checkbox" name="fx[{{ $from }}][confirmed]" aria-invalid="{{ $errors->has('fx.'.$from.'.confirmed')?'true':'false' }}" aria-describedby="fx-{{ $from }}-review-error" value="1" class="mt-1 accent-accent" @checked($f['confirmed']??false)>I checked the rate, date and conversion direction.</label>
<p id="fx-{{ $from }}-review-error" class="field-error sm:col-span-2">@error('fx.'.$from.'.confirmed'){{ $message }}@enderror</p>
</div></details>
@endforeach
@if(!$fxCurrencies)<p class="hint">All current priced lines use the target currency. No exchange rate is required.</p>@endif
</div>
