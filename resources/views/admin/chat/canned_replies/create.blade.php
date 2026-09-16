@extends('adminlte::page')

@section('title', 'New Saved Reply')

@section('content_header')
    <x-ui.page-header title="New Saved Reply" subtitle="A snippet an operator can insert with a shortcut" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Live Chat', 'url' => route('admin.chat.index')],
        ['label' => 'Saved Replies', 'url' => route('admin.chat.canned-replies.index')],
        ['label' => 'New', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-lightning" title="Create Saved Reply"
        :action="route('admin.chat.canned-replies.store')" submit-label="Create Reply"
        :cancel-url="route('admin.chat.canned-replies.index')">
        @include('admin.chat.canned_replies._form', ['reply' => null])
    </x-adminlte.partials.form-card>
@stop
