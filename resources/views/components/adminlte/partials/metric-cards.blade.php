@props(['items' => [], 'cols' => 'col-lg-3 col-6'])

<div class="row mh-metric-row">
    @foreach ($items as $item)
        <div class="{{ $cols }}">
            <x-adminlte-small-box
                :title="$item['title'] ?? ''"
                :text="$item['text'] ?? ''"
                :icon="$item['icon'] ?? 'bi bi-circle'"
                :theme="$item['theme'] ?? 'info'"
                :url="$item['url'] ?? null"
                :url-text="$item['url-text'] ?? null"
            />
        </div>
    @endforeach
</div>
