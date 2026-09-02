{{-- Shared by create and edit. $department is a TicketDepartment (unsaved on create). --}}
<div class="row">
    <div class="col-md-5">
        <x-adminlte-input name="name" label="Name" placeholder="e.g. Sales"
                          value="{{ old('name', $department->name) }}" required />
    </div>
    <div class="col-md-3">
        @if ($department->exists)
            <div class="mb-3">
                <label class="form-label">Key</label>
                <input type="text" class="form-control" value="{{ $department->slug }}" disabled>
                <small class="form-text text-muted">Fixed — existing tickets are stored against it</small>
            </div>
        @else
            <x-adminlte-input name="slug" label="Key" placeholder="auto from name"
                              value="{{ old('slug') }}">
                <small class="form-text text-muted">Lowercase, no spaces. Cannot be changed later.</small>
            </x-adminlte-input>
        @endif
    </div>
    <div class="col-md-2">
        <x-adminlte-select name="enabled" label="Status">
            <option value="active" @selected(old('enabled', $department->enabled || ! $department->exists ? 'active' : 'inactive') === 'active')>Active</option>
            <option value="inactive" @selected(old('enabled', $department->enabled || ! $department->exists ? 'active' : 'inactive') === 'inactive')>Inactive</option>
        </x-adminlte-select>
        <small class="form-text text-muted">Inactive hides it from new tickets</small>
    </div>
    <div class="col-md-2">
        <x-adminlte-input name="sort_order" label="Sort Order" type="number" min="0"
                          value="{{ old('sort_order', $department->sort_order ?? 0) }}" />
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <x-adminlte-select name="allow_new_tickets" label="Open New Tickets By Email">
            <option value="yes" @selected(old('allow_new_tickets', ($department->allow_new_tickets ?? true) ? 'yes' : 'no') === 'yes')>Yes</option>
            <option value="no" @selected(old('allow_new_tickets', ($department->allow_new_tickets ?? true) ? 'yes' : 'no') === 'no')>No</option>
        </x-adminlte-select>
        <small class="form-text text-muted">No = replies only; unrecognised mail is held for review</small>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <x-adminlte-input name="email_address" label="Email Address" type="email"
                          placeholder="e.g. sales@example.com"
                          value="{{ old('email_address', $department->email_address) }}">
            <small class="form-text text-muted">Used as the Reply-To on this department's ticket mail</small>
        </x-adminlte-input>
    </div>
    <div class="col-md-3">
        <label class="form-label d-block">Default Department</label>
        <div class="form-check form-switch pt-1">
            <input type="hidden" name="is_default" value="no">
            <input type="checkbox" class="form-check-input" id="is_default" name="is_default" value="yes"
                   @checked(old('is_default', $department->is_default ? 'yes' : 'no') === 'yes')>
            <label class="form-check-label" for="is_default">Use as default</label>
        </div>
        <small class="form-text text-muted">New tickets fall back here when nothing else matches</small>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <label for="description" class="form-label">Description</label>
        <textarea name="description" id="description" rows="2"
                  class="form-control @error('description') is-invalid @enderror"
                  maxlength="1000">{{ old('description', $department->description) }}</textarea>
        @error('description')
            <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
        @enderror
        <small class="form-text text-muted">Shown to admins on the department list</small>
    </div>
    <div class="col-md-6">
        <label for="signature" class="form-label">Signature</label>
        <textarea name="signature" id="signature" rows="2"
                  class="form-control @error('signature') is-invalid @enderror"
                  maxlength="2000">{{ old('signature', $department->signature) }}</textarea>
        @error('signature')
            <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
        @enderror
        <small class="form-text text-muted">Appended to staff replies sent from this department</small>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        @php $selectedStaff = old('staff_ids', $department->exists ? $department->staff->pluck('id')->all() : []); @endphp
        <label for="staff_ids" class="form-label">Staff</label>
        <select name="staff_ids[]" id="staff_ids" multiple size="6"
                class="form-select @error('staff_ids') is-invalid @enderror @error('staff_ids.*') is-invalid @enderror">
            @foreach ($staffUsers as $staffUser)
                <option value="{{ $staffUser->id }}" @selected(in_array($staffUser->id, $selectedStaff))>
                    {{ trim($staffUser->first_name.' '.$staffUser->last_name) ?: $staffUser->email }}
                </option>
            @endforeach
        </select>
        @error('staff_ids')
            <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
        @enderror
        <small class="form-text text-muted">Users who can work tickets in this department. Ctrl/Cmd-click to select multiple.</small>
    </div>
</div>

<hr class="my-3">
<h5 class="mb-1">Incoming Mailbox</h5>
<p class="text-muted small">
    The mailbox this department's replies are collected from. Leave disabled to use the global
    <a href="{{ route('admin.settings.index', ['tab' => 'email']) }}">Settings &rsaquo; Email &rsaquo; Incoming Mail</a> configuration.
    Each department needs its <strong>own</strong> mailbox — an alias is not enough, and two departments
    sharing one mailbox imports every reply twice.
</p>

<div class="row">
    <div class="col-md-3">
        <x-adminlte-select name="imap_enabled" label="Use Own Mailbox">
            <option value="no" @selected(old('imap_enabled', $department->imap_enabled ? 'yes' : 'no') === 'no')>No</option>
            <option value="yes" @selected(old('imap_enabled', $department->imap_enabled ? 'yes' : 'no') === 'yes')>Yes</option>
        </x-adminlte-select>
    </div>
    <div class="col-md-5">
        <x-adminlte-input name="imap_host" label="IMAP Host" placeholder="mail.example.com"
                          value="{{ old('imap_host', $department->imap_host) }}" />
    </div>
    <div class="col-md-2">
        <x-adminlte-input name="imap_port" label="Port" type="number" min="1" max="65535"
                          value="{{ old('imap_port', $department->imap_port ?? 993) }}">
            <small class="form-text text-muted">993 SSL / 143 plain</small>
        </x-adminlte-input>
    </div>
    <div class="col-md-2">
        <x-adminlte-select name="imap_encryption" label="Encryption">
            @foreach (['ssl' => 'SSL', 'tls' => 'TLS', 'none' => 'None'] as $value => $label)
                <option value="{{ $value }}" @selected(old('imap_encryption', $department->imap_encryption ?? 'ssl') === $value)>{{ $label }}</option>
            @endforeach
        </x-adminlte-select>
    </div>
</div>

<div class="row">
    <div class="col-md-6">
        <x-adminlte-input name="imap_username" label="Username" placeholder="sales@example.com"
                          value="{{ old('imap_username', $department->imap_username) }}" />
    </div>
    <div class="col-md-6">
        <div class="mb-3">
            <label for="imap_password" class="form-label">Password</label>
            <input type="password" name="imap_password" id="imap_password" value=""
                   placeholder="{{ $department->exists && $department->imap_password ? 'Leave blank to keep current' : '' }}"
                   class="form-control @error('imap_password') is-invalid @enderror" autocomplete="new-password">
            @error('imap_password')
                <span class="invalid-feedback d-block" role="alert">{{ $message }}</span>
            @enderror
            <small class="form-text text-muted">Never shown back — leave blank to keep the stored one</small>
        </div>
    </div>
</div>

<div class="row">
    <div class="col-md-4">
        <x-adminlte-input name="imap_folder" label="Folder"
                          value="{{ old('imap_folder', $department->imap_folder ?: 'INBOX') }}">
            <small class="form-text text-muted">Usually INBOX</small>
        </x-adminlte-input>
    </div>
    <div class="col-md-4">
        <x-adminlte-select name="imap_validate_cert" label="Validate Certificate">
            <option value="yes" @selected(old('imap_validate_cert', ($department->imap_validate_cert ?? true) ? 'yes' : 'no') === 'yes')>Yes</option>
            <option value="no" @selected(old('imap_validate_cert', ($department->imap_validate_cert ?? true) ? 'yes' : 'no') === 'no')>No</option>
        </x-adminlte-select>
        <small class="form-text text-muted">Only turn off for a self-signed mail server</small>
    </div>
    <div class="col-md-4">
        <x-adminlte-select name="imap_delete_after_fetch" label="Delete After Fetch">
            <option value="no" @selected(old('imap_delete_after_fetch', $department->imap_delete_after_fetch ? 'yes' : 'no') === 'no')>No</option>
            <option value="yes" @selected(old('imap_delete_after_fetch', $department->imap_delete_after_fetch ? 'yes' : 'no') === 'yes')>Yes</option>
        </x-adminlte-select>
        <small class="form-text text-muted">No = leave read messages in the mailbox</small>
    </div>
</div>
