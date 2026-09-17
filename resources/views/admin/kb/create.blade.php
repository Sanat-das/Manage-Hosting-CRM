@extends('adminlte::page')

@section('title', 'Add Article')

@section('content_header')
    <x-ui.page-header title="Add Article" subtitle="Create a new knowledge base article" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Knowledge Base', 'url' => route('admin.kb.index')],
        ['label' => 'Add Article', 'active' => true],
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
        title="New Knowledge Base Article"
        :action="route('admin.kb.store')"
        submit-label="Publish Article"
        :cancel-url="route('admin.kb.index')"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="category" label="Category" required>
                    <option value="">Select category...</option>
                    @foreach ($categories as $cat)
                        <option value="{{ $cat['id'] }}" @selected(old('category') === $cat['id'])>{{ $cat['name'] }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status" required>
                    @foreach (['draft' => 'Draft', 'published' => 'Published'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('status', 'draft') === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
        </div>

        <x-adminlte-input name="title" label="Title" placeholder="Article title"
                          value="{{ old('title') }}" required />

        <x-adminlte-input name="slug" label="Slug (optional)" placeholder="auto-generated-from-title"
                          value="{{ old('slug') }}" />

        <x-adminlte-textarea name="content" label="Content" rows="12"
                             placeholder="Write the article content here... Markdown or plain text."
                             required>{{ old('content') }}</x-adminlte-textarea>
    </x-adminlte.partials.form-card>
@stop
