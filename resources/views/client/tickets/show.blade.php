@extends('adminlte::page')

@section('title', $ticket->ticket_no)

@section('content_header')
    <div class="row">
        <div class="col-sm-6"><h1 class="m-0">Ticket {{ $ticket->ticket_no }}</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('client.tickets.index') }}">Tickets</a></li>
                <li class="breadcrumb-item active">{{ $ticket->ticket_no }}</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    <div class="row">
        <div class="col-lg-8">
            {{-- Ticket header --}}
            <x-adminlte-card icon="bi bi-life-preserver" title="{{ $ticket->subject }}">
                <div class="d-flex justify-content-between mb-2">
                    <div>
                        <x-adminlte.partials.status-badge :status="$ticket->priority" />
                        <span class="text-muted ms-2">{{ \App\Services\TicketService::departmentLabel($ticket->department) ?: 'General' }}</span>
                    </div>
                    <div class="text-muted small">Created {{ $ticket->created_at?->format('M j, Y H:i') }}</div>
                </div>

                {{-- Thread --}}
                @forelse ($ticket->replies->sortByDesc('created_at') as $reply)
                    @php
                        // Bcc is deliberately never rendered here (or anywhere client-facing):
                        // showing a recipient who else received the mail defeats the entire
                        // point of blind-copying them. Only staff (admin view) sees it.
                        $sanitizedHtml = \App\Support\TicketHtmlSanitizer::sanitize($reply->html_body);
                    @endphp
                    <div class="border rounded p-3 mb-3 {{ $reply->is_staff ? 'border-primary bg-light' : '' }}">
                        <div class="d-flex justify-content-between mb-1">
                            <strong>{{ $reply->is_staff ? 'Staff' : 'You' }}</strong>
                            <small class="text-muted">{{ $reply->created_at?->format('M j, H:i') }}</small>
                        </div>

                        @if ($reply->to || $reply->cc)
                            <div class="mb-2 small">
                                @foreach (['To' => $reply->to, 'Cc' => $reply->cc] as $label => $addresses)
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

                        @if ($sanitizedHtml)
                            <div class="mb-0">{!! $sanitizedHtml !!}</div>
                        @else
                            <div class="mb-0" style="white-space: pre-wrap;">{{ $reply->message }}</div>
                        @endif

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
                                            $previewUrl = route('client.tickets.attachments.show', [$ticket->id, $attachment]);
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
                                             style="min-width: 210px; max-width: 300px; cursor: {{ $isPreviewable ? 'pointer' : 'default' }};"
                                             @if($isPreviewable) data-preview-url="{{ $previewUrl }}" data-preview-mime="{{ $mime }}" data-filename="{{ $attachment->filename }}" data-download-url="{{ $downloadUrl }}" @endif>
                                            <div class="flex-shrink-0 d-flex align-items-center justify-content-center bg-light border rounded overflow-hidden" style="width: 40px; height: 40px;">
                                                @if($isImage)
                                                    <img src="{{ $previewUrl }}" alt="{{ $attachment->filename }}" loading="lazy" style="width:100%; height:100%; object-fit: cover;">
                                                @else
                                                    <i class="bi {{ $icon }} fs-5"></i>
                                                @endif
                                            </div>
                                            <div class="flex-grow-1 min-w-0" style="min-width:0;">
                                                <div class="text-truncate fw-medium small" title="{{ $attachment->filename }}" style="max-width: 140px;">{{ $attachment->filename }}</div>
                                                <div class="small text-muted text-truncate">{{ $sizeLabel ?? strtoupper($ext) }}</div>
                                            </div>
                                            <div class="d-flex gap-1 flex-shrink-0">
                                                @if($isPreviewable)
                                                    <button type="button" class="btn btn-sm btn-light border attachment-preview-btn" data-preview-url="{{ $previewUrl }}" data-preview-mime="{{ $mime }}" data-filename="{{ $attachment->filename }}" data-download-url="{{ $downloadUrl }}" title="Preview"><i class="bi bi-eye"></i></button>
                                                @endif
                                                <a href="{{ $downloadUrl }}" class="btn btn-sm btn-light border" title="Download" download><i class="bi bi-download"></i></a>
                                            </div>
                                        </div>
                                    @endforeach
                                </div>
                            </div>
                        @endif
                    </div>
                @empty
                    <p class="text-muted">No replies yet.</p>
                @endforelse
            </x-adminlte-card>

            {{-- Reply form --}}
            @if (!in_array($ticket->status, ['closed']))
                <x-adminlte-card icon="bi bi-reply" title="Reply">
                    <form method="POST" action="{{ route('client.tickets.reply', $ticket) }}" enctype="multipart/form-data">
                        @csrf
                        <x-adminlte-textarea name="message" rows="4" placeholder="Type your reply..." required>{{ old('message') }}</x-adminlte-textarea>
                        <div class="mb-3">
                            <label for="client-reply-attachments" class="form-label">Attachments <span class="text-muted small">(up to 10 files, 25 MB each)</span></label>
                            <input type="file" name="attachments[]" id="client-reply-attachments" class="form-control" multiple accept="*/*">
                            <div id="client-attachments-list" class="mt-2 d-flex flex-column gap-1"></div>
                            <small class="text-muted">You can select multiple files at once or drag & drop.</small>
                        </div>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-send me-1"></i> Send Reply</button>
                    </form>
                </x-adminlte-card>
                @push('js')
                <script>
                    (function(){
                        function initClientAttachments(){
                            var input=document.getElementById('client-reply-attachments');
                            var list=document.getElementById('client-attachments-list');
                            if(!input||!list) return;
                            function fmt(b){ if(b>=1048576) return (b/1048576).toFixed(1)+' MB'; if(b>=1024) return (b/1024).toFixed(1)+' KB'; return b+' B'; }
                            function render(){
                                list.innerHTML='';
                                if(!input.files||!input.files.length) return;
                                Array.from(input.files).forEach(function(f,i){
                                    var row=document.createElement('div');
                                    row.className='d-flex align-items-center gap-2 p-2 bg-light border rounded';
                                    var ext=f.name.split('.').pop().toLowerCase();
                                    var icon='bi-file-earmark';
                                    if(f.type.startsWith('image/')) icon='bi-file-earmark-image text-success';
                                    else if(f.type==='application/pdf'||ext==='pdf') icon='bi-file-earmark-pdf text-danger';
                                    else if(['zip','rar','7z'].includes(ext)) icon='bi-file-earmark-zip text-warning';
                                    row.innerHTML='<i class="bi '+icon+'"></i><span class="flex-grow-1 text-truncate small" title="'+f.name+'">'+f.name+'</span><span class="text-muted small">'+fmt(f.size)+'</span><button type="button" class="btn btn-sm btn-outline-danger py-0 px-1" data-idx="'+i+'"><i class="bi bi-x"></i></button>';
                                    list.appendChild(row);
                                });
                            }
                            input.addEventListener('change', function(){
                                var incoming=Array.from(input.files);
                                if(!incoming.length){ render(); return; }
                                var existing=Array.from(input._accumulated||[]);
                                var merged=existing.slice();
                                incoming.forEach(function(f){
                                    var dup=merged.some(function(g){ return g.name===f.name&&g.size===f.size&&g.lastModified===f.lastModified; });
                                    if(!dup) merged.push(f);
                                });
                                if(merged.length>10){ alert('Maximum 10 files allowed.'); merged=merged.slice(0,10); }
                                var dt=new DataTransfer();
                                merged.forEach(function(f){ dt.items.add(f); });
                                input.files=dt.files;
                                input._accumulated=merged;
                                render();
                            });
                            list.addEventListener('click', function(e){
                                var btn=e.target.closest('button[data-idx]');
                                if(!btn) return;
                                var idx=parseInt(btn.getAttribute('data-idx'),10);
                                var dt=new DataTransfer();
                                Array.from(input.files).forEach(function(f,j){ if(j!==idx) dt.items.add(f); });
                                input.files=dt.files;
                                input._accumulated=Array.from(dt.files);
                                render();
                            });
                            var dz=input.closest('.mb-3')||input.parentElement;
                            if(dz){
                                ['dragenter','dragover'].forEach(function(ev){ dz.addEventListener(ev, function(e){ e.preventDefault(); input.classList.add('border-primary'); }); });
                                ['dragleave','drop'].forEach(function(ev){ dz.addEventListener(ev, function(e){ input.classList.remove('border-primary'); }); });
                                dz.addEventListener('drop', function(e){
                                    e.preventDefault();
                                    if(!e.dataTransfer||!e.dataTransfer.files.length) return;
                                    var dt=new DataTransfer();
                                    Array.from(input.files).forEach(function(f){ dt.items.add(f); });
                                    Array.from(e.dataTransfer.files).forEach(function(f){ dt.items.add(f); });
                                    if(dt.files.length>10){ alert('Maximum 10 files allowed.'); var dt2=new DataTransfer(); Array.from(dt.files).slice(0,10).forEach(function(f){ dt2.items.add(f); }); input.files=dt2.files; } else input.files=dt.files;
                                    render();
                                });
                            }
                        }
                        if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', initClientAttachments); else initClientAttachments();
                    })();
                </script>
                @endpush
            @endif
        </div>

    {{-- Attachment preview modal --}}
    <div class="modal fade" id="attachmentPreviewModal" tabindex="-1" aria-hidden="true">
        <div class="modal-dialog modal-xl modal-dialog-centered">
            <div class="modal-content">
                <div class="modal-header py-2">
                    <h6 class="modal-title text-truncate me-2" id="attachmentPreviewTitle" style="max-width:60%;"></h6>
                    <div class="ms-auto d-flex align-items-center gap-2">
                        <a id="attachmentPreviewDownload" href="#" class="btn btn-sm btn-primary" download><i class="bi bi-download me-1"></i>Download</a>
                        <a id="attachmentPreviewOpen" href="#" target="_blank" rel="noopener" class="btn btn-sm btn-outline-secondary"><i class="bi bi-box-arrow-up-right"></i></a>
                        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
                    </div>
                </div>
                <div class="modal-body p-0 bg-dark d-flex align-items-center justify-content-center" style="min-height:320px; max-height:78vh; overflow:auto;">
                    <img id="attachmentPreviewImage" src="" alt="" style="max-width:100%; max-height:74vh; object-fit:contain; display:none;">
                    <iframe id="attachmentPreviewFrame" src="" style="width:100%; height:74vh; border:0; display:none; background:white;"></iframe>
                    <div id="attachmentPreviewFallback" class="text-white text-center p-4" style="display:none;"><i class="bi bi-file-earmark fs-1 d-block mb-2"></i><span>Preview not available.</span></div>
                </div>
            </div>
        </div>
    </div>

    @push('css')
        <style>
            .attachment-card { transition: box-shadow .15s, border-color .15s; }
            .attachment-card:hover { border-color: #adb5bd !important; box-shadow: 0 1px 6px rgba(0,0,0,.08); }
            .attachment-card.attachment-previewable:hover { border-color: #0d6efd !important; }
        </style>
    @endpush
    @push('js')
        <script>
            (function(){
                function initAttachmentPreview(){
                    var modalEl = document.getElementById('attachmentPreviewModal');
                    if (!modalEl) return;
                    var bsModal = (typeof bootstrap !== 'undefined' && bootstrap.Modal) ? new bootstrap.Modal(modalEl) : null;
                    var titleEl = document.getElementById('attachmentPreviewTitle');
                    var imgEl = document.getElementById('attachmentPreviewImage');
                    var frameEl = document.getElementById('attachmentPreviewFrame');
                    var fallbackEl = document.getElementById('attachmentPreviewFallback');
                    var dlEl = document.getElementById('attachmentPreviewDownload');
                    var openEl = document.getElementById('attachmentPreviewOpen');
                    function openPreview(url, mime, filename, downloadUrl){
                        titleEl.textContent = filename || '';
                        dlEl.href = downloadUrl || url + '?download=1';
                        dlEl.setAttribute('download', filename || '');
                        openEl.href = url;
                        imgEl.style.display='none'; frameEl.style.display='none'; fallbackEl.style.display='none';
                        imgEl.removeAttribute('src'); frameEl.removeAttribute('src');
                        var m=(mime||'').toLowerCase();
                        if(m.startsWith('image/') && m!=='image/svg+xml'){ imgEl.src=url; imgEl.style.display='block'; }
                        else if(m==='application/pdf' || m.startsWith('text/')){ frameEl.src=url; frameEl.style.display='block'; }
                        else { fallbackEl.style.display='block'; }
                        if(bsModal) bsModal.show();
                        else { modalEl.style.display='block'; modalEl.classList.add('show'); document.body.classList.add('modal-open');
                            var bd=document.createElement('div'); bd.className='modal-backdrop fade show'; bd.id='attachment-preview-backdrop'; document.body.appendChild(bd);
                            bd.addEventListener('click', closeFallback);
                        }
                    }
                    function closeFallback(){ modalEl.style.display='none'; modalEl.classList.remove('show'); document.body.classList.remove('modal-open'); var bd=document.getElementById('attachment-preview-backdrop'); if(bd) bd.remove(); imgEl.removeAttribute('src'); frameEl.removeAttribute('src'); }
                    document.addEventListener('click', function(e){
                        var btn=e.target.closest('.attachment-preview-btn');
                        if(btn){ e.preventDefault(); e.stopPropagation(); openPreview(btn.dataset.previewUrl, btn.dataset.previewMime, btn.dataset.filename, btn.dataset.downloadUrl); return; }
                        var card=e.target.closest('.attachment-previewable');
                        if(card && !e.target.closest('a') && !e.target.closest('button')){ e.preventDefault(); openPreview(card.dataset.previewUrl, card.dataset.previewMime, card.dataset.filename, card.dataset.downloadUrl); }
                        if(e.target.closest('[data-bs-dismiss="modal"]') && e.target.closest('#attachmentPreviewModal') && !bsModal){ e.preventDefault(); closeFallback(); }
                    });
                    modalEl.addEventListener('hidden.bs.modal', function(){ imgEl.removeAttribute('src'); frameEl.removeAttribute('src'); });
                    modalEl.addEventListener('click', function(e){ if(e.target===modalEl && !bsModal) closeFallback(); });
                }
                if(document.readyState==='loading') document.addEventListener('DOMContentLoaded', initAttachmentPreview); else initAttachmentPreview();
            })();
        </script>
    @endpush

        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-info-circle" title="Ticket Info">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="text-muted">Status</th>
                        <td>
                            <x-adminlte.partials.status-badge :status="$ticket->status" />
                        </td>
                    </tr>
                    <tr><th class="text-muted">Priority</th><td>{{ ucfirst($ticket->priority) }}</td></tr>
                    <tr><th class="text-muted">Department</th><td>{{ \App\Services\TicketService::departmentLabel($ticket->department) ?: 'General' }}</td></tr>
                    <tr><th class="text-muted">Assigned to</th><td>{{ $ticket->assignedTo?->full_name ?? 'Unassigned' }}</td></tr>
                    <tr><th class="text-muted">Last reply</th><td>{{ $ticket->last_reply_at?->format('M j, H:i') ?? '—' }}</td></tr>
                </table>
            </x-adminlte-card>
        </div>
    </div>
@stop
