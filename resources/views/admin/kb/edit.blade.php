@extends('adminlte::page')

@section('title', 'Edit: ' . $article->title)

@section('content_header')
    <x-ui.page-header title="Edit Article" subtitle="Update knowledge article details" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Knowledge Base', 'url' => route('admin.kb.index')],
        ['label' => $article->title, 'url' => route('admin.kb.show', $article)],
        ['label' => 'Edit', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card
        icon="bi bi-book"
        title="Edit: {{ $article->title }}"
        :action="route('admin.kb.update', $article)"
        submit-label="Save Changes"
        :cancel-url="route('admin.kb.show', $article)"
    >
        @method('PUT')

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="category" label="Category" required>
                    <option value="">Select category...</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat['id'] }}" @selected(old('category', $article->category) === $cat['id'])>{{ $cat['name'] }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status" required>
                    @foreach (['draft' => 'Draft', 'published' => 'Published'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', $article->status) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <x-adminlte-input name="title" label="Title" placeholder="Article title"
                          value="{{ old('title', $article->title) }}" required />

        <x-adminlte-input name="slug" label="Slug (optional)" placeholder="auto-generated-from-title"
                          value="{{ old('slug', $article->slug) }}" />

        <x-adminlte-textarea name="content" label="Content" rows="12"
                             placeholder="Write the article content here..."
                             required>{{ old('content', $article->content) }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
