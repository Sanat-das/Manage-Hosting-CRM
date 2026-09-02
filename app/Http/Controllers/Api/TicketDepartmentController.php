<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\TicketDepartment;
use Illuminate\Http\JsonResponse;

/**
 * Read-only department directory for client/automation use — backs
 * `Rule::in(departments())` validation on the customer side and lets
 * external clients discover valid department slugs without admin access.
 * Never exposes IMAP credentials.
 */
class TicketDepartmentController extends Controller
{
    public function index(): JsonResponse
    {
        $departments = TicketDepartment::query()
            ->enabled()
            ->ordered()
            ->get();

        return response()->json([
            'data' => $departments->map(fn (TicketDepartment $department) => $this->present($department)),
        ]);
    }

    public function show(string $slug): JsonResponse
    {
        $department = TicketDepartment::where('slug', $slug)->firstOrFail();

        return response()->json(['data' => $this->present($department)]);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(TicketDepartment $department): array
    {
        return [
            'id' => $department->id,
            'name' => $department->name,
            'slug' => $department->slug,
            'email_address' => $department->email_address,
            'enabled' => $department->enabled,
            'sort_order' => $department->sort_order,
            'is_default' => $department->is_default,
            'description' => $department->description,
        ];
    }
}
