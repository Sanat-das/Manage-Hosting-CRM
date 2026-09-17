{{--
    One message row.

    $message is a ChatMessagePayload array, the same shape the websocket
    delivers, so this partial and renderMessage() in chat.js stay in step.

    body_html is already escaped and sanitised by ChatBodyHtml (everything is
    entity-escaped first, then the handful of recognised markers are turned back
    into tags), which is why it is printed unescaped here. Nothing else on this
    row is.
--}}
<li class="chat-message {{ $message['is_deleted'] ? 'is-deleted' : '' }}"
    id="chat-message-{{ $message['id'] }}"
    data-message-id="{{ $message['id'] }}"
    data-author-id="{{ $message['user']['id'] ?? '' }}">

    <div class="chat-message__meta">
        <span class="chat-message__author">{{ $message['author_name'] }}</span>
        <time class="chat-message__time" datetime="{{ $message['created_at'] }}">
            {{ $message['created_at'] ? \Illuminate\Support\Carbon::parse($message['created_at'])->format('d M H:i') : '' }}
        </time>
        @if ($message['is_edited'])
            <span class="chat-message__edited">(edited)</span>
        @endif
    </div>

    <div class="chat-message__body">{!! $message['body_html'] !!}</div>

    @if (! empty($message['entity_links']))
        <div class="chat-message__cards">
            @foreach ($message['entity_links'] as $link)
                @if ($link['url'])
                    <a class="chat-card" href="{{ $link['url'] }}">
                        <span class="chat-card__type">{{ $link['type'] }}</span>{{ $link['label'] }}
                    </a>
                @else
                    <span class="chat-card is-gone">
                        <span class="chat-card__type">{{ $link['type'] }}</span>{{ $link['label'] }}
                    </span>
                @endif
            @endforeach
        </div>
    @endif

    @if (! empty($message['attachments']))
        <div class="chat-message__attachments">
            @foreach ($message['attachments'] as $attachment)
                @if ($attachment['is_image'])
                    <a href="{{ $attachment['url'] }}" target="_blank" rel="noopener">
                        <img src="{{ $attachment['url'] }}" alt="{{ $attachment['filename'] }}" class="chat-attachment__image">
                    </a>
                @else
                    <a class="chat-attachment" href="{{ $attachment['url'] }}">
                        <i class="bi bi-paperclip" aria-hidden="true"></i>
                        {{ $attachment['filename'] }}
                        <span class="text-body-secondary">{{ $attachment['size'] }}</span>
                    </a>
                @endif
            @endforeach
        </div>
    @endif

    <div class="chat-message__reactions" data-reactions-for="{{ $message['id'] }}"></div>

    @unless ($message['is_deleted'])
        <div class="chat-message__actions">
            <button type="button" class="chat-action" data-action="react" title="React" aria-label="React">
                <i class="bi bi-emoji-smile" aria-hidden="true"></i>
            </button>
            <button type="button" class="chat-action" data-action="thread" title="Reply in thread" aria-label="Reply in thread">
                <i class="bi bi-chat-right-text" aria-hidden="true"></i>
            </button>
            @if (($message['user']['id'] ?? null) === auth()->id())
                <button type="button" class="chat-action" data-action="edit" title="Edit" aria-label="Edit message">
                    <i class="bi bi-pencil" aria-hidden="true"></i>
                </button>
            @endif
            <button type="button" class="chat-action" data-action="delete" title="Delete" aria-label="Delete message">
                <i class="bi bi-trash" aria-hidden="true"></i>
            </button>
        </div>
    @endunless
</li>
