@if (\)
    <div class="grid-embed">
        {{ \ ?? '' }}
@endif
@if (! \)
<x-adminlte-card :title=\"\ ?? 'T'\" bodyClass=\"p-0\">
    @isset(\)
        <x-slot name=\"tools\">{{ \ }}</x-slot>
    @endisset
@endif

    @include('adminlte.partials.test-inner', get_defined_vars())

@if (! \)
</x-adminlte-card>
@else
    </div>
@endif
