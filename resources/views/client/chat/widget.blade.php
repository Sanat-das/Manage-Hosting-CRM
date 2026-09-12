{{--
    Floating customer chat widget.

    Included from the AdminLTE master layout for everything outside /admin, so
    both a signed-in customer and a visitor with no account get it. It
    deliberately knows nothing about channels, the operator queue, or any
    conversation other than its own — the endpoints it talks to cannot list
    conversations at all.

    Starts collapsed as a button, so it costs one element and no requests until
    somebody actually opens it.

    The conversation id and token come from the session rather than from a fetch
    on page load: they are this browser's own credential for its own
    conversation, and rendering them here is what lets a reload pick the
    transcript back up without an extra round trip.
--}}
@php
    $chatConversationId = session('chat.conversation_id');
    $chatGuestToken = session('chat.guest_token');
    // Deliberately "has no customer record", not "is logged out": that is the
    // condition StartClientChatRequest requires name and email under, and a
    // signed-in user with no customer (staff on a client page) would otherwise
    // be shown a form missing the two fields the server insists on.
    $chatNeedsIdentity = auth()->user()?->customer === null;
@endphp
<div class="client-chat" id="client-chat"
     data-conversation-id="{{ $chatConversationId }}"
     data-token="{{ $chatGuestToken }}"
     data-authenticated="{{ auth()->check() ? '1' : '0' }}"
     data-start-url="{{ route('chat.start') }}"
     data-guest-auth-url="{{ route('chat.guest-auth') }}"
     data-conversation-url-template="{{ route('chat.messages', ['conversation' => '__ID__']) }}">

    <button type="button" class="client-chat__launcher" id="client-chat-launcher"
            aria-expanded="false" aria-controls="client-chat-panel">
        <i class="bi bi-chat-dots" aria-hidden="true"></i>
        <span class="client-chat__label">Chat with us</span>
        <span class="badge text-bg-danger client-chat__unread d-none" id="client-chat-unread">0</span>
    </button>

    <section class="client-chat__panel d-none" id="client-chat-panel" aria-label="Support chat">
        <header class="client-chat__header">
            <span class="fw-semibold">Support</span>
            <span class="client-chat__status" id="client-chat-status"></span>
            <button type="button" class="btn-close btn-close-white" id="client-chat-minimise"
                    aria-label="Minimise chat"></button>
        </header>

        {{-- Shown until a conversation exists. A signed-in customer skips the
             name/email fields entirely — we already know who they are. --}}
        <form class="client-chat__intro" id="client-chat-intro">
            @csrf
            @if ($chatNeedsIdentity)
                <label class="form-label small mb-1" for="client-chat-name">Your name</label>
                <input type="text" class="form-control form-control-sm mb-2" id="client-chat-name" name="name" required maxlength="100">

                <label class="form-label small mb-1" for="client-chat-email">Email</label>
                <input type="email" class="form-control form-control-sm mb-2" id="client-chat-email" name="email" required maxlength="190">
            @endif

            <label class="form-label small mb-1" for="client-chat-first">How can we help?</label>
            <textarea class="form-control form-control-sm mb-2" id="client-chat-first" name="body" rows="3" required maxlength="4000"></textarea>

            <button type="submit" class="btn btn-sm btn-primary w-100">Start chat</button>
            <p class="client-chat__error small text-danger mt-2 mb-0 d-none" id="client-chat-intro-error"></p>
        </form>

        <ol class="client-chat__messages d-none" id="client-chat-messages"></ol>

        {{-- "Support is typing". Deliberately generic: the operator's typing
             arrives on this conversation's own private channel (the only one a
             guest token authorises), and the event names no one — which
             operator is replying is not something the transcript reveals
             either. Hidden at rest; client-chat.js toggles d-none and expires
             it on a timer, so a dropped "stopped typing" cannot leave it
             stuck on. --}}
        <p class="client-chat__typing small text-body-secondary d-none" id="client-chat-typing"
           aria-live="polite">Support is typing...</p>

        <form class="client-chat__composer d-none" id="client-chat-composer">
            @csrf
            <label class="visually-hidden" for="client-chat-body">Message</label>
            <textarea class="form-control form-control-sm" id="client-chat-body" rows="2"
                      maxlength="4000" placeholder="Type a message"></textarea>

            <div class="client-chat__composer-actions">
                <label class="client-chat__attach" for="client-chat-file" title="Attach a file">
                    <i class="bi bi-paperclip" aria-hidden="true"></i>
                    <span class="visually-hidden">Attach a file</span>
                </label>
                <input type="file" class="d-none" id="client-chat-file"
                       accept=".jpg,.jpeg,.png,.gif,.webp,.svg,.pdf,.txt,.csv,.log,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.zip">
                <span class="client-chat__filename small text-body-secondary" id="client-chat-filename"></span>
                <button type="submit" class="btn btn-sm btn-primary ms-auto">Send</button>
            </div>

            <p class="client-chat__error small text-danger mt-2 mb-0 d-none" id="client-chat-error"></p>
        </form>

        {{-- Offered once the operator closes the conversation. --}}
        <div class="client-chat__rating d-none" id="client-chat-rating">
            <p class="small mb-2">How did we do?</p>
            <div class="client-chat__stars">
                @for ($star = 1; $star <= 5; $star++)
                    <button type="button" class="client-chat__star" data-rating="{{ $star }}"
                            aria-label="{{ $star }} out of 5">&#9733;</button>
                @endfor
            </div>
            <p class="small text-success mt-2 mb-0 d-none" id="client-chat-thanks">Thank you.</p>
        </div>
    </section>
</div>

@vite(['resources/css/client-chat.css', 'resources/js/client-chat.js'])
