@extends('adminlte::page')

@section('title', $ticket->ticket_no . ' — ' . $ticket->subject)

@section('content_header')
    <div class="row">
        <div class="col-sm-6">
            <h1 class="m-0">{{ $ticket->ticket_no }}</h1>
        </div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.tickets.index') }}">Tickets</a></li>
                <li class="breadcrumb-item active" aria-current="page">{{ $ticket->ticket_no }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif
    @if (session('error'))
        <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
    @endif
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    {{-- Guest ticket banner --}}
    @if ($ticket->isGuest())
        <x-adminlte-alert theme="warning" dismissible>
            <strong><i class="bi bi-person-exclamation me-1"></i> Guest sender:</strong>
            {{ $ticket->guest_name ?? 'Guest' }} ({{ $ticket->guest_email }}) — this ticket is not linked to a customer yet.
            <div class="mt-2 d-flex flex-wrap gap-2">
                <button type="button" class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#link-guest-modal">
                    <i class="bi bi-link-45deg me-1"></i> Link to customer
                </button>
                <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal" data-bs-target="#add-contact-modal">
                    <i class="bi bi-person-plus me-1"></i> Add as contact
                </button>
            </div>
        </x-adminlte-alert>
    @endif

    {{-- Ticket header --}}
    <x-adminlte-card>
        <div class="d-flex flex-wrap align-items-center gap-3">
            <div class="flex-grow-1">
                <div class="d-flex align-items-center flex-wrap gap-2">
                    <h4 class="mb-0">{{ $ticket->subject }}</h4>
                    <x-adminlte.partials.status-badge :status="$ticket->status" />
                    <x-adminlte.partials.status-badge :status="$ticket->priority" />
                    <span class="badge text-bg-info">{{ ucfirst($ticket->department) }}</span>
                </div>
                <div class="text-muted mt-1">
                    @if ($ticket->customer)
                        Customer: <strong>{{ $ticket->customer->full_name }}</strong>
                        @if ($ticket->customer->user)
                            <span class="text-muted">({{ $ticket->customer->user->email }})</span>
                        @endif
                    @elseif ($ticket->guest_email)
                        <span class="badge text-bg-warning">Guest</span>
                        <strong>{{ $ticket->guest_name ?? '—' }}</strong>
                        <span class="text-muted">({{ $ticket->guest_email }})</span>
                    @else
                        Customer: <strong>—</strong>
                    @endif
                    @if ($ticket->assignedTo)
                        <span class="mx-2">|</span> Assigned to: <strong>{{ $ticket->assignedTo->full_name }}</strong>
                    @endif
                    <span class="mx-2">|</span> Created: {{ $ticket->created_at?->format('M j, Y H:i') }}
                </div>
            </div>
            <div class="d-flex gap-2">
                @if ($ticket->status !== 'closed')
                    @can('tickets.edit')
                        <form method="POST" action="{{ route('admin.tickets.close', $ticket) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-outline-warning" title="Close ticket">
                                <i class="bi bi-x-circle me-1"></i> Close
                            </button>
                        </form>
                    @endcan
                @else
                    @can('tickets.edit')
                        <form method="POST" action="{{ route('admin.tickets.reopen', $ticket) }}" class="d-inline">
                            @csrf
                            <button class="btn btn-sm btn-outline-success" title="Reopen ticket">
                                <i class="bi bi-arrow-counterclockwise me-1"></i> Reopen
                            </button>
                        </form>
                    @endcan
                @endif
                @if ($ticket->status !== 'closed')
                    @can('tickets.edit')
                        <div class="dropdown d-inline">
                            <button class="btn btn-sm btn-outline-info dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <i class="bi bi-arrow-repeat me-1"></i> Set Status
                            </button>
                            <ul class="dropdown-menu">
                                @foreach (\App\Services\TicketService::MANUAL_STATUSES as $manualStatus)
                                    <li>
                                        <form method="POST" action="{{ route('admin.tickets.status', $ticket) }}">
                                            @csrf
                                            @method('PUT')
                                            <input type="hidden" name="status" value="{{ $manualStatus }}">
                                            <button type="submit" class="dropdown-item" @disabled($ticket->status === $manualStatus)>
                                                {{ $statuses[$manualStatus] }}
                                            </button>
                                        </form>
                                    </li>
                                @endforeach
                            </ul>
                        </div>
                    @endcan
                @endif
                @can('tickets.assign')
                    <button type="button" class="btn btn-sm btn-outline-primary" data-bs-toggle="modal"
                            data-bs-target="#reassign-modal">
                        <i class="bi bi-person-gear me-1"></i> Reassign
                    </button>
                @endcan
                @can('tickets.transfer')
                    <button type="button" class="btn btn-sm btn-outline-secondary" data-bs-toggle="modal"
                            data-bs-target="#transfer-modal">
                        <i class="bi bi-arrow-left-right me-1"></i> Transfer department
                    </button>
                @endcan
            </div>
        </div>
    </x-adminlte-card>

    <div class="row">
        {{-- Conversation timeline --}}
        <div class="col-lg-8">
            {{-- Reply form --}}
            @if ($ticket->status !== 'closed')
                @can('tickets.edit')
                    <x-adminlte-card icon="bi bi-reply" title="Reply">
                        <form id="reply-form" method="POST" action="{{ route('admin.tickets.reply', $ticket) }}" enctype="multipart/form-data">
                            @csrf
                            <div class="position-relative ticket-contact-field" data-field="to" data-single="0" data-search-url="{{ route('admin.tickets.contacts.search', $ticket) }}">
                                <x-adminlte-input name="to" label="To" value="{{ old('to', $defaultReplyTo) }}"
                                                   placeholder="comma-separated addresses" />
                                <div class="ticket-contact-dropdown dropdown-menu bg-white border shadow rounded p-1" role="listbox" aria-label="Contact suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1050; max-height:200px; overflow-y:auto;"></div>
                            </div>

                            <div class="mb-2">
                                <a class="small" data-bs-toggle="collapse" href="#reply-cc-bcc" role="button">
                                    <i class="bi bi-plus-slash-minus me-1"></i>Cc / Bcc
                                </a>
                            </div>
                            <div class="collapse {{ old('cc') || old('bcc') ? 'show' : '' }}" id="reply-cc-bcc">
                                <div class="position-relative ticket-contact-field" data-field="cc" data-single="0" data-search-url="{{ route('admin.tickets.contacts.search', $ticket) }}">
                                    <x-adminlte-input name="cc" label="Cc" value="{{ old('cc', $defaultCc ?? '') }}"
                                                       placeholder="comma-separated addresses" />
                                    <div class="ticket-contact-dropdown dropdown-menu bg-white border shadow rounded p-1" role="listbox" aria-label="Contact suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1050; max-height:200px; overflow-y:auto;"></div>
                                </div>
                                <div class="position-relative ticket-contact-field" data-field="bcc" data-single="0" data-search-url="{{ route('admin.tickets.contacts.search', $ticket) }}">
                                    <x-adminlte-input name="bcc" label="Bcc" value="{{ old('bcc', $defaultBcc ?? '') }}"
                                                       placeholder="comma-separated addresses" />
                                    <div class="ticket-contact-dropdown dropdown-menu bg-white border shadow rounded p-1" role="listbox" aria-label="Contact suggestions" style="display:none; position:absolute; top:100%; left:0; right:0; z-index:1050; max-height:200px; overflow-y:auto;"></div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Reply message</label>
                                @php
                                    $sig = auth()->user()?->ticket_signature;
                                    $sigHtml = $sig ? '<p><br></p><p>--<br>' . str_replace("\n", '<br>', e($sig)) . '</p>' : '';
                                    $defaultHtml = old('html_body', $sigHtml);
                                @endphp
                                <input type="hidden" id="reply-html-body" name="html_body" value="{{ $defaultHtml }}">
                                <input type="hidden" id="reply-message" name="message" value="{{ old('message') }}">
                                <trix-editor input="reply-html-body" placeholder="Type your reply..."
                                             class="{{ $errors->has('message') || $errors->has('html_body') ? 'is-invalid' : '' }}"></trix-editor>
                                @error('message')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                                @error('html_body')
                                    <div class="text-danger small mt-1">{{ $message }}</div>
                                @enderror
                            </div>

                            <div class="mb-3">
                                <label for="reply-attachments" class="form-label">Attachments <span class="text-muted small">(up to 10 files, 25 MB each)</span></label>
                                <input type="file" name="attachments[]" id="reply-attachments" class="form-control" multiple accept="*/*">
                                <div id="reply-attachments-list" class="mt-2 d-flex flex-column gap-1"></div>

                            </div>

                            <button type="submit" class="btn btn-primary">
                                <i class="bi bi-send me-1"></i> Send Reply
                            </button>
                        </form>
                    </x-adminlte-card>


                    @push('js')
                        <script src="{{ asset('vendor/trix/trix.js') }}"></script>
                        <script>
                            document.addEventListener('trix-file-accept', function (e) { e.preventDefault(); });
                            document.addEventListener('DOMContentLoaded', function () {
                                var replyForm = document.getElementById('reply-form');
                                if (replyForm) {
                                    replyForm.addEventListener('submit', function () {
                                        var editor = replyForm.querySelector('trix-editor');
                                        var msgInput = document.getElementById('reply-message');
                                        if (editor && msgInput) {
                                            msgInput.value = editor.innerText.trim();
                                        }
                                    });
                                }
                            });
                        </script>
                        <script>
                            (function(){
                                function initAttachmentList(){
                                    var input = document.getElementById('reply-attachments');
                                    var list = document.getElementById('reply-attachments-list');
                                    if (!input || !list) return;
                                    function formatSize(bytes){
                                        if (bytes >= 1048576) return (bytes/1048576).toFixed(1)+' MB';
                                        if (bytes >= 1024) return (bytes/1024).toFixed(1)+' KB';
                                        return bytes+' B';
                                    }
                                    function render(){
                                        list.innerHTML='';
                                        if (!input.files || input.files.length===0) return;
                                        Array.from(input.files).forEach(function(file, idx){
                                            var row=document.createElement('div');
                                            row.className='d-flex align-items-center gap-2 p-2 bg-light border rounded';
                                            var icon='bi-file-earmark';
                                            var ext=file.name.split('.').pop().toLowerCase();
                                            if (file.type.startsWith('image/')) icon='bi-file-earmark-image text-success';
                                            else if (file.type==='application/pdf' || ext==='pdf') icon='bi-file-earmark-pdf text-danger';
                                            else if (['zip','rar','7z'].includes(ext)) icon='bi-file-earmark-zip text-warning';
                                            row.innerHTML='<i class="bi '+icon+'"></i><span class="flex-grow-1 text-truncate small" title="'+file.name+'">'+file.name+'</span><span class="text-muted small">'+formatSize(file.size)+'</span><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" title="Remove" data-idx="'+idx+'"><i class="bi bi-x"></i></button>';
                                            list.appendChild(row);
                                        });
                                    }
                                    input.addEventListener('change', function(e){
                                        // Merge newly picked files with already-queued files
                                        // so the user can add files one by one.
                                        var incoming = Array.from(input.files);
                                        if (!incoming.length) { render(); return; }
                                        var existing = Array.from(input._accumulated || []);
                                        var merged = existing.slice();
                                        incoming.forEach(function(f){
                                            var dup = merged.some(function(g){ return g.name===f.name && g.size===f.size && g.lastModified===f.lastModified; });
                                            if (!dup) merged.push(f);
                                        });
                                        if (merged.length > 10) {
                                            alert('Maximum 10 files allowed.');
                                            merged = merged.slice(0, 10);
                                        }
                                        var dt = new DataTransfer();
                                        merged.forEach(function(f){ dt.items.add(f); });
                                        input.files = dt.files;
                                        input._accumulated = merged;
                                        render();
                                    });
                                    list.addEventListener('click', function(e){
                                        var btn=e.target.closest('button[data-idx]');
                                        if(!btn) return;
                                        var idx=parseInt(btn.getAttribute('data-idx'),10);
                                        var dt=new DataTransfer();
                                        Array.from(input.files).forEach(function(f,i){ if(i!==idx) dt.items.add(f); });
                                        input.files=dt.files;
                                        input._accumulated=Array.from(dt.files);
                                        render();
                                    });
                                    // drag & drop on the input area
                                    var dropZone=input.closest('.mb-3') || input.parentElement;
                                    if(dropZone){
                                        ['dragenter','dragover'].forEach(function(ev){
                                            dropZone.addEventListener(ev, function(e){ e.preventDefault(); input.classList.add('border-primary'); });
                                        });
                                        ['dragleave','drop'].forEach(function(ev){
                                            dropZone.addEventListener(ev, function(e){ input.classList.remove('border-primary'); });
                                        });
                                        dropZone.addEventListener('drop', function(e){
                                            e.preventDefault();
                                            if(!e.dataTransfer || !e.dataTransfer.files.length) return;
                                            var dt=new DataTransfer();
                                            Array.from(input.files).forEach(function(f){ dt.items.add(f); });
                                            Array.from(e.dataTransfer.files).forEach(function(f){ dt.items.add(f); });
                                            // cap at 10
                                            if(dt.files.length>10){
                                                alert('Maximum 10 files allowed.');
                                                var dt2=new DataTransfer();
                                                Array.from(dt.files).slice(0,10).forEach(function(f){ dt2.items.add(f); });
                                                input.files=dt2.files;
                                            } else {
                                                input.files=dt.files;
                                            }
                                            render();
                                            input.dispatchEvent(new Event('change', {bubbles:true}));
                                        });
                                    }
                                    // expose for editor image insertion to reuse
                                    window.renderReplyAttachmentsList=render;
                                    // also observe programmatic changes via DataTransfer (editor inserts)
                                    var lastCount=-1;
                                    setInterval(function(){
                                        if(input.files && input.files.length!==lastCount){
                                            lastCount=input.files.length;
                                            render();
                                        }
                                    }, 500);
                                }
                                if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', initAttachmentList); else initAttachmentList();
                            })();
                        </script>
                    @endpush
                    @push('js')
                        <script>
                            document.addEventListener('DOMContentLoaded', function () {
                                var fields = document.querySelectorAll('.ticket-contact-field');
                                if (!fields.length) return;

                                fields.forEach(function (container) {
                                    var input = container.querySelector('input[name="' + container.getAttribute('data-field') + '"]');
                                    var dropdown = container.querySelector('.ticket-contact-dropdown');
                                    var searchUrl = container.getAttribute('data-search-url');
                                    var isSingle = container.getAttribute('data-single') === '1';
                                    if (!input || !dropdown || !searchUrl) return;

                                    // Accessibility
                                    input.setAttribute('autocomplete', 'off');
                                    input.setAttribute('aria-autocomplete', 'list');
                                    input.setAttribute('aria-expanded', 'false');
                                    input.setAttribute('role', 'combobox');
                                    if (!dropdown.id) dropdown.id = input.id + '-contact-listbox';
                                    input.setAttribute('aria-controls', dropdown.id);
                                    dropdown.setAttribute('aria-label', 'Contact suggestions');

                                    var debounceTimer = null;
                                    var abortController = null;
                                    var activeIndex = -1;
                                    var currentItems = [];
                                    var lastQuery = null;

                                    function getToken() {
                                        var val = input.value;
                                        if (isSingle) return val.trim();
                                        var lastComma = Math.max(val.lastIndexOf(','), val.lastIndexOf(';'));
                                        var token = lastComma === -1 ? val : val.slice(lastComma + 1);
                                        return token.trim();
                                    }

                                    function closeDropdown() {
                                        dropdown.style.display = 'none';
                                        dropdown.innerHTML = '';
                                        input.setAttribute('aria-expanded', 'false');
                                        activeIndex = -1;
                                        currentItems = [];
                                    }

                                    function render(items) {
                                        currentItems = items.slice(0, 8);
                                        dropdown.innerHTML = '';
                                        if (!currentItems.length) { closeDropdown(); return; }
                                        currentItems.forEach(function (item, idx) {
                                            var btn = document.createElement('button');
                                            btn.type = 'button';
                                            btn.className = 'dropdown-item d-flex flex-column align-items-start py-2' + (idx === activeIndex ? ' active' : '');
                                            btn.setAttribute('role', 'option');
                                            btn.setAttribute('id', dropdown.id + '-opt-' + idx);
                                            btn.setAttribute('aria-selected', idx === activeIndex ? 'true' : 'false');
                                            if (idx === activeIndex) btn.setAttribute('aria-current', 'true');

                                            var label = document.createElement('span');
                                            label.className = 'fw-medium small text-truncate w-100';
                                            label.textContent = item.label;
                                            var email = document.createElement('span');
                                            email.className = 'text-muted small text-truncate w-100';
                                            email.textContent = item.email;
                                            // If label already contains email, hide duplicate email line
                                            if (item.label === item.email) {
                                                btn.appendChild(label);
                                            } else {
                                                btn.appendChild(label);
                                                // keep email line for clarity? label already shows email, but keep as secondary if name differs
                                                // label is "Name <email>", we still show email muted is redundant, so skip
                                            }

                                            btn.addEventListener('mousedown', function (e) {
                                                e.preventDefault();
                                                applySelection(item.email);
                                            });
                                            btn.addEventListener('click', function (e) {
                                                e.preventDefault();
                                                applySelection(item.email);
                                            });
                                            dropdown.appendChild(btn);
                                        });
                                        dropdown.style.display = 'block';
                                        input.setAttribute('aria-expanded', 'true');
                                        // Position handled by CSS absolute top:100%
                                    }

                                    function updateActive(newIndex) {
                                        var buttons = dropdown.querySelectorAll('[role="option"]');
                                        buttons.forEach(function (b, i) {
                                            b.classList.toggle('active', i === newIndex);
                                            b.setAttribute('aria-selected', i === newIndex ? 'true' : 'false');
                                            if (i === newIndex) b.setAttribute('aria-current', 'true'); else b.removeAttribute('aria-current');
                                        });
                                        activeIndex = newIndex;
                                        if (activeIndex >= 0 && buttons[activeIndex]) {
                                            buttons[activeIndex].scrollIntoView({ block: 'nearest' });
                                        }
                                    }

                                    function applySelection(email) {
                                        if (isSingle) {
                                            input.value = email;
                                        } else {
                                            var val = input.value;
                                            var lastComma = Math.max(val.lastIndexOf(','), val.lastIndexOf(';'));
                                            if (lastComma === -1) {
                                                input.value = email + ', ';
                                            } else {
                                                var prefix = val.slice(0, lastComma + 1);
                                                input.value = prefix + ' ' + email + ', ';
                                                // Clean double spaces/comma weirdness: collapse "  " to " "
                                                input.value = input.value.replace(/,\s*,/g, ',').replace(/\s{2,}/g, ' ');
                                                // Ensure one space after comma
                                                input.value = input.value.replace(/,\s*/g, ', ');
                                            }
                                        }
                                        closeDropdown();
                                        input.focus();
                                        // Trigger input event for any listeners
                                        input.dispatchEvent(new Event('input', { bubbles: true }));
                                        // Move cursor to end
                                        try { input.setSelectionRange(input.value.length, input.value.length); } catch (e) {}
                                    }

                                    function fetchAndRender(query) {
                                        if (abortController) abortController.abort();
                                        abortController = new AbortController();
                                        var url = searchUrl + '?q=' + encodeURIComponent(query);
                                        fetch(url, {
                                            headers: { 'Accept': 'application/json' },
                                            signal: abortController.signal
                                        }).then(function (resp) {
                                            if (!resp.ok) throw new Error('bad status');
                                            return resp.json();
                                        }).then(function (data) {
                                            if (!Array.isArray(data)) data = [];
                                            render(data);
                                        }).catch(function (err) {
                                            if (err && err.name === 'AbortError') return;
                                            closeDropdown();
                                        });
                                    }

                                    function debouncedFetch() {
                                        var token = getToken();
                                        // Avoid refetching same token while dropdown open? Still debounce.
                                        lastQuery = token;
                                        if (debounceTimer) clearTimeout(debounceTimer);
                                        debounceTimer = setTimeout(function () { fetchAndRender(token); }, 200);
                                    }

                                    input.addEventListener('input', debouncedFetch);
                                    input.addEventListener('focus', debouncedFetch);
                                    input.addEventListener('click', debouncedFetch);

                                    input.addEventListener('keydown', function (e) {
                                        var isOpen = dropdown.style.display !== 'none' && currentItems.length > 0;
                                        if (!isOpen) {
                                            if (e.key === 'ArrowDown' && document.activeElement === input) {
                                                // On arrow down when closed, trigger fetch if not already
                                                debouncedFetch();
                                            }
                                            return;
                                        }
                                        if (e.key === 'ArrowDown') {
                                            e.preventDefault();
                                            var next = activeIndex + 1;
                                            if (next >= currentItems.length) next = 0;
                                            updateActive(next);
                                        } else if (e.key === 'ArrowUp') {
                                            e.preventDefault();
                                            var prev = activeIndex - 1;
                                            if (prev < 0) prev = currentItems.length - 1;
                                            updateActive(prev);
                                        } else if (e.key === 'Enter' || e.key === 'Tab') {
                                            if (activeIndex >= 0 && activeIndex < currentItems.length) {
                                                e.preventDefault();
                                                applySelection(currentItems[activeIndex].email);
                                            } else if (e.key === 'Enter') {
                                                // Let form submit? Close dropdown.
                                                closeDropdown();
                                            }
                                        } else if (e.key === 'Escape') {
                                            e.preventDefault();
                                            closeDropdown();
                                        }
                                    });

                                    // Close on outside click / blur with delay to allow mousedown
                                    document.addEventListener('click', function (e) {
                                        if (!container.contains(e.target)) closeDropdown();
                                    });
                                    input.addEventListener('blur', function () {
                                        // Delay close to allow option mousedown to fire
                                        setTimeout(function () {
                                            if (!dropdown.contains(document.activeElement)) {
                                                // Keep open only if focus moved into dropdown; otherwise close after 200ms
                                                // Use timeout check
                                            }
                                        }, 150);
                                    });

                                    // When collapse for Cc/Bcc is hidden, close
                                    var collapseEl = document.getElementById('reply-cc-bcc');
                                    if (collapseEl) {
                                        collapseEl.addEventListener('hidden.bs.collapse', closeDropdown);
                                    }
                                });
                            });
                        </script>
                    @endpush
                @endcan
            @endif

            <x-adminlte-card icon="bi bi-chat-left-text" title="Conversation">
                @forelse ($replies->sortByDesc('created_at')->values() as $reply)
                    @php
                        $isInternal = str_starts_with($reply->message, $internalPrefix);
                        $isStaff = $reply->is_staff;
                        $sanitizedHtml = \App\Support\TicketHtmlSanitizer::sanitize($reply->html_body);
                    @endphp
                    <div class="border-bottom pb-3 mb-3 {{ $isInternal ? 'bg-warning bg-opacity-10 p-2 rounded' : '' }}">
                        <div class="d-flex justify-content-between align-items-center mb-1">
                            <div>
                                <strong>{{ $reply->user?->full_name ?? 'Unknown' }}</strong>
                                @if ($isStaff)
                                    <span class="badge text-bg-primary ms-1">Staff</span>
                                @endif
                                @if ($isInternal)
                                    <span class="badge text-bg-warning ms-1">Internal Note</span>
                                @endif
                            </div>
                            <small class="text-muted">{{ $reply->created_at?->diffForHumans() }}</small>
                        </div>

                        @if (!$isInternal && ($reply->to || $reply->cc || $reply->bcc))
                            <div class="mb-2 small">
                                @foreach (['To' => $reply->to, 'Cc' => $reply->cc, 'Bcc' => $reply->bcc] as $label => $addresses)
                                    @if ($addresses)
                                        <div class="mb-1">
                                            <span class="text-muted">{{ $label }}:</span>
                                            @foreach ($addresses as $address)
                                                <span class="badge text-bg-light border ms-1">{{ $address }}</span>
                                            @endforeach
                                        </div>
                                    @endif
                                @endforeach
                            </div>
                        @endif

                        <div class="{{ $isInternal ? 'text-muted fst-italic' : '' }}">
                            @if (!$isInternal && $sanitizedHtml)
                                {!! $sanitizedHtml !!}
                            @else
                                {!! nl2br(e($isInternal ? Str::after($reply->message, $internalPrefix . ' ') : $reply->message)) !!}
                            @endif
                        </div>

                        @php $visibleAttachments = $reply->attachments->where('is_inline', false); @endphp
                        @if ($visibleAttachments->isNotEmpty())
                            <div class="ticket-attachments mt-3">
                                <div class="d-flex align-items-center gap-1 mb-2 small text-muted">
                                    <i class="bi bi-paperclip"></i>
                                    <span>{{ $visibleAttachments->count() }} {{ \Illuminate\Support\Str::plural('attachment', $visibleAttachments->count()) }}</span>
                                </div>
                                <div class="d-flex flex-wrap gap-2">
                                    @foreach ($visibleAttachments as $attachment)
                                        @php
                                            $mime = strtolower($attachment->mime_type ?? '');
                                            $ext = strtolower(pathinfo($attachment->filename ?? '', PATHINFO_EXTENSION));
                                            $isImage = str_starts_with($mime, 'image/') && $mime !== 'image/svg+xml';
                                            $isPdf = $mime === 'application/pdf' || $ext === 'pdf';
                                            // text/html is deliberately absent: it is served as a download, never
                                            // previewed inline, because an iframe on our origin would run its script.
                                            $isText = in_array($mime, ['text/plain','text/csv'], true);
                                            $isPreviewable = $isImage || $isPdf || $isText;
                                            $previewUrl = route('admin.tickets.attachments.show', [$ticket, $attachment]);
                                            $downloadUrl = $previewUrl.'?download=1';
                                            $sizeLabel = null;
                                            if ($attachment->size_bytes) {
                                                $bytes = (int) $attachment->size_bytes;
                                                $sizeLabel = $bytes >= 1048576 ? number_format($bytes/1048576, 1).' MB' : ($bytes >= 1024 ? number_format($bytes/1024, 1).' KB' : $bytes.' B');
                                            }
                                            $icon = match(true) {
                                                $isImage => 'bi-file-earmark-image text-success',
                                                $isPdf => 'bi-file-earmark-pdf text-danger',
                                                in_array($ext, ['zip','rar','7z','tar','gz']) => 'bi-file-earmark-zip text-warning',
                                                in_array($ext, ['doc','docx']) => 'bi-file-earmark-word text-primary',
                                                in_array($ext, ['xls','xlsx','csv']) => 'bi-file-earmark-excel text-success',
                                                $ext === 'txt' => 'bi-file-earmark-text text-muted',
                                                default => 'bi-file-earmark text-secondary',
                                            };
                                        @endphp
                                        <div class="attachment-card d-flex align-items-center gap-2 p-2 bg-white border rounded {{ $isPreviewable ? 'attachment-previewable' : '' }}"
                                             style="min-width: 220px; max-width: 320px; cursor: {{ $isPreviewable ? 'pointer' : 'default' }};"
                                             @if($isPreviewable) data-preview-url="{{ $previewUrl }}" data-preview-mime="{{ $mime }}" data-filename="{{ $attachment->filename }}" data-download-url="{{ $downloadUrl }}" @endif>
                                            <div class="flex-shrink-0 d-flex align-items-center justify-content-center bg-light border rounded overflow-hidden"
                                                 style="width: 44px; height: 44px;">
                                                @if($isImage)
                                                    <img src="{{ $previewUrl }}" alt="{{ $attachment->filename }}" loading="lazy"
                                                         style="width: 100%; height: 100%; object-fit: cover;">
                                                @else
                                                    <i class="bi {{ $icon }} fs-4"></i>
                                                @endif
                                            </div>
                                            <div class="flex-grow-1 min-w-0" style="min-width:0;">
                                                <div class="text-truncate fw-medium small" title="{{ $attachment->filename }}" style="max-width: 150px;">{{ $attachment->filename }}</div>
                                                <div class="small text-muted text-truncate">{{ $sizeLabel ?? strtoupper($ext) }}</div>
                                            </div>
                                            <div class="d-flex gap-1 flex-shrink-0">
                                                @if($isPreviewable)
                                                    <button type="button"
                                                            class="btn btn-sm btn-light border attachment-preview-btn"
                                                            data-preview-url="{{ $previewUrl }}" data-preview-mime="{{ $mime }}"
                                                            data-filename="{{ $attachment->filename }}" data-download-url="{{ $downloadUrl }}"
                                                            title="Preview">
                                                        <i class="bi bi-eye"></i>
                                                    </button>
                                                @endif
                                                <a href="{{ $downloadUrl }}" class="btn btn-sm btn-light border" title="Download" download>
                                                    <i class="bi bi-download"></i>
                                                </a>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif

                        {{--
                            Mirrors TicketMailService::originalSourceFor()'s own condition
                            (raw_source present, or a staff reply that was actually mailed)
                            so the link only appears when there is genuinely something to
                            show — a portal-submitted customer reply has neither.
                        --}}
                        @if (!$isInternal && ($reply->raw_source || $isStaff))
                            <div class="mt-2">
                                <a href="{{ route('admin.tickets.replies.original', [$ticket, $reply]) }}"
                                   class="small text-muted text-decoration-none" target="_blank" rel="noopener">
                                    <i class="bi bi-code-slash me-1"></i>Show original
                                </a>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-muted mb-0">No replies yet.</p>
                @endforelse
            </x-adminlte-card>

            {{-- Transfer history --}}
            @if ($ticket->transfers->isNotEmpty())
                <x-adminlte-card icon="bi bi-arrow-left-right" title="Transfer History">
                    <ul class="list-unstyled mb-0">
                        @foreach ($ticket->transfers as $transfer)
                            <li class="border-bottom pb-2 mb-2">
                                <strong>{{ $transfer->actor?->full_name ?? 'System' }}</strong>
                                transferred
                                <span class="badge text-bg-secondary">{{ \App\Services\TicketService::departmentLabel($transfer->from_department) }}</span>
                                &rarr;
                                <span class="badge text-bg-info">{{ \App\Services\TicketService::departmentLabel($transfer->to_department) }}</span>
                                <small class="text-muted ms-2">{{ $transfer->created_at?->format('M j, Y H:i') }}</small>
                                @if ($transfer->note)
                                    <div class="text-muted fst-italic mt-1">{{ $transfer->note }}</div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                </x-adminlte-card>
            @endif
        </div>

        {{-- Sidebar --}}
        <div class="col-lg-4">
            {{-- Internal note --}}
            @can('tickets.edit')
                <x-adminlte-card class="mb-3" icon="bi bi-sticky" title="Internal Note">
                    <form method="POST" action="{{ route('admin.tickets.note', $ticket) }}">
                        @csrf
                        <x-adminlte-textarea name="note" label="" rows="3"
                                             placeholder="Add a staff-only note..." required>{{ old('note') }}</x-adminlte-textarea>
                        <button type="submit" class="btn btn-sm btn-warning">
                            <i class="bi bi-sticky me-1"></i> Add Note
                        </button>
                    </form>
                </x-adminlte-card>
            @endcan

            {{-- Ticket info --}}
            <x-adminlte-card class="mb-3" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tbody>
                        <tr><th class="text-muted w-25">Status</th><td><x-adminlte.partials.status-badge :status="$ticket->status" /></td></tr>
                        <tr><th class="text-muted">Priority</th><td><x-adminlte.partials.status-badge :status="$ticket->priority" /></td></tr>
                        <tr><th class="text-muted">Department</th><td>{{ \App\Services\TicketService::departmentLabel($ticket->department) }}</td></tr>
                        <tr><th class="text-muted">Assigned</th><td>{{ $ticket->assignedTo?->full_name ?? 'Unassigned' }}</td></tr>
                        <tr><th class="text-muted">Created</th><td>{{ $ticket->created_at?->format('M j, Y H:i') }}</td></tr>
                        <tr><th class="text-muted">Last reply</th><td>{{ $ticket->last_reply_at?->diffForHumans() ?? '—' }}</td></tr>
                    </tbody>
                </table>
            </x-adminlte-card>

            {{-- Customer products / services --}}
            <x-adminlte-card icon="bi bi-box-seam" title="Products">
                @if ($ticket->isGuest() || $ticket->customer_id === null)
                    <p class="text-muted small mb-0">Guest ticket — no linked customer products.</p>
                @elseif(isset($customerServices) && $customerServices->isNotEmpty())
                    <div class="list-group list-group-flush border rounded overflow-hidden">
                        @foreach($customerServices as $svc)
                            @php
                                $status = strtolower((string) $svc->status);
                                $badge = match($status) {
                                    'active' => 'success',
                                    'suspended' => 'warning',
                                    'terminated','cancelled' => 'danger',
                                    'pending','provisioning' => 'info',
                                    default => 'secondary',
                                };
                                $productName = $svc->catalogProduct?->name ?? 'Service #'.$svc->id;
                                $categoryName = $svc->catalogProduct?->category?->name;
                            @endphp
                            <div class="list-group-item px-2 py-2">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-medium small text-truncate" title="{{ $productName }}">{{ $productName }}</div>
                                        @if($categoryName)
                                            <div class="text-muted" style="font-size:0.7rem;">{{ $categoryName }}</div>
                                        @endif
                                        @if($svc->domain)
                                            <div class="text-muted small text-truncate" title="{{ $svc->domain }}"><i class="bi bi-globe me-1"></i>{{ $svc->domain }}</div>
                                        @endif
                                        <div class="d-flex align-items-center gap-2 mt-1 flex-wrap">
                                            @if($svc->server)
                                                <span class="text-muted" style="font-size:0.7rem;"><i class="bi bi-hdd me-1"></i>{{ $svc->server->name ?? $svc->server->hostname ?? 'Server #'.$svc->server_id }}</span>
                                            @endif
                                            @if($svc->next_billing_date)
                                                <span class="text-muted" style="font-size:0.7rem;"><i class="bi bi-calendar me-1"></i>{{ \Illuminate\Support\Carbon::parse($svc->next_billing_date)->format('M j, Y') }}</span>
                                            @endif
                                        </div>
                                    </div>
                                    <span class="badge text-bg-{{ $badge }} text-capitalize flex-shrink-0" style="font-size:0.68rem;">{{ $svc->status }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted">{{ $customerServices->count() }} {{ \Illuminate\Support\Str::plural('service', $customerServices->count()) }}</small>
                        <a href="{{ route('admin.customers.show', $ticket->customer) }}" class="small text-decoration-none">View customer <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                @elseif(isset($customerOrders) && $customerOrders->isNotEmpty())
                    <div class="list-group list-group-flush border rounded overflow-hidden">
                        @foreach($customerOrders as $order)
                            @php
                                $ostatus = strtolower((string) $order->status);
                                $obadge = match($ostatus) {
                                    'active' => 'success',
                                    'suspended' => 'warning',
                                    'terminated','cancelled' => 'danger',
                                    'pending','paid','provisioning' => 'info',
                                    default => 'secondary',
                                };
                                $prodName = $order->product?->name ?? $order->items->first()?->product_name ?? $order->items->first()?->product?->name ?? 'Order';
                            @endphp
                            <div class="list-group-item px-2 py-2">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-medium small text-truncate" title="{{ $prodName }}">{{ $prodName }}</div>
                                        <div class="text-muted" style="font-size:0.7rem;">{{ $order->order_number ?? '#'.$order->id }} · {{ $order->billing_cycle ?? '—' }}@if($order->domain_name) · {{ $order->domain_name }}@endif</div>
                                        @if($order->total !== null)
                                            <div class="text-muted small">${{ number_format((float) $order->total, 2) }}</div>
                                        @endif
                                    </div>
                                    <span class="badge text-bg-{{ $obadge }} text-capitalize flex-shrink-0" style="font-size:0.68rem;">{{ $order->status }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted">{{ $customerOrders->count() }} {{ \Illuminate\Support\Str::plural('order', $customerOrders->count()) }}</small>
                        <a href="{{ route('admin.customers.show', $ticket->customer) }}" class="small text-decoration-none">View customer <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                @elseif(isset($customerHostingAccounts) && $customerHostingAccounts->isNotEmpty())
                    <div class="list-group list-group-flush border rounded overflow-hidden">
                        @foreach($customerHostingAccounts as $acct)
                            @php
                                $hstatus = strtolower((string) $acct->status);
                                $hbadge = match($hstatus) {
                                    'active' => 'success',
                                    'suspended' => 'warning',
                                    'terminated','cancelled' => 'danger',
                                    'pending' => 'info',
                                    default => 'secondary',
                                };
                                $hProdName = $acct->product?->name ?? 'Hosting';
                            @endphp
                            <div class="list-group-item px-2 py-2">
                                <div class="d-flex align-items-start justify-content-between gap-2">
                                    <div class="flex-grow-1 min-w-0">
                                        <div class="fw-medium small text-truncate">{{ $hProdName }}</div>
                                        @if($acct->domain)
                                            <div class="text-muted small text-truncate"><i class="bi bi-globe me-1"></i>{{ $acct->domain }}</div>
                                        @endif
                                        <div class="text-muted" style="font-size:0.7rem;">{{ $acct->host_name ?? 'HOST-'.str_pad($acct->id,5,'0',STR_PAD_LEFT) }}@if($acct->server) · {{ $acct->server->name ?? $acct->server->hostname }}@endif</div>
                                    </div>
                                    <span class="badge text-bg-{{ $hbadge }} text-capitalize flex-shrink-0" style="font-size:0.68rem;">{{ $acct->status ?? 'unknown' }}</span>
                                </div>
                            </div>
                        @endforeach
                    </div>
                    <div class="d-flex justify-content-between align-items-center mt-2">
                        <small class="text-muted">{{ $customerHostingAccounts->count() }} {{ \Illuminate\Support\Str::plural('account', $customerHostingAccounts->count()) }}</small>
                        <a href="{{ route('admin.customers.show', $ticket->customer) }}" class="small text-decoration-none">View customer <i class="bi bi-arrow-right ms-1"></i></a>
                    </div>
                @else
                    <p class="text-muted small mb-1">No products found for this customer.</p>
                    <a href="{{ route('admin.customers.show', $ticket->customer) }}" class="small text-decoration-none">View customer <i class="bi bi-box-arrow-up-right ms-1"></i></a>
                @endif
            </x-adminlte-card>
        </div>
    </div>

    {{-- Reassign modal --}}
    @can('tickets.assign')
        <div class="modal fade" id="reassign-modal" tabindex="-1" aria-labelledby="reassign-modal-label" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.tickets.assign', $ticket) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="reassign-modal-label">Reassign Ticket</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <x-adminlte-select name="assigned_to" label="Assign to">
                                <option value="">Unassigned</option>
                                @foreach ($staff as $member)
                                    <option value="{{ $member->id }}" @selected($ticket->assigned_to === $member->id)>
                                        {{ $member->full_name }}
                                    </option>
                                @endforeach
                            </x-adminlte-select>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Save</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan

    {{-- Link guest to customer modal --}}
    @if ($ticket->isGuest())
        <div class="modal fade" id="link-guest-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.tickets.linkGuest', $ticket) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title">Link guest sender to customer</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <p class="text-muted small">Guest: <strong>{{ $ticket->guest_name }}</strong> ({{ $ticket->guest_email }})</p>
                            <x-adminlte-select name="customer_id" label="Select customer" required>
                                <option value="">Choose customer...</option>
                                @foreach ($customers as $cust)
                                    <option value="{{ $cust->id }}">{{ $cust->full_name }} — {{ $cust->user?->email ?? 'no email' }}</option>
                                @endforeach
                            </x-adminlte-select>
                            <div class="form-check mt-2">
                                <input class="form-check-input" type="checkbox" name="create_contact" value="1" id="create_contact_check">
                                <label class="form-check-label" for="create_contact_check">Also add as contact to this customer</label>
                            </div>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-warning">Link ticket</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
        <div class="modal fade" id="add-contact-modal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.tickets.addGuestContact', $ticket) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title">Add guest sender as contact</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                        </div>
                        <div class="modal-body">
                            <x-adminlte-select name="customer_id" label="Select customer" required>
                                <option value="">Choose customer...</option>
                                @foreach ($customers as $cust)
                                    <option value="{{ $cust->id }}">{{ $cust->full_name }} — {{ $cust->user?->email ?? 'no email' }}</option>
                                @endforeach
                            </x-adminlte-select>
                            <p class="text-muted small mt-2">Will add <strong>{{ $ticket->guest_email }}</strong> as a contact to the selected customer. Ticket remains unlinked (use Link if you want to assign ownership).</p>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Add contact</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endif

    {{-- Attachment preview modal — Gmail/Outlook style lightbox --}}
    <div class="modal fade" id="attachmentPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title text-truncate me-2" id="attachmentPreviewTitle" style="max-width: 60%;"></h6>
                    <div class="ms-auto d-flex align-items-center gap-2">
                        <a id="attachmentPreviewDownload" href="#" class="btn btn-sm btn-primary" download>
                            <i class="bi bi-download me-1"></i>Download
                        </a>
                        <a id="attachmentPreviewOpen" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary" title="Open in new tab">
                            <i class="bi bi-box-arrow-up-right"></i>
                        </a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                </div>
                <div class="modal-body p-0 bg-dark d-flex align-items-center justify-content-center" style="min-height: 320px; max-height: 78vh; overflow: auto;">
                    <img id="attachmentPreviewImage" src="" alt="" style="max-width: 100%; max-height: 74vh; object-fit: contain; display: none;">
                    <iframe id="attachmentPreviewFrame" src="" style="width: 100%; height: 74vh; border: 0; display: none; background: white;"></iframe>
                    <div id="attachmentPreviewFallback" class="text-white text-center p-4" style="display: none;">
                        <i class="bi bi-file-earmark fs-1 d-block mb-2"></i>
                        <span>Preview not available.</span>
                    </div>
                </div>
            </div>
        </div>
    </div>

    @push('css')
        <link rel="stylesheet" href="{{ asset('vendor/trix/trix.css') }}">
        <style>
            trix-editor { min-height: 220px; font-size: 0.875rem; line-height: 1.6; border: 1px solid #ced4da; border-radius: 0.25rem; padding: 0.5rem 0.75rem; background: #fff; overflow-y: auto; }
            trix-editor:focus { border-color: #86b7fe; outline: 0; box-shadow: 0 0 0 0.2rem rgba(13,110,253,.25); }
            trix-editor.is-invalid { border-color: #dc3545; }
            trix-toolbar .trix-button-group { border: 1px solid #ced4da; border-radius: 0.2rem; }
            trix-toolbar .trix-button { border-bottom: none; }
            trix-toolbar .trix-button.trix-active { background: #e9ecef; }
        trix-toolbar .trix-button-group--file-tools { display: none; }
            .attachment-card { transition: box-shadow .15s, border-color .15s; }
            .attachment-card:hover { border-color: #adb5bd !important; box-shadow: 0 1px 6px rgba(0,0,0,.08); }
            .attachment-card.attachment-previewable:hover { border-color: #0d6efd !important; }
        </style>
    @endpush
    @push('js')
        <script>
            (function () {
                function initAttachmentPreview() {
                    var modalEl = document.getElementById('attachmentPreviewModal');
                    if (!modalEl) return;
                    var bsModal = (typeof bootstrap !== 'undefined' && bootstrap.Modal) ? new bootstrap.Modal(modalEl) : null;
                    var titleEl = document.getElementById('attachmentPreviewTitle');
                    var imgEl = document.getElementById('attachmentPreviewImage');
                    var frameEl = document.getElementById('attachmentPreviewFrame');
                    var fallbackEl = document.getElementById('attachmentPreviewFallback');
                    var dlEl = document.getElementById('attachmentPreviewDownload');
                    var openEl = document.getElementById('attachmentPreviewOpen');

                    function openPreview(url, mime, filename, downloadUrl) {
                        titleEl.textContent = filename || '';
                        dlEl.href = downloadUrl || url + '?download=1';
                        dlEl.setAttribute('download', filename || '');
                        openEl.href = url;
                        imgEl.style.display = 'none';
                        frameEl.style.display = 'none';
                        fallbackEl.style.display = 'none';
                        imgEl.removeAttribute('src');
                        frameEl.removeAttribute('src');
                        var m = (mime || '').toLowerCase();
                        if (m.startsWith('image/') && m !== 'image/svg+xml') {
                            imgEl.src = url;
                            imgEl.style.display = 'block';
                        } else if (m === 'application/pdf' || m.startsWith('text/')) {
                            frameEl.src = url;
                            frameEl.style.display = 'block';
                        } else {
                            fallbackEl.style.display = 'block';
                        }
                        if (bsModal) bsModal.show();
                        else {
                            modalEl.style.display = 'block';
                            modalEl.classList.add('show');
                            document.body.classList.add('modal-open');
                            var backdrop = document.createElement('div');
                            backdrop.className = 'modal-backdrop fade show';
                            backdrop.id = 'attachment-preview-backdrop';
                            document.body.appendChild(backdrop);
                            backdrop.addEventListener('click', function(){ closeFallback(); });
                        }
                    }
                    function closeFallback() {
                        modalEl.style.display = 'none';
                        modalEl.classList.remove('show');
                        document.body.classList.remove('modal-open');
                        var bd = document.getElementById('attachment-preview-backdrop');
                        if (bd) bd.remove();
                        imgEl.removeAttribute('src');
                        frameEl.removeAttribute('src');
                    }

                    document.addEventListener('click', function (e) {
                        var btn = e.target.closest('.attachment-preview-btn');
                        if (btn) {
                            e.preventDefault(); e.stopPropagation();
                            openPreview(btn.dataset.previewUrl, btn.dataset.previewMime, btn.dataset.filename, btn.dataset.downloadUrl);
                            return;
                        }
                        var card = e.target.closest('.attachment-previewable');
                        if (card && !e.target.closest('a') && !e.target.closest('button')) {
                            e.preventDefault();
                            openPreview(card.dataset.previewUrl, card.dataset.previewMime, card.dataset.filename, card.dataset.downloadUrl);
                        }
                        if (e.target.closest('[data-bs-dismiss="modal"]') && e.target.closest('#attachmentPreviewModal')) {
                            if (!bsModal) { e.preventDefault(); closeFallback(); }
                        }
                    });

                    modalEl.addEventListener('hidden.bs.modal', function () {
                        imgEl.removeAttribute('src');
                        frameEl.removeAttribute('src');
                    });
                    // also clean fallback when hidden via our manual backdrop
                    modalEl.addEventListener('click', function(e){
                        if (e.target === modalEl && !bsModal) closeFallback();
                    });
                }
                if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', initAttachmentPreview);
                else initAttachmentPreview();
            })();
        </script>
    @endpush

    {{-- Transfer department modal --}}
    @can('tickets.transfer')
        <div class="modal fade" id="transfer-modal" tabindex="-1" aria-labelledby="transfer-modal-label" aria-hidden="true">
            <div class="modal-dialog">
                <div class="modal-content">
                    <form method="POST" action="{{ route('admin.tickets.transfer', $ticket) }}">
                        @csrf
                        <div class="modal-header">
                            <h5 class="modal-title" id="transfer-modal-label">Transfer Department</h5>
                            <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                        </div>
                        <div class="modal-body">
                            <x-adminlte-select name="target_department" label="Target department" required>
                                <option value="">Select department...</option>
                                @foreach ($departments as $slug => $label)
                                    @if ($slug !== $ticket->department)
                                        <option value="{{ $slug }}" @selected(old('target_department') === $slug)>{{ $label }}</option>
                                    @endif
                                @endforeach
                            </x-adminlte-select>
                            <x-adminlte-select name="assigned_to" label="Assign to (optional)">
                                <option value="">Keep current / unassigned</option>
                                @foreach ($staff as $member)
                                    <option value="{{ $member->id }}" @selected((string) old('assigned_to') === (string) $member->id)>
                                        {{ $member->full_name }}
                                    </option>
                                @endforeach
                            </x-adminlte-select>
                            <small class="text-muted d-block mb-3">Assignee must be a member of the target department.</small>
                            <x-adminlte-textarea name="note" label="Note (optional)" rows="3"
                                                 placeholder="Reason for transfer...">{{ old('note') }}</x-adminlte-textarea>
                        </div>
                        <div class="modal-footer">
                            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                            <button type="submit" class="btn btn-primary">Transfer</button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    @endcan
@stop
