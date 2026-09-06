@extends('adminlte::page')

@section('title', 'New Email Template')

@push('adminlte_css')
<link href="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.snow.css" rel="stylesheet">
<style>
.et-editor-wrap{border:1px solid var(--bs-border-color); border-radius:.5rem; overflow:hidden; background:var(--bs-body-bg);}
.et-editor-tabs{display:flex; gap:.25rem; padding:.5rem .5rem 0; background:var(--bs-tertiary-bg); border-bottom:1px solid var(--bs-border-color);}
.et-editor-tabs button{border:1px solid transparent; border-bottom:0; background:transparent; padding:.4rem .75rem; border-radius:.4rem .4rem 0 0; font-size:.85rem; font-weight:600; color:var(--bs-secondary-color);}
.et-editor-tabs button.active{background:var(--bs-body-bg); border-color:var(--bs-border-color); color:var(--bs-body-color);}
.et-var-chip{display:inline-flex; align-items:center; gap:.25rem; padding:.2rem .5rem; font-size:.78rem; border:1px solid var(--bs-border-color); border-radius:999px; background:var(--bs-tertiary-bg); cursor:pointer; user-select:none;}
.et-var-chip:hover{background:var(--bs-primary); color:#fff; border-color:var(--bs-primary);}
.et-preview-frame{width:100%; min-height:420px; border:0; background:#fff;}
.et-toolbar{display:flex; flex-wrap:wrap; gap:.35rem; padding:.5rem; border-bottom:1px solid var(--bs-border-color); background:var(--bs-body-bg);}
.et-toolbar button{border:1px solid var(--bs-border-color); background:var(--bs-tertiary-bg); border-radius:.35rem; padding:.25rem .5rem; font-size:.8rem;}
.et-toolbar button:hover{background:var(--bs-secondary-bg);}
#quillEditor{height:380px; background:#fff;}
#quillEditor .ql-editor{font-family: ui-monospace, SFMono-Regular, Menlo, monospace; font-size:13px;}
</style>
@endpush

@section('content_header')
    <div class="row align-items-center">
        <div class="col-sm-6"><h1 class="m-0">New Email Template</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.email-templates.index') }}">Email Templates</a></li>
                <li class="breadcrumb-item active">New</li>
            </ol>
        </div>
    </div>
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul>
        </x-adminlte-alert>
    @endif

    {{-- Top actions --}}
    <div class="d-flex flex-wrap gap-2 justify-content-end mb-3">
        <div class="dropdown">
            <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-collection me-1"></i> Load starter</button>
            <ul class="dropdown-menu dropdown-menu-end" style="max-height:320px; overflow:auto;">
                @foreach($defaults as $key => $def)
                    <li><a class="dropdown-item small starter-item" href="#" data-name="{{ $key }}" data-subject="{{ $def['subject'] }}">{{ $key }} <span class="text-muted">— {{ Str::limit($def['subject'], 36) }}</span></a></li>
                @endforeach
            </ul>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-envelope" title="Create Email Template">
                <form method="POST" action="{{ route('admin.email-templates.store') }}" id="etForm">
                    @csrf

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Template Name (slug) <span class="text-danger">*</span></label>
                            <input type="text" name="name" value="{{ old('name') }}" class="form-control" required placeholder="e.g. invoice_created">
                            <div class="form-text">Lowercase, underscores. Used in code to trigger this template.</div>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" @selected(old('status', 'active') === 'active')>Active</option>
                                <option value="inactive" @selected(old('status') === 'inactive')>Inactive</option>
                            </select>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold d-flex justify-content-between">
                            Subject <span class="text-danger">*</span>
                            <small class="text-muted">click a variable to insert</small>
                        </label>
                        <input type="text" id="subjectInput" name="subject" value="{{ old('subject') }}" class="form-control" required placeholder="e.g. Invoice @{{invoice_no}} — @{{currency_symbol}}@{{total}} due @{{due_date}}">
                        <div class="mt-1 small text-muted">Rendered: <span id="subjectPreview" class="fw-semibold text-body"></span></div>
                    </div>

                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <label class="form-label fw-semibold mb-0">Body (HTML) <span class="text-danger">*</span></label>
                        <small class="text-muted">Variables insert at cursor • HTML tables supported</small>
                    </div>

                    {{-- Editor tabs --}}
                    <div class="et-editor-wrap mb-3">
                        <div class="et-editor-tabs">
                            <button type="button" data-tab="code" class="active"><i class="bi bi-code-slash me-1"></i> HTML Code</button>
                            <button type="button" data-tab="visual"><i class="bi bi-pencil-square me-1"></i> Visual</button>
                            <span class="ms-auto small text-muted d-flex align-items-center gap-2">
                                <span id="charCount"></span>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" id="btnWrapTable" title="Wrap selection in table">Table</button>
                                <button type="button" class="btn btn-xs btn-outline-secondary py-0 px-1" id="btnInsertButton" title="Insert CTA button">CTA</button>
                            </span>
                        </div>

                        {{-- Code pane --}}
                        <div id="pane-code">
                            <textarea id="bodyTextarea" name="body" rows="16" class="form-control border-0 rounded-0" style="font-family: ui-monospace, monospace; font-size:13px; min-height:380px;" required>{{ old('body') }}</textarea>
                        </div>
                        {{-- Visual pane (Quill) --}}
                        <div id="pane-visual" class="d-none">
                            <div id="quillToolbar" class="et-toolbar">
                                <button type="button" data-q="bold"><b>B</b></button>
                                <button type="button" data-q="italic"><i>I</i></button>
                                <button type="button" data-q="underline"><u>U</u></button>
                                <button type="button" data-q="h2">H2</button>
                                <button type="button" data-q="link">🔗</button>
                                <button type="button" data-q="list-ordered">1.</button>
                                <button type="button" data-q="list-bullet">•</button>
                                <button type="button" data-q="clean">✕</button>
                            </div>
                            <div id="quillEditor"></div>
                        </div>
                    </div>

                    <div class="d-flex gap-2">
                        <a href="{{ route('admin.email-templates.index') }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Create Template</button>
                    </div>
                </form>
            </x-adminlte-card>
        </div>

        <div class="col-lg-4">
            {{-- Variable palette --}}
            <x-adminlte-card icon="bi bi-braces" title="Variables — click to insert" class="sticky-top" style="top:1rem;">
                <div class="mb-2">
                    <input type="search" id="varSearch" class="form-control form-control-sm" placeholder="Filter variables...">
                </div>
                <div style="max-height:560px; overflow:auto;" id="varPalette">
                    @foreach($variableGroups as $group => $vars)
                        <div class="mb-3 var-group" data-group="{{ $group }}">
                            <div class="small fw-bold text-uppercase text-muted mb-1">{{ $group }}</div>
                            <div class="d-flex flex-wrap gap-1">
                                @foreach($vars as $v)
                                    <span class="et-var-chip" data-key="{{ $v['key'] }}" title="{{ $v['desc'] }} — {{ chr(123).chr(123).$v['key'].chr(125).chr(125) }}"><code>{{ chr(123).chr(123).$v['key'].chr(125).chr(125) }}</code></span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="small text-muted mt-2">Click a chip to insert at cursor. <a href="#" data-bs-toggle="modal" data-bs-target="#varsHelpModal">Full reference</a></div>
            </x-adminlte-card>
        </div>
    </div>

    <div class="modal fade" id="varsHelpModal" tabindex="-1"><div class="modal-dialog modal-lg"><div class="modal-content"><div class="modal-header"><h5 class="modal-title">All variables</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div><div class="modal-body" style="max-height:70vh; overflow:auto;">
        @foreach($variableGroups as $g => $vars)
            <h6 class="mt-3">{{ $g }}</h6>
            <table class="table table-sm"><thead><tr><th>Key</th><th>Label</th><th>Desc</th></tr></thead><tbody>
            @foreach($vars as $v)<tr><td><code>{{ chr(123).chr(123).$v['key'].chr(125).chr(125) }}</code></td><td>{{ $v['label'] }}</td><td class="text-muted small">{{ $v['desc'] }}</td></tr>@endforeach
            </tbody></table>
        @endforeach
    </div></div></div></div>
@stop

@push('js')
<script src="https://cdn.jsdelivr.net/npm/quill@2.0.3/dist/quill.js"></script>
<script>
(function(){
const ob = String.fromCharCode(123,123), cb = String.fromCharCode(125,125);
const subjectInput = document.getElementById('subjectInput');
const bodyTextarea = document.getElementById('bodyTextarea');
const charCount = document.getElementById('charCount');
let lastFocused = bodyTextarea;

subjectInput.addEventListener('focus', ()=> lastFocused = subjectInput);
bodyTextarea.addEventListener('focus', ()=> lastFocused = bodyTextarea);

// Tabs
document.querySelectorAll('.et-editor-tabs button[data-tab]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.querySelectorAll('.et-editor-tabs button').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const tab = btn.dataset.tab;
    document.getElementById('pane-code').classList.toggle('d-none', tab!=='code');
    document.getElementById('pane-visual').classList.toggle('d-none', tab!=='visual');
    if(tab==='visual') syncToQuill();
    if(tab==='code') syncToTextarea();
  });
});

// Quill
let quill = null;
function initQuill(){
  quill = new Quill('#quillEditor', { theme:'snow', modules:{ toolbar:false }});
  const html = bodyTextarea.value;
  if(html) quill.clipboard.dangerouslyPasteHTML(html);
  quill.on('text-change', ()=>{ charCount.textContent = quill.getLength()+' chars'; });
}
initQuill();
function syncToQuill(){
  quill.setContents([]); quill.clipboard.dangerouslyPasteHTML(bodyTextarea.value);
}
function syncToTextarea(){
  if(quill) bodyTextarea.value = quill.root.innerHTML;
}
document.getElementById('quillToolbar')?.addEventListener('click', (e)=>{
  const btn = e.target.closest('button[data-q]'); if(!btn||!quill) return;
  const q = btn.dataset.q;
  const range = quill.getSelection(true);
  if(q==='bold') quill.format('bold', !quill.getFormat(range).bold);
  else if(q==='italic') quill.format('italic', !quill.getFormat(range).italic);
  else if(q==='underline') quill.format('underline', !quill.getFormat(range).underline);
  else if(q==='h2') quill.format('header', quill.getFormat(range).header===2? false:2);
  else if(q==='list-ordered') quill.format('list','ordered');
  else if(q==='list-bullet') quill.format('list','bullet');
  else if(q==='clean') quill.removeFormat(range.index, range.length);
  else if(q==='link'){
    const url = prompt('URL:','https://');
    if(url) quill.format('link', url);
  }
});
document.getElementById('btnWrapTable')?.addEventListener('click', ()=>{
  const sel = getTextareaSelection(bodyTextarea);
  const wrapped = `<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #e2e8f0; border-radius:8px;"><tr><td style="padding:12px;">${sel.text||'Content'}</td></tr></table>`;
  insertAtCursor(bodyTextarea, wrapped); syncToQuill();
});
document.getElementById('btnInsertButton')?.addEventListener('click', ()=>{
  const btn = `<table role="presentation" cellpadding="0" cellspacing="0"><tr><td style="background:${ob}primary_color${cb}; border-radius:8px;"><a href="${ob}pay_url${cb}" style="display:inline-block; padding:12px 22px; color:#ffffff; text-decoration:none; font-weight:700;">Pay Invoice Now</a></td></tr></table>`;
  insertAtCursor(bodyTextarea, btn); syncToQuill();
});

// Variable chips
function insertAtCursor(el, text){
  const start = el.selectionStart, end = el.selectionEnd;
  const before = el.value.substring(0,start), after = el.value.substring(end);
  el.value = before + text + after;
  el.selectionStart = el.selectionEnd = start + text.length;
  el.focus();
  el.dispatchEvent(new Event('input', {bubbles:true}));
}
function insertIntoQuill(text){
  if(!quill) return false;
  const range = quill.getSelection(true);
  quill.insertText(range.index, text, 'user');
  quill.setSelection(range.index + text.length, 0, 'user');
  return true;
}
document.querySelectorAll('.et-var-chip').forEach(chip=>{
  chip.addEventListener('click', ()=>{
    const tag = ob + chip.dataset.key + cb;
    if(lastFocused===subjectInput){
      insertAtCursor(subjectInput, tag);
    } else {
      const visualActive = !document.getElementById('pane-visual').classList.contains('d-none');
      if(visualActive && quill){ insertIntoQuill(tag); syncToTextarea(); }
      else { insertAtCursor(bodyTextarea, tag); if(quill) syncToQuill(); }
    }
  });
});
document.getElementById('varSearch')?.addEventListener('input', e=>{
  const q = e.target.value.toLowerCase();
  document.querySelectorAll('.var-group').forEach(g=>{
    const chips = g.querySelectorAll('.et-var-chip');
    let visible=0;
    chips.forEach(c=>{
      const show = !q || c.dataset.key.toLowerCase().includes(q) || c.textContent.toLowerCase().includes(q);
      c.style.display = show?'':'none';
      if(show) visible++;
    });
    g.style.display = visible? '':'none';
  });
});

// Starter loader
document.querySelectorAll('.starter-item').forEach(a=>{
  a.addEventListener('click', e=>{
    e.preventDefault();
    const name = a.dataset.name;
    if(!confirm(`Load starter "${name}" into editor? Unsaved changes will be overwritten.`)) return;
    const defaults = @json($defaults);
    const def = defaults[name];
    if(def){
      subjectInput.value = def.subject;
      bodyTextarea.value = def.body;
      document.querySelector('input[name="name"]').value = name;
      syncToQuill();
    }
  });
});

bodyTextarea.addEventListener('input', ()=>{ charCount.textContent = bodyTextarea.value.length+' chars'; });
document.getElementById('etForm')?.addEventListener('submit', ()=>{
  if(!document.getElementById('pane-visual').classList.contains('d-none')) syncToTextarea();
});
charCount.textContent = bodyTextarea.value.length+' chars';

function getTextareaSelection(el){
  return { text: el.value.substring(el.selectionStart, el.selectionEnd), start: el.selectionStart, end: el.selectionEnd };
}
})();
</script>
@endpush
