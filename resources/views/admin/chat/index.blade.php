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
         data-can-operate="{{ $canOperate ? '1' : '0' }}"
         {{-- Endpoints as data, not as string literals in the bundle: the app
              can be installed under a subdirectory, and route() is the only
              thing that knows. --}}
         data-canned-url="{{ route('admin.chat.canned-replies.pick') }}"
         data-canned-used-url="{{ route('admin.chat.canned-replies.used', ['cannedReply' => '__ID__']) }}"
         data-canned-manage-url="{{ route('admin.chat.canned-replies.index') }}"
         data-availability-url="{{ route('admin.chat.availability') }}">
        {{-- Reconnection banner — hidden until JS shows it when Echo is unavailable. --}}
        <div class="chat-reconnect-banner d-none" id="chat-reconnect-banner" data-reconnect-banner role="status" aria-live="polite">Realtime disconnected — polling</div>
        <div class="card-body p-0 d-flex chat-shell__body">

            {{-- Sidebar ------------------------------------------------- --}}
            <aside class="chat-sidebar border-end" aria-label="Conversations">
                <div class="p-3 border-bottom d-flex align-items-center gap-2">
                    <input type="search" class="form-control form-control-sm" id="chat-filter"
                           placeholder="Filter conversations" aria-label="Filter conversations">
                    <button type="button" class="btn btn-sm btn-outline-secondary flex-shrink-0" id="chat-search-open"
                            title="Search messages (/)" aria-label="Search messages">
                        <i class="bi bi-search"></i>
                    </button>
                    @if ($canCreateChannel)
                        <button type="button" class="btn btn-sm btn-primary flex-shrink-0" id="chat-new-channel"
                                title="New channel" aria-label="New channel">
                            <i class="bi bi-plus-lg"></i>
                        </button>
                    @endif
                </div>

                {{-- The operator's own state, OUTSIDE the scrolling list.
                     It used to sit at the bottom of that list, below every
                     conversation and the presence roster, which put the one
                     control that decides whether customers can reach you off
                     the bottom of the screen. It does not scroll away now.

                     Only for people who can actually take a customer chat
                     (chat.manage): for anyone else this would set a flag that
                     nothing reads, while appearing to promise otherwise. --}}
                @if ($canOperate)
                    <div class="chat-availability border-bottom px-3 py-2" id="chat-availability-group">
                        <label class="visually-hidden" for="chat-availability">My availability</label>
                        <select class="form-select form-select-sm" id="chat-availability">
                            @foreach ($availabilityOptions as $value => $label)
                                <option value="{{ $value }}" @selected($availability === $value)>{{ $label }}</option>
                            @endforeach
                        </select>
                        <span class="small text-body-secondary" id="chat-availability-status" aria-live="polite"></span>
                    </div>
                @endif

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
                                @php $state = $availabilityStates[$person['id']] ?? null; @endphp
                                <li data-user-id="{{ $person['id'] }}"
                                    data-state="{{ $state['state'] ?? 'available' }}">
                                    <span class="chat-presence__dot chat-presence__dot--{{ $state['state'] ?? 'available' }}"
                                          aria-hidden="true"></span>{{ $person['name'] }}
                                    {{-- Only the exceptions are labelled. A badge
                                         reading "Available" next to a green dot on
                                         every row is noise that hides the one row
                                         that says Away. --}}
                                    @if (($state['state'] ?? 'available') !== 'available')
                                        <span class="badge text-bg-light text-body chat-presence__state">{{ $state['label'] }}</span>
                                    @endif
                                    @if (! empty($state['note']))
                                        <span class="chat-presence__note">{{ $state['note'] }}</span>
                                    @endif
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

                        @if ($selected->type === \App\Models\ChatConversation::TYPE_CHANNEL && ! $selected->isArchived())
                            @can('archive', $selected)
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-archive"
                                        data-chat-archive>Archive</button>
                            @endcan
                        @endif

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
                        {{-- Loading skeletons — hidden once history has rendered. JS toggles them. --}}
                        <div class="chat-skeleton d-none" data-chat-skeleton aria-hidden="true">
                            <div class="chat-skeleton__line"></div>
                            <div class="chat-skeleton__line w-75"></div>
                            <div class="chat-skeleton__line w-50"></div>
                            <div class="chat-skeleton__line"></div>
                            <div class="chat-skeleton__line w-75"></div>
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

                    {{-- Archive is a freeze, not a deletion: the history above
                         stays readable, the composer below stops working. The
                         server refuses the post either way (the policy's
                         sendMessage() denies on an archived room); this is the
                         affordance that says so before you type. --}}
                    @php $isArchived = $selected->isArchived(); @endphp
                    @if ($isArchived)
                        <p class="alert alert-secondary rounded-0 border-0 border-top mb-0 py-2 px-3 small"
                           data-chat-archived role="status">
                            <i class="bi bi-archive me-1" aria-hidden="true"></i>This conversation is archived. You can read it, but not post to it.
                        </p>
                    @endif

                    <form class="chat-composer border-top" id="chat-composer" data-conversation-id="{{ $selected->id }}">
                        @csrf
                        <div class="chat-composer__chips" id="chat-chips" aria-live="polite"></div>

                        <label class="visually-hidden" for="chat-body">Message</label>
                        <textarea class="form-control" id="chat-body" name="body" rows="2" {{ $isArchived ? 'disabled' : '' }}
                                  maxlength="{{ \App\Services\ChatService::MAX_BODY_LENGTH }}"
                                  @if ($isArchived)
                                      placeholder="This conversation is archived"
                                  @else
                                      placeholder="Message {{ $selected->displayName() }} &#8212; Enter to send, Shift+Enter for a new line"
                                  @endif
                        ></textarea>

                        <div class="chat-composer__bar">
                            <div class="d-flex align-items-center gap-1">
                                <input type="file" id="chat-file" class="d-none">
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-attach"
                                        title="Attach a file" aria-label="Attach a file" {{ $isArchived ? 'disabled' : '' }}>
                                    <i class="bi bi-paperclip"></i>
                                </button>
                                @if ($entityTypes->isNotEmpty())
                                    <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-attach-entity"
                                            title="Attach a record" aria-label="Attach a record" {{ $isArchived ? 'disabled' : '' }}>
                                        <i class="bi bi-link-45deg"></i>
                                    </button>
                                @endif
                                <button type="button" class="btn btn-sm btn-outline-secondary" id="chat-canned-open"
                                        title="Insert a saved reply (type / in an empty box)"
                                        aria-label="Insert a saved reply" {{ $isArchived ? 'disabled' : '' }}>
                                    <i class="bi bi-lightning"></i>
                                </button>
                                <span class="text-body-secondary small ms-1" id="chat-status" aria-live="polite"></span>
                            </div>
                            <button type="submit" class="btn btn-sm btn-primary" {{ $isArchived ? 'disabled' : '' }}>Send</button>
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

                        {{-- Saved replies. Same shape as the entity picker above,
                             and deliberately not a <select>: the list is searched
                             server-side and each row shows the text as well as the
                             title, because a title alone is not enough to tell two
                             refund snippets apart. --}}
                        <div class="chat-canned-picker d-none" id="chat-canned-picker">
                            <div class="d-flex gap-2 p-2 border-bottom">
                                <input type="search" class="form-control form-control-sm" id="chat-canned-query"
                                       placeholder="Search saved replies" aria-label="Search saved replies">
                                @can('chat.manage')
                                    <a href="{{ route('admin.chat.canned-replies.index') }}"
                                       class="btn btn-sm btn-outline-secondary flex-shrink-0"
                                       title="Manage saved replies" aria-label="Manage saved replies">
                                        <i class="bi bi-gear"></i>
                                    </a>
                                @endcan
                            </div>
                            <ul class="chat-autocomplete__list" id="chat-canned-results" role="listbox"></ul>
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
                {{-- A thread reply is a post into the same conversation, so it
                     freezes with it. Without this the panel offers a composer
                     the server will refuse. --}}
                @php $threadFrozen = $selected !== null && $selected->isArchived(); @endphp
                <form class="chat-thread__composer border-top" id="chat-thread-composer">
                    @csrf
                    <label class="visually-hidden" for="chat-thread-body-input">Reply</label>
                    <textarea class="form-control" id="chat-thread-body-input" rows="2"
                              maxlength="{{ \App\Services\ChatService::MAX_BODY_LENGTH }}"
                              placeholder="Reply to thread" {{ $threadFrozen ? 'disabled' : '' }}></textarea>
                    <button type="submit" class="btn btn-sm btn-primary mt-2" {{ $threadFrozen ? 'disabled' : '' }}>Reply</button>
                </form>
            </aside>
        </div>

        {{-- Error toasts — hidden until a send fails. --}}
        <div class="chat-toast d-none" id="chat-toast" data-chat-toast role="alert" aria-live="assertive">
            <span class="chat-toast__message" data-chat-toast-message></span>
            <button type="button" class="btn btn-sm btn-outline-light ms-2 d-none" data-chat-retry>Retry</button>
            <button type="button" class="btn-close btn-close-white ms-2" data-chat-toast-close aria-label="Dismiss"></button>
        </div>

        {{-- Confirmation dialog for archive/delete — hidden until needed. --}}
        <div class="chat-confirm d-none" id="chat-confirm" data-chat-confirm role="dialog" aria-modal="true" aria-labelledby="chat-confirm-title">
            <div class="chat-confirm__backdrop" data-chat-confirm-cancel></div>
            <div class="chat-confirm__dialog card shadow">
                <div class="card-body">
                    <h2 class="h6 mb-2" id="chat-confirm-title" data-chat-confirm-title>Are you sure?</h2>
                    <p class="small text-body-secondary mb-3" data-chat-confirm-body></p>
                    <div class="d-flex justify-content-end gap-2">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-chat-confirm-cancel>Cancel</button>
                        <button type="button" class="btn btn-sm btn-danger" data-chat-confirm-ok>Confirm</button>
                    </div>
                </div>
            </div>
        </div>

        {{-- Message search — hidden until opened from the sidebar button or `/`.
             The conversation dropdown lists the rooms in the sidebar; archived
             rooms are not in it, and are still found by the unfiltered search
             (they stay readable, so they stay searchable). --}}
        <div class="chat-palette d-none" id="chat-search" role="dialog" aria-modal="true"
             aria-labelledby="chat-search-title">
            <div class="chat-palette__backdrop" data-chat-search-close></div>
            <div class="chat-palette__dialog card shadow">
                <div class="card-body">
                    <div class="d-flex align-items-center gap-2 mb-2">
                        <h2 class="h6 mb-0 flex-grow-1" id="chat-search-title">Search messages</h2>
                        <button type="button" class="btn-close" data-chat-search-close aria-label="Close search"></button>
                    </div>

                    <form id="chat-search-form" autocomplete="off">
                        <input type="search" class="form-control mb-2" id="chat-search-q" name="q"
                               placeholder="Search messages" aria-label="Search messages">
                        <div class="chat-palette__filters">
                            <select class="form-select form-select-sm" id="chat-search-channel"
                                    aria-label="Limit to one conversation">
                                <option value="">All conversations</option>
                                {{-- "All conversations" stays the default on purpose. Pre-selecting
                                     the room you happen to be standing in silently narrows every
                                     search to it, and a search that quietly answers a narrower
                                     question than the one asked reads as a broken search. --}}
                                @foreach ($conversations->flatten() as $conversation)
                                    <option value="{{ $conversation->id }}">{{ $conversation->displayName() }}</option>
                                @endforeach
                            </select>
                            <input type="date" class="form-control form-control-sm" id="chat-search-from"
                                   aria-label="Search from date">
                            <input type="date" class="form-control form-control-sm" id="chat-search-to"
                                   aria-label="Search to date">
                            <button type="submit" class="btn btn-sm btn-primary">Search</button>
                        </div>
                    </form>

                    <p class="small text-body-secondary mt-2 mb-1" id="chat-search-status" aria-live="polite"></p>
                    <ol class="chat-palette__results" id="chat-search-results"></ol>

                    <nav class="chat-palette__pager d-none" id="chat-search-pager" aria-label="Search result pages">
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-search-page="prev">Previous</button>
                        <span class="small text-body-secondary" id="chat-search-page-label"></span>
                        <button type="button" class="btn btn-sm btn-outline-secondary" data-search-page="next">Next</button>
                    </nav>
                </div>
            </div>
        </div>

        {{-- Ctrl/Cmd+K conversation switcher. Its list is built from the sidebar
             links already on the page rather than from a second copy of the
             conversation data — one source, so it cannot drift out of step with
             what the sidebar shows. --}}
        <div class="chat-palette d-none" id="chat-switcher" role="dialog" aria-modal="true"
             aria-labelledby="chat-switcher-title">
            <div class="chat-palette__backdrop" data-chat-switcher-close></div>
            <div class="chat-palette__dialog card shadow">
                <div class="card-body">
                    <h2 class="h6 mb-2" id="chat-switcher-title">Jump to a conversation</h2>
                    <input type="search" class="form-control" id="chat-switcher-input"
                           placeholder="Type to filter, Enter to open" aria-label="Jump to a conversation"
                           role="combobox" aria-expanded="true" aria-controls="chat-switcher-results">
                    <ul class="chat-palette__results" id="chat-switcher-results" role="listbox"></ul>
                </div>
            </div>
        </div>

        {{-- Keyboard shortcut legend, for discoverability and for screen readers. --}}
        <p class="chat-shortcut-hint small text-body-secondary" id="chat-shortcut-hint">
            <kbd>j</kbd>/<kbd>k</kbd> move between messages &middot;
            <kbd>r</kbd> reply in thread &middot;
            <kbd>e</kbd> edit &middot;
            <kbd>/</kbd> search &middot;
            <kbd>Ctrl</kbd>+<kbd>K</kbd> switch conversation
        </p>

        {{-- The emoji set travels as data, not markup: the reaction picker is
             built from it next to whichever message was clicked. --}}
        <script type="application/json" id="chat-emoji-set">@json($emojis)</script>
    </div>
@stop
