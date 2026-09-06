@extends('adminlte::page')

@section('title', 'Edit: ' . $template->name)

@push('adminlte_css')
<link href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/theme/material-darker.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/fold/foldgutter.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/dialog/dialog.css" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/display/fullscreen.css" rel="stylesheet">
<style>
.et-editor-wrap{border:1px solid var(--bs-border-color); border-radius:.5rem; overflow:hidden; background:var(--bs-body-bg);}
.et-editor-tabs{display:flex; flex-wrap:wrap; align-items:center; gap:.25rem; padding:.5rem .5rem 0; background:var(--bs-tertiary-bg); border-bottom:1px solid var(--bs-border-color);}
.et-editor-tabs > button[data-tab]{border:1px solid transparent; border-bottom:0; background:transparent; padding:.4rem .75rem; border-radius:.4rem .4rem 0 0; font-size:.85rem; font-weight:600; color:var(--bs-secondary-color);}
.et-editor-tabs > button[data-tab].active{background:var(--bs-body-bg); border-color:var(--bs-border-color); color:var(--bs-body-color);}
.et-var-chip{display:inline-flex; align-items:center; gap:.25rem; padding:.2rem .5rem; font-size:.78rem; border:1px solid var(--bs-border-color); border-radius:999px; background:var(--bs-tertiary-bg); cursor:pointer; user-select:none;}
.et-var-chip:hover{background:var(--bs-primary); color:#fff; border-color:var(--bs-primary);}
.et-preview-frame{width:100%; min-height:420px; border:0; background:#fff; transition:width .2s;}
/* Code toolbar */
.et-code-toolbar{display:flex; flex-wrap:wrap; align-items:center; gap:.3rem; padding:.45rem .5rem; border-bottom:1px solid var(--bs-border-color); background:var(--bs-body-bg);}
.et-code-toolbar .tb{border:1px solid var(--bs-border-color); background:var(--bs-tertiary-bg); border-radius:.35rem; padding:.22rem .55rem; font-size:.78rem; line-height:1.3; color:var(--bs-body-color); cursor:pointer; white-space:nowrap;}
.et-code-toolbar .tb:hover{background:var(--bs-secondary-bg);}
.et-code-toolbar .tb.on{background:var(--bs-primary); color:#fff; border-color:var(--bs-primary);}
.et-code-toolbar .sep{width:1px; height:20px; background:var(--bs-border-color); margin:0 .2rem;}
/* CodeMirror */
.CodeMirror{height:480px; font-family:ui-monospace, SFMono-Regular, "SF Mono", Menlo, Consolas, monospace; font-size:13px; line-height:1.6;}
.CodeMirror-fullscreen{position:fixed; top:0; left:0; right:0; bottom:0; height:auto; z-index:10500;}
.CodeMirror-gutters{border-right:1px solid var(--bs-border-color);}
.CodeMirror-activeline-background{background:rgba(74,134,232,.07);}
.cm-matchhighlight{background:rgba(255,213,79,.35);}
.CodeMirror-matchingtag{background:rgba(74,134,232,.20);}
/* Highlight template placeholders */
.cm-et-var{color:#7c3aed; background:rgba(124,58,237,.10); border-radius:3px; font-weight:700;}
.cm-s-material-darker .cm-et-var{color:#c792ea; background:rgba(199,146,234,.14);}
.cm-et-var-bad{color:#dc2626; background:rgba(220,38,38,.12); border-radius:3px; font-weight:700; text-decoration:underline wavy #dc2626;}
/* Autosave badge */
#autosaveBadge{display:none; font-size:.75rem; padding:.2rem .55rem; border-radius:999px; background:var(--bs-warning-bg-subtle,#fff3cd); color:var(--bs-warning-text,#664d03); border:1px solid var(--bs-warning-border-subtle,#ffc107);}
/* Viewport toggle */
.vp-btn{border:1px solid var(--bs-border-color); background:var(--bs-tertiary-bg); border-radius:.35rem; padding:.18rem .5rem; font-size:.78rem; cursor:pointer;}
.vp-btn.active{background:var(--bs-primary); color:#fff; border-color:var(--bs-primary);}
/* Status bar */
.et-statusbar{display:flex; flex-wrap:wrap; align-items:center; gap:.75rem; padding:.35rem .6rem; border-top:1px solid var(--bs-border-color); background:var(--bs-tertiary-bg); font-size:.75rem; color:var(--bs-secondary-color);}
.et-statusbar .ok{color:#16a34a;} .et-statusbar .bad{color:#dc2626;}
</style>
@endpush

@section('content_header')
    <div class="row align-items-center">
        <div class="col-sm-6"><h1 class="m-0">Edit Email Template</h1></div>
        <div class="col-sm-6">
            <ol class="breadcrumb float-sm-end mb-0">
                <li class="breadcrumb-item"><a href="{{ url('/') }}">{{ __('adminlte.home') }}</a></li>
                <li class="breadcrumb-item"><a href="{{ route('admin.email-templates.index') }}">Email Templates</a></li>
                <li class="breadcrumb-item active">{{ $template->name }}</li>
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
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    {{-- Top actions --}}
    <div class="d-flex flex-wrap gap-2 justify-content-between align-items-center mb-3">
        <div class="d-flex gap-2">
            <a href="{{ route('admin.email-templates.show', $template) }}" class="btn btn-outline-secondary btn-sm"><i class="bi bi-eye me-1"></i> View</a>
            <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#sendTestModal"><i class="bi bi-send me-1"></i> Send test</button>
        </div>
        <div class="d-flex gap-2">
            @if($isDefaultModified && $defaultTemplate)
                <button type="button" class="btn btn-outline-warning btn-sm" data-bs-toggle="modal" data-bs-target="#resetModal"><i class="bi bi-arrow-counterclockwise me-1"></i> Reset to default</button>
            @endif
            <div class="dropdown">
                <button class="btn btn-outline-secondary btn-sm dropdown-toggle" data-bs-toggle="dropdown"><i class="bi bi-collection me-1"></i> Load starter</button>
                <ul class="dropdown-menu dropdown-menu-end" style="max-height:320px; overflow:auto;">
                    @foreach($defaults as $key => $def)
                        <li><a class="dropdown-item small starter-item" href="#" data-name="{{ $key }}">{{ $key }} <span class="text-muted">— {{ Str::limit($def['subject'], 36) }}</span></a></li>
                    @endforeach
                </ul>
            </div>
        </div>
    </div>

    <div class="row g-3">
        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-envelope" title="Edit: {{ $template->name }}">
                <form method="POST" action="{{ route('admin.email-templates.update', $template) }}" id="etForm">
                    @csrf @method('PUT')

                    <div class="row">
                        <div class="col-md-6 mb-3">
                            <label class="form-label fw-semibold">Template Name <span class="text-danger">*</span></label>
                            <input type="text" name="name" value="{{ old('name', $template->name) }}" class="form-control" required>
                        </div>
                        <div class="col-md-3 mb-3">
                            <label class="form-label fw-semibold">Status</label>
                            <select name="status" class="form-select">
                                <option value="active" @selected(old('status', $template->status) === 'active')>Active</option>
                                <option value="inactive" @selected(old('status', $template->status) === 'inactive')>Inactive</option>
                            </select>
                        </div>
                        <div class="col-md-3 mb-3 d-flex align-items-end">
                            <div class="form-text">Slug: <code>{{ $template->name }}</code></div>
                        </div>
                    </div>

                    <div class="mb-3">
                        <label class="form-label fw-semibold d-flex justify-content-between">
                            Subject <span class="text-danger">*</span>
                            <small class="text-muted">click a variable to insert</small>
                        </label>
                        <input type="text" id="subjectInput" name="subject" value="{{ old('subject', $template->subject) }}" class="form-control" required placeholder="Invoice @{{invoice_no}} — @{{currency_symbol}}@{{total}} due @{{due_date}}">
                        <div class="mt-1 small text-muted">Rendered: <span id="subjectPreview" class="fw-semibold text-body"></span></div>
                    </div>

                    <div class="mb-2 d-flex justify-content-between align-items-center">
                        <label class="form-label fw-semibold mb-0">Body (HTML) <span class="text-danger">*</span></label>
                        <small class="text-muted">Ctrl+F find · Ctrl+Shift+F format · F11 fullscreen</small>
                    </div>

                    {{-- Editor --}}
                    <div class="et-editor-wrap mb-3">
                        <div class="et-editor-tabs">
                            <button type="button" data-tab="code" class="active"><i class="bi bi-code-slash me-1"></i> HTML Code</button>
                            <button type="button" data-tab="preview"><i class="bi bi-eye me-1"></i> Live preview</button>
                            <span class="ms-auto small text-muted d-flex align-items-center gap-2 pe-2">
                                <span id="autosaveBadge" title="Draft auto-saved locally"><i class="bi bi-clock-history"></i> Draft saved</span>
                            </span>
                        </div>

                        {{-- Code pane --}}
                        <div id="pane-code">
                            <div class="et-code-toolbar">
                                <button type="button" class="tb" id="tbFormat" title="Beautify / re-indent HTML (Ctrl+Shift+F)"><i class="bi bi-magic"></i> Format</button>
                                <button type="button" class="tb" id="tbFind" title="Find &amp; replace (Ctrl+F / Ctrl+Shift+R)"><i class="bi bi-search"></i> Find</button>
                                <div class="sep"></div>
                                <button type="button" class="tb" id="tbFoldAll" title="Fold all tags"><i class="bi bi-chevron-contract"></i> Fold</button>
                                <button type="button" class="tb" id="tbUnfoldAll" title="Unfold all"><i class="bi bi-chevron-expand"></i> Unfold</button>
                                <div class="sep"></div>
                                <div class="dropdown">
                                    <button type="button" class="tb dropdown-toggle" data-bs-toggle="dropdown" title="Insert an email HTML block"><i class="bi bi-puzzle"></i> Snippets</button>
                                    <ul class="dropdown-menu" style="min-width:210px;">
                                        <li><h6 class="dropdown-header">Layout</h6></li>
                                        <li><a class="dropdown-item small" href="#" data-snippet="doc">Full document skeleton</a></li>
                                        <li><a class="dropdown-item small" href="#" data-snippet="section">Section wrapper</a></li>
                                        <li><a class="dropdown-item small" href="#" data-snippet="two-col">Two-column row</a></li>
                                        <li><a class="dropdown-item small" href="#" data-snippet="hero">Hero / amount block</a></li>
                                        <li><hr class="dropdown-divider"></li>
                                        <li><h6 class="dropdown-header">Elements</h6></li>
                                        <li><a class="dropdown-item small" href="#" data-snippet="cta-pay">CTA — Pay now</a></li>
                                        <li><a class="dropdown-item small" href="#" data-snippet="cta-view">CTA — View invoice</a></li>
                                        <li><a class="dropdown-item small" href="#" data-snippet="divider">Divider line</a></li>
                                        <li><a class="dropdown-item small" href="#" data-snippet="table-items">Line-items table</a></li>
                                        <li><a class="dropdown-item small" href="#" data-snippet="footer">Footer block</a></li>
                                    </ul>
                                </div>
                                <button type="button" class="tb" id="tbCheckVars" title="Find placeholders that will not be replaced"><i class="bi bi-braces-asterisk"></i> Check vars</button>
                                <div class="sep"></div>
                                <button type="button" class="tb" id="tbWrap" title="Toggle word wrap"><i class="bi bi-text-wrap"></i> Wrap</button>
                                <button type="button" class="tb" id="tbTheme" title="Toggle dark / light editor theme"><i class="bi bi-moon-stars"></i> Dark</button>
                                <button type="button" class="tb" id="tbFontDown" title="Smaller font">A&minus;</button>
                                <button type="button" class="tb" id="tbFontUp" title="Larger font">A+</button>
                                <button type="button" class="tb" id="tbFullscreen" title="Fullscreen (F11 / Esc to exit)"><i class="bi bi-arrows-fullscreen"></i></button>
                            </div>
                            <textarea id="bodyTextarea" name="body" required>{{ old('body', $template->body) }}</textarea>
                            <div class="et-statusbar">
                                <span id="stPos">Ln 1, Col 1</span>
                                <span id="stChars"></span>
                                <span id="stLines"></span>
                                <span id="stVars"></span>
                                <span class="ms-auto" id="stMsg"></span>
                            </div>
                        </div>

                        {{-- Preview pane --}}
                        <div id="pane-preview" class="d-none" style="background:#f6f8fb; padding:12px;">
                            <div class="d-flex justify-content-between align-items-center mb-2 flex-wrap gap-1">
                                <small class="text-muted">Preview with sample invoice <code>INV-2026-00001</code> • Shyamolesh Ghosh</small>
                                <div class="d-flex align-items-center gap-2">
                                    <div class="btn-group btn-group-sm" id="vpToggleGroup">
                                        <button type="button" class="vp-btn" data-vp="375" title="Mobile (375px)">📱 375</button>
                                        <button type="button" class="vp-btn active" data-vp="600" title="Email (600px)">✉ 600</button>
                                        <button type="button" class="vp-btn" data-vp="full" title="Full width">🖥 Full</button>
                                    </div>
                                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="btnRefreshPreview">Refresh</button>
                                </div>
                            </div>
                            <div id="previewSubject" class="small fw-semibold mb-2 p-2 bg-white border rounded"></div>
                            <div class="d-flex justify-content-center" style="overflow-x:auto;">
                                <iframe id="previewFrame" class="et-preview-frame border rounded bg-white" style="width:600px;"></iframe>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex gap-2 align-items-center flex-wrap">
                        <a href="{{ route('admin.email-templates.show', $template) }}" class="btn btn-outline-secondary">Cancel</a>
                        <button type="submit" class="btn btn-primary"><i class="bi bi-check-lg me-1"></i> Save Changes</button>
                        <button type="button" class="btn btn-outline-warning btn-sm d-none" id="btnRestoreDraft" title="Restore unsaved local draft"><i class="bi bi-arrow-counterclockwise me-1"></i> Restore draft</button>
                        <button type="button" class="btn btn-outline-info ms-auto" id="btnPreviewFromForm"><i class="bi bi-eye me-1"></i> Update preview</button>
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
                                    <span class="et-var-chip" data-key="{{ $v['key'] }}" title="{{ $v['desc'] }} — {{ chr(123).chr(123).$v['key'].chr(125).chr(125) }}"><code>{{ chr(123).chr(123).$v['key'].chr(125).chr(125) }}</code> <span class="d-none d-xl-inline small">{{ $v['label'] }}</span></span>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
                <div class="small text-muted mt-2">Keys match <code>InvoiceEmailService::buildVariables()</code>. <a href="#" data-bs-toggle="modal" data-bs-target="#varsHelpModal">Full list</a></div>
            </x-adminlte-card>

            @if($defaultTemplate)
                <x-adminlte-card icon="bi bi-file-earmark-code" title="Default template">
                    <div class="small text-muted mb-1">Subject</div>
                    <code class="small d-block mb-2" style="word-break:break-all;">{{ $defaultTemplate['subject'] }}</code>
                    <div class="small text-muted mb-1">Body preview</div>
                    <div class="small p-2 bg-body-tertiary border rounded" style="max-height:220px; overflow:auto; white-space:pre-wrap;">{{ Str::limit($defaultTemplate['body'], 900) }}</div>
                </x-adminlte-card>
            @endif
        </div>
    </div>

    {{-- Reset modal --}}
    <div class="modal fade" id="resetModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Reset to default?</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">This will overwrite subject and body with the seeder default for <code>{{ $template->name }}</code>.</div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <form method="POST" action="{{ route('admin.email-templates.reset', $template) }}">@csrf<button type="submit" class="btn btn-warning">Reset now</button></form>
            </div>
        </div></div>
    </div>

    {{-- Send test modal --}}
    <div class="modal fade" id="sendTestModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Send test email</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="small text-muted">Renders current editor content with sample invoice and sends to an address.</p>
                <input type="email" id="testEmail" class="form-control" value="{{ auth()->user()->email }}" placeholder="you@example.com">
                <div id="testResult" class="small mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="btnSendTest"><i class="bi bi-send me-1"></i> Send</button>
            </div>
        </div></div>
    </div>

    {{-- Variable check result modal --}}
    <div class="modal fade" id="varCheckModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Placeholder check</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body" id="varCheckBody" style="max-height:60vh; overflow:auto;"></div>
                    <div class="modal-footer"><button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button></div>
        </div></div>
    </div>
    <button type="button" id="varCheckTrigger" class="d-none" data-bs-toggle="modal" data-bs-target="#varCheckModal"></button>
    <button type="button" id="varCheckTrigger" class="d-none" data-bs-toggle="modal" data-bs-target="#varCheckModal"></button>

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
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/lib/codemirror.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/xml/xml.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/javascript/javascript.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/css/css.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/mode/htmlmixed/htmlmixed.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/matchbrackets.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/closetag.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/edit/matchtags.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/fold/xml-fold.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/fold/foldcode.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/fold/foldgutter.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/fold/brace-fold.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/fold/comment-fold.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/dialog/dialog.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/search/searchcursor.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/search/search.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/search/jump-to-line.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/selection/active-line.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/comment/comment.js"></script>
<script src="https://cdn.jsdelivr.net/npm/codemirror@5.65.16/addon/display/fullscreen.js"></script>
<script src="https://cdn.jsdelivr.net/npm/js-beautify@1.15.1/js/lib/beautify-html.js"></script>
<script>
(function(){
const ob = String.fromCharCode(123,123), cb = String.fromCharCode(125,125);
const KNOWN_VARS = @json(collect($variableGroups)->flatMap(fn($vs) => collect($vs)->pluck('key'))->unique()->values());
const subjectInput   = document.getElementById('subjectInput');
const bodyTextarea   = document.getElementById('bodyTextarea');
const previewFrame   = document.getElementById('previewFrame');
const previewSubject = document.getElementById('previewSubject');
const subjectPreview = document.getElementById('subjectPreview');
const stPos = document.getElementById('stPos'), stChars = document.getElementById('stChars');
const stLines = document.getElementById('stLines'), stVars = document.getElementById('stVars'), stMsg = document.getElementById('stMsg');
let lastFocused = 'body';
let fontSize = 13;

subjectInput.addEventListener('focus', ()=> lastFocused = 'subject');

/* ── Highlight placeholders as an overlay mode ────────────────── */
const varOverlay = {
  token: function(stream){
    if(stream.match(ob)){
      let key = '';
      while(!stream.eol()){
        if(stream.match(cb)) {
          const clean = key.trim();
          return KNOWN_VARS.includes(clean) ? 'et-var' : 'et-var-bad';
        }
        key += stream.next();
      }
      return 'et-var-bad';
    }
    while(stream.next() != null && !stream.match(ob, false)){}
    return null;
  }
};

/* ── CodeMirror ───────────────────────────────────────────────── */
const cm = CodeMirror.fromTextArea(bodyTextarea, {
  mode: 'htmlmixed',
  theme: 'default',
  lineNumbers: true,
  lineWrapping: false,
  autoCloseTags: true,
  matchBrackets: true,
  matchTags: { bothTags: true },
  styleActiveLine: true,
  foldGutter: true,
  gutters: ['CodeMirror-linenumbers', 'CodeMirror-foldgutter'],
  indentUnit: 2,
  tabSize: 2,
  extraKeys: {
    'Ctrl-F': 'findPersistent',
    'Cmd-F': 'findPersistent',
    'Ctrl-H': 'replace',
    'Shift-Ctrl-R': 'replaceAll',
    'Alt-G': 'jumpToLine',
    'Ctrl-/': 'toggleComment',
    'Cmd-/': 'toggleComment',
    'Shift-Ctrl-F': function(){ formatHtml(); },
    'F11': function(c){ c.setOption('fullScreen', !c.getOption('fullScreen')); },
    'Esc': function(c){ if(c.getOption('fullScreen')) c.setOption('fullScreen', false); }
  }
});
cm.addOverlay(varOverlay);
cm.on('focus', ()=> lastFocused = 'body');
cm.on('cursorActivity', updateStatus);
cm.on('change', ()=>{ updateStatus(); refreshPreviewDebounced(); scheduleDraft(); });

function updateStatus(){
  const p = cm.getCursor();
  const val = cm.getValue();
  stPos.textContent   = `Ln ${p.line+1}, Col ${p.ch+1}`;
  stChars.textContent = `${val.length.toLocaleString()} chars`;
  stLines.textContent = `${cm.lineCount()} lines`;
  const found = val.match(new RegExp(ob + '\\s*([a-zA-Z0-9_]+)\\s*' + cb, 'g')) || [];
  const keys  = [...new Set(found.map(m => m.replace(/[{}]/g,'').trim()))];
  const bad   = keys.filter(k => !KNOWN_VARS.includes(k));
  stVars.innerHTML = bad.length
    ? `<span class="bad">${keys.length} vars · ${bad.length} unknown</span>`
    : `<span class="ok">${keys.length} vars ok</span>`;
}

/* ── Toolbar ──────────────────────────────────────────────────── */
function formatHtml(){
  if(typeof html_beautify !== 'function'){ flash('Formatter not loaded', true); return; }
  const cur = cm.getCursor();
  cm.setValue(html_beautify(cm.getValue(), {
    indent_size: 2, wrap_line_length: 0, preserve_newlines: true,
    max_preserve_newlines: 1, indent_inner_html: true, unformatted: ['a','strong','em','b','i','span','code']
  }));
  cm.setCursor(cur);
  flash('Formatted');
}
function flash(msg, bad){
  stMsg.textContent = msg;
  stMsg.className = bad ? 'ms-auto bad' : 'ms-auto ok';
  setTimeout(()=>{ stMsg.textContent=''; }, 2500);
}
document.getElementById('tbFormat').addEventListener('click', formatHtml);
document.getElementById('tbFind').addEventListener('click', ()=>{ cm.focus(); cm.execCommand('findPersistent'); });
document.getElementById('tbFoldAll').addEventListener('click', ()=>{
  for(let i=cm.firstLine(); i<=cm.lastLine(); i++) cm.foldCode({line:i, ch:0}, null, 'fold');
});
document.getElementById('tbUnfoldAll').addEventListener('click', ()=>{
  for(let i=cm.firstLine(); i<=cm.lastLine(); i++) cm.foldCode({line:i, ch:0}, null, 'unfold');
});
document.getElementById('tbWrap').addEventListener('click', function(){
  const on = !cm.getOption('lineWrapping');
  cm.setOption('lineWrapping', on);
  this.classList.toggle('on', on);
});
document.getElementById('tbTheme').addEventListener('click', function(){
  const dark = cm.getOption('theme') === 'default';
  cm.setOption('theme', dark ? 'material-darker' : 'default');
  this.classList.toggle('on', dark);
  this.innerHTML = dark ? '<i class="bi bi-sun"></i> Light' : '<i class="bi bi-moon-stars"></i> Dark';
  localStorage.setItem('et_cm_theme', dark ? 'material-darker' : 'default');
});
function setFont(px){
  fontSize = Math.min(20, Math.max(10, px));
  cm.getWrapperElement().style.fontSize = fontSize + 'px';
  cm.refresh();
  localStorage.setItem('et_cm_font', fontSize);
}
document.getElementById('tbFontUp').addEventListener('click', ()=> setFont(fontSize+1));
document.getElementById('tbFontDown').addEventListener('click', ()=> setFont(fontSize-1));
document.getElementById('tbFullscreen').addEventListener('click', ()=>{
  cm.setOption('fullScreen', !cm.getOption('fullScreen'));
  cm.focus();
});
// Restore editor prefs
(function(){
  const t = localStorage.getItem('et_cm_theme');
  if(t === 'material-darker') document.getElementById('tbTheme').click();
  const f = parseInt(localStorage.getItem('et_cm_font'));
  if(f) setFont(f);
})();

/* ── Placeholder checker ──────────────────────────────────────── */
document.getElementById('tbCheckVars').addEventListener('click', ()=>{
  const val  = cm.getValue() + ' ' + subjectInput.value;
  const rx   = new RegExp(ob + '\\s*([a-zA-Z0-9_]+)\\s*' + cb, 'g');
  const keys = [...new Set([...val.matchAll(rx)].map(m => m[1]))];
  const bad  = keys.filter(k => !KNOWN_VARS.includes(k));
  const good = keys.filter(k => KNOWN_VARS.includes(k));
  const body = document.getElementById('varCheckBody');
  let html = '';
  if(bad.length){
    html += '<div class="alert alert-danger py-2 small mb-3"><strong>' + bad.length + ' placeholder(s) will NOT be replaced</strong> — they are not produced by <code>buildVariables()</code> and will appear literally in the email.</div>';
    html += '<div class="d-flex flex-wrap gap-1 mb-3">' + bad.map(k => '<code class="text-danger border border-danger rounded px-2 py-1">' + ob + k + cb + '</code>').join('') + '</div>';
  } else {
    html += '<div class="alert alert-success py-2 small mb-3">All ' + keys.length + ' placeholders are valid and will be replaced at send time.</div>';
  }
  if(good.length){
    html += '<div class="small text-muted mb-1">Valid placeholders in use (' + good.length + ')</div>';
    html += '<div class="d-flex flex-wrap gap-1">' + good.map(k => '<code class="bg-body-tertiary border rounded px-2 py-1">' + ob + k + cb + '</code>').join('') + '</div>';
  }
  body.innerHTML = html;
  document.getElementById('varCheckTrigger').click();
});

/* ── Snippets ─────────────────────────────────────────────────── */
const SNIPPETS = {
'doc':`<!doctype html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
</head>
<body style="margin:0;padding:0;background:#f1f5f9;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif;">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;background:#f1f5f9;padding:32px 16px;">
<tr><td align="center">
<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;max-width:600px;background:#ffffff;border-radius:20px;overflow:hidden;">
<tr><td style="padding:32px 40px;">
  <p style="margin:0;font-size:15px;line-height:1.7;color:#334155;">Your content here</p>
</td></tr>
</table>
</td></tr>
</table>
</body>
</html>`,
'section':`<tr><td style="padding:24px 40px;">
  <p style="margin:0;font-size:15px;line-height:1.7;color:#334155;">Your content here</p>
</td></tr>`,
'two-col':`<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;">
<tr>
  <td width="50%" style="padding:12px 10px 12px 0;vertical-align:top;font-size:14px;color:#334155;">Left column</td>
  <td width="50%" style="padding:12px 0 12px 10px;vertical-align:top;font-size:14px;color:#334155;border-left:1px solid #e2e8f0;">Right column</td>
</tr>
</table>`,
'hero':`<tr><td style="background:${ob}primary_color${cb};padding:36px 40px;text-align:center;">
  <div style="font-size:11px;font-weight:700;letter-spacing:0.14em;text-transform:uppercase;color:rgba(255,255,255,0.65);margin-bottom:10px;">Amount Due</div>
  <div style="font-size:52px;font-weight:900;color:#ffffff;letter-spacing:-0.04em;line-height:1;">${ob}currency_symbol${cb}${ob}total${cb}</div>
  <div style="margin-top:14px;font-size:13px;color:rgba(255,255,255,0.75);">Invoice ${ob}invoice_no${cb} &nbsp;&middot;&nbsp; Due ${ob}due_date${cb}</div>
</td></tr>`,
'cta-pay':`<tr><td style="padding:28px 40px 16px;text-align:center;">
  <a href="${ob}pay_url${cb}" style="display:inline-block;background:${ob}primary_color${cb};color:#ffffff;text-decoration:none;font-size:16px;font-weight:800;padding:18px 56px;border-radius:12px;">Pay Invoice Now &rarr;</a>
</td></tr>`,
'cta-view':`<tr><td style="padding:0 40px 28px;text-align:center;">
  <a href="${ob}invoice_url${cb}" style="display:inline-block;color:#475569;text-decoration:none;font-size:13px;padding:10px 18px;border:1px solid #e2e8f0;border-radius:8px;">View Invoice</a>
</td></tr>`,
'divider':`<tr><td style="padding:0 40px;"><div style="border-top:1px solid #f1f5f9;"></div></td></tr>`,
'table-items':`<table role="presentation" cellpadding="0" cellspacing="0" style="width:100%;border:1px solid #e2e8f0;border-radius:14px;overflow:hidden;">
<tr style="background:#f8fafc;">
  <td style="padding:14px 18px;font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:0.08em;color:#64748b;width:44%;border-bottom:1px solid #e2e8f0;">Invoice No.</td>
  <td style="padding:14px 18px;font-size:14px;font-weight:700;color:#0f172a;border-bottom:1px solid #e2e8f0;">${ob}invoice_no${cb}</td>
</tr>
<tr>
  <td style="padding:12px 18px;font-size:12px;color:#64748b;border-bottom:1px solid #f1f5f9;">Invoice Date</td>
  <td style="padding:12px 18px;font-size:14px;color:#334155;border-bottom:1px solid #f1f5f9;">${ob}invoice_date${cb}</td>
</tr>
<tr style="background:#f0fdf4;">
  <td style="padding:16px 18px;font-size:13px;font-weight:800;color:#166534;">Balance Due</td>
  <td style="padding:16px 18px;font-size:20px;font-weight:900;color:#166534;">${ob}currency_symbol${cb}${ob}balance${cb}</td>
</tr>
</table>`,
'footer':`<tr><td style="background:#f8fafc;border-top:1px solid #e2e8f0;padding:24px 40px;text-align:center;">
  <div style="font-size:13px;font-weight:700;color:#334155;margin-bottom:4px;">${ob}company_name${cb}</div>
  <div style="font-size:12px;color:#64748b;line-height:1.7;">${ob}company_address${cb}<br>${ob}company_email${cb}${ob}company_phone${cb}</div>
  <div style="margin-top:10px;font-size:11px;color:#94a3b8;">${ob}footer_text${cb} &nbsp;&middot;&nbsp; <a href="${ob}app_url${cb}" style="color:#94a3b8;text-decoration:none;">${ob}app_url${cb}</a></div>
</td></tr>`
};
document.querySelectorAll('[data-snippet]').forEach(a=>{
  a.addEventListener('click', e=>{
    e.preventDefault();
    const s = SNIPPETS[a.dataset.snippet]; if(!s) return;
    cm.replaceSelection('\n' + s + '\n');
    cm.focus();
    flash('Snippet inserted');
  });
});

/* ── Variable chips ───────────────────────────────────────────── */
function insertAtCursor(el, text){
  const start = el.selectionStart, end = el.selectionEnd;
  el.value = el.value.substring(0,start) + text + el.value.substring(end);
  el.selectionStart = el.selectionEnd = start + text.length;
  el.focus();
  el.dispatchEvent(new Event('input', {bubbles:true}));
}
document.querySelectorAll('.et-var-chip').forEach(chip=>{
  chip.addEventListener('click', ()=>{
    const tag = ob + chip.dataset.key + cb;
    if(lastFocused === 'subject'){ insertAtCursor(subjectInput, tag); }
    else { cm.replaceSelection(tag); cm.focus(); }
    refreshPreviewDebounced();
  });
});
document.getElementById('varSearch')?.addEventListener('input', e=>{
  const q = e.target.value.toLowerCase();
  document.querySelectorAll('.var-group').forEach(g=>{
    let visible = 0;
    g.querySelectorAll('.et-var-chip').forEach(c=>{
      const show = !q || c.dataset.key.toLowerCase().includes(q) || c.textContent.toLowerCase().includes(q);
      c.style.display = show ? '' : 'none';
      if(show) visible++;
    });
    g.style.display = visible ? '' : 'none';
  });
});

/* ── Tabs ─────────────────────────────────────────────────────── */
document.querySelectorAll('.et-editor-tabs button[data-tab]').forEach(btn=>{
  btn.addEventListener('click', ()=>{
    document.querySelectorAll('.et-editor-tabs button[data-tab]').forEach(b=>b.classList.remove('active'));
    btn.classList.add('active');
    const tab = btn.dataset.tab;
    document.getElementById('pane-code').classList.toggle('d-none', tab!=='code');
    document.getElementById('pane-preview').classList.toggle('d-none', tab!=='preview');
    if(tab==='code') setTimeout(()=>cm.refresh(), 10);
    if(tab==='preview') refreshPreview();
  });
});

/* ── Starter loader ───────────────────────────────────────────── */
const DEFAULTS = @json($defaults);
document.querySelectorAll('.starter-item').forEach(a=>{
  a.addEventListener('click', e=>{
    e.preventDefault();
    const name = a.dataset.name;
    if(!confirm(`Load starter "${name}" into the editor? Unsaved changes will be overwritten.`)) return;
    const def = DEFAULTS[name];
    if(def){
      subjectInput.value = def.subject;
      cm.setValue(def.body);
      refreshPreview();
      flash('Loaded starter: ' + name);
    }
  });
});

/* ── Preview ──────────────────────────────────────────────────── */
let previewTimer=null;
function refreshPreviewDebounced(){ clearTimeout(previewTimer); previewTimer=setTimeout(refreshPreview, 400); }
async function refreshPreview(){
  const payload = { subject: subjectInput.value, body: cm.getValue() };
  try{
    const res = await fetch('{{ route('admin.email-templates.preview', $template) }}', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},
      body: JSON.stringify(payload)
    });
    const data = await res.json();
    previewSubject.textContent = data.subject || '';
    subjectPreview.textContent = data.subject || '';
    const doc = previewFrame.contentDocument || previewFrame.contentWindow.document;
    const containerW = previewFrame.parentElement.clientWidth || 540;
    const frameW = parseInt(previewFrame.style.width) || containerW;
    const scale = frameW > containerW ? containerW / frameW : 1;
    const scaleStyle = scale < 1
      ? `<style>body{transform:scale(${scale.toFixed(4)});transform-origin:top left;width:${(100/scale).toFixed(2)}%;overflow-x:hidden;}</style>`
      : '';
    doc.open(); doc.write(scaleStyle + (data.html || '<em style="color:#94a3b8">No preview</em>')); doc.close();
    const rawH = doc.body ? doc.body.scrollHeight : 420;
    previewFrame.style.height = Math.min(900, Math.round(rawH * scale) + 24) + 'px';
  }catch(err){
    previewSubject.textContent = 'Preview error: ' + err.message;
  }
}
subjectInput.addEventListener('input', ()=>{ refreshPreviewDebounced(); scheduleDraft(); });
document.getElementById('btnRefreshPreview')?.addEventListener('click', refreshPreview);
document.getElementById('btnPreviewFromForm')?.addEventListener('click', ()=>{
  document.querySelector('.et-editor-tabs button[data-tab="preview"]').click();
});
document.querySelectorAll('#vpToggleGroup .vp-btn').forEach(b=>{
  b.addEventListener('click', ()=>{
    document.querySelectorAll('#vpToggleGroup .vp-btn').forEach(x=>x.classList.remove('active'));
    b.classList.add('active');
    previewFrame.style.width = b.dataset.vp === 'full' ? '100%' : b.dataset.vp + 'px';
    refreshPreview();
  });
});

/* ── Auto-save draft ──────────────────────────────────────────── */
const DRAFT_KEY = 'et_draft_{{ $template->id }}';
const autosaveBadge = document.getElementById('autosaveBadge');
const btnRestoreDraft = document.getElementById('btnRestoreDraft');
let draftTimer=null;
function saveDraft(){
  localStorage.setItem(DRAFT_KEY, JSON.stringify({subject:subjectInput.value, body:cm.getValue(), ts:Date.now()}));
  if(autosaveBadge){ autosaveBadge.style.display='inline-flex'; setTimeout(()=>{ autosaveBadge.style.display='none'; }, 2200); }
}
function scheduleDraft(){ clearTimeout(draftTimer); draftTimer=setTimeout(saveDraft, 3000); }
(function(){
  const d = JSON.parse(localStorage.getItem(DRAFT_KEY)||'null');
  if(d && d.ts && btnRestoreDraft){
    btnRestoreDraft.classList.remove('d-none');
    btnRestoreDraft.title = `Restore draft from ${Math.round((Date.now()-d.ts)/60000)} min ago`;
  }
})();
btnRestoreDraft?.addEventListener('click', ()=>{
  const d = JSON.parse(localStorage.getItem(DRAFT_KEY)||'null');
  if(!d) return;
  if(!confirm(`Restore local draft from ${Math.round((Date.now()-d.ts)/60000)} min ago? Current editor content will be overwritten.`)) return;
  subjectInput.value = d.subject||'';
  cm.setValue(d.body||'');
  refreshPreview();
  btnRestoreDraft.classList.add('d-none');
});

/* ── Submit ───────────────────────────────────────────────────── */
document.getElementById('etForm')?.addEventListener('submit', ()=>{
  cm.save();                       // write CodeMirror content back to the textarea
  localStorage.removeItem(DRAFT_KEY);
});

/* ── Test send ────────────────────────────────────────────────── */
document.getElementById('btnSendTest')?.addEventListener('click', async ()=>{
  const email = document.getElementById('testEmail').value.trim();
  const result = document.getElementById('testResult');
  if(!email){ result.textContent='Enter an email'; return; }
  result.textContent='Sending…';
  try{
    const res = await fetch('{{ route('admin.email-templates.send-test', $template) }}', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},
      body: JSON.stringify({ email, subject: subjectInput.value, body: cm.getValue() })
    });
    const data = await res.json();
    result.textContent = res.ok ? '✓ '+data.message : '✗ '+(data.message||res.statusText);
    result.className = res.ok ? 'small mt-2 text-success' : 'small mt-2 text-danger';
  }catch(e){ result.textContent='✗ '+e.message; }
});

updateStatus();
refreshPreview();
})();
</script>
@endpush
