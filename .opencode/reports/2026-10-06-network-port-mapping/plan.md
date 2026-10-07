# Plan: Device42-style Network Device Port Mapping & Cable Management

- Date: 2026-10-06 · Tier: T3 · Repo: `C:\Users\Administrator\Local Sites\managehosting\app` (Laravel, PHP 8.4, PHPUnit + sqlite `:memory:`)
- Intent target (project convention): `.opencode/reports/2026-10-06-network-port-mapping/plan.md` (mirror of this file — see §12).
- Whole-plan acceptance:
  - `php artisan test --compact tests/Feature/DevicePortSchemaTest.php tests/Feature/DevicePortCrudTest.php tests/Feature/PortConnectionTest.php tests/Feature/SnmpPortImportTest.php tests/Feature/SeederIntegrityTest.php`
  - `vendor/bin/pint --dirty` (clean)
  - Browser: switch asset show page (ports card, connect flow) + `/admin/port-connections` (filter, export). Label code-only if the app is not running.

---

## 1. V1 scope and non-goals

**In scope (v1)**
1. Manual port registry per inventory asset: `device_ports` CRUD on the device show page.
2. Manual port↔port connections with cable metadata (`port_connections`), one cable per port.
3. Connections index at `/admin/port-connections` with filters, search, CSV export.
4. Append-only connection audit ledger (`port_connection_events`) + recent-changes card on the device page.
5. SNMP interface import: poll payload → `device_ports` upsert (live hook + backfill command `snmp:sync-ports --dry-run`).
6. Device-level IP correlation (ports card shows the asset's IPs); MAC stored on the port for future correlation and duplicate-MAC detection.

**Non-goals v1 (explicit)**
- LLDP/CDP auto-cabling → Phase 2.
- Patch-panel front/rear pairing, structured-cabling chains → Phase 3.
- Cable inventory/stock, bundles (one row per strand) → Phase 3.
- Graphical map editor, cable tracing UI → out of plan.
- REST API, global-search provider, rack-elevation port overlays → follow-ups, not prerequisites.

Rationale: v1 makes the data model real and the manual workflow correct; every automation (LLDP, ARP, patch panels) becomes an additional writer against frozen tables rather than a schema redesign.

---

## 2. Verified ground truth (context corrections in §2.1)

- Inventory: `inventory_assets` (asset types incl. `server|switch|nic|pdu`, rack/U position, soft deletes) — `database/migrations/2026_07_30_120070_create_inventory_tables.php:22-47`; `InventoryAsset` constants `ASSET_TYPES` (`:24-28`), `IP_TRACKING_TYPES = ['server','switch']` (`:43`), `ipAddresses()` hasMany via `inventory_asset_id` (`:65-68`).
- CI graph: `asset_relationships` typed kind/id pairs, unique `(parent_kind,parent_id,child_kind,child_id,relationship_type)` — `database/migrations/2026_08_06_000001_create_asset_relationships_table.php:11-32`; model duplicate-check in `saving()` hook — `app/Models/AssetRelationship.php:53-61,95-108`; `RELATIONSHIP_TYPES` `:14-19`, `ASSET_KINDS` `:26-37` (separate admin-UI list).
- IPAM: `ip_addresses` (`subnet_id`, `type`, polymorphic `assigned_to_type`, `inventory_asset_id`, `last_seen_at`) — migration `2026_07_30_120080_create_ipam_dns_tables.php:47-66`; ledger `ip_allocation_history` (`changed_at`, snapshots, `changed_by_user_id`, enum `action`) `:68-83`; `IpAddress` morph `assigned_to()` `app/Models/IpAddress.php:33-36`; mutation pattern `IpAssignmentService::assignToAsset()/releaseFromAsset()` + `writeHistory()` `app/Services/IpAssignmentService.php:184-280` (`writeHistory` `:352-374`).
- SNMP module: `SnmpCollector` ifTable walk (`1.3.6.1.2.1.2.2.1`) `modules/snmp-monitor/src/Services/SnmpCollector.php:74,196-201,399-445`; payload per interface `{index,name,descr,operStatus,oper_status,adminStatus,speed,inOctets,in_octets,outOctets,out_octets,type,mtu,physAddress}` `:426-441`; MAC formatting `:729-749`. `PollHostBatch` persists counters to `snmp_if_samples` and the **full payload JSON** to `snmp_latest` `modules/snmp-monitor/src/Jobs/PollHostBatch.php:331-386`; best-effort discovery hook `:220-228`; `InventoryDiscoveryService::discover()` `modules/snmp-monitor/src/Services/InventoryDiscoveryService.php:54-73`. Module command registration pattern (`ConsoleApplication::starting`) `modules/snmp-monitor/src/SnmpMonitor.php:41-45`; schedules `routes/console.php:110-122`.
- UI: single-column card stack, `table table-sm align-middle mb-0` inside `.table-responsive`, `@can` Actions hidden entirely — `resources/views/admin/inventory_assets/show.blade.php:15-38,248-293,326-361`; asset picker inline JS + `data-search-url` + `{results:[{id,label,meta}]}` — `show.blade.php:364-538` and `InventoryAssetController::search()` `app/Http/Controllers/Admin/InventoryAssetController.php:130-176`; IP picker pattern `_ip_picker.blade.php:13,38-249`; datatable component `resources/views/components/adminlte/partials/datatable.blade.php:1-46,175-207`; index usage `resources/views/admin/inventory_assets/index.blade.php:11-31`; export via `CsvStreamService::stream()` `app/Services/Exports/CsvStreamService.php:53` used by `InventoryAssetController::export()` `:87-120`.
- Permissions: routes in `routes/admin/inventory.php` (registered `bootstrap/app.php:69`) and `routes/admin/enterprise.php`; RBAC authority `database/seeders/AdminLteRbacSeeder.php:21-157` (infrastructure block `:67-107`, support matrix `:194-214`); backfill precedent `database/migrations/2026_10_06_000001_backfill_support_inventory_view_permission.php:40-68`; `.manage` implies `.view` in `PermissionMiddleware:24-33`; admin is **not** special-cased and must hold every permission explicitly (`app/Models/Concerns/HasRoles.php:48-73`); `SeederIntegrityTest` scrapes route middleware permissions from `routes/*.php` + `routes/*/*.php` and menu `can` keys (`tests/Feature/SeederIntegrityTest.php:60-88,286-331,354-415`).
- Test harness: `tests/Support/InteractsWithSnmpMonitorModule.php:166-259` (scripted `fakeSnmpClient`: `lo`=index 1, `eth0`=index 2, no ifType/speed/MAC scripted), `fakeSnmpWalk` `:266-292`, `bindCapturingCollector` `:301-323`; house fixture style `tests/Feature/InventoryAssetIpLinkTest.php:34-57`; `tests/Feature/SnmpInventoryDiscoveryTest.php:34-57` polling pattern.

### 2.1 Corrections to the provided context
1. **Interface metadata is not in `snmp_if_samples`.** Base row is `(host_id, if_index, collected_at)` (`modules/snmp-monitor/database/migrations/2026_08_24_000002_create_snmp_samples_tables.php:60-67`); counters `in_octets, out_octets, in_bps, out_bps` arrive via `modules/snmp-monitor/database/migrations/2026_08_25_000001_add_interface_rate_columns_to_snmp_if_samples.php:28-41`. Port name/type/speed/MAC exist only inside `snmp_latest.payload` JSON (`PollHostBatch.php:374-386`). The importer reads the payload, never `snmp_if_samples`.
2. **Admin role is not an implicit superuser** (`HasRoles.php:48-73`). New permissions need a seeder declaration *and* a backfill migration granting the admin role — the 2026-10-06 inventory backfill granted only `support` because it added an existing permission; a brand-new pair must be granted to `admin`.
3. `asset_relationships` is a generic kind/id graph, not asset-only — yes, but its `RELATIONSHIP_TYPES` are semantic CI links (`hosted_on|hosted_in|manages|contains`), which is why v1 does **not** materialize cables there (§3.4).
4. Routes are registered by explicit `require` in `bootstrap/app.php:44-97`; no glob. New routes go into the existing `routes/admin/inventory.php` — no bootstrap edit.

---

## 3. Data model (frozen contract)

### 3.1 Naming and shape
- `device_ports` — Device42 vocabulary ("device ports"), no collision with any existing table (verified: no `ports`/`device_ports`/`port_connections` migrations or models).
- `port_connections` — one row = one cable segment between exactly two ports.
- `port_connection_events` — append-only ledger, mirrors `ip_allocation_history` (`$timestamps = false`, `changed_at`).

### 3.2 Migration files (exact names, additive/reversible)
1. `database/migrations/2026_10_06_000010_create_device_ports_table.php`
2. `database/migrations/2026_10_06_000011_create_port_connections_table.php`
3. `database/migrations/2026_10_06_000012_create_port_connection_events_table.php`
4. `database/migrations/2026_10_06_000013_backfill_ports_permissions.php` (data migration, `down()` no-op per precedent `2026_10_06_000001:70-81`)

### 3.3 DDL sketch

```php
// 2026_10_06_000010_create_device_ports_table.php
Schema::create('device_ports', function (Blueprint $table) {
    $table->id();
    $table->foreignId('inventory_asset_id')->constrained('inventory_assets')->cascadeOnDelete();
    $table->string('name', 100);                       // display name; SNMP: ifName ?: ifDescr
    $table->string('port_number', 50)->nullable();     // physical label, e.g. "Gi1/0/24"
    $table->string('port_type', 32)->default('other'); // DevicePort::PORT_TYPES (string, not DB enum)
    $table->string('media', 16)->nullable();           // DevicePort::MEDIA
    $table->unsignedBigInteger('speed_bps')->nullable();
    $table->string('status', 16)->default('unknown');  // DevicePort::STATUSES
    $table->string('mac_address', 17)->nullable();     // normalized UPPER hex, colon-separated
    $table->string('source', 8)->default('manual');    // manual|snmp
    $table->unsignedInteger('snmp_if_index')->nullable();
    $table->string('snmp_if_descr', 255)->nullable();
    $table->timestamp('last_synced_at')->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->unique(['inventory_asset_id', 'name'], 'device_ports_asset_name_unique');
    $table->unique(['inventory_asset_id', 'snmp_if_index'], 'device_ports_asset_ifindex_unique');
    $table->index('mac_address');                      // duplicate-MAC scan
    // queries by asset use the leftmost column of both unique indexes — no extra index
});

// 2026_10_06_000011_create_port_connections_table.php
Schema::create('port_connections', function (Blueprint $table) {
    $table->id();
    $table->foreignId('port_a_id')->constrained('device_ports')->cascadeOnDelete();
    $table->foreignId('port_b_id')->constrained('device_ports')->cascadeOnDelete();
    $table->string('cable_label', 100)->nullable();
    $table->string('cable_type', 32)->nullable();      // PortConnection::CABLE_TYPES
    $table->decimal('cable_length_m', 6, 2)->nullable();
    $table->string('cable_color', 16)->nullable();
    $table->text('notes')->nullable();
    $table->timestamps();

    $table->unique('port_a_id', 'port_connections_port_a_unique');
    $table->unique('port_b_id', 'port_connections_port_b_unique');
});

// 2026_10_06_000012_create_port_connection_events_table.php
Schema::create('port_connection_events', function (Blueprint $table) {
    $table->id();
    $table->unsignedBigInteger('port_connection_id')->nullable(); // no FK: the row is deleted on disconnect
    $table->unsignedBigInteger('port_a_id');
    $table->unsignedBigInteger('port_b_id');
    $table->string('a_asset_tag', 255);   // snapshots survive port/asset deletion
    $table->string('a_port_name', 100);
    $table->string('b_asset_tag', 255);
    $table->string('b_port_name', 100);
    $table->string('action', 16);         // connected|disconnected|cable_updated
    $table->string('cable_label', 100)->nullable();
    $table->string('cable_type', 32)->nullable();
    $table->decimal('cable_length_m', 6, 2)->nullable();
    $table->string('cable_color', 16)->nullable();
    $table->unsignedBigInteger('changed_by_user_id')->nullable();
    $table->timestamp('changed_at');
    $table->text('notes')->nullable();

    $table->index('changed_at');
    $table->index('port_connection_id');
    $table->index(['port_a_id', 'port_b_id']);
});
```

### 3.4 Decisions and their rationale
- **No soft deletes** on `device_ports`/`port_connections`. `unique(inventory_asset_id, name)` would be blocked by a soft-deleted row (MySQL/SQLite unique ignores the deleted flag), and a disconnected cable must free its ports. History is preserved in `port_connection_events`. Deleting a connected port is **blocked** (error: "Disconnect the cable first"), mirroring `IpAddressController::destroy()` `:308-317`.
- **One cable per port enforcement** (layered, house pattern):
  1. DB: `unique(port_a_id)` + `unique(port_b_id)` (same-column case).
  2. Model `PortConnection::booted() saving` hook (precedent `AssetRelationship.php:53-61`): reject `port_a_id === port_b_id`; canonicalize so `port_a_id < port_b_id`; reject when either endpoint appears in either column of another row (`where('port_a_id', X)->orWhere('port_b_id', X)`) — closes the cross-column case the two uniques miss; throw `InvalidArgumentException`/`PortConnectionConflictException`.
  3. Service `connect()` wraps in `DB::transaction` + `lockForUpdate` on both port rows (precedent `IpAssignmentService::assignToAsset()` `:186-215`) to serialize concurrent connects.
- **Canonical ordering** (`port_a_id < port_b_id`) makes "same cable inserted twice" and reversed duplicates impossible and display deterministic.
- **Cables are NOT materialized into `asset_relationships`.** Reasons: (1) two sources of truth for one physical fact; (2) a 48-port switch would add 48 edges to `/admin/inventory-tree`, drowning the CI graph; (3) connection history/audit lives in the events ledger. A derived device-adjacency view (distinct asset pairs) can be added later as a read-only report.
- **FK strategy**: `cascadeOnDelete` on all three FKs — ports are compositional children of assets; cable rows are children of ports. Soft-deleted assets keep rows (soft delete fires no FK) — handled in the connections index by excluding trashed assets (§6.3).
- **Enums as strings + model constants** (`port_type`, `media`, `status`, `source`, `cable_type`, `action`), not DB enums: SNMP ifType values are an open set, and string columns avoid MySQL enum-widening migrations. Precedent: `AssetRelationship::ASSET_KINDS` (`:26-37`).
- **Correlation to racks/patch panels**: none direct — ports hang off `inventory_asset_id`; the asset already carries datacenter/rack. Patch panels arrive Phase 3 as `inventory_assets.asset_type = 'patch_panel'` (additive enum widen; the MySQL/sqlite dual-path pattern exists at `database/migrations/2026_08_06_000002_widen_ipam_assignment_enums.php:28-44`) plus a nullable `face` column on `device_ports`.

### 3.5 Model constants (freeze)

```php
DevicePort::PORT_TYPES = ['ethernet','sfp','sfp_plus','qsfp','fiber','usb','console','power','lag','vlan','loopback','virtual','other'];
DevicePort::MEDIA      = ['copper','fiber','virtual','other'];
DevicePort::STATUSES   = ['up','down','disabled','unknown'];
DevicePort::SOURCES    = ['manual','snmp'];
PortConnection::CABLE_TYPES = ['cat5e','cat6','cat6a','cat7','om3_fiber','om4_fiber','single_mode_fiber','dac','aoc','power','console','other'];
PortConnectionEvent::ACTIONS = ['connected','disconnected','cable_updated'];
InventoryAsset::PORT_BEARING_TYPES = ['server','switch','nic','pdu','other_hardware']; // port UI visibility, mirrors IP_TRACKING_TYPES
```

---

## 4. Frozen interfaces (before any parallel slice)

### 4.1 Routes (added to `routes/admin/inventory.php`, same group `web+auth+admin+throttle:admin`, prefix `admin`, name `admin.`)

| Method | URI | Name | Permission |
| --- | --- | --- | --- |
| GET | `admin/ports/search` | `admin.ports.search` | `ports.view` |
| POST | `admin/inventory-assets/{inventoryAsset}/ports` | `admin.inventory-assets.ports.store` | `ports.manage` |
| PUT | `admin/inventory-assets/{inventoryAsset}/ports/{devicePort}` | `admin.inventory-assets.ports.update` | `ports.manage` |
| DELETE | `admin/inventory-assets/{inventoryAsset}/ports/{devicePort}` | `admin.inventory-assets.ports.destroy` | `ports.manage` |
| POST | `admin/inventory-assets/{inventoryAsset}/port-connections` | `admin.inventory-assets.port-connections.store` | `ports.manage` |
| GET | `admin/port-connections` | `admin.port-connections.index` | `ports.view` |
| GET | `admin/port-connections/export` | `admin.port-connections.export` | `ports.view` |
| PUT | `admin/port-connections/{portConnection}` | `admin.port-connections.update` | `ports.manage` |
| DELETE | `admin/port-connections/{portConnection}` | `admin.port-connections.destroy` | `ports.manage` |

Nested bindings must verify ownership (`(int)$devicePort->inventory_asset_id === (int)$inventoryAsset->id`, else 404 — precedent `InventoryAssetController::detachIp` `:386-393`).

### 4.2 Services

```php
namespace App\Services\Inventory;

final class PortConnectionService
{
    public function connect(DevicePort $source, DevicePort $peer, array $cable = [], ?User $actor = null): PortConnection;
    public function updateCable(PortConnection $connection, array $cable, ?User $actor = null): PortConnection;
    public function disconnect(PortConnection $connection, ?User $actor = null): void;
}

final class PortDiscoveryService
{
    /** @param array<int,array<string,mixed>> $interfaces SnmpCollector payload['interfaces'] */
    public function syncForAsset(InventoryAsset $asset, array $interfaces, bool $dryRun = false): array;
    /** @return array{created:int,updated:int,skipped:int,stale:int} */
}
```
New exception `App\Exceptions\PortConnectionConflictException extends \RuntimeException` (mirror `NoAvailableIpException`); **created in slice A** (the `PortConnection` saving hook throws it), consumed by B2's service and controllers. Controllers catch → `back()->withErrors([...])`.

### 4.3 Picker endpoint contract

`GET /admin/ports/search?q=<string≤100>&exclude_port_id=<int>&exclude_asset_id=<int>&include_connected=<0|1>`

```json
{ "results": [ { "id": 41, "label": "SW-CORE-01 · GigabitEthernet1/0/24",
                 "meta": "ethernet · up · 00:15:5D:03:01:A4 · Rack B2 / DC-1", "disabled": false },
               { "id": 77, "label": "SW-CORE-01 · GigabitEthernet1/0/12",
                 "meta": "ethernet · up · connected to SW-ACCESS-02 Gi1/0/4", "disabled": true } ] }
```
Default returns only **free** ports (`connectionAsA` and `connectionAsB` both absent), excludes the source port (and optionally its asset), `limit 20`, empty `q` → `{results: []}` without touching the DB (mirror `InventoryAssetController::search()` `:137-140`). Label is `asset_tag · port name`; meta joins `port_type · status · mac · rack/datacenter` and the device IP when present. **Amendment (2026-10-07, user feedback):** with `include_connected=1` — which the connect modal sends — connected ports are returned too, **free ports first** (stable name order within each group), each connected entry carrying `"disabled": true` and meta appended `connected to <peer asset tag> <peer port name>` (bare `connected` when the peer is unavailable). The picker renders connected entries muted and unselectable — the one-cable-per-port invariant means an occupied port cannot be chosen; re-cabling = disconnect first. `disabled` is always present (false for free ports).

### 4.4 Module config key

`snmp-monitor` configSchema gains `auto_ports` (checkbox, default **false**, section "Inventory"): "Auto-import SNMP interfaces as device ports on the linked inventory asset." Additive; existing configs unaffected (`SnmpMonitor.php:91-239`, placement after `auto_inventory` `:231-237`).

### 4.5 File ownership (no two slices write the same file)

| Slice | Owns (exact paths) |
| --- | --- |
| A | 4 migrations §3.2; `app/Models/DevicePort.php`; `app/Models/PortConnection.php`; `app/Models/PortConnectionEvent.php`; `app/Models/InventoryAsset.php` (add `PORT_BEARING_TYPES` + `ports()` relation only); `app/Exceptions/PortConnectionConflictException.php`; `routes/admin/inventory.php`; `config/adminlte.php`; `database/seeders/AdminLteRbacSeeder.php`; `tests/Feature/DevicePortSchemaTest.php`; `tests/Feature/SeederIntegrityTest.php` (no edit expected — run only) |
| B1 | `app/Http/Controllers/Admin/DevicePortController.php`; `resources/views/admin/inventory_assets/_ports.blade.php`; `app/Http/Controllers/Admin/InventoryAssetController.php` (`show()`); `resources/views/admin/inventory_assets/show.blade.php` (single `@include` of the partial only); `tests/Feature/DevicePortCrudTest.php` |
| B2 | `app/Services/Inventory/PortConnectionService.php` (uses the exception created in A; never recreates it); `app/Http/Controllers/Admin/PortConnectionController.php`; `resources/views/admin/port_connections/index.blade.php`; `resources/views/admin/inventory_assets/_ports.blade.php` (extend modals/table — **after** B1); `resources/views/admin/inventory_assets/index.blade.php` (Connections tools link, §5.2); `tests/Feature/PortConnectionTest.php` |
| C | `app/Services/Inventory/PortDiscoveryService.php`; `modules/snmp-monitor/src/Console/SyncInventoryPortsCommand.php`; `modules/snmp-monitor/src/Jobs/PollHostBatch.php` (hook); `modules/snmp-monitor/src/SnmpMonitor.php` (config field + command registration); `routes/console.php` (schedule line); `tests/Feature/SnmpPortImportTest.php` |
| D | verification only; no source writes. Integration fixes route back to the owning slice. |

---

## 5. Manual UX

### 5.1 Device show page — "Ports" card (B1/B2)
- Rendered from `InventoryAssetController::show()` when `in_array($asset->asset_type, InventoryAsset::PORT_BEARING_TYPES) || $ports->isNotEmpty()`.
- Placement: new `<div class="row"><div class="col-12">` block **after the Details card** (`show.blade.php:15-38`) — the port table is the primary cabling surface on a switch.
- `show()` additions (no N+1): load ports ordered by `name`; then load `PortConnection::with(['portA.inventoryAsset:id,asset_tag','portB.inventoryAsset:id,asset_tag'])->whereIn('port_a_id',$portIds)->orWhereIn('port_b_id',$portIds)`; build `portId => [connection, peerPort, peerAssetTag]` in PHP. Also `$recentCableEvents` (10 latest events touching this asset's ports).
- Table (`table table-sm align-middle mb-0` in `.table-responsive`): columns **Port | Type | Media | Speed | Status | MAC | Connected to | Cable | Actions**.
  - Speed via `DevicePort::getSpeedLabelAttribute()` (`100 Mbps`, `1 Gbps`, `—` for null).
  - Connected to: `peer asset tag` link to its show page + port name, or `—`.
  - Cable: `label (type · length m)` or `—`.
- Actions column is `@can('ports.manage')` and **omitted entirely** in view-only (header + cells), matching the show-page convention (`show.blade.php:58-60,261-263`).
- Ports.manage extras:
  - Header badge `N ports · M connected`; "Add Port" button → modal form: name*, port_number, port_type select, media select, speed select (10M/100M/1G/2.5G/5G/10G/25G/40G/100G + Unknown, posted as `speed_bps`), status select, mac, notes.
  - Row actions: Edit (same modal), Connect (or Disconnect if connected), Delete. Delete/disconnect use `<x-adminlte.partials.confirm-modal>`; delete blocked while connected (server-side guard + message).
  - Connect modal: source port hidden; peer-port combobox input using the §4.3 endpoint with `data-peer-port-include-connected="1"` — connected peers render **disabled** (muted, skipped by keyboard navigation) with their cable's far end in the meta line (combobox script shape from `_ip_picker.blade.php:38-249` — ARIA combobox, 250 ms debounce, AbortController, click-outside close — plus the single hidden value + submit-disabled-until-chosen gating from the show-page picker `show.blade.php:392-394`; hidden `peer_port_id`); cable fields: label, type select, length m, color, notes. Posts to `admin.inventory-assets.port-connections.store`.
- Below the ports card: "Recent cable changes" card (same shape as "Recent IP allocation history" `show.blade.php:295-324`): **Changed at | Action | Port A | Port B | Cable | By**; hidden when empty.

### 5.2 Connections index (B2)
`resources/views/admin/port_connections/index.blade.php`, using `<x-adminlte.partials.datatable>` (`:1-46`):
- Columns: **Cable** (`cable_label`, sort `cable_label`) | Type | Length | Port A (sort key `a_asset`) | Port B (sort `b_asset`) | Location | Updated (sort `updated_at`) | Actions.
- Filters (tools slot): datacenter select (join `inventory_assets.datacenter_id`), cable type select; search box matches `cable_label`, `notes`, both port names and both asset tags (LIKE).
- Query: `PortConnection` + joins `device_ports as pa/pb` and `inventory_assets as aa/ab`, `select('port_connections.*')`, `whereNull('aa.deleted_at')`/`ab.deleted_at`, `with(['portA.inventoryAsset','portB.inventoryAsset'])`, `gridSort([...closures for join columns...])`, `paginate(25)->withQueryString()`. `GridSort` supports closures for non-inferable FK paths (`app/Support/GridSort.php:54-58,62-111`).
- Export: route `admin.port-connections.export` through `CsvStreamService` with headers `id,cable_label,cable_type,cable_length_m,cable_color,a_asset_tag,a_port,b_asset_tag,b_port,a_datacenter,updated_at`, chunk 500 (copy `InventoryAssetController::export()` `:87-120`).
- Actions: Edit cable (PUT modal), Disconnect (confirm-modal DELETE). Hidden in view-only.
- Sidebar: add `['text' => 'Connections', 'route' => 'admin.port-connections.index', 'icon' => 'bi bi-plug', 'can' => 'ports.view']` after the Inventory entry (`config/adminlte.php:400-405`).
- Also link "Connections" from the inventory index tools next to "Dependency Tree" (`index.blade.php:57-60`), gated `@can('ports.view')`.

---

## 6. Discovery path (SNMP → ports)

### 6.1 Source and hook point
- Input: `snmp_latest.payload['interfaces']` (per target), i.e. the existing collector output — **no collector change in v1** (see Phase 2 for ifXTable).
- Live hook: in `PollHostBatch::pollTarget()`, inside the existing best-effort block pattern after `InventoryDiscoveryService` (`PollHostBatch.php:220-228`): if `config['auto_ports']` is true, `collect_network` produced interfaces, and `$target->fresh()->inventory_asset_id` is set → `app(PortDiscoveryService::class)->syncForAsset(InventoryAsset::find($id), $payload['interfaces'])` in its own try/catch with `Log::warning('SNMP port sync failed.', ...)` — a port failure must never fail a poll.
- Backfill/reconcile command: `snmp:sync-ports` (`Modules\SnmpMonitor\Console\SyncInventoryPortsCommand`), registered in `SnmpMonitor::boot()` behind `app()->runningInConsole()` + `ConsoleApplication::starting` exactly like `MaintainSnmpPartitions` (`SnmpMonitor.php:41-45`).
  - Options: `--target=*` (repeatable SnmpTarget id), `--asset=*` (repeatable InventoryAsset id), `--dry-run`. Default scope: all `snmp_targets` with `inventory_asset_id` not null whose `snmp_latest` payload has interfaces. Reads `snmp_latest` on the `monitoring` connection.
  - Schedule slot: `routes/console.php` — `Schedule::command('snmp:sync-ports')->dailyAt('04:10')->withoutOverlapping();` (04:10 is free; nearby: partitions 00:10, warranty 03:15, licenses 03:30/03:45, pricing 04:30).
  - `--dry-run` prints per asset `planned create/update/stale` and writes nothing (exit 0).

### 6.2 Mapping and idempotency
| Payload | Column | Rule |
| --- | --- | --- |
| `index` | `snmp_if_index` | matching key 1 |
| `descr` (fallback `name`) | `snmp_if_descr` | matching key 2; raw string kept |
| `name` ?: `descr` | `name` | **create only**; collision with existing name → append ` (if{index})` |
| `type` (ifType) | `port_type` | map: 6→ethernet, 161→lag, 135/136→vlan, 24→loopback(skip), 1→other, else other |
| `speed` | `speed_bps` | ifSpeed bits/s; `4294967295` (unknown) or missing → null. (ifSpeed caps at ~4.29 Gb/s — Phase 2 ifHighSpeed) |
| `adminStatus`/`operStatus` | `status` | adminStatus 2→disabled; else operStatus 1→up, 2/7→down, 6→disabled, else unknown |
| `physAddress` | `mac_address` | normalize to UPPER colon-separated; empty→null |
| — | `source` | `snmp` on create; never flipped for matched manual rows |
| — | `last_synced_at` | now on every touch |

Rules:
- Match order: `(asset_id, snmp_if_index)` → `(asset_id, snmp_if_descr)` → `(asset_id, name)`; create otherwise. When a `source=snmp` row is re-matched via either fallback, refresh `snmp_if_index`, `snmp_if_descr` and `last_synced_at` — this is what defeats the R1 renumbering duplicate; a unique conflict during a refresh is caught by the per-interface try/catch.
- In today's payload `descr` is a copy of the ifDescr-derived `name` (`SnmpCollector.php:428-429`), so v1's cascade effectively exposes two distinct keys (index; descr=name); Phase 2 ifXTable is what differentiates `ifName`/`ifAlias`.
- If the matched row is `source=manual`: backfill **only** `snmp_if_index` (when null), `snmp_if_descr`, `mac_address` (when null), `status`, `last_synced_at` — never `name`/`port_type`/`media`/`notes`/`port_number`/`source`.
- Skip interfaces: missing index; ifType 24 (loopback); empty name and descr.
- Never delete. Interfaces absent from the payload leave their `source=snmp` rows with `status='unknown'` (returned as `stale`) — human metadata is never destroyed.
- Idempotency: second poll updates the same rows; unique `(asset_id, snmp_if_index)` is the DB backstop; wrap per-interface writes in try/catch inside the service so one bad row cannot abort a device's sync.
- Concurrency: duplicate poll jobs can race; the unique index + catch-and-log makes it idempotent.

### 6.3 Correlation
- **v1 (device-level)**: ports card and picker meta show the asset's `ip_addresses` via `inventory_asset_id` (`IpAddress::inventoryAsset()` `IpAddress.php:38-41`). No per-port IP claim is made — SNMP ifTable cannot attribute IPs to interfaces.
- **MAC**: stored per port; the picker/search shows it; duplicate-MAC detection is a read-only query (`DevicePort where mac_address in (select mac_address ... group by having count>1)`) surfaced as a warning on the ports card (Phase 2 can turn it into a "suspected link" suggestion).
- Port↔IP at port granularity requires ARP/FDB (Phase 2: `ipNetToMediaTable`), which would land in `port_mac_ip_entries` — **not** a new column on `ip_addresses` (keeps IPAM untouched; MAC is a link-layer fact, not an IP attribute).

### 6.4 Test plan (slice C)
`tests/Feature/SnmpPortImportTest.php` (uses `InteractsWithSnmpMonitorModule`, `RefreshDatabase`, the `SnmpInventoryDiscoveryTest` setUp pattern `:34-40`):
1. Poll creates ports for the linked asset; `lo` skipped; `eth0` present with `source=snmp`, index 2; no MAC/speed in the default fixture → nulls.
2. Second poll: counts unchanged (idempotent), `last_synced_at` advances.
3. Human rename (`name` edited) and manual port survive re-poll; manual row gets `snmp_if_index` backfilled only.
4. Custom walk override (`$fake->walks['1.3.6.1.2.1.2.2.1'] = static::fakeSnmpWalk([...with ifType/ifSpeed/physAddress...])`) → `port_type`, `speed_bps`, `mac_address` mapped; `4294967295` → null.
5. Interface removed from payload → status `unknown`, row retained.
6. `auto_ports=false` → zero ports written (toggle parity with `auto_inventory` tests).
7. Command: `Artisan::registerCommand(app(SyncInventoryPortsCommand::class)); $this->artisan('snmp:sync-ports', ['--dry-run' => true])->assertSuccessful();` and no writes; then without `--dry-run` → writes. (Explicit registration is deliberate: `ConsoleApplication::starting` does not replay for an already-built test console.)
8. Regression: `tests/Feature/SnmpPollingPipelineTest.php` + `tests/Feature/SnmpInventoryDiscoveryTest.php` still green (no collector change, so fixtures untouched).

### 6.5 Phase notes
- **Phase 2a — ifXTable**: extend `SnmpCollector::fetchNetwork()` with a walk of `1.3.6.1.2.1.31.1.1.1` (ifName=1, ifHighSpeed=15, ifAlias=18) merged by row index into the payload; port naming becomes `ifName`, `ifAlias` lands in `notes`/label, speed prefers `ifHighSpeed*1e6`. This touches the shared test fixture (`InteractsWithSnmpMonitorModule::fakeSnmpClient` throws on unexpected walks) — collector + harness + `Unit/SnmpCollectorOsStrategyTest` must change in ONE slice.
- **Phase 2b — LLDP/CDP**: walk `lldpRemTable` (1.0.8802.1.1.2.1.4.1) / `cdpCacheTable` (1.3.6.1.4.1.9.9.23.1.2) → `port_link_suggestions` (or reuse `port_connection_events` with a `discovered` action); suggestions never overwrite manual connections — accept action only.
- **Phase 3 — patch panels**: `asset_type` enum widen + `device_ports.face` (`front|rear`) + structured-cabling chains (a "circuit" = ordered connections); bundles via `port_connection_members` table (junction), which is the point at which the one-cable-per-port unique pair is relaxed.

---

## 7. Permissions and audit

### 7.1 Permissions (frozen)
- New pair: `ports.view` / `ports.manage`. Not reusing `inventory.*`: port permissions gate a distinct write surface (connections affect live cabling), and the area pattern (`asset-relationships.*`, `ip-addresses.*`, `racks.*`) is a pair per sub-resource. `ports.manage` implies `ports.view` via `PermissionMiddleware:24-33`.
- `AdminLteRbacSeeder.php`: add `'ports.view' => 'View Ports & Connections'`, `'ports.manage' => 'Manage Ports & Connections'` in the infrastructure block (`:67-107`); admin gets them via `$all` (`:187-193`); add `'ports.view'` to the support matrix (`:194-214`) — read-only cabling visibility mirrors `inventory.view`.
- `2026_10_06_000013_backfill_ports_permissions.php`: mirror `2026_10_06_000001` (`:40-68`): guard tables exist; `insertOrIgnore` both permissions + labels matching the seeder exactly; attach both to role `admin` and `ports.view` to role `support` via `insertOrIgnore`; `down()` no-op. **Admin is not special-cased** (`HasRoles.php:48-73`) — omitting this leaves existing installs' admin unable to open the new pages.
- Contract: `SeederIntegrityTest` scrapes `routes/*/*.php` + menu `can` keys (`:60-88`) — after slice A, `permission:ports.*` appears in routes and `ports.view` in the sidebar, so the seeder + migration must already declare both.

### 7.2 Audit
- New ledger table `port_connection_events` (3.3), written by `PortConnectionService` on connect / updateCable / disconnect; snapshots `a_asset_tag`, `a_port_name`, `b_asset_tag`, `b_port_name`, cable fields, `changed_by_user_id`, `changed_at`, notes. `changed_by_user_id` nullable so Phase 2 discovery can write with null.
- Read surface: "Recent cable changes" card on the device show page (last 10 events for that asset's ports).
- Not using `activity_log` (`2026_07_30_120060_create_audit_tables.php:99-122`): it is a generic human-readable feed with no typed endpoint columns; the ledger needs structured before/after state like `ip_allocation_history` (`:68-83`). Same reason `ip_allocation_history` exists next to `activity_log`.

---

## 8. Phased task graph

Contracts from §3–§4 freeze before any slice starts. Queue: after A, run B1 and C in parallel (disjoint files); B2 follows B1 (same `_ports.blade.php`); D last.

### Slice A — Schema, models, routes, permissions (owner: general #1)
- Deliverable: 3 tables + 3 models + `PortConnectionConflictException`; `InventoryAsset::PORT_BEARING_TYPES` + `ports()` relation; 9 routes frozen; `ports.view/manage` in seeder + backfill migration; sidebar entry.
- deps: —
- Acceptance: `php artisan test --compact tests/Feature/DevicePortSchemaTest.php tests/Feature/SeederIntegrityTest.php`
  - `DevicePortSchemaTest` asserts tables/indexes via `Schema::getIndexes()` and exercises `up()`/`down()` of the three migrations (require-file pattern modeled on `InventoryAssetIpLinkTest.php:103-108` — that helper calls `up()` only; this test additionally calls `down()`).
- Size: ~11 files, half-day.

### Slice B1 — Manual port CRUD + ports card (owner: general #2, after A)
- Deliverable: `DevicePortController` (search/store/update/destroy), `_ports.blade.php` (table + add/edit/delete modals + picker JS + recent-changes section placeholder), `InventoryAssetController::show()` loading ports + connection map + recent events, `show.blade.php` include line.
- deps: A
- Acceptance: `php artisan test --compact tests/Feature/DevicePortCrudTest.php`
  - Cases: create/update/delete port; unique name per asset; asset-scoped 404; view-only hides actions; `ports.manage` implies `ports.view`; picker search excludes connected/disallowed ports; delete blocked while connected.
- Size: ~4 files, 1 day.

### Slice B2 — Connections: service, connect/disconnect UI, index, export (owner: general #3, after B1)
- Deliverable: `PortConnectionService`; `PortConnectionController` (store/update/destroy/index/export); extend `_ports.blade.php` (connect/disconnect modals, Connected-to column); connections index view; inventory-index Connections link; events ledger writes.
- deps: B1
- Acceptance: `php artisan test --compact tests/Feature/PortConnectionTest.php`
  - Cases: connect two free ports; same-port rejected; already-connected (same column and cross column) rejected; canonical order; cable metadata update writes `cable_updated`; disconnect deletes row and writes `disconnected`; index filters + export streams CSV; view-only.
- Size: ~6 files, 1 day.

### Slice C — SNMP port import (owner: general #4, after A, parallel with B1/B2)
- Deliverable: `PortDiscoveryService`; `auto_ports` config field; `PollHostBatch` hook; `SyncInventoryPortsCommand` + `snmp:sync-ports` schedule; tests.
- deps: A
- Acceptance: `php artisan test --compact tests/Feature/SnmpPortImportTest.php` then `php artisan test --compact tests/Feature/SnmpPollingPipelineTest.php tests/Feature/SnmpInventoryDiscoveryTest.php`
- Size: ~6 files, 1 day.

### Slice D — Integration verify (owner: verify subagent; fixes routed to owning slice)
- Deliverable: adversarial pass — falsify one-cable invariant (direct DB insert + service races), permission matrix (ports.view vs manage vs none), SNMP idempotency under duplicate jobs, export correctness, N+1 on show/index, migration rollback, Pint.
- Acceptance: `php artisan test --compact tests/Feature/DevicePortSchemaTest.php tests/Feature/DevicePortCrudTest.php tests/Feature/PortConnectionTest.php tests/Feature/SnmpPortImportTest.php tests/Feature/SeederIntegrityTest.php` + `vendor/bin/pint --dirty` + `bash scripts/seed-smoke.sh` + browser pass (device page, connect flow, index filters, CSV) or explicit code-only label.
- Then: `php artisan route:list --path=admin/port` and `--path=admin/ports` matches §4.1 exactly.

---

## 9. Risks

| # | Risk | Severity | Mitigation |
| --- | --- | --- | --- |
| R1 | `ifIndex` renumbering between reboots (devices without ifIndex persistence) creates duplicate ports | Medium | Dedupe cascade if_index → if_descr → name (§6.2); document; Phase 2 can add vendor-specific stable keys |
| R2 | Virtual-interface noise (docker0/veth/lo) on servers | Medium | Skip ifType 24; `auto_ports` defaults **off**; status/type filters; never delete |
| R3 | One-cable invariant bypass via raw SQL (cross-column case) | Low | Uniques + model hook + service locks; verify agent must attempt direct insert to confirm documented residual (cross-column only blocks at model layer) |
| R4 | Connections index joins + LIKE search slow past ~100k cables | Low-Med | Page size 25, FK-indexed joins, export chunk 500; ADD `WHERE` datacenter first when supplied; measure before indexing further |
| R5 | sqlite test behavior differs from MySQL (FK enforcement, unique NULLs) | Low | Both engines treat NULLs as distinct in unique indexes; `foreign_key_constraints` on; migration test runs on sqlite; seed-smoke runs on the configured DB |
| R6 | SNMP sync races duplicate polls | Low | Unique `(asset_id, snmp_if_index)` + per-interface catch/log; upsert-style matching |
| R7 | `speed_bps` wrong for >4.29 Gb/s links | Low | Known ifSpeed cap; map 4294967295→null; Phase 2 ifHighSpeed |
| R8 | Show page for 48+ port switches results in heavy DOM/modals | Low | Port table is server-rendered; modals one per connect action (render lazily via single modal + JS data) — B1 must use ONE reusable modals set, not per-peer modals |
| R9 | Backfill migration labels drift vs seeder | Low | `SeederIntegrityTest::test_permission_labels_match_the_seeder_inventory` catches it |

---

## 10. Open questions (recommended defaults)

1. **Dedupe key for SNMP ports**: ifIndex vs ifDescr vs MAC.
   → Default: `(asset_id, snmp_if_index)` primary, `if_descr` fallback, name last. ifIndex is stable within an agent session and unique per device; ifDescr is the human fallback. Cost if wrong: occasional duplicate port after a renumbering reboot — manually mergeable, no data loss.
2. **Collision between a manual port and an SNMP-discovered interface** (same name).
   → Default: match-by-name, backfill only SNMP telemetry columns, never overwrite human fields or flip `source`. Cost if wrong: a manual port that should have been machine-managed stays manual.
3. **Port↔IP correlation depth**: device-level now, port-level later.
   → Default: v1 shows the device's IPs (via `inventory_asset_id`); port-level requires ARP/FDB (Phase 2 `port_mac_ip_entries`), and MAC stays off `ip_addresses`. Cost if wrong: operators cannot see "which switch port this IP is behind" until Phase 2 — acceptable, that is the LLDP/ARP phase.
4. **One cable per port vs bundles/patch panels**.
   → Default: one row per cable, port has at most one connection; bundles/patch chains deferred with a documented additive path (§6.5). Cost if wrong: a bundled link is entered as one row + notes.
5. **Do port deletions need history?**
   → Default: no per-port ledger in v1 (connections are the audited fact); deletion of a connected port is blocked, so no cable disappears unaudited. Cost if wrong: no "who deleted this port" answer — recoverable by adding a `port_events` table additively.
6. **Connections visible to `support`?**
   → Default: yes, read-only (`ports.view` in the support matrix), mirroring `inventory.view`. Cost if wrong: one seeder/migration line to revoke.

---

## 11. Definition of done (whole feature)

- All four migrations additive and reversible (tested); `migrate:fresh` + seed chain idempotent (`seed-smoke.sh`).
- All acceptance commands in §8 pass; no test weakened/skipped.
- Pint clean on changed files; no debug output; no secrets.
- Permission matrix verified for roles admin/support/staff/none (403s + hidden UI).
- Edge cases covered: connected port delete, duplicate port name, connect conflicts (same column + cross column), SNMP re-poll, missing fields in payload, soft-deleted asset in connections index, empty states, export of filtered set.
- Plan artifact and verification report written under `.opencode/reports/2026-10-06-network-port-mapping/` (`plan.md`, `verification.md`).

## 12. Artifact note

**The project-path copy is authoritative**: on 2026-10-06 it received the orchestrator review corrections (citations, §6.2 refresh semantics, §4.5 ownership fixes). Do not re-copy the planning-agent staging file over it.

This file was produced by the `plan` subagent whose write permission is restricted to `C:\Users\Administrator\.opencode\plan`. To place it at the project convention path, run from the repo root:

```powershell
New-Item -ItemType Directory -Force .opencode\reports\2026-10-06-network-port-mapping | Out-Null
Copy-Item "C:\Users\Administrator\.opencode\plan\2026-10-06-network-port-mapping\plan.md" ".opencode\reports\2026-10-06-network-port-mapping\plan.md"
```
