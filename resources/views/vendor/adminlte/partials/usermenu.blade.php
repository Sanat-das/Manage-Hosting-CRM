@php
    $user = auth()->user();
    $name = $user->name ?? ($user->email ?? 'Guest');
    $memberSince = $user?->created_at ? $user->created_at->format('M. Y') : null;
@endphp
<li class="nav-item dropdown user-menu">
    <a href="#" class="nav-link dropdown-toggle" data-bs-toggle="dropdown">
        <i class="bi bi-person-circle user-image rounded-circle shadow" style="font-size:1.5rem;"></i>
        <span class="d-none d-md-inline">{{ $name }}</span>
    </a>
    <ul class="dropdown-menu dropdown-menu-lg dropdown-menu-end">
        {{-- Header --}}
        <li class="user-header text-bg-primary">
            <i class="bi bi-person-circle" style="font-size:3rem;"></i>
            <p>
                {{ $name }}
                @if ($memberSince)<small>{{ __('adminlte.member_since') }} {{ $memberSince }}</small>@endif
            </p>
        </li>
        {{-- Body --}}
        <li class="user-body">
            <div class="row">
                <div class="col-4 text-center"><a href="#">{{ __('adminlte.followers') }}</a></div>
                <div class="col-4 text-center"><a href="#">{{ __('adminlte.sales') }}</a></div>
                <div class="col-4 text-center"><a href="#">{{ __('adminlte.friends') }}</a></div>
            </div>
        </li>
        {{-- Footer --}}
        <li class="user-footer">
            @php
                $profileUrl = $user->hasRole('client')
                    ? route('client.profile')
                    : route('admin.profile');
            @endphp
            <a href="{{ $profileUrl }}" class="btn btn-outline-secondary">
                {{ __('adminlte.profile') }}
            </a>
            <a href="#" class="btn btn-outline-danger float-end"
               onclick="event.preventDefault(); document.getElementById('adminlte-logout-form').submit();">
                {{ __('adminlte.sign_out') }}
            </a>
            <form id="adminlte-logout-form" action="{{ url('logout') }}" method="POST" class="d-none">@csrf</form>
        </li>
    </ul>
</li>
