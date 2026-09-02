<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Admin self-service profile.
 *
 * Admins manage their own account here (2FA setup / recovery codes). Identity
 * fields are managed through the AdminLTE user management screens.
 */
class ProfileController extends Controller
{
    public function edit(Request $request): View
    {
        $user = $request->user();

        return view('admin.profile', compact('user'));
    }
}
