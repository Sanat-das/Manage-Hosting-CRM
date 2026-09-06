<?php

use App\Http\Controllers\Admin\CustomerController;
use App\Http\Controllers\Admin\DashboardController;
use App\Http\Controllers\Admin\ProfileController;
use App\Http\Controllers\AdminLte\RoleController;
use App\Http\Controllers\AdminLte\UserController;
use App\Http\Controllers\Auth\ImpersonationController;
use App\Http\Controllers\Client\RegisteredUserController;
use Illuminate\Support\Facades\Route;

// Client login — the application home page.
Route::get('/', function () {
    return view('auth.client-login');
})->middleware('guest')->name('client.login');

// Staff / admin login — redirects to the shared Fortify login page.
Route::get('/admin', fn () => redirect(route('login')))->middleware('guest')->name('admin.login');

// Self-registration (gated by admin setting) — both GET and POST are throttled to prevent enumeration/scraping.
// Login display and submission are throttled via Fortify limiter 'login' (5/min per email|IP) — see FortifyServiceProvider.
Route::middleware(['registration.enabled', 'throttle:register'])->group(function () {
    Route::get('/register', fn () => view('auth.register'))->name('register');
    Route::post('/register', [RegisteredUserController::class, 'store']);
});

// AdminLTE scaffold routes
Route::middleware(['web', 'auth'])->prefix('admin')->name('adminlte.')->group(function () {
    // [adminlte:roles]
    Route::get('roles', [RoleController::class, 'index'])->name('roles.index');
    Route::get('roles/create', [RoleController::class, 'create'])->name('roles.create');
    Route::post('roles', [RoleController::class, 'store'])->name('roles.store');
    Route::get('roles/{role}/edit', [RoleController::class, 'edit'])->name('roles.edit');
    Route::put('roles/{role}', [RoleController::class, 'update'])->name('roles.update');
    Route::delete('roles/{role}', [RoleController::class, 'destroy'])->name('roles.destroy');

    // [adminlte:users]
    Route::get('users', [UserController::class, 'index'])->name('users.index');
    Route::get('users/create', [UserController::class, 'create'])->name('users.create');
    Route::post('users', [UserController::class, 'store'])->name('users.store');
    Route::get('users/{user}/edit', [UserController::class, 'edit'])->name('users.edit');
    Route::put('users/{user}', [UserController::class, 'update'])->name('users.update');
    Route::delete('users/{user}', [UserController::class, 'destroy'])->name('users.destroy');
});

// Admin panel routes
Route::middleware(['web', 'auth', 'admin', 'throttle:admin'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('dashboard', [DashboardController::class, 'index'])
        ->middleware('permission:dashboard.view')
        ->name('dashboard');

    // Admin self-service profile (2FA management, signature)
    Route::get('profile', [ProfileController::class, 'edit'])
        ->name('profile');
    Route::post('profile', [ProfileController::class, 'update'])
        ->name('profile.update');

    // Admin impersonation (admin → client session switching)
    Route::post('impersonate/{user}', [ImpersonationController::class, 'start'])
        ->middleware('throttle:impersonate')
        ->whereNumber('user')
        ->name('impersonate.start');

    // Admin customers (pilot module) — permission-gated per action.
    // NB: `customers/create` must be registered before `customers/{customer}`.
    Route::get('customers', [CustomerController::class, 'index'])
        ->middleware('permission:customers.view')
        ->name('customers.index');
    Route::get('customers/create', [CustomerController::class, 'create'])
        ->middleware('permission:customers.create')
        ->name('customers.create');
    Route::post('customers', [CustomerController::class, 'store'])
        ->middleware('permission:customers.create')
        ->name('customers.store');
    // Inline creation from the admin order form (returns JSON so the modal
    // can append + select the new customer without leaving the page).
    Route::post('customers/quick-store', [CustomerController::class, 'quickStore'])
        ->middleware('permission:customers.create')
        ->name('customers.quick-store');
    Route::get('customers/{customer}', [CustomerController::class, 'show'])
        ->middleware('permission:customers.view')
        ->name('customers.show');
    Route::get('customers/{customer}/edit', [CustomerController::class, 'edit'])
        ->middleware('permission:customers.edit')
        ->name('customers.edit');
    Route::put('customers/{customer}', [CustomerController::class, 'update'])
        ->middleware('permission:customers.edit')
        ->name('customers.update');
    Route::delete('customers/{customer}', [CustomerController::class, 'destroy'])
        ->middleware('permission:customers.delete')
        ->name('customers.destroy');

    // Embedded sub-resources on the customer detail page
    Route::post('customers/{customer}/notes', [CustomerController::class, 'storeNote'])
        ->middleware('permission:customers.edit')
        ->name('customers.notes.store');
    Route::delete('customers/{customer}/notes/{note}', [CustomerController::class, 'destroyNote'])
        ->middleware('permission:customers.edit')
        ->name('customers.notes.destroy');
    Route::put('customers/{customer}/notes/{note}', [CustomerController::class, 'updateNote'])
        ->middleware('permission:customers.edit')
        ->name('customers.notes.update');
    Route::post('customers/{customer}/contacts', [CustomerController::class, 'storeContact'])
        ->middleware('permission:customers.edit')
        ->name('customers.contacts.store');
    Route::put('customers/{customer}/contacts/{contact}', [CustomerController::class, 'updateContact'])
        ->middleware('permission:customers.edit')
        ->name('customers.contacts.update');
    Route::delete('customers/{customer}/contacts/{contact}', [CustomerController::class, 'destroyContact'])
        ->middleware('permission:customers.edit')
        ->name('customers.contacts.destroy');
    Route::post('customers/{customer}/wallet', [CustomerController::class, 'storeWallet'])
        ->middleware('permission:customers.edit')
        ->name('customers.wallet.store');
});

// Stop impersonation is available to ANY authenticated user (the impersonated
// client must be able to end it from their portal banner). Harmless when the
// session has no impersonation in progress.
Route::middleware(['web', 'auth'])->prefix('admin')->name('admin.')->group(function () {
    Route::get('impersonate/stop', [ImpersonationController::class, 'stop'])
        ->name('impersonate.stop');
});


