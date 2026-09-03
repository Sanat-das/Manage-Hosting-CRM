# Changelog

All notable user-facing changes to this project are documented in this file.

## [Unreleased]

### Fixed

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
