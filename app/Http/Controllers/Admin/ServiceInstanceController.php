<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ServiceInstance;
use App\Models\ProvisioningEvent;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class ServiceInstanceController extends Controller
{
    public function index(Request $request): View
    {
        $query = ServiceInstance::with(['customer', 'catalogProduct', 'server', 'serverGroup']);
        if ($request->filled('status')) {
            $query->where('status', $request->query('status'));
        }
        if ($request->filled('provision_status')) {
            $query->where('provision_status', $request->query('provision_status'));
        }
        if ($request->filled('search')) {
            $q = $request->query('search');
            $query->where(function ($q2) use ($q) {
                $q2->where('username', 'like', "%{$q}%")
                    ->orWhere('domain', 'like', "%{$q}%")
                    ->orWhereHas('customer', fn($cq) => $cq->where('email', 'like', "%{$q}%"));
            });
        }
        $instances = $query->orderByDesc('id')->paginate(25)->withQueryString();
        return view('admin.service-instances.index', compact('instances'));
    }

    public function show(ServiceInstance $serviceInstance): View
    {
        $serviceInstance->load(['customer', 'catalogProduct', 'server', 'serverGroup', 'subscriptionPeriods', 'usageRecords']);
        $provisioningEvents = ProvisioningEvent::where('service_instance_id', $serviceInstance->id)
            ->orderByDesc('created_at')->limit(20)->get();
        return view('admin.service-instances.show', compact('serviceInstance', 'provisioningEvents'));
    }

    public function update(Request $request, ServiceInstance $serviceInstance): RedirectResponse
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'string', 'in:active,suspended,cancelled,terminated,pending'],
            'provision_status' => ['sometimes', 'string', 'in:pending,provisioning,provisioned,failed,suspended'],
        ]);
        $serviceInstance->update($validated);
        return redirect()->route('admin.service-instances.show', $serviceInstance)->with('success', 'Service instance updated.');
    }

    public function suspend(ServiceInstance $serviceInstance): RedirectResponse
    {
        $serviceInstance->update(['status' => 'suspended', 'provision_status' => 'suspended']);
        ProvisioningEvent::create([
            'service_instance_id' => $serviceInstance->id,
            'event_type' => 'suspended',
            'event_status' => 'completed',
            'triggered_by' => auth()->id(),
            'payload' => ['reason' => 'Admin action'],
            'result' => ['status' => 'suspended'],
        ]);
        return redirect()->route('admin.service-instances.show', $serviceInstance)->with('success', 'Service suspended.');
    }

    public function terminate(ServiceInstance $serviceInstance): RedirectResponse
    {
        $serviceInstance->update(['status' => 'terminated', 'provision_status' => 'terminated']);
        ProvisioningEvent::create([
            'service_instance_id' => $serviceInstance->id,
            'event_type' => 'terminated',
            'event_status' => 'completed',
            'triggered_by' => auth()->id(),
            'payload' => ['reason' => 'Admin termination'],
            'result' => ['status' => 'terminated'],
        ]);
        return redirect()->route('admin.service-instances.index')->with('success', 'Service terminated.');
    }
}
