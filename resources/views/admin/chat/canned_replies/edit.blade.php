@extends('adminlte::page')

@section('title', 'Edit Saved Reply')

@section('content_header')
    <x-ui.page-header title="Edit Saved Reply" :subtitle="$reply->title" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Live Chat', 'url' => route('admin.chat.index')],
        ['label' => 'Saved Replies', 'url' => route('admin.chat.canned-replies.index')],
        ['label' => 'Edit', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    <x-adminlte.partials.form-card icon="bi bi-lightning" title="Edit Saved Reply"
        :action="route('admin.chat.canned-replies.update', $reply)" method="PUT" submit-label="Save Reply"
        :cancel-url="route('admin.chat.canned-replies.index')">
        @include('admin.chat.canned_replies._form')

        <p class="text-muted small mb-0">
            Inserted {{ $reply->uses }} {{ Str::plural('time', $reply->uses) }}.
            @if ($reply->isShared() && $reply->author)
                Added by {{ $reply->author->full_name }}.
            @endif
        </p>
    </x-adminlte.partials.form-card>
@stop
