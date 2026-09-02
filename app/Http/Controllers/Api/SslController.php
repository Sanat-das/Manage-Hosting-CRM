<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SslCertificate;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Sanctum-protected SSL certificate REST API (full CRUD).
 *
 * Fresh module mirroring the Api\CustomerController shape (paginated index
 * with status + "expiring soon" filters, full resource presentation).
 */
class SslController extends Controller
{
    private const PER_PAGE = 20;

    /**
     * Number of days used by the "expiring soon" filter.
     */
    private const EXPIRING_SOON_DAYS = 30;

    private const STATUSES = ['active', 'pending', 'expired', 'revoked', 'failed'];

    public function index(Request $request): JsonResponse
    {
        $status = $request->query('status');
        $expiring = filter_var($request->query('expiring', false), FILTER_VALIDATE_BOOLEAN);

        $certificates = SslCertificate::query()
            ->with('customer:id')
            ->when(in_array($status, self::STATUSES, true), function ($query) use ($status) {
                $query->where('status', $status);
            })
            ->when($expiring, function ($query) {
                $query->where('status', 'active')
                    ->whereDate('expiry_date', '>=', now()->startOfDay())
                    ->whereDate('expiry_date', '<=', now()->addDays(self::EXPIRING_SOON_DAYS)->endOfDay());
            })
            ->orderByDesc('id')
            ->paginate(self::PER_PAGE);

        return response()->json([
            'data' => $certificates->map(fn (SslCertificate $certificate) => $this->present($certificate)),
            'meta' => [
                'current_page' => $certificates->currentPage(),
                'last_page' => $certificates->lastPage(),
                'per_page' => $certificates->perPage(),
                'total' => $certificates->total(),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate($this->rules());

        $certificate = SslCertificate::create($validated);

        return response()->json(['data' => $this->present($certificate->load('customer:id'))], 201);
    }

    public function show(SslCertificate $ssl): JsonResponse
    {
        $ssl->load(['customer:id', 'order:id']);

        return response()->json(['data' => $this->present($ssl, true)]);
    }

    public function update(Request $request, SslCertificate $ssl): JsonResponse
    {
        $validated = $request->validate($this->rules(partial: true));

        $ssl->update($validated);

        return response()->json(['data' => $this->present($ssl->fresh()->load('customer:id'))]);
    }

    public function destroy(SslCertificate $ssl): JsonResponse
    {
        $ssl->delete();

        return response()->json(['message' => 'SSL certificate deleted.'], 200);
    }

    /**
     * Validation rules. In partial mode (`update`) every attribute is
     * optional so a client can send only the fields it wants to change.
     *
     * @return array<string, array<int, mixed>>
     */
    private function rules(bool $partial = false): array
    {
        $required = $partial ? 'sometimes' : 'required';

        return [
            'customer_id' => [$required, 'integer', 'exists:customers,id'],
            'domain_name' => [$required, 'string', 'max:255'],
            'certificate_type' => [$required, Rule::in(['single', 'wildcard', 'multidomain'])],
            'provider' => ['nullable', 'string', 'max:255'],
            'status' => [$required, Rule::in(self::STATUSES)],
            'issue_date' => ['nullable', 'date'],
            'expiry_date' => ['nullable', 'date', 'after_or_equal:issue_date'],
            'order_id' => ['nullable', 'integer', 'exists:orders,id'],
            'notes' => ['nullable', 'string', 'max:5000'],
        ];
    }

    /**
     * API resource shape.
     *
     * @return array<string, mixed>
     */
    private function present(SslCertificate $certificate, bool $detailed = false): array
    {
        $data = [
            'id' => $certificate->id,
            'customer_id' => $certificate->customer_id,
            'domain_name' => $certificate->domain_name,
            'certificate_type' => $certificate->certificate_type,
            'provider' => $certificate->provider,
            'status' => $certificate->status,
            'issue_date' => $certificate->issue_date?->toDateString(),
            'expiry_date' => $certificate->expiry_date?->toDateString(),
            'order_id' => $certificate->order_id,
            'notes' => $certificate->notes,
            'created_at' => $certificate->created_at?->toIso8601String(),
            'updated_at' => $certificate->updated_at?->toIso8601String(),
        ];

        if ($detailed) {
            $data['customer'] = $certificate->customer !== null ? ['id' => $certificate->customer->id] : null;
        }

        return $data;
    }
}
