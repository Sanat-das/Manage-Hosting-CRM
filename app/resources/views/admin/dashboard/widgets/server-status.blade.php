@props(['servers' => []])

@php
    $value = fn ($row, $key, $default = null) => data_get($row, $key, $default);
    $serverId = fn ($server) => is_array($server) ? ($server['id'] ?? null) : ($server->id ?? null);
@endphp

@if (empty($servers))
    <p class="text-muted mb-0">No servers configured.</p>
@else
    <div class="table-responsive">
        <table class="table table-sm table-hover align-middle mb-0">
            <thead>
                <tr>
                    <th>Server</th>
                    <th>IP address</th>
                    <th>Panel</th>
                    <th>Status</th>
                    <th class="text-end">Accounts</th>
                </tr>
            </thead>
            <tbody>
                @foreach ($servers as $server)
                    <tr>
                        <td>
                            @if (Route::has('admin.servers.show') && $serverId($server))
                                <a href="{{ route('admin.servers.show', $serverId($server)) }}"><strong>{{ $value($server, 'name', '—') }}</strong></a>
                            @else
                                <strong>{{ $value($server, 'name', '—') }}</strong>
                            @endif
                        </td>
                        <td class="text-muted">{{ $value($server, 'ip_address', '—') }}</td>
                        <td>{{ ucfirst($value($server, 'panel_type', '—')) }}</td>
                        <td><span class="badge bg-{{ $value($server, 'status_color', 'secondary') }}">{{ ucfirst($value($server, 'status', '—')) }}</span></td>
                        <td class="text-end">{{ $value($server, 'hosting_count', $value($server, 'hosting_accounts_count', 0)) }}</td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
@endif
