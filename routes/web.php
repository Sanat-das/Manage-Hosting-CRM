<?php

use Illuminate\Support\Facades\Route;

// Client login — the application home page.
Route::get('/', function () {
    return view('auth.client-login');
})->middleware('guest')->name('client.login');

// Staff / admin login — redirects to the shared Fortify login page.
Route::get('/admin', fn () => redirect(route('login')))->middleware('guest')->name('admin.login');

// Self-registration (gated by admin setting).
Route::middleware(['registration.enabled'])->group(function () {
    Route::get('/register', fn () => view('auth.register'))->name('register');
    Route::post('/register', [\App\Http\Controllers\Client\RegisteredUserController::class, 'store'])
        ->middleware('throttle:register');
});

// AdminLTE scaffold routes
Route::middleware(['web', 'auth'])->prefix('admin')->name('adminlte.')->group(function () {
    // [adminlte:roles]
    Route::get('roles', [\App\Http\Controllers\AdminLte\RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/create', [\App\Http\Controllers\AdminLte\RoleController::class, 'create'])->name('roles.create');
    Route::post('roles', [\App\Http\Controllers\AdminLte\RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}/edit', [\App\Http\Controllers\AdminLte\RoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{role}', [\App\Http\Controllers\AdminLte\RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{role}', [\App\Http\Controllers\AdminLte\RoleController::class, 'destroy'])->name('roles.destroy');

    // [adminlte:users]
    Route::get('users', [\App\Http\Controllers\AdminLte\UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [\App\Http\Controllers\AdminLte\UserController::class, 'create'])->name('users.create');
    Route::post('users', [\App\Http\Controllers\AdminLte\UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [\App\Http\Controllers\AdminLte\UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [\App\Http\Controllers\AdminLte\UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [\App\Http\Controllers\AdminLte\UserController::class, 'destroy'])->name('users.destroy');
});

// Admin panel routes
Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\Admin\DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard');

    // Admin self-service profile (2FA management)
    Route::get('profile', [\App\Http\Controllers\Admin\ProfileController::class, 'edit'])
        ->name('profile');

    // Admin impersonation (admin → client session switching)
    Route::post('impersonate/{user}', [\App\Http\Controllers\Auth\ImpersonationController::class, 'start'])
        ->middleware('throttle:impersonate')
        ->whereNumber('user')
        ->name('impersonate.start');

    // Admin customers (pilot module) — permission-gated per action.
    // NB: `customers/create` must be registered before `customers/{customer}`.
    Route::get('customers', [\App\Http\Controllers\Admin\CustomerController::class, 'index'])
        ->middleware('permission:customers.view')
        ->name('customers.index');
    Route::get('customers/create', [\App\Http\Controllers\Admin\CustomerController::class, 'create'])
        ->middleware('permission:customers.create')
        ->name('customers.create');
    Route::post('customers', [\App\Http\Controllers\Admin\CustomerController::class, 'store'])
        ->middleware('permission:customers.create')
        ->name('customers.store');
    Route::get('customers/{customer}', [\App\Http\Controllers\Admin\CustomerController::class, 'show'])
        ->middleware('permission:customers.view')
        ->name('customers.show');
    Route::get('customers/{customer}/edit', [\App\Http\Controllers\Admin\CustomerController::class, 'edit'])
        ->middleware('permission:customers.edit')
        ->name('customers.edit');
    Route::put('customers/{customer}', [\App\Http\Controllers\Admin\CustomerController::class, 'update'])
        ->middleware('permission:customers.edit')
        ->name('customers.update');
    Route::delete('customers/{customer}', [\App\Http\Controllers\Admin\CustomerController::class, 'destroy'])
        ->middleware('permission:customers.delete')
        ->name('customers.destroy');

    // Embedded sub-resources on the customer detail page
    Route::post('customers/{customer}/notes', [\App\Http\Controllers\Admin\CustomerController::class, 'storeNote'])
        ->middleware('permission:customers.edit')
        ->name('customers.notes.store');
    Route::delete('customers/{customer}/notes/{note}', [\App\Http\Controllers\Admin\CustomerController::class, 'destroyNote'])
        ->middleware('permission:customers.edit')
        ->name('customers.notes.destroy');
    Route::post('customers/{customer}/contacts', [\App\Http\Controllers\Admin\CustomerController::class, 'storeContact'])
        ->middleware('permission:customers.edit')
        ->name('customers.contacts.store');
    Route::put('customers/{customer}/contacts/{contact}', [\App\Http\Controllers\Admin\CustomerController::class, 'updateContact'])
        ->middleware('permission:customers.edit')
        ->name('customers.contacts.update');
    Route::delete('customers/{customer}/contacts/{contact}', [\App\Http\Controllers\Admin\CustomerController::class, 'destroyContact'])
        ->middleware('permission:customers.edit')
        ->name('customers.contacts.destroy');
    Route::post('customers/{customer}/wallet', [\App\Http\Controllers\Admin\CustomerController::class, 'storeWallet'])
        ->middleware('permission:customers.edit')
        ->name('customers.wallet.store');
});

// Stop impersonation is available to ANY authenticated user (the impersonated
// client must be able to end it from their portal banner). Harmless when the
// session has no impersonation in progress.
Route::middleware(['web', 'auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('impersonate/stop', [\App\Http\Controllers\Auth\ImpersonationController::class, 'stop'])
        ->name('impersonate.stop');
});

// Client portal routes
Route::middleware(['web', 'auth', 'client'])->prefix('client')->name('client.')->group(function () {
    Route::get('dashboard', [\App\Http\Controllers\Client\DashboardController::class, 'index'])
        ->name('dashboard');

    // Client profile (view + edit own details)
    Route::get('profile', [\App\Http\Controllers\Client\ProfileController::class, 'edit'])
        ->name('profile');
    Route::put('profile', [\App\Http\Controllers\Client\ProfileController::class, 'update'])
        ->name('profile.update');
});
