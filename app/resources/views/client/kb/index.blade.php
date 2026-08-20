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
    {{-- Search --}}
    <x-adminlte-card icon="bi bi-search" title="Search">
        <form method="GET" action="{{ route('client.kb.index') }}" class="d-flex gap-2">
            <input type="text" name="search" class="form-control" placeholder="Search articles..." value="{{ $search }}">
            <button class="btn btn-primary"><i class="bi bi-search"></i></button>
        </form>
    </x-adminlte-card>

    {{-- Categories --}}
    @if ($categories->isNotEmpty())
        <div class="mb-3">
            @foreach ($categories as $cat => $count)
                <span class="badge bg-info me-1 mb-1 fs-6">{{ $cat }} ({{ $count }})</span>
            @endforeach
        </div>
    @endif

    {{-- Articles --}}
    <x-adminlte-card icon="bi bi-journal-text" title="Articles">
        <table class="table table-hover align-middle mb-0">
            <thead>
                <tr><th>Title</th><th>Category</th><th>Views</th><th></th></tr>
            </thead>
            <tbody>
                @forelse ($articles as $article)
                    <tr>
                        <td><strong>{{ $article->title }}</strong></td>
                        <td class="text-muted">{{ $article->category }}</td>
                        <td class="text-muted">{{ $article->views }}</td>
                        <td class="text-end">
                            <a href="{{ route('client.kb.show', $article->slug) }}" class="btn btn-sm btn-outline-primary">Read</a>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="4" class="text-center text-muted py-4">No articles found.</td></tr>
                @endforelse
            </tbody>
        </table>
        {{ $articles->links() }}
    </x-adminlte-card>
@stop
