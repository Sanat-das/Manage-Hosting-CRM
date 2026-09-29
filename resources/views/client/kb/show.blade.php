@extends('adminlte::page')

@section('title', $article->title)

@section('content_header')
    <x-ui.page-header :title="$article->title" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Knowledge Base', 'url' => route('client.kb.index')],
        ['label' => Str::limit($article->title, 40), 'active' => true],
    ]" />
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
