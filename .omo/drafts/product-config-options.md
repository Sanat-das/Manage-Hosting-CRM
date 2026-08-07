---
slug: product-config-options
status: plan-written (awaiting user to start execution via /start-work)
intent: clear
review_required: false
pending-action: user reviews .omo/plans/product-config-options.md and runs /start-work to execute
approach: consume existing IPAM for auto-lease + manual override/release on VPS/Dedicated activation (trigger via Product::requiresIp(), no new toggle column); add read-only polymorphic asset_relationships table + admin CRUD + two canned reports. C1 ("Require domain") dropped — that feature already exists end-to-end.
---

# Draft: product-config-options

## Components (topology ledger)
| id | outcome (one line) | status | evidence path |
|----|-------------------|--------|---------------|
| C1 | Admin form surfaces TWO toggles: existing `require_domain` + new `require_ip`; order form shows the right input per product | active | Product.php fillable:10 cast:20; ProductRequest.php:37; ProductController.productData:181; create/edit/show blade 113/115/110 |
| C2 | Provisioning for `require_ip` products pulls the NEXT AVAILABLE `IpAddress` from the existing IPAM, marks it assigned to the HostingAccount, and writes an audit row. Admin can manually override (pick/clear specific IP) | active | IpAddress.php fillable:10 (already has polymorphic `assigned_to_type`/`assigned_to_id`); IpAllocationHistory.php; IpSubnetController routes in enterprise.php; migration 2026_07_30_120080_create_ipam_dns_tables.php |
| C3 | New READ-ONLY `asset_relationships` link + admin report show "what product/asset is hosted on what other product/asset" (e.g. SERVER01 → these VPS; this shared-hosting product → its VPS parents). No order/client effect | active | user clarification: "nothing to do with order and client... build/query a report." librarian brief confirms no industry tool nests natively; we model the edges as reporting only |

## Open assumptions (announced defaults)
| assumption | adopted default | rationale | reversible? |
|------------|----------------|-----------|-------------|
| `require_ip` semantics | boolean nullable default false; admin/order input is a single IP literal; validated server-side as IPv4 or IPv6 | mirrors existing `require_domain` pattern byte-for-byte | yes (additive col) |
| Order-time identifier input | shown when EITHER toggle is on; both can be on simultaneously | user chose "two separate toggles" | yes |
| "Available" predicate on `ip_addresses` | `assigned_to_type IS NULL` AND morph-assign candidate is `HostingAccount` (NOT touching `type` ∈ private/public/reserved which exists already and is orthogonal) | the table already uses polymorphic assignment via `assigned_to_type`/`assigned_to_id` — that is the lease flag | yes (predicate, no schema change) |
| Assign flow writes history | every assign/release/override calls `IpAllocationHistory::create` with the snapshot + changed_by_user_id | matches the existing audit table shape exactly | yes |
| Manual override | "auto pull" vs "select specific available IP from subnet" radio on the hosting-account screen; release frees the IP and clears the link | user explicitly asked for manual override | yes |
| `asset_relationships` shape | (parent_kind, parent_id, child_kind, child_id, relationship_type, sort_order, notes); polymorphic on BOTH ends; relationship_type ∈ {hosted_on, hosted_in, manages}; no order_id, no customer_id | user: pure reporting, no order/client binding; polymorphic-on-both-ends gives product-to-product AND product-to-server in one table | yes (new table) |
| Relationship scope vs Module/Billing | reporting does NOT change billing, provisioning, suspend/terminate | explicit from user; keeps cadence light | yes |

## Findings (cited - path:lines)
- `products.require_domain` already exists end-to-end (fillable+cast Product.php:10,20; rule ProductRequest.php:37; wiring ProductController.php:181; blade create/edit/show 113/115/110). **C1's only net-new is the IP half.**
- `Product::TYPES` already enumerates `vps`, `dedicated`, `bundle`, `domain`, `addon` — Product.php:29-39. So the types the topology feature will relate already exist as a constant.
- IPAM already exists and is the right consumer: `IpAddress` has polymorphic `assigned_to_type`/`assigned_to_id` fillable (app/Models/IpAddress.php:10); `IpAllocationHistory` is the audit ledger (app/Models/IpAllocationHistory.php:10); `IpSubnet`/`Vlan`/`Datacenter`/`Rack` complete the inventory layer; routes + admin UI already wired (routes/admin/enterprise.php:78-92). Migration `database/migrations/2026_07_30_120080_create_ipam_dns_tables.php`.
- NO IP column on `hosting_accounts` exists; `hosting_accounts.server_id` lands the account on a `Server` (fillable:13) and `ip_addresses` polymorphic-assign would call `assigned_to_type='HostingAccount'`. So assignment is via the existing morph column — no new FK column strictly required (though a convenience `hosting_accounts.ip_address_id` nullable FK is optionally acceptable for fast reads; call only).
- ServerGroup / ServerGroupMember exist (app/Models/ServerGroup.php:22; ServerGroupMember.php:11) with `load_balancing` {round_robin, least_loaded, failover} (ServerGroupController.php:121). `Product.server_group_id` exists. The asset layer already matches WHMCS-style server groups.
- `ProductResource` is quota entitlements (app/Models/ProductResource.php:11/12), NOT asset links — must NOT be repurposed for the reporting relationship.
- Other enterprise models already present and useful to this feature: `Datacenter`, `Rack`, `Vlan`, `IpSubnet`, `License`, `ResourcePool` (routes/admin/enterprise.php) — the "asset" vocabulary is broad; the polymorphic relationship table can point at any of them.

## Industry research ledger (librarian brief, two passes)
- Pass 1 — domain/IP + related-products concepts: WHMCS `require_domain` = `showdomainoptions`; cross-sells = `recommendations()` BelongsToMany (tbl name unconfirmed); Product Bundles separate. Blesta delegates domain-vs-IP to module. Chargebee = Products Families only.
- Pass 2 — server groups / IP pools / product-hosts-product: WHMCS `tblservergroups`+`tblservergroupsrel`+`tblservers`, Fill Type least-full vs strict-default NO core IP pool (dedicated IP = column on service + addon; pools via 3rd-party addon). Blesta `module_groups`/`module_rows` (tables unconfirmed in official docs); module-specific IP pool (Virtualizor `ippoolid`). HostBill no generic server-group entity; IPAM plugin; module "Apps". NONE of the three supports a billable service acting as a server asset (product-hosts-product) — that nesting is unique to this product.

## Decisions (with rationale)
- **D1 — Two separate toggles** (user-confirmed). Keep `require_domain`; add `require_ip` (boolean, nullable, default false). Additive migration; mirrors existing pattern exactly. Order form: matching input shown when either is on; both can be on.
- **D2 — "Related assets" = read-only reporting relationship** (user-confirmed). A polymorphic-on-both-ends `asset_relationships(parent_kind, parent_id, child_kind, child_id, relationship_type, …)` table + admin report(s). Zero effect on orders/clients/billing/provisioning/cascade. Simpler than every depth option previously drafted.
- **D3 — IP lease CONSUMES the existing IPAM; pool is NOT re-built** (user-confirmed). "Next available IP" = first `ip_addresses` row scoped to the chosen ServerGroup's subnet/datacenter with `assigned_to_type IS NULL`. Writes history via `IpAllocationHistory`. Adds a manual override path (select a specific available IpAddress) and an explicit release path, both also writing history. No new lease table.
- **D4 — Optional convenience FK `hosting_accounts.ip_address_id`** (working default: include). It's nullable + ON DELETE SET NULL + indexed; lets the show screen join without walking the polymorphic pair. Pure convenience, no behavioral consequence. (Veto-able at gate.)

## Scope IN
- **C1 — Toggle pair**
  - migration adds `products.require_ip` boolean nullable default false;
  - `Product` fillable + cast (next to `require_domain`);
  - `ProductRequest::rules()` adds `'require_ip' => ['sometimes','boolean']`;
  - `ProductController::productData()` sets `'require_ip' => (bool)($validated['require_ip'] ?? false)`;
  - `admin/products/create.blade.php` + `edit.blade.php` add a checkbox labelled "Requires an IP address" alongside the existing "Requires a domain";
  - `admin/products/show.blade.php` shows "Requires an IP" row next to "Requires domain";
  - order-form (whatever blade renders the cart identifier input) shows ONE input per active toggle; if `require_ip`, validate the input with `'ip'` Laravel rule (accepts both v4 and v6); if `require_domain`, keep the existing domain validator path.
- **C2 — IP provisioning + manual override (consume IPAM)**
  - new service `App\Services\IpAssignmentService` with three methods:
    - `assignNextAvailable(HostingAccount $account, ?int $subnetId = null, ?int $datacenterId = null): ?IpAddress` (transactional select-for-update of `ip_addresses where assigned_to_type IS NULL AND (subnet scope if provided) order by id limit 1`, set assigned_to=`HostingAccount` $account->id, write `IpAllocationHistory` action='assigned'),
    - `assignSpecific(HostingAccount $account, int $ipAddressId): IpAddress` (manual override; requires that row be currently unassigned),
    - `release(HostingAccount $account, ?string $reason = null): void` (clears morph, writes history action='released');
  - **integration point:** called during hosting-account activation / provisioning when `Product.require_ip` is true (location TBD by worker using the existing HostingService activation path — `HostingService::unsuspend()` activates pending accounts); if no available IP in scope, throw a typed exception with a friendly message surfaced in the activation flow;
  - admin UI on the hosting account show/edit blade: a card "IP address" showing the currently assigned IpAddress (reads via the morph assigned_to_type) + buttons "Pull next available" (calls `assignNextAvailable`) / "Choose specific IP" (lists available IPs scoped to the account's server group subnets) / "Release" (calls `release`);
  - unit tests for the service (happy path with a stocked subnet; failure path when subnet empty; manual override on an unassigned IP; release clears morph + writes history); feature test that provisioning a `require_ip` product lease-locks an IP.
- **C3 — Reporting relationship `asset_relationships`**
  - migration creates `asset_relationships(id, parent_kind, parent_id, child_kind, child_id, relationship_type, label, sort_order, notes, created_at)` with indexes on (parent_kind, parent_id) and (child_kind, child_id); `relationship_type` enum {hosted_on, hosted_in, manages, contains}; polymorphic-by-convention (app enforces kind strings);
  - `AssetRelationship` Eloquent model + a thin safety guard `saveGuard` rejecting self-relationships and duplicate (parent,child,type) rows;
  - admin UI: simple "Asset relationships" index (filter by parent_kind/child_kind, search by type) + create/edit form (parent picker with kind select; child picker; type select; label/notes);
  - admin report(s) (the reason the feature exists per user):
    - "Server hosting tree": choose a Server → list every VPS product/service hosted on it (walks asset_relationships where parent_kind='Server' AND child_kind IN ['Product','HostingAccount']);
    - "Product-hosted-on product": choose a Product (e.g. shared hosting) → list every other Product it declares it is hosted on;
    - both reports are read-only (no form mutation through the report); CSV export using the existing project convention.
- Tests for each piece + agent-executed QA per todo (happy + failure, exact tool + invocation, evidence path). TDD where the piece is pure logic (IpAssignmentService, AssetRelationship saveGuard); tests-after where it's a Laravel plumbing change.

## Scope OUT (Must NOT have)
- NO removal or rename of `require_domain`; it stays.
- NO migration of existing data semantics.
- NO touching `ProductResource` (quota layer, unrelated).
- NO marketing cross-sell / "recommended products" tab (explicitly off the table after the user's clarification).
- NO building a new IP pool / `ip_addresses` replacement — consume `ip_addresses` / `ip_subnets` / `ip_allocation_history` AS-IS.
- NO order-blocking constraint, NO state cascade, NO lifecycle coupling for the `asset_relationships` rows — they are reporting-only.
- NO provisioned-side automation that pushes the assigned IP into the actual panel (cPanel/Virtualizor/DirectAdmin) in this plan — that's a separate provisioning-module concern; we only store the lease in our DB.
- NO replace of `Server`/`ServerGroup` with a new asset model — the existing model is the asset layer.
- NO removal of existing IP addresses / datacenters / racks to canonicalize — additive only.

## Open questions
- None remaining. All forks answered.

## Approval gate
status: awaiting-approval
brief: "Additive `require_ip` toggle mirroring `require_domain`; consume the existing IPAM for lease+manual-override+release audit; add a pure report-only `asset_relationships` link + admin reports. No new pool, no lifecycle coupling, no order validation. Approve and I write the plan; execution starts in a separate worker (`/start-work`)."