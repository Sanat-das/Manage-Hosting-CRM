@extends('install.layout')

@section('content')
    <div class="section" style="text-align:center">
        <div style="width:56px;height:56px;border-radius:50%;background:rgba(21,128,61,.12);color:var(--ok);display:inline-flex;align-items:center;justify-content:center;font-size:26px;font-weight:700;margin-bottom:16px">✓</div>
        <h2 style="font-size:18px;margin-bottom:6px">Installation complete</h2>
        <p style="color:var(--muted);font-size:13.5px;margin:0 0 20px">The application is ready. Sign in with the administrator account you just created.</p>
        <a href="{{ route('login') }}" class="btn">Go to login</a>
    </div>
@endsection
