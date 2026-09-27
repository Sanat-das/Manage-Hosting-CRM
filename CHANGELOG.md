# Changelog

All notable user-facing changes to this project are documented in this file.

## [Unreleased]

### Fixed

- **Compute VMs are never mailed a login that exists nowhere.**
  Hyper-V and Proxmox VE welcome emails paired a guest username with the
  generated panel password when no guest credentials had been applied — a
  secret that opens nothing in the guest. Compute VMs without a guest login now
  deliver the service address only (no cPanel link, no "change this password"
  prompt), cloud-init guest credentials are delivered verbatim, and a guest
  username is never paired with the panel password. The audit log still redacts
  whatever was actually sent.

- **Proxmox VE template discovery reports failures instead of "no templates".**
  A privilege-separated API token with no ACL sees zero VMs on every node,
  which rendered exactly like a healthy cluster with no templates. Discovery
  now probes effective privileges and fails when every node errors, and the
  *Clone templates* panel shows the reason as an error rather than an empty
  picker.

- **Terminating a running Proxmox VE VM now works.**
  PVE refuses to destroy a running VM ("VM is running - destroy failed"), so
  the destroy path stops it first. This also fixes the unrecorded-VM cleanup
  action on the server page.

- **Service-instance "Provision Status" now saves, badges and filters for real.**
  The `service_instances.provision_status` column never existed: the select
  saved nothing while reporting success, the badge was always empty, and the
  filter errored with "Unknown column". The column is added and backfilled from
  each row's newest panel account (or its service status), and provisioning
  updates it as the module reports its outcome.

- **Global search: roles holding only `x.manage` now find the records their screens already admit them to.**
  `GlobalSearchService::permissionNames()` expands every held `x.manage` permission into its `x.view` twin,
  mirroring `PermissionMiddleware`'s existing manage-implies-view fallback. A custom role holding only
  `service-instances.manage` (or `hosting.manage`, `domains.manage`, `catalog-products.manage`) could already
  open those screens but got zero search results for them; it now gets the matching groups. The permission
  resolution stays a single query, and nothing is widened in the other direction.

- **Installer: auto-bootstrap `.env` and `APP_KEY` on first boot.**
  `bootstrap/app.php` now copies `.env.example` → `.env` and generates a
  random `APP_KEY` before Laravel starts, so a fresh `git clone` boots
  directly to `/install` without any manual steps.

- **Installer: migrations ran against in-memory SQLite instead of MySQL.**
  `InstallerService` now switches `database.default` and
  `DB::setDefaultConnection` to `mysql` before calling
  `Artisan::call('migrate')`, ensuring tables are created in the configured
  MySQL database and not discarded in the `:memory:` connection.

- **Installer: session/cache driver switched to `database` after migrations.**
  `SESSION_DRIVER` and `CACHE_STORE` are written to `.env` only after
  migrations complete, so the `sessions` and `cache` tables are guaranteed
  to exist before the drivers try to use them.

- **Installer form: Database field no longer pre-fills with `:memory:`.**
  `InstallerController::defaults()` filters the `:memory:` sentinel value
  so the form shows a blank field on a fresh install.

- **IIS: PHP stderr no longer bleeds into the HTTP response body.**
  IIS FastCGI merges PHP `stderr` into the response before any HTML.
  All `error_log()` calls in `ModuleManager` replaced with `Log::warning()`
  so boot failures are written to `storage/logs/laravel.log` only.

- **IIS/Apache: boot timeout on firewalled ports eliminated.**
  `.env.example` now defaults to `DB_CONNECTION=sqlite` /
  `DB_DATABASE=:memory:` with `SESSION_DRIVER=file` and `CACHE_STORE=file`.
  An in-memory SQLite failure is instant (< 1 ms); the previous defaults
  triggered 7–11 s TCP timeouts against firewalled MySQL/Redis ports on
  every request before installation, exceeding IIS FastCGI and Apache
  mod_fcgid request timeouts.

- **IIS: `web.config` no longer contains a hardcoded PHP path.**
  The per-site `<handlers>` entry with `scriptProcessor="…\php-cgi.exe"`
  is removed. PHP is now registered once at the IIS server level via
  Handler Mappings, so the path survives deployments and works across
  servers with different PHP install locations.  See `docs/iis-deployment.md`.

### Added

- **`docs/iis-deployment.md`** — step-by-step IIS deployment reference
  covering server-level FastCGI registration, auto-bootstrap behaviour,
  pre-install boot defaults, stderr isolation, and a redeployment checklist.

- **Compiled Vite assets committed** (`public/build/`). `npm` and Node.js
  are no longer required on the server; assets ship with the repository.

- **Global search across the panel** (`Ctrl/Cmd+K`). A permission-aware
  provider registry of 17 entities (customers, contacts, staff, orders,
  invoices, payments, transactions, quotes, service instances, hosting
  accounts, servers, domains, SSL certificates, tickets, KB articles, catalog
  products, products) now powers both the command palette and the grouped
  `/admin/search` results page. Each provider is one class listed in
  `config/search.php`, and a viewer only ever sees the groups their
  permissions allow. Reference: [docs/search.md](docs/search.md).

- **Proxmox VE provisioning module.** Proxmox VE is no longer a stub: the
  driver creates and manages QEMU/KVM VMs over the PVE REST API — task-polled
  clone/create/resize/start/stop/destroy, curated templates per server with
  per-product restrictions, cloud-init `ciuser`/`cipassword` on templates with
  a cloud-init drive, ISO-aware empty builds (the ISO pool is resolved and the
  file verified before the VM is created), best-effort cleanup of a failed
  build, and a configurable clone timeout. Reference:
  [app/Modules/Proxmox/README.md](app/Modules/Proxmox/README.md).

- **Destroy unrecorded VMs from the server page.** A VM on a Proxmox host with
  no provisioned record — for example a clone that outlived its task timeout —
  is listed under *On host, not provisioned* and can be destroyed by an admin
  holding `hosting.manage` after typing the VMID. VMs that belong to a service
  and templates are refused, and the destroy is audited.

- **Auto/Manual is now part of the provisioning module choice.** The product
  Details dropdown lists every builtin module twice — *Auto* and *Manual*
  (`Proxmox VE — Manual`, `Hyper-V — Auto`, …). Auto provisions when the order
  is paid; Manual never touches the host: the order activates with a pending
  hosting account and the account/VM is built later from the service page,
  with the product's template restriction and optional template selection.
  Hyper-V manual keeps its existing behaviour, and Proxmox VE and Virtualizor
  manual now follow the same flow. The product-level default-template card
  appears for Auto only — a Manual build picks its template at build time.

### Changed

- **Quantity & Service Behaviour is the single switch.** `products.quantity_behaviour`
  (`none` / `multiple_services` / `scaling`) now controls how an ordered quantity
  is interpreted everywhere — the store cart, admin cart, and order validation.
  `none` means the product is sold as a single unit: the order form hides the
  quantity selector and locks the quantity to 1.

### Removed

- **Legacy `sell_single` flag dropped.** The `products.sell_single` column and the
  "Sell as a single unit only" checkbox are gone. Existing single-unit products
  were migrated to `quantity_behaviour = none` automatically, and the column was
  removed from the schema. No action is needed for existing data.

### Added

- **Product pricing & billing configuration** (admin product create/edit, tabbed
  Details / Pricing / Options layout): payment type (free / one-time / recurring),
  an enabled-cycle pricing matrix with live effective-monthly + savings badges,
  per-cycle promo pricing, recurring cycles limit, auto-termination / fixed term,
  prorated billing, early-renewal windows, and configurable-option pricing that
  mirrors the product's enabled billing cycles.
- **Billing engine enforcement**: recurring-cycles-limit ends recurring billing
  after the configured cycle count (the initial invoice counts as cycle 1), and
  fixed-term auto-termination runs on `billing:recurring`.
