@extends('adminlte::page')

@section('title', $article->title)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $article->title }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.kb.index') }}">Knowledge Base</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $article->title }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <div class="row">
        <div class="col-lg-8">
            <x-adminlte-card>
                <div class="d-flex align-items-center gap-2 mb-3">
                    @php
                        $catLabel = collect($categories)->firstWhere('id', $article->category)['name'] ?? ucfirst(str_replace('_', ' ', $article->category));
                    @endphp
                    <span class="badge text-bg-secondary">{{ $catLabel }}</span>
                    <x-adminlte.partials.status-badge :status="$article->status" />
                    <small class="text-muted ms-auto">{{ $article->views }} views</small>
                </div>
                <div class="article-content">
                    {!! nl2br(e($article->content)) !!}
                </div>
                <hr>
                <div class="text-muted small">
                    Created: {{ $article->created_at?->format('M j, Y H:i') }}
                    &middot; Updated: {{ $article->updated_at?->format('M j, Y H:i') }}
                    &middot; Slug: <code>{{ $article->slug }}</code>
                </div>
            </x-adminlte-card>
        </div>

        <div class="col-lg-4">
            <x-adminlte-card title="Actions">
                @can('kb.edit')
                    <a href="{{ route('admin.kb.edit', $article) }}" class="btn btn-primary w-100 mb-2">
                        <i class="bi bi-pencil me-1"></i> Edit Article
                    </a>
                @endcan
                @can('kb.delete')
                    <button type="button" class="btn btn-outline-danger w-100"
                            data-bs-toggle="modal" data-bs-target="#delete-article-modal">
                        <i class="bi bi-trash me-1"></i> Delete Article
                    </button>
                @endcan
            </x-adminlte-card>

            @if ($popular->count())
                <x-adminlte-card title="Popular Articles" class="mt-3">
                    <div class="list-group list-group-flush">
                        @foreach ($popular as $pop)
                            <a href="{{ route('admin.kb.show', $pop) }}" class="list-group-item list-group-item-action {{ $pop->id === $article->id ? 'active' : '' }}">
                                <div class="d-flex justify-content-between">
                                    <span>{{ $pop->title }}</span>
                                    <small class="text-muted">{{ $pop->views }} views</small>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </x-adminlte-card>
            @endif
        </div>
    </div>

    @can('kb.delete')
        <x-adminlte.partials.confirm-modal
            id="delete-article-modal"
            title="Delete article"
            :message="'Delete ' . $article->title . '? This cannot be undone.'"
            :action="route('admin.kb.destroy', $article)"
            confirm-label="Delete article"
        />
    @endcan
@stop
