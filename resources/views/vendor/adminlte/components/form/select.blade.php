<div class="mb-3 {{ $fgroupClass }}">
    @isset($label)
        <label for="{{ $id }}" class="form-label">{{ $label }}@if($attributes->has('required')) <span class="mh-required" aria-hidden="true">*</span><span class="visually-hidden"> (required)</span>@endif</label>
    @endisset

    <select
        name="{{ $name }}"
        id="{{ $id }}"
        {{ $attributes->merge(['class' => 'form-select'.($hasError() ? ' is-invalid' : '')]) }}>
        {{ $slot }}
    </select>

    @if ($hasError())
        <div class="invalid-feedback d-block">{{ $errorMessage() }}</div>
    @endif
</div>
