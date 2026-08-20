@extends('install.layout')

@section('content')
    <div class="section">
        <div class="section-head">
            <h2>System requirements</h2>
            <span class="hint">{{ collect($checks)->filter(fn ($check) => $check['passed'])->count() }}/{{ count($checks) }} passed</span>
        </div>
        <ul class="checks">
            @foreach ($checks as $check)
                <li>
                    <span class="dot {{ $check['passed'] ? 'ok' : 'fail' }}">{{ $check['passed'] ? '✓' : '✕' }}</span>
                    <span class="name">{{ $check['name'] }}</span>
                    <span class="detail">{{ $check['detail'] }}</span>
                </li>
            @endforeach
        </ul>
        @unless ($canProceed)
            <p class="note">Fix the failed checks above (PHP version, extensions, directory permissions) before continuing. Database connectivity is re-tested when you submit the form.</p>
        @endunless
    </div>

    <div class="section">
        <form method="POST" action="{{ route('install.run') }}" id="install-form">
            @csrf

            <h2>Application</h2>
            <div class="grid" style="margin-top:12px">
                <div class="field full">
                    <label for="app_name">Application name</label>
                    <input id="app_name" name="app_name" value="{{ old('app_name', $defaults['app_name']) }}" required maxlength="60">
                    @error('app_name')<span class="err">{{ $message }}</span>@enderror
                </div>
            </div>

            <h2>Database</h2>
            <div class="grid" style="margin-top:12px">
                <div class="field">
                    <label for="db_host">Host</label>
                    <input id="db_host" name="db_host" value="{{ old('db_host', $defaults['db_host']) }}" required>
                    @error('db_host')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="db_port">Port</label>
                    <input id="db_port" name="db_port" value="{{ old('db_port', $defaults['db_port']) }}" required>
                    @error('db_port')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="db_database">Database name</label>
                    <input id="db_database" name="db_database" value="{{ old('db_database', $defaults['db_database']) }}" required>
                    @error('db_database')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="db_username">Username</label>
                    <input id="db_username" name="db_username" value="{{ old('db_username', $defaults['db_username']) }}" required>
                    @error('db_username')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="field full">
                    <label for="db_password">Password</label>
                    <input id="db_password" name="db_password" type="password" value="{{ old('db_password', $defaults['db_password']) }}" autocomplete="off">
                    @error('db_password')<span class="err">{{ $message }}</span>@enderror
                </div>
            </div>

            <h2>Administrator account</h2>
            <div class="grid" style="margin-top:12px">
                <div class="field">
                    <label for="first_name">First name</label>
                    <input id="first_name" name="first_name" value="{{ old('first_name') }}" required maxlength="100">
                    @error('first_name')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="last_name">Last name</label>
                    <input id="last_name" name="last_name" value="{{ old('last_name') }}" required maxlength="100">
                    @error('last_name')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="field full">
                    <label for="email">Email address</label>
                    <input id="email" name="email" type="email" value="{{ old('email') }}" required maxlength="255">
                    @error('email')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="password">Password (min. 8 characters)</label>
                    <input id="password" name="password" type="password" required minlength="8" autocomplete="new-password">
                    @error('password')<span class="err">{{ $message }}</span>@enderror
                </div>
                <div class="field">
                    <label for="password_confirmation">Confirm password</label>
                    <input id="password_confirmation" name="password_confirmation" type="password" required autocomplete="new-password">
                </div>
            </div>

            <button type="submit" class="btn" id="install-btn" @unless($canProceed) disabled @endunless>
                Install application
            </button>
        </form>
    </div>

    <script>
        document.getElementById('install-form').addEventListener('submit', function () {
            var btn = document.getElementById('install-btn');
            btn.disabled = true;
            btn.textContent = 'Installing…';
        });
    </script>
@endsection
