@extends('adminlte::layouts.errors-master')

@section('title', 'Maintenance Mode')

@section('content')
    <div class="text-center">
        <div class="mb-3">
            <i class="bi bi-tools" style="font-size:4rem;color:var(--color-warning);opacity:.9" aria-hidden="true"></i>
        </div>
        <h1 class="h2 mb-2">{{ __('Scheduled maintenance') }}</h1>
        <p class="mb-4 mx-auto" style="max-width:34rem;color:var(--bs-secondary-color)">
            {{ __('The platform is offline for a short maintenance window. Everything will be back shortly — no action is needed on your side.') }}
        </p>
        <div class="d-flex gap-2 flex-wrap justify-content-center">
            <button type="button" data-reload class="btn btn-primary">
                <i class="bi bi-arrow-clockwise me-1" aria-hidden="true"></i>
                {{ __('Try again') }}
            </button>
        </div>
    </div>
@endsection

@push('js')
<script>
    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('[data-reload]').forEach(function (btn) {
            btn.addEventListener('click', function () { window.location.reload(); });
        });
    });
</script>
@endpush
