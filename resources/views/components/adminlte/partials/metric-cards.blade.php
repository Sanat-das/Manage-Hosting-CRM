@props(['items' => [], 'cols' => 'col-lg-3 col-6'])

<div class="row">
    @foreach ($items as $item)
        <div class="{{ $cols }} mb-3">
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
