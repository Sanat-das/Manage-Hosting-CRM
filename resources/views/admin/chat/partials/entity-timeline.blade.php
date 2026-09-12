{{--
    Recent chat messages that reference this record.

    Self-contained on purpose: it takes only the entity and resolves everything
    else itself, so a show page adopts it with a single @include and no
    controller change.

        @include('admin.chat.partials.entity-timeline', ['entity' => $product])

    Visibility is MessageEntityLink::timelineFor()'s job — it narrows to the
    conversations the viewer may read and then re-checks the policy. Nothing is
    rendered at all when there is nothing to show, so a page with no linked
    chat gains no empty card.

    @param \Illuminate\Database\Eloquent\Model $entity  product, customer or ticket
--}}
@php
    $chatLinks = \App\Models\MessageEntityLink::timelineFor($entity, auth()->user());
@endphp

@if ($chatLinks->isNotEmpty())
    <div class="card" data-chat-entity-timeline>
        <div class="card-header d-flex align-items-center justify-content-between">
            <h3 class="card-title mb-0">
                <i class="bi bi-chat-dots me-2" aria-hidden="true"></i>Linked chat messages
            </h3>
            <a href="{{ route('admin.chat.index') }}" class="btn btn-sm btn-outline-secondary">
                Open chat
            </a>
        </div>

        <div class="card-body p-0">
            <ul class="list-group list-group-flush">
                @foreach ($chatLinks as $chatLink)
                    @php
                        $chatMessage = $chatLink->message;
                        $chatConversation = $chatMessage->conversation;
                        $chatUrl = route('admin.chat.index', ['c' => $chatConversation->id]);
                        // A link to one of this customer's contacts, rather than
                        // to the customer itself, says so.
                        $chatVia = $chatLink->linkable_type === $entity::class
                            && (int) $chatLink->linkable_id === (int) $entity->getKey()
                                ? null
                                : $chatLink->label();
                    @endphp

                    <li class="list-group-item">
                        <div class="d-flex flex-wrap align-items-center gap-2 mb-1">
                            <span class="fw-semibold">{{ $chatMessage->authorName() }}</span>

                            <span class="badge text-bg-secondary">
                                {{ $chatConversation->isCustomerInbox() ? '' : '#' }}{{ $chatConversation->displayName() }}
                            </span>

                            @if ($chatVia !== null)
                                <span class="badge text-bg-light border">via {{ $chatVia }}</span>
                            @endif

                            @if ($chatMessage->isEdited())
                                <span class="small text-body-secondary">(edited)</span>
                            @endif

                            <span class="small text-body-secondary ms-auto">
                                {{ $chatMessage->created_at?->diffForHumans() }}
                            </span>
                        </div>

                        <div class="text-body-secondary">
                            {{ \Illuminate\Support\Str::limit($chatMessage->visibleBody(), 160) }}
                        </div>

                        <a href="{{ $chatUrl }}" class="small text-decoration-none">
                            View in chat <i class="bi bi-arrow-right-short" aria-hidden="true"></i>
                        </a>
                    </li>
                @endforeach
            </ul>
        </div>
    </div>
@endif
