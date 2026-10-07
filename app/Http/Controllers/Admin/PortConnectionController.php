<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\PortConnectionConflictException;
use App\Http\Controllers\Controller;
use App\Models\Datacenter;
use App\Models\DevicePort;
use App\Models\InventoryAsset;
use App\Models\PortConnection;
use App\Models\User;
use App\Services\Exports\CsvStreamService;
use App\Services\Inventory\PortConnectionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\View\View;
use Symfony\Component\HttpFoundation\StreamedResponse;

class PortConnectionController extends Controller
{
    /**
     * The cable columns accepted from the connect / edit-cable forms. Shared by
     * store() and update() so the two agree.
     */
    private const CABLE_FIELDS = ['cable_label', 'cable_type', 'cable_length_m', 'cable_color', 'notes'];

    public function __construct(private readonly PortConnectionService $portConnectionService) {}

    /**
     * Connect two ports of the given asset. The source port must belong to the
     * asset (else 404); the service enforces the one-cable-per-port invariant
     * and reports conflicts as a form error rather than a 500.
     */
    public function store(Request $request, InventoryAsset $inventoryAsset): RedirectResponse
    {
        $validated = $request->validate([
            'source_port_id' => ['required', 'integer', 'exists:device_ports,id'],
            'peer_port_id' => ['required', 'integer', 'exists:device_ports,id'],
            'cable_label' => ['nullable', 'string', 'max:100'],
            'cable_type' => ['nullable', 'string', Rule::in(PortConnection::CABLE_TYPES)],
            'cable_length_m' => ['nullable', 'numeric', 'min:0'],
            'cable_color' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $source = DevicePort::query()->findOrFail((int) $validated['source_port_id']);
        abort_unless((int) $source->inventory_asset_id === (int) $inventoryAsset->id, 404);

        $peer = DevicePort::query()->findOrFail((int) $validated['peer_port_id']);

        try {
            $this->portConnectionService->connect($source, $peer, $this->cableFrom($validated), $this->actor($request));
        } catch (PortConnectionConflictException $exception) {
            return back()->withErrors(['connection' => $exception->getMessage()]);
        }

        return back()->with('success', 'Ports connected.');
    }

    public function update(Request $request, PortConnection $portConnection): RedirectResponse
    {
        $validated = $request->validate([
            'cable_label' => ['nullable', 'string', 'max:100'],
            'cable_type' => ['nullable', 'string', Rule::in(PortConnection::CABLE_TYPES)],
            'cable_length_m' => ['nullable', 'numeric', 'min:0'],
            'cable_color' => ['nullable', 'string', 'max:16'],
            'notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $this->portConnectionService->updateCable($portConnection, $this->cableFrom($validated), $this->actor($request));

        return back()->with('success', 'Cable updated.');
    }

    public function destroy(PortConnection $portConnection): RedirectResponse
    {
        $this->portConnectionService->disconnect($portConnection);

        return back()->with('success', 'Ports disconnected.');
    }

    public function index(Request $request): View
    {
        $connections = $this->filteredQuery($request)
            ->gridSort([
                'cable_label' => fn (Builder $query, string $direction) => $query->orderBy('port_connections.cable_label', $direction),
                'a_asset' => fn (Builder $query, string $direction) => $query->orderBy('aa.asset_tag', $direction),
                'b_asset' => fn (Builder $query, string $direction) => $query->orderBy('ab.asset_tag', $direction),
                'updated_at' => fn (Builder $query, string $direction) => $query->orderBy('port_connections.updated_at', $direction),
            ])
            ->orderByDesc('port_connections.updated_at')
            ->paginate(25)
            ->withQueryString();

        return view('admin.port_connections.index', [
            'connections' => $connections,
            'datacenters' => Datacenter::orderBy('name')->get(),
            'cableTypes' => PortConnection::CABLE_TYPES,
            'search' => trim((string) $request->query('search')),
        ]);
    }

    /**
     * The filtered, joined query shared by the index grid and the streamed CSV
     * export, so both honour the same filters. Soft-deleted endpoint assets are
     * excluded, since their ports should not surface as live cabling.
     */
    public function export(Request $request): StreamedResponse
    {
        $filename = 'port-connections-'.now()->format('Ymd-His').'.csv';
        $csvHeaders = [
            'id', 'cable_label', 'cable_type', 'cable_length_m', 'cable_color',
            'a_asset_tag', 'a_port', 'b_asset_tag', 'b_port', 'a_datacenter', 'updated_at',
        ];

        /** @var CsvStreamService $csv */
        $csv = app(CsvStreamService::class);

        return $csv->stream($filename, $csvHeaders, function ($handle) use ($request): void {
            $this->filteredQuery($request)
                ->orderByDesc('port_connections.updated_at')
                ->chunk(500, function ($connections) use ($handle): void {
                    foreach ($connections as $connection) {
                        fputcsv($handle, [
                            $connection->id,
                            $connection->cable_label,
                            $connection->cable_type,
                            $connection->cable_length_m,
                            $connection->cable_color,
                            $connection->portA?->inventoryAsset?->asset_tag,
                            $connection->portA?->name,
                            $connection->portB?->inventoryAsset?->asset_tag,
                            $connection->portB?->name,
                            $connection->portA?->inventoryAsset?->datacenter?->name,
                            $connection->updated_at?->format('Y-m-d H:i:s'),
                        ]);
                    }
                });
        });
    }

    private function filteredQuery(Request $request): Builder
    {
        $query = PortConnection::query()
            ->join('device_ports as pa', 'port_connections.port_a_id', '=', 'pa.id')
            ->join('device_ports as pb', 'port_connections.port_b_id', '=', 'pb.id')
            ->join('inventory_assets as aa', 'pa.inventory_asset_id', '=', 'aa.id')
            ->join('inventory_assets as ab', 'pb.inventory_asset_id', '=', 'ab.id')
            ->select('port_connections.*')
            ->whereNull('aa.deleted_at')
            ->whereNull('ab.deleted_at')
            ->with(['portA.inventoryAsset.datacenter', 'portB.inventoryAsset.datacenter']);

        if ($request->filled('datacenter_id')) {
            $datacenterId = $request->query('datacenter_id');
            $query->where(function (Builder $inner) use ($datacenterId): void {
                $inner->where('aa.datacenter_id', $datacenterId)
                    ->orWhere('ab.datacenter_id', $datacenterId);
            });
        }

        if ($request->filled('cable_type')) {
            $query->where('port_connections.cable_type', $request->query('cable_type'));
        }

        $search = trim((string) $request->query('search'));
        if ($search !== '') {
            $like = '%'.$search.'%';
            $query->where(function (Builder $inner) use ($like): void {
                $inner->where('port_connections.cable_label', 'like', $like)
                    ->orWhere('port_connections.notes', 'like', $like)
                    ->orWhere('pa.name', 'like', $like)
                    ->orWhere('pb.name', 'like', $like)
                    ->orWhere('aa.asset_tag', 'like', $like)
                    ->orWhere('ab.asset_tag', 'like', $like);
            });
        }

        return $query;
    }

    /**
     * @param  array<string, mixed>  $validated
     * @return array<string, mixed>
     */
    private function cableFrom(array $validated): array
    {
        return array_intersect_key($validated, array_flip(self::CABLE_FIELDS));
    }

    private function actor(Request $request): ?User
    {
        /** @var User|null $user */
        $user = $request->user();

        return $user;
    }
}
