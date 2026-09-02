@if (session('error'))
    <x-adminlte-alert theme="danger" dismissible>{{ session('error') }}</x-adminlte-alert>
@endif
@if (session('success'))
    <x-adminlte-alert theme="success" dismissible>{{ session('success') }}</x-adminlte-alert>
@endif
@if (session('info'))
    <x-adminlte-alert theme="info" dismissible>{{ session('info') }}</x-adminlte-alert>
@endif
@if (session('warning'))
    <x-adminlte-alert theme="warning" dismissible>{{ session('warning') }}</x-adminlte-alert>
@endif
@if ($errors->any())
    <x-adminlte-alert theme="danger" dismissible><ul class="mb-0">@foreach ($errors->all() as $error)<li>{{ $error }}</li>@endforeach</ul></x-adminlte-alert>
@endif
