@props(['steps'])
<div class="form-guide" data-form-guide hidden>
    <p class="eyebrow mb-3">One step at a time · your entries stay here as you move between steps</p>
    <nav style="--form-step-count: {{ count($steps) }}" class="form-guide-tabs" aria-label="Form steps">
        @foreach($steps as $key=>$label)
        <button type="button" data-form-go="{{ $key }}"><span aria-hidden="true">{{ $loop->iteration }}</span>{{ $label }}</button>
        @endforeach
    </nav>
    <p class="mt-3 text-xs text-slate-500" data-form-position role="status" aria-live="polite"></p>
</div>
