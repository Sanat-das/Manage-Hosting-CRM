<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ResourceType;
use App\Models\UsageRecord;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UsageRecordController extends Controller
{
    public function index(Request $request): View
    {
        $search = trim((string) $request->query('search'));

        $query = UsageRecord::with(['service', 'resourceType']);

        if ($search !== '') {
            $query->whereHas('service', function ($q) use ($search) {
                $q->where('domain', 'like', "%{$search}%")
                    ->orWhere('username', 'like', "%{$search}%");
            });
        }
        if ($request->filled('resource_type_id')) {
            $query->where('resource_type_id', $request->query('resource_type_id'));
        }

        $records = $query
            ->gridSort([
                'recorded_at' => 'recorded_at',
                'service' => 'service.domain',
                'resource' => 'resourceType.name',
            ])
            ->orderByDesc('recorded_at')->paginate(30)->withQueryString();
        $resourceTypes = ResourceType::orderBy('name')->get();

        return view('admin.usage_records.index', compact('records', 'resourceTypes', 'search'));
    }

    public function show(UsageRecord $usageRecord): View
    {
        $usageRecord->load(['service', 'resourceType']);

        return view('admin.usage_records.show', compact('usageRecord'));
    }
}
