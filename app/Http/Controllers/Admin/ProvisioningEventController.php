<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ProvisioningEvent;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ProvisioningEventController extends Controller
{
    public function index(Request $request): View
    {
        $query = ProvisioningEvent::with('serviceInstance');
        if ($request->filled('event_type')) {
            $query->where('event_type', $request->query('event_type'));
        }
        if ($request->filled('event_status')) {
            $query->where('event_status', $request->query('event_status'));
        }
        $events = $query->orderByDesc('created_at')->paginate(30)->withQueryString();
        return view('admin.provisioning-events.index', compact('events'));
    }

    public function show(ProvisioningEvent $provisioningEvent): View
    {
        $provisioningEvent->load('serviceInstance');
        return view('admin.provisioning-events.show', compact('provisioningEvent'));
    }
}
