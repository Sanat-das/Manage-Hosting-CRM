<div class="mb-3 {{ $fgroupClass }}">
    @isset($label)
        <label for="{{ $id }}" class="form-label">{{ $label }}@if($attributes->has('required')) <span class="mh-required" aria-hidden="true">*</span><span class="visually-hidden"> (required)</span>@endif</label>
    @endisset

    <textarea
        name="{{ $name }}"
        id="{{ $id }}"
        {{ $attributes->merge(['class' => 'form-control'.($hasError() ? ' is-invalid' : '')]) }}>{{ old($dotName(), $slot ?? '') }}</textarea>

    @if ($hasError())
        <div class="invalid-feedback d-block">{{ $errorMessage() }}</div>
    @endif
</div>
