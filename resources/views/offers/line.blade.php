@php($base='lines['.$i.']')
<fieldset class="offer-charge rounded-2xl border border-slate-200 p-5" data-charge-row>
<legend class="px-2 text-xs font-semibold">Charge <span data-charge-number>{{ $i+1 }}</span> · <span data-charge-key class="font-normal text-slate-500">{{ $line['key']??'line-'.($i+1) }}</span></legend>
<input type="hidden" name="{{ $base }}[key]" value="{{ $line['key']??'line-'.($i+1) }}">
<div class="grid gap-4 sm:grid-cols-2">
<x-field :name="$base.'[description]'" label="Vendor's original charge description" :value="$line['description']??''" required maxlength="500" />
<x-field :name="$base.'[source_ref]'" label="Unique source line / page locator" :value="$line['source_ref']??''" maxlength="2000" hint="Distinct source line; retain the original wording below." />
@foreach(['category'=>\App\Support\OfferCosts::CATEGORIES,'service'=>\App\Support\OfferCosts::SERVICES,'state'=>\App\Support\OfferCosts::STATES,'basis'=>\App\Support\OfferCosts::BASES] as $key=>$options)
<x-field :name="$base.'['.$key.']'" :label="ucfirst($key)" type="select">@foreach($options as $value=>$label)<option value="{{ $value }}" @selected(($line[$key]??null)===$value)>{{ $label }}</option>@endforeach</x-field>
@endforeach
<x-field :name="$base.'[currency]'" label="Original line currency" type="select">@foreach(array_keys(config('offers.currency_precision')) as $c)<option @selected(($line['currency']??$company->currency)===$c)>{{ $c }}</option>@endforeach</x-field>
<x-field :name="$base.'[rate]'" label="Amount / rate per billing unit" :value="$line['rate']??''" inputmode="decimal" hint="Blank stays unknown. Zero needs quoted evidence." />
<x-field :name="$base.'[minimum_charge]'" label="Minimum monetary charge" :value="$line['minimum_charge']??''" inputmode="decimal" />
<x-field :name="$base.'[minimum_quantity]'" label="Minimum billable quantity" :value="$line['minimum_quantity']??''" inputmode="decimal" />
<x-field :name="$base.'[tax_treatment]'" label="Vendor-stated tax treatment" type="select">@foreach(['unknown'=>'Unknown · resolve','inclusive'=>'Included in the stated amount','exclusive'=>'Additional stated tax','not_applicable'=>'Explicitly not applicable'] as $key=>$label)<option value="{{ $key }}" @selected(($line['tax_treatment']??'unknown')===$key)>{{ $label }}</option>@endforeach</x-field>
<x-field :name="$base.'[tax_rate]'" label="Stated additional tax percentage" :value="$line['tax_rate']??''" inputmode="decimal" />
</div>
<details class="mt-5"><summary class="cursor-pointer text-xs font-medium">Unit definitions, included charges & full-cost arrangements</summary>
<div class="mt-4 grid gap-4 sm:grid-cols-2">
<x-field :name="$base.'[container_type]'" label="Exact container type, for container basis" :value="$line['container_type']??''" hint="20GP, 40GP, 40HC, 20RF, 40RF or Other" />
<x-field :name="$base.'[billing_increment]'" label="Vendor-stated billing increment" :value="$line['billing_increment']??''" inputmode="decimal" hint="Leave blank unless explicitly quoted. Rounds billable quantity upward." />
<x-field :name="$base.'[wm_kg]'" label="W/M · kg per vendor unit" :value="$line['wm_kg']??''" inputmode="decimal" />
<x-field :name="$base.'[wm_cbm]'" label="W/M · CBM per vendor unit" :value="$line['wm_cbm']??''" inputmode="decimal" />
<x-field :name="$base.'[custom_quantity]'" label="Explicit custom quantity" :value="$line['custom_quantity']??''" inputmode="decimal" />
<x-field :name="$base.'[unit_definition]'" label="Custom unit / quoted basis definition" :value="$line['unit_definition']??''" />
<x-field :name="$base.'[included_in]'" label="Included in priced charge key" :value="$line['included_in']??''" hint="For example line-1. Included charges add no second price." />
<x-field :name="$base.'[zero_evidence]'" label="Explicit zero-price evidence" :value="$line['zero_evidence']??''" />
<x-field :name="$base.'[arrangement_for]'" label="Unresolved charge key covered by arrangement" :value="$line['arrangement_for']??''" hint="A separately reviewed full price for the same required service." />
<x-field :name="$base.'[arrangement_evidence]'" label="Arrangement provider / quotation / full-cost evidence" :value="$line['arrangement_evidence']??''" />
</div></details>
<div class="mt-5"><x-field :name="$base.'[raw_text]'" label="Raw quoted wording / uncertainty" type="textarea" :value="$line['raw_text']??''" maxlength="2000" /></div>
<div class="mt-4 space-y-3">
<label class="flex items-start gap-3 text-xs leading-6"><input type="checkbox" name="{{ $base }}[optional]" value="1" @checked($line['optional']??false) class="mt-1 accent-accent">Optional service · excluded from required baseline total</label>
<label class="flex items-start gap-3 text-xs leading-6"><input type="checkbox" name="{{ $base }}[confirmed]" value="1" @checked(old('lines.'.$i.'.confirmed',false)) class="mt-1 accent-accent">I checked this line's description, scope, amount, basis, applicable confirmed quantity, minimums, tax and source evidence.</label>
</div>
<button type="button" class="text-link mt-4 text-xs" data-remove-charge>Remove this draft line</button>
</fieldset>
