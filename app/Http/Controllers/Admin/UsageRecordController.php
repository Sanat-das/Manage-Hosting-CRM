<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsageRecord;
use App\Models\ResourceType;
use Illuminate\Http\Request;
use Illuminate\View\View;

class UsageRecordController extends Controller
{
    public function index(Request $request): View
    {
        $query = UsageRecord::with(['service', 'resourceType']);
        if ($request->filled('resource_type_id')) {
            $query->where('resource_type_id', $request->query('resource_type_id'));
        }
        $records = $query->orderByDesc('recorded_at')->paginate(30)->withQueryString();
        $resourceTypes = ResourceType::orderBy('name')->get();
        return view('admin.usage_records.index', compact('records', 'resourceTypes'));
    }

    public function show(UsageRecord $usageRecord): View
    {
        $usageRecord->load(['service', 'resourceType']);
        return view('admin.usage_records.show', compact('usageRecord'));
    }
}
