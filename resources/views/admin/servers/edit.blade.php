@extends('adminlte::page')

@section('title', 'Edit Server — '.$server->name)

@section('content_header')
    <x-ui.page-header title="Edit Server" subtitle="Update server configuration" :breadcrumbs="[
        ['label' => __('adminlte.home'), 'url' => url('/')],
        ['label' => 'Servers', 'url' => route('admin.servers.index')],
        ['label' => $server->name, 'url' => route('admin.servers.show', $server)],
        ['label' => 'Edit', 'active' => true],
    ]" />
@stop

@section('content')
    @if ($errors->any())
        <x-adminlte-alert theme="danger" dismissible>
            <ul class="mb-0">
                @foreach ($errors->all() as $error)
                    <li>{{ $error }}</li>
                @endforeach
            </ul>
        </x-adminlte-alert>
    @endif

    {{-- todo-12 transport prefill (scope only): camel-aware value resolution --}}
    @php
        // todo-12 (scope only): camel-aware transport prefill value resolution.
        // Nested connection_meta.meta wins (snake+camel aware), then flat meta,
        // then api_url parse, then useSsl ? 5986 : 5985 default. String bools
        // normalized like ServerDetailViewModel. api_url / api_username /
        // api_key+password branches preserved (base inputs keep their own
        // old() fallbacks; resolved here for parity with the verifier).
        $transportPrefill = [];
        foreach (['api_url', 'api_username', 'api_key', 'host', 'port', 'use_ssl', 'verify_tls'] as $key) {
            // value resolution: old > server attribute mapping
            $serverVal = null;
            if ($key === 'api_url') {
                $serverVal = $server->api_url ?? $server->ip_address;
            } elseif ($key === 'api_username' || $key === 'username') {
                $serverVal = $server->api_username;
            } elseif ($key === 'api_key' || $key === 'password') {
                $serverVal = ''; // encrypted - never display
            } elseif (in_array($key, ['host','port','use_ssl','verify_tls'], true)) {
                // Hyper-V transport: nested meta.meta wins, snake+camel aware
                // (same rule as ServerDetailViewModel: nested-first normalized
                // lookup, then api_url parse, then useSsl ? 5986 : 5985 default).
                $meta = is_array($server->connection_meta) ? $server->connection_meta : [];
                $hv = (isset($meta['meta']) && is_array($meta['meta'])) ? $meta['meta'] : [];
                $normTransportKey = static fn ($k) => strtolower(str_replace('_', '', (string) $k));
                $findTransport = static function (array $arr, array $cands) use ($normTransportKey) {
                    $map = [];
                    foreach ($arr as $k => $v) {
                        if (! is_string($k)) { continue; }
                        $n = $normTransportKey($k);
                        if (! array_key_exists($n, $map)) { $map[$n] = $v; }
                    }
                    foreach ($cands as $c) {
                        $n = $normTransportKey($c);
                        if (array_key_exists($n, $map) && $map[$n] !== null && $map[$n] !== '') { return $map[$n]; }
                    }
                    return null;
                };
                $transportCands = match ($key) {
                    'host' => ['host'],
                    'port' => ['port'],
                    'use_ssl' => ['use_ssl','useSsl'],
                    'verify_tls' => ['verify_tls','verifyTls'],
                    default => [$key],
                };
                $serverVal = $findTransport($hv, $transportCands) ?? $findTransport($meta, $transportCands);
                // Normalize string bools like the ViewModel (accept "true"/"false", 0/1).
                if (($key === 'use_ssl' || $key === 'verify_tls') && is_string($serverVal)) {
                    $lowerTransport = strtolower($serverVal);
                    if (in_array($lowerTransport, ['1','true','yes'], true)) { $serverVal = true; }
                    elseif (in_array($lowerTransport, ['0','false','no'], true)) { $serverVal = false; }
                }
                if ($key === 'host' && ($serverVal === null || $serverVal === '')) {
                    if (is_string($server->api_url) && $server->api_url !== '' && str_contains($server->api_url, '://')) {
                        $serverVal = parse_url($server->api_url, PHP_URL_HOST) ?? $server->api_url;
                    } else {
                        $serverVal = $server->api_url ?? $server->ip_address;
                    }
                }
                if ($key === 'port' && $serverVal === null) {
                    if (is_string($server->api_url) && str_contains($server->api_url, '://')) {
                        $parsedPort = parse_url($server->api_url, PHP_URL_PORT);
                        $serverVal = is_numeric($parsedPort) ? (int) $parsedPort : null;
                    }
                }
                if ($key === 'port' && $serverVal === null) {
                    $rawSsl = $findTransport($hv, ['use_ssl','useSsl']) ?? $findTransport($meta, ['use_ssl','useSsl']);
                    if (is_string($rawSsl)) {
                        $lowerSsl = strtolower($rawSsl);
                        $rawSsl = in_array($lowerSsl, ['1','true','yes'], true) ? true : (in_array($lowerSsl, ['0','false','no'], true) ? false : $rawSsl);
                    }
                    $serverVal = ($rawSsl === true || $rawSsl === 1 || $rawSsl === '1') ? 5986 : 5985;
                }
            } else {
                $meta = is_array($server->connection_meta) ? $server->connection_meta : [];
                $serverVal = $meta[$key] ?? $server->getAttribute($key) ?? null;
            }
            $transportPrefill[$key] = old($key, $serverVal);
        }
        unset($key, $serverVal, $meta, $hv, $transportCands);
    @endphp

    <x-adminlte.partials.form-card
        icon="bi bi-server"
        title="Edit Server"
        :action="route('admin.servers.update', $server)"
        method="PUT"
        submit-label="Update Server"
        :cancel-url="route('admin.servers.show', $server)"
    >
        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="name" label="Name" placeholder="e.g. Web-01"
                                  value="{{ old('name', $server->name) }}" required />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="ip_address" label="IP address" placeholder="e.g. 192.168.1.10"
                                  value="{{ old('ip_address', $server->ip_address) }}" required />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-select name="panel_type" label="Panel type">
                    @foreach (['cpanel' => 'cPanel', 'plesk' => 'Plesk', 'directadmin' => 'DirectAdmin', 'custom' => 'Custom'] as $value => $label)
                        <option value="{{ $value }}" @selected(old('panel_type', $server->panel_type) === $value)>{{ $label }}</option>
                    @endforeach
                </x-adminlte-select>
            </div>
            <div class="col-md-6">
                <x-adminlte-select name="status" label="Status">
                    <option value="active" @selected(old('status', $server->status) === 'active')>Active</option>
                    <option value="inactive" @selected(old('status', $server->status) === 'inactive')>Inactive</option>
                </x-adminlte-select>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="api_url" label="API URL" placeholder="e.g. https://web01.example.com:2083"
                                  value="{{ old('api_url', $server->api_url) }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="api_username" label="API username" placeholder="Optional"
                                  value="{{ old('api_username', $server->api_username) }}" />
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <x-adminlte-input name="api_key" label="API key" placeholder="Leave blank to keep unchanged"
                                  value="{{ old('api_key', $server->api_key) }}" />
            </div>
            <div class="col-md-6">
                <x-adminlte-input name="max_accounts" type="number" min="0" step="1" label="Max accounts (0 = unlimited)"
                                  value="{{ old('max_accounts', $server->max_accounts) }}" />
            </div>
        </div>

        @if (($server->server_type ?? $server->panel_type ?? '') === 'hyperv')
            <hr class="my-3">
            <h6 class="fw-semibold mb-3">Transport connection</h6>
            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-input name="host" label="Host" placeholder="hv01.example.local"
                                      value="{{ $transportPrefill['host'] ?? '' }}" />
                </div>
                <div class="col-md-6">
                    <x-adminlte-input name="port" type="number" label="Port" placeholder="5985"
                                      value="{{ $transportPrefill['port'] ?? '' }}" />
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <x-adminlte-select name="use_ssl" label="Use SSL">
                        <option value="1" @selected(filter_var($transportPrefill['use_ssl'] ?? false, FILTER_VALIDATE_BOOLEAN))>Yes</option>
                        <option value="0" @selected(! filter_var($transportPrefill['use_ssl'] ?? false, FILTER_VALIDATE_BOOLEAN))>No</option>
                    </x-adminlte-select>
                </div>
                <div class="col-md-6">
                    <x-adminlte-select name="verify_tls" label="Verify TLS">
                        <option value="1" @selected(($transportPrefill['verify_tls'] ?? null) === null ? true : filter_var($transportPrefill['verify_tls'], FILTER_VALIDATE_BOOLEAN))>Yes</option>
                        <option value="0" @selected(($transportPrefill['verify_tls'] ?? null) !== null && ! filter_var($transportPrefill['verify_tls'], FILTER_VALIDATE_BOOLEAN))>No</option>
                    </x-adminlte-select>
                </div>
            </div>

            <div class="row">
                <div class="col-md-6">
                    <label for="field_password" class="form-label fw-medium">Password</label>
                    <input type="password" id="field_password" name="password" value="" class="form-control"
                           placeholder="Leave blank to keep existing" autocomplete="new-password">
                    <div class="form-text">Leave blank to keep existing.</div>
                </div>
            </div>
        @endif
    </x-adminlte.partials.form-card>
@stop
