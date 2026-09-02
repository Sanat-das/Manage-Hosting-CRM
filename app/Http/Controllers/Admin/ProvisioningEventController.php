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
        $eventStatus = trim((string) $request->query('event_status'));

        $query = ProvisioningEvent::with('serviceInstance');

        if ($request->filled('event_type')) {
            $query->where('event_type', $request->query('event_type'));
        }
        if ($eventStatus !== '') {
            $query->where('event_status', $eventStatus);
        }

        $events = $query
            ->gridSort([
                'created_at' => 'created_at',
                'service' => 'service_instance_id',
                'event_type' => 'event_type',
                'event_status' => 'event_status',
            ])
            ->orderByDesc('created_at')->paginate(30)->withQueryString();

        return view('admin.provisioning-events.index', compact('events', 'eventStatus'));
    }

    public function show(ProvisioningEvent $provisioningEvent): View
    {
        $provisioningEvent->load('serviceInstance');

        return view('admin.provisioning-events.show', compact('provisioningEvent'));
    }
}
