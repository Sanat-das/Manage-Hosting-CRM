@extends('adminlte::page')

@section('title', 'Live Chat')

@section('content_header')
    <x-ui.page-header title="Live Chat" subtitle="Channels, direct messages and the customer inbox" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Live Chat', 'active' => true],
    ]" />
@stop

{{-- `css` and `js` are the sections the AdminLTE master layout actually yields
     (master.blade.php:51 and :85). There is no `adminlte_js` hook — a section
     by that name compiles perfectly well and is then never rendered. --}}
@section('css')
    @vite('resources/css/chat.css')
@stop

@section('js')
    @vite('resources/js/chat.js')
@stop

@section('content')
    {{-- Three columns: room list, conversation, thread. A plain flex row rather
         than the AdminLTE DirectChat widget, which is a static demo with no
         real-time anything behind it. --}}
    <div class="card chat-shell" id="chat-app"
         data-conversation-id="{{ $selected?->id }}"
         data-user-id="{{ auth()->id() }}"
         data-heartbeat="{{ $heartbeatSeconds }}"
         data-can-operate="{{ $canOperate ? '1' : '0' }}">
        <div class="card-body p-0 d-flex chat-shell__body">

            {{-- Sidebar ------------------------------------------------- --}}
            <aside class="chat-sidebar border-end" aria-label="Conversations">
                <div class="p-3 border-bottom d-flex align-items-center gap-2">
                    <input type="search" class="form-control form-control-sm" id="chat-filter"
                           placeholder="Filter conversations" aria-label="Filter conversations">
                    @if ($canCreateChannel)
                        <button type="button" class="btn btn-sm btn-primary flex-shrink-0" id="chat-new-channel"
                                title="New channel" aria-label="New channel">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    @endif
                </div>

                <div class="chat-sidebar__scroll" id="chat-sidebar-list">
                    @foreach (['channels' => 'Channels', 'dms' => 'Direct messages', 'inbox' => 'Customer inbox'] as $group => $heading)
                        @php $items = $conversations[$group] ?? collect(); @endphp

                        @if ($group !== 'inbox' || $canOperate)
                            <div class="chat-sidebar__group" data-group="{{ $group }}">
                                <h2 class="chat-sidebar__heading">{{ $heading }}</h2>

                                @forelse ($items as $conversation)
                                    @php
                                        $count = $unread[$conversation->id] ?? 0;
                                        $isSelected = $selected !== null && $selected->id === $conversation->id;
                                    @endphp
                                    <a class="chat-sidebar__item {{ $isSelected ? 'is-active' : '' }}"
                                       href="{{ route('admin.chat.index', ['c' => $conversation->id]) }}"
                                       data-conversation-id="{{ $conversation->id }}"
                                       data-name="{{ Str::lower($conversation->displayName()) }}">
                                        <span class="chat-sidebar__icon" aria-hidden="true">
                                            @if ($conversation->type === \App\Models\ChatConversation::TYPE_CHANNEL)
                                                <i class="bi {{ $conversation->is_private ? 'bi-lock' : 'bi-hash' }}"></i>
                                            @elseif ($conversation->type === \App\Models\ChatConversation::TYPE_CUSTOMER_INBOX)
                                                <i class="bi bi-life-preserver"></i>
                                            @else
                                                <i class="bi bi-people"></i>
                                            @endif
                                        </span>
                                        <span class="chat-sidebar__name">{{ $conversation->displayName() }}</span>
                                        @if ($conversation->isCustomerInbox() && $conversation->status)
                                            <span class="badge chat-sidebar__status text-bg-{{ $conversation->status === 'waiting' ? 'warning' : ($conversation->status === 'active' ? 'success' : 'secondary') }}">
                                                {{ $conversation->status }}
                                            </span>
                                        @endif
                                        <span class="badge text-bg-danger chat-unread {{ $count > 0 ? '' : 'd-none' }}"
                                              data-unread-for="{{ $conversation->id }}">{{ $count }}</span>
                                    </a>
                                @empty
                                    <p class="chat-sidebar__empty">Nothing here yet.</p>
                                @endforelse
                            </div>
                        @endif
                    @endforeach

                    <div class="chat-sidebar__group">
                        <h2 class="chat-sidebar__heading">Online now</h2>
                        <ul class="chat-presence" id="chat-presence-list">
                            @forelse ($online as $person)
                                <li data-user-id="{{ $person['id'] }}">
                                    <span class="chat-presence__dot" aria-hidden="true"></span>{{ $person['name'] }}
                                </li>
                            @empty
                                <li class="chat-sidebar__empty" data-empty>Nobody else is here.</li>
                            @endforelse
                        </ul>
                    </div>
                </div>
            </aside>

            {{-- Conversation --------------------------------------------- --}}
            <section class="chat-main" aria-label="Conversation">
                @if ($selected === null)
                    <div class="chat-empty">
                        <i class="bi bi-chat-square-text" aria-hidden="true"></i>
                        <h2 class="h5 mt-3">No conversations yet</h2>
                        <p class="text-body-secondary mb-0">
                            @if ($canCreateChannel)
                                Create a channel to get started.
                            @else
                                You will see channels here once someone adds you to one.
                            @endif
                        </p>
                    </div>
                @else
                    <header class="chat-main__header border-bottom">
                        <div class="d-flex align-items-center gap-2 flex-wrap">
                            <h2 class="h6 mb-0 fw-semibold">{{ $selected->displayName() }}</h2>
                            @if ($selected->is_private)
                                <span class="badge text-bg-secondary">private</span>
                            @endif
                            @if ($selected->department)
                                <span class="badge text-bg-light text-body">{{ $selected->department }}</span>
                            @endif
                            @if ($selected->topic)
                                <span class="text-body-secondary small">{{ $selected->topic }}</span>
                            @endif
                        </div>

                        @if ($canOperate && $selected->isCustomerInbox())
                            <div class="d-flex gap-2" role="group" aria-label="Customer conversation actions">
                                <button type="button" class="btn btn-sm btn-outline-primary" data-inbox-action="assign">Take</button>
                                <button type="button" class="btn btn-sm btn-outline-secondary" data-inbox-action="convert">To ticket</button>
                                <button type="button" class="btn btn-sm btn-outline-danger" data-inbox-action="close">Close</button>
                            </div>
                        @endif
                    </header>

                    <div class="chat-messages" id="chat-messages" data-oldest="{{ $messages->first()['id'] ?? '' }}">
                        <div class="text-center py-2">
                            <button type="button" class="btn btn-sm btn-link" id="chat-load-older">Load older messages</button>
                        </div>
                        <ol class="chat-messages__list" id="chat-message-list">
                            {{-- Rendered server-side so the conversation is readable
                                 before any websocket connects, and still readable if
                                 none ever does. chat.js produces the same markup. --}}
                            @foreach ($messages as $message)
                                @include('admin.chat.partials.message', ['message' => $message])
                            @endforeach
                        </ol>
                        <p class="chat-empty__hint {{ $messages->isEmpty() ? '' : 'd-none' }}" id="chat-no-messages">
                            No messages yet. Say something.
                        </p>
                    </div>

                    <div class="chat-typing" id="chat-typing" aria-live="polite"></div>

                    <form class="chat-composer border-top" id="chat-composer" data-conversation-id="{{ $selected->id }}">
                        @csrf
                        <div class="chat-composer__chips" id="chat-chips" aria-live="polite"></div>

                        <label class="visually-hidden" for="chat-body">Message</label>
                        <textarea class="form-control" id="chat-body" name="body" rows="2"
                                  maxlength="{{ \App\Services\ChatService::MAX_BODY_LENGTH }}"
                                  placeholder="Message {{ $selected->displayName() }} &#8212; Enter to send, Shift+Enter for a new line"></textarea>

                        <div class="chat-composer__bar">
                            <div class="d-flex align-items-center gap-1">
                                <input type="file" id="chat-file" class="d-none">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-attach"
                                        title="Attach a file" aria-label="Attach a file">
                                    <i class="bi bi-paperclip"></i>
                                </button>
                                @if ($entityTypes->isNotEmpty())
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-attach-entity"
                                            title="Attach a record" aria-label="Attach a record">
                                        <i class="bi bi-link-45deg"></i>
                                    </button>
                                @endif
                                <span class="text-body-secondary small ms-1" id="chat-status" aria-live="polite"></span>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary">Send</button>
                        </div>

                        <ul class="chat-autocomplete d-none" id="chat-mentions" role="listbox"
                            data-people='@json($mentionables)'></ul>

                        <div class="chat-entity-picker d-none" id="chat-entity-picker">
                            <div class="d-flex gap-2 p-2 border-bottom">
                                <select class="form-select form-select-sm" id="chat-entity-type" aria-label="Record type">
                                    @foreach ($entityTypes as $type)
                                        <option value="{{ $type }}">{{ ucfirst($type) }}</option>
                                    @endforeach
                                </select>
                                <input type="search" class="form-control form-control-sm" id="chat-entity-query"
                                       placeholder="Search records" aria-label="Search records">
                            </div>
                            <ul class="chat-autocomplete__list" id="chat-entity-results" role="listbox"></ul>
                        </div>
                    </form>
                @endif
            </section>

            {{-- Thread slideover ----------------------------------------- --}}
            <aside class="chat-thread d-none" id="chat-thread" aria-label="Thread">
                <header class="chat-thread__header border-bottom">
                    <h2 class="h6 mb-0">Thread</h2>
                    <button type="button" class="btn-close" id="chat-thread-close" aria-label="Close thread"></button>
                </header>
                <div class="chat-thread__body" id="chat-thread-body"></div>
                <form class="chat-thread__composer border-top" id="chat-thread-composer">
                    @csrf
                    <label class="visually-hidden" for="chat-thread-body-input">Reply</label>
                    <textarea class="form-control" id="chat-thread-body-input" rows="2"
                              maxlength="{{ \App\Services\ChatService::MAX_BODY_LENGTH }}"
                              placeholder="Reply to thread"></textarea>
                    <button type="submit" class="btn btn-sm btn-primary mt-2">Reply</button>
                </form>
            </aside>
        </div>

        {{-- The emoji set travels as data, not markup: the reaction picker is
             built from it next to whichever message was clicked. --}}
        <script type="application/json" id="chat-emoji-set">@json($emojis)</script>
    </div>
@stop
