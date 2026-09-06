@extends('adminlte::page')

@section('title', $template->name)

@section('content_header')
    <div class="row align-items-center">
        <div class="col-sm-6"><h1 class="m-0">{{ $template->name }}</h1></div>
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
    @if (session('success'))
        <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
    @endif

    <div class="d-flex flex-wrap gap-2 mb-3">
        <a href="{{ route('admin.email-templates.edit', $template) }}" class="btn btn-outline-primary btn-sm"><i class="bi bi-pencil me-1"></i> Edit</a>
        <button type="button" class="btn btn-outline-info btn-sm" data-bs-toggle="modal" data-bs-target="#sendTestModal"><i class="bi bi-send me-1"></i> Send test</button>
        <button type="button" class="btn btn-outline-danger btn-sm ms-auto" data-bs-toggle="modal" data-bs-target="#deleteModal"><i class="bi bi-trash me-1"></i> Delete</button>
    </div>

    <x-adminlte.partials.confirm-modal
        id="deleteModal"
        title="Delete email template"
        :message="'Delete ' . $template->name . '? This cannot be undone.'"
        :action="route('admin.email-templates.destroy', $template)"
        confirm-label="Delete template"
    />

    <div class="row g-3">
        <div class="col-lg-4">
            <x-adminlte-card icon="bi bi-info-circle" title="Details">
                <table class="table table-sm table-borderless mb-0">
                    <tr><th class="w-40 text-muted fw-normal">Name</th><td><strong>{{ $template->name }}</strong></td></tr>
                    <tr><th class="text-muted fw-normal">Status</th><td><x-adminlte.partials.status-badge :status="$template->status" /></td></tr>
                    <tr><th class="text-muted fw-normal">Subject</th><td class="small">{{ $template->subject }}</td></tr>
                    <tr><th class="text-muted fw-normal">Updated</th><td class="small">{{ $template->updated_at?->diffForHumans() }}</td></tr>
                </table>
            </x-adminlte-card>
        </div>

        <div class="col-lg-8">
            <x-adminlte-card icon="bi bi-eye" title="Rendered preview (sample data)">
                <div class="d-flex justify-content-between align-items-center mb-2">
                    <small class="text-muted">Sample invoice INV-2026-00001 · Shyamolesh Ghosh</small>
                    <button type="button" class="btn btn-sm btn-outline-secondary py-0" id="btnRefresh"><i class="bi bi-arrow-clockwise"></i> Refresh</button>
                </div>
                <div id="previewSubjectBadge" class="small fw-semibold p-2 bg-body-tertiary border rounded mb-2 text-truncate"></div>
                <iframe id="previewFrame" style="width:100%; min-height:480px; border:1px solid var(--bs-border-color); border-radius:.4rem; background:#fff;"></iframe>
                <div id="previewError" class="small text-danger mt-1 d-none"></div>
            </x-adminlte-card>
        </div>
    </div>

    {{-- Send test modal --}}
    <div class="modal fade" id="sendTestModal" tabindex="-1">
        <div class="modal-dialog"><div class="modal-content">
            <div class="modal-header"><h5 class="modal-title">Send test email</h5><button type="button" class="btn-close" data-bs-dismiss="modal"></button></div>
            <div class="modal-body">
                <p class="small text-muted">Renders this template with sample invoice data and sends to:</p>
                <input type="email" id="testEmail" class="form-control" value="{{ auth()->user()->email }}" placeholder="you@example.com">
                <div id="testResult" class="small mt-2"></div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
                <button type="button" class="btn btn-primary" id="btnSendTest"><i class="bi bi-send me-1"></i> Send</button>
            </div>
        </div></div>
    </div>
@stop

@push('js')
<script>
(function(){
async function loadPreview(){
  try{
    const res = await fetch('{{ route('admin.email-templates.preview', $template) }}', {
      method:'POST',
      headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},
      body: JSON.stringify({subject:'{{ addslashes($template->subject) }}', body: null})
    });
    const data = await res.json();
    document.getElementById('previewSubjectBadge').textContent = 'Subject: ' + (data.subject || '');
    const frame = document.getElementById('previewFrame');
    const doc = frame.contentDocument || frame.contentWindow.document;
    const containerW = frame.parentElement.clientWidth || 540;
    const frameW = frame.clientWidth || containerW;
    const scale = frameW > containerW ? containerW / frameW : 1;
    const scaleStyle = scale < 1
      ? `<style>body{transform:scale(${scale.toFixed(4)});transform-origin:top left;width:${(100/scale).toFixed(2)}%;overflow-x:hidden;}</style>`
      : '';
    doc.open(); doc.write(scaleStyle + (data.html || '<p style="color:#94a3b8;padding:16px">No HTML body.</p>')); doc.close();
    setTimeout(()=>{ frame.style.height = Math.min(900, Math.round((doc.body.scrollHeight||420) * scale) + 24) + 'px'; }, 200);
    document.getElementById('previewError').classList.add('d-none');
  }catch(e){
    document.getElementById('previewError').textContent = 'Preview error: '+e.message;
    document.getElementById('previewError').classList.remove('d-none');
  }
}
loadPreview();
document.getElementById('btnRefresh')?.addEventListener('click', loadPreview);

document.getElementById('btnSendTest')?.addEventListener('click', async ()=>{
  const email = document.getElementById('testEmail').value.trim();
  const result = document.getElementById('testResult');
  if(!email){ result.textContent='Enter an email'; return; }
  result.textContent='Sending…';
  try{
    const res = await fetch('{{ route('admin.email-templates.send-test', $template) }}', {
      method:'POST', headers:{'Content-Type':'application/json','X-CSRF-TOKEN':'{{ csrf_token() }}','Accept':'application/json'},
      body: JSON.stringify({ email })
    });
    const data = await res.json();
    result.textContent = res.ok ? '✓ '+data.message : '✗ '+(data.message||res.statusText);
    result.className = res.ok ? 'small mt-2 text-success' : 'small mt-2 text-danger';
  }catch(e){ result.textContent='✗ '+e.message; }
});
})();
</script>
@endpush
