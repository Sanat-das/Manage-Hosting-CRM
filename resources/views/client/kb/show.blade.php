@extends('adminlte::page')

@section('title', $article->title)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">{{ $article->title }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.kb.index') }}">Knowledge Base</a></li>
                <li class="breadcrumb-item active">{{ Str::limit($article->title, 40) }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <x-adminlte-card icon="bi bi-journal-text" title="{{ $article->title }}">
        <div class="d-flex justify-content-between text-muted small mb-3">
            <span>Category: <strong>{{ $article->category }}</strong></span>
            <span>{{ $article->views }} views · {{ $article->helpful }} found helpful</span>
        </div>
        <div style="white-space: pre-wrap;">{{ $article->content }}</div>
    </x-adminlte-card>
@stop
