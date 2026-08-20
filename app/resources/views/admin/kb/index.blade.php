@extends('adminlte::page')

@section('title', 'Knowledge Base')

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">Knowledge Base</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item active" aria-current="page">Knowledge Base</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif

    <x-adminlte.partials.metric-cards :items="[
        ['title' => $stats['total'], 'text' => 'Total Articles', 'icon' => 'bi bi-file-text', 'theme' => 'primary'],
        ['title' => $stats['published'], 'text' => 'Published', 'icon' => 'bi bi-check-circle', 'theme' => 'success'],
        ['title' => $stats['drafts'], 'text' => 'Drafts', 'icon' => 'bi bi-pencil', 'theme' => 'warning'],
        ['title' => $stats['categories'], 'text' => 'Categories', 'icon' => 'bi bi-tags', 'theme' => 'info'],
    ]" />

    <div class="row">
        <div class="col-lg-8">
            <x-adminlte.partials.datatable
                icon="bi bi-book"
                title="Articles"
                :search-value="$search"
                search-placeholder="Search articles..."
                :status-options="$statuses"
                :status-value="$status"
                :columns="[
                    ['label' => 'Title'],
                    ['label' => 'Category'],
                    ['label' => 'Status'],
                    ['label' => 'Views', 'class' => 'text-end'],
                    ['label' => 'Updated'],
                    ['label' => 'Actions', 'class' => 'text-end'],
                ]"
                :pagination="$articles"
            >
                <x-slot name="tools">
                    @can('kb.create')
                        <a href="{{ route('admin.kb.create') }}" class="btn btn-sm btn-primary">
                            <i class="bi bi-plus-lg me-1" aria-hidden="true"></i> Add Article
                        </a>
                    @endcan
                </x-slot>

                @forelse ($articles as $article)
                    <tr>
                        <td>
                            <a href="{{ route('admin.kb.show', $article) }}"><strong>{{ $article->title }}</strong></a>
                        </td>
                        <td>
                            @php
                                $catLabel = collect($categories)->firstWhere('id', $article->category)['name'] ?? ucfirst(str_replace('_', ' ', $article->category));
                            @endphp
                            <span class="badge bg-secondary">{{ $catLabel }}</span>
                        </td>
                        <td>
                            <x-adminlte.partials.status-badge :status="$article->status" />
                        </td>
                        <td class="text-end text-muted">{{ $article->views }}</td>
                        <td class="text-muted">{{ $article->updated_at?->format('M j, Y') }}</td>
                        <td class="text-end">
                            <a href="{{ route('admin.kb.show', $article) }}"
                               class="btn btn-sm btn-outline-secondary" title="View">
                                <i class="bi bi-eye"></i>
                            </a>
                            @can('kb.edit')
                                <a href="{{ route('admin.kb.edit', $article) }}"
                                   class="btn btn-sm btn-outline-secondary" title="Edit">
                                    <i class="bi bi-pencil"></i>
                                </a>
                            @endcan
                            @can('kb.delete')
                                <form method="POST" action="{{ route('admin.kb.destroy', $article) }}"
                                      onsubmit="return confirm('Delete article? This cannot be undone.');"
                                      class="d-inline">
                                    @csrf
                                    @method('DELETE')
                                    <button class="btn btn-sm btn-outline-danger" title="Delete">
                                        <i class="bi bi-trash"></i>
                                    </button>
                                </form>
                            @endcan
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="6" class="text-center text-muted py-4">
                            No articles found.
                        </td>
                    </tr>
                @endforelse
            </x-adminlte.partials.datatable>
        </div>

        <div class="col-lg-4">
            {{-- Categories sidebar --}}
            <x-adminlte-card title="Categories">
                <div class="list-group list-group-flush">
                    @foreach ($categories as $cat)
                        <a href="{{ route('admin.kb.index', array_filter(['category' => $cat['id'], 'search' => $search, 'status' => $status])) }}"
                           class="list-group-item list-group-item-action d-flex justify-content-between align-items-center {{ $category === $cat['id'] ? 'active' : '' }}">
                            {{ $cat['name'] }}
                            <span class="badge bg-pill {{ $category === $cat['id'] ? 'bg-white text-primary' : 'bg-primary' }}">{{ $cat['article_count'] }}</span>
                        </a>
                    @endforeach
                </div>
            </x-adminlte-card>

            {{-- Popular articles --}}
            @if ($popular->count())
                <x-adminlte-card title="Popular Articles" class="mt-3">
                    <div class="list-group list-group-flush">
                        @foreach ($popular as $article)
                            <a href="{{ route('admin.kb.show', $article) }}" class="list-group-item list-group-item-action">
                                <div class="d-flex justify-content-between">
                                    <span>{{ $article->title }}</span>
                                    <small class="text-muted">{{ $article->views }} views</small>
                                </div>
                            </a>
                        @endforeach
                    </div>
                </x-adminlte-card>
            @endif
        </div>
    </div>
@stop
