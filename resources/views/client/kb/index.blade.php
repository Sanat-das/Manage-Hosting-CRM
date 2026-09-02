@extends('adminlte::page')

@section('title', 'Knowledge Base')

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Knowledge Base</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.dashboard') }}">Dashboard</a></li>
                <li class="breadcrumb-item active">Knowledge Base</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    {{-- Categories --}}
    @if ($categories->isNotEmpty())
        <div class="mb-3">
            @foreach ($categories as $cat => $count)
                <span class="badge text-bg-info me-1 mb-1 fs-6">{{ $cat }} ({{ $count }})</span>
            @endforeach
        </div>
    @endif

    {{-- Articles --}}
    <x-adminlte.partials.datatable
        icon="bi bi-journal-text"
        title="Articles"
        :search-value="$search"
        search-placeholder="Search articles..."
        :columns="[
            ['label' => 'Title', 'sort' => 'title'],
            ['label' => 'Category', 'sort' => 'category'],
            ['label' => 'Views', 'sort' => 'views'],
            ['label' => 'Actions', 'class' => 'text-end'],
        ]"
        :pagination="$articles"
    >
        @forelse ($articles as $article)
            <tr>
                <td><strong>{{ $article->title }}</strong></td>
                <td class="text-muted">{{ $article->category }}</td>
                <td class="text-muted">{{ $article->views }}</td>
                <td class="text-end">
                    <div class="table-actions">
                        <a href="{{ route('client.kb.show', $article->slug) }}" class="btn btn-sm btn-outline-primary btn-icon" title="Read" aria-label="Read"><i class="bi bi-book"></i></a>
                    </div>
                </td>
            </tr>
        @empty
            <tr><td colspan="4" class="text-center text-muted py-4">No articles found.</td></tr>
        @endforelse
    </x-adminlte.partials.datatable>
@stop
