<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class DashboardController extends Controller
{
    /**
     * Admin panel landing page.
     */
    public function index(): View
    {
        return view('admin.dashboard');
    }
}
