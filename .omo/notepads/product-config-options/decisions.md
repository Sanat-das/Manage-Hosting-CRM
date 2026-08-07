# Decisions — product-config-options

Architectural choices and rationales discovered during work on this plan.

_Auto-scaffolded by /start-work. Append new entries below - never overwrite._

---

## 2026-08-06 - Scope decisions from planning
- `require_domain` already exists end-to-end; no new toggle work. IP provisioning triggered by `Product::requiresIp()` returning true for `vps`/`dedicated` types.
- IP lease consumes existing `ip_addresses` polymorphic `assigned_to_type`/`assigned_to_id` + `ip_allocation_history`; no new IP pool table.
- No convenience FK on `hosting_accounts`; assigned IP read via morph pair.
- `asset_relationships` is polymorphic-on-both-ends, read-only reporting only; no order/client/billing/cascade coupling.
- Reports: "Server hosting tree" and "Product-hosted-on product" + CSV export.
