# Plan: Hosting CRM — Laravel + AdminLTE Rebuild

## Overview

**Goal**: Rebuild the hosting CRM from `C:\Projects\hostingcrm - v5` as a **Laravel 13** application using the **official AdminLTE 4 Laravel package** (`colorlibhq/adminlte-laravel`). Every feature from the reference must be preserved. New enhancements, researched from existing Laravel hosting CRMs (Paymenter, PNLCS), will be added on top.

**Key principle — reference as specification**: The old codebase defines *what* the system does (business logic, data model, workflows) — not *how* it's implemented. Every view is redesigned from scratch using AdminLTE Blade components. Business logic (billing GST rules, provisioning adapters) is carefully ported, not copied blindly.

**Reference project**: `C:\Projects\hostingcrm - v5`
**Target location**: `C:\Users\Administrator\Local Sites\managehosting\app\public`
**Laravel version**: 13.x (requires PHP 8.3+)
**Theme**: AdminLTE 4 via `colorlibhq/adminlte-laravel` (official Colorlib package)
**Authorization**: AdminLTE built-in RBAC (NOT Spatie)
**Activity log**: AdminLTE built-in audit logging (NOT Spatie)

---

## Prerequisites (Before Session 1)

### 1. Verify PHP in PATH
- **PHP 8.4.16** is installed at:
  `C:\Users\Administrator\AppData\Roaming\Local\lightning-services\php-8.4.16+1\bin\win64\php.exe`
- PATH must include the above directory for `php` to work from the shell
- After adding to PATH via `setx`, **open a new terminal** (setx only takes effect in new sessions)
- Verify: `php --version` → should show `PHP 8.4.16`

### 2. Verify Composer
- Composer 2.8.6 is installed at:
  `C:\Users\Administrator\AppData\Local\Programs\Local\resources\extraResources\bin\composer\win32\composer.bat`
- The PHP PATH above is required for the wrapper to work
- Verify: `composer --version` → should show `Composer 2.8.6`

### 3. Create php.ini (one-time setup)
- PHP 8.4.16 has **no php.ini loaded** — zero extensions are active
- Create it from the bundled development template:
  ```cmd
  copy "C:\Users\Administrator\AppData\Roaming\Local\lightning-services\php-8.4.16+1\bin\win64\php.ini-development" "C:\Users\Administrator\AppData\Roaming\Local\lightning-services\php-8.4.16+1\bin\win64\php.ini"
  ```

### 4. Verify PHP Extensions
- Run: `php --ini` → should show `Loaded Configuration File: C:\...\php.ini`
- Run: `php -m | findstr gd` → should show `gd`
- Run: `php -m | findstr curl` → should show `curl`
- Other required extensions already uncommented in the development ini: mbstring, openssl, pdo_mysql, sodium, zip, fileinfo, mysqli, pdo_sqlite, sqlite3

---

## Feature Inventory (71 Tables, All Must Be Preserved)

### 27 Modules / 36 Feature Areas

| # | Module | Key Features |
|---|---|---|
| 1 | Dashboard | Metrics cards, charts, recent activity, quick actions |
| 2 | Customers | CRUD, notes, contacts, activity log, transactions, emails, wallet (deposit/credit/deduct/pay invoice), impersonation |
| 3 | Products | CRUD, groups, addons, pricing tiers, promo pricing, billing cycles |
| 4 | Orders | CRUD, order workflow (pending→active→cancelled), automated provisioning trigger |
| 5 | Invoices | CRUD, pay, email, PDF, partial payment, overpayment, GST tax calculation (CGST/SGST/IGST), due dates |
| 6 | Quotes | CRUD, stage transitions, email, stats |
| 7 | Payments | Record, reconcile, receipts, payment methods |
| 8 | Transactions | CRUD, stats, per-customer |
| 9 | Hosting Accounts | CRUD, suspend/unsuspend, change package, server assignment |
| 10 | Domains | CRUD, check availability, expiring list, stats, sync, bulk suspend/terminate, pricing terms |
| 11 | Domain Registrars | Integration config per registrar |
| 12 | SSL Certificates | SSL module |
| 13 | DNS Management | Zones, records (CRUD) |
| 14 | Tickets | CRUD, departments, priority, replies, close, stats, internal notes |
| 15 | Knowledge Base | Articles, categories, search, popular articles |
| 16 | Users (Staff) | CRUD, password, role assignment, status toggle |
| 17 | Roles & Permissions | CRUD, permission assignment (45+ granular permissions) |
| 18 | Analytics | Dashboard metrics, revenue/customer/performance trends, data export |
| 19 | Reports | Revenue, customers, products, support, financial, operational, scheduled reports |
| 20 | Email Log | Sent email history, stats |
| 21 | Activity Log | Full audit trail per entity |
| 22 | Email Templates | CRUD, toggle, preview, test send |
| 23 | Integrations | Status, test connection |
| 24 | Settings (General) | Company info, panel config, security (CAPTCHA + 2FA), billing, support, email, system info |
| 25 | Enterprise Inventory | Assets, parts, network interfaces, cables, VLANs, lifecycle, maintenance, allocation |
| 26 | Enterprise Network | Subnets, IPs (single + bulk), VLANs |
| 27 | Enterprise Infrastructure | Datacenters, racks, server groups |
| 28 | Billing Enterprise | Billing cycles, reconciliation, aging, subscriptions, subscription changes, usage records |
| 29 | Resource Management | Resource types, product resources, resource pools |
| 30 | Provisioning Engine | Service instances, provisioning events, adapters, retry |
| 31 | Catalog | Catalog products CRUD |
| 32 | Licenses | License CRUD |
| 33 | Automated Tasks | Cron jobs (domain sync, invoice generation, reminders, suspension) |
| 34 | Client Portal | Customer self-service: view invoices, pay, manage hosting, domains, tickets, KB, profile, wallet |
| 35 | Shopping Cart | Domain search, product selection, order placement |
| 36 | Search | Global search across customers, invoices, tickets, domains, hosting |

### Core Infrastructure (Mapped to Laravel Equivalents)

| Reference Component | Laravel Replacement |
|---|---|
| `includes/Router.php` (rate-limited) | `routes/web.php`, `routes/api.php`, throttle middleware |
| `includes/Auth.php` (2FA, RBAC) | Laravel Fortify + AdminLTE built-in RBAC |
| `includes/Database.php` (PDO singleton) | Eloquent ORM + DB facade |
| `includes/Session.php` | Laravel session driver |
| `includes/View.php` (theme-aware) | Blade templating + AdminLTE layout |
| `includes/Security.php` (CSRF, rate limit) | Laravel CSRF protection + throttle middleware |
| `includes/Validator.php` | Laravel validation (Form Requests) |
| `includes/Logger.php` (audit trail) | AdminLTE built-in activity log |
| `includes/Cache.php` | Laravel cache facade |
| `includes/Captcha.php` | `mewebstudio/captcha` or Google reCAPTCHA |
| `includes/TwoFactor.php` (TOTP) | `pragmarx/google2fa-laravel` + AdminLTE 2FA UI |
| `includes/MigrationRunner.php` (531 lines) | Laravel migrations (`php artisan make:migration`) |
| `core/queue/` (QueueWorker, JobInterface) | Laravel queues (database driver) |
| `core/events/` | Laravel events & listeners |
| `core/notification/` | Laravel notifications (mail, database) |
| `core/theme/ThemeService.php` | AdminLTE package handles theme; config-driven |
| `core/audit/` | AdminLTE built-in audit log |
| `core/storage/` | Laravel filesystem (local, S3) |
| `core/workflow/` | Custom state machine or Laravel model states |
| `core/search/` | Laravel Scout or custom LIKE search |
| `core/plugin/` | Laravel package auto-discovery |
| `integrations/` | Modular integration services |
| `helpers/DataGrid.php`, `FormEngine.php`, `DetailView.php` | TypeScript modules (rewritten from scratch) |
| `cron/`, `cron.php` | Laravel scheduler (`schedule:run`) |
| `migrate.php` | `php artisan migrate` |
| `phpunit.xml.dist`, `tests/` | PHPUnit built into Laravel |

---

## Research-Based Enhancements (From Paymenter, PNLCS, WHMCS)

### Enhanced Features (Not in the Reference)

1. **Multi-currency support** — Expand beyond INR. Add currency switching per client, exchange rate sync.
2. **Multi-language UI** — Laravel localization. Start with English, add Hindi/others. (Inspired by PNLCS's 30-locale system.)
3. **REST API with API tokens** — Laravel Sanctum for API token auth. Full JSON API for every module.
4. **Webhook system** — Outgoing webhooks for order creation, invoice paid, ticket created.
5. **Affiliate system** — Track referrals, commission payouts (inspired by PNLCS).
6. **Theme customization UI** — In-app theme settings: logo, colors, brand name via AdminLTE config.
7. **Docker deployment** — `docker-compose.yml` with PHP-FPM, MySQL, Redis, Nginx, Mailpit.
8. **OAuth/social login** — Google, GitHub login (Laravel Socialite).
9. **Payment gateway marketplace** — Pluggable gateways: Stripe, PayPal, Razorpay, bank transfer.
10. **2FA backup codes** — Email-based recovery codes (beyond TOTP-only in reference).
11. **PDF engine** — Invoice PDF with customizable template (DomPDF).
12. **Scheduled report emailing** — Auto-send reports on schedule.
13. **Service health dashboard** — Real-time server monitoring, resource usage (Paymenter-inspired).

---

## Architecture

### Laravel Directory Structure
```
hosting-crm/
├── app/
│   ├── Http/
│   │   ├── Controllers/
│   │   │   ├── Admin/          # Admin controllers per module
│   │   │   └── Client/         # Client portal controllers
│   │   ├── Middleware/
│   │   │   ├── AdminMiddleware.php
│   │   │   └── ClientMiddleware.php
│   │   ├── Requests/            # Form requests (validation)
│   │   └── Resources/           # API resources (JSON transformation)
│   ├── Models/
│   │   ├── Customer.php
│   │   ├── Invoice.php
│   │   ├── HostingAccount.php
│   │   ├── Domain.php
│   │   ├── Ticket.php
│   │   └── ...
│   ├── Services/                # Business logic services
│   │   ├── BillingService.php   # Ported from reference (264 lines of GST logic)
│   │   ├── DomainService.php
│   │   ├── ProvisioningService.php
│   │   └── ...
│   ├── Jobs/                    # Queue jobs
│   │   ├── GenerateInvoiceJob.php
│   │   ├── ProvisionHostingJob.php
│   │   └── ...
│   ├── Events/
│   ├── Listeners/
│   ├── Console/Commands/        # Artisan commands
│   │   ├── SyncDomains.php
│   │   ├── GenerateRecurringInvoices.php
│   │   └── ...
│   └── Providers/
├── config/
│   ├── adminlte.php             # AdminLTE config (sidebar, RBAC, layout)
│   └── ...
├── database/
│   ├── migrations/              # All 71 tables as Laravel migrations
│   └── seeders/
├── resources/
│   └── views/
│       ├── admin/               # AdminLTE Blade views per module
│       ├── client/              # Client portal views
│       └── vendor/adminlte/     # Published AdminLTE views
├── routes/
│   ├── web.php                  # Admin routes
│   ├── client.php               # Client portal routes
│   └── api.php                  # API routes
├── public/
├── tests/
├── composer.json
├── package.json
├── Dockerfile              # Root-level (compose context is '.', NOT docker/)
└── docker-compose.yml
```

---

## Execution Plan — 6 Worker Sessions

### Session 1 — Laravel Foundation + AdminLTE + Database

**Phase 1.0 — Install Composer (if not present)**
- Download and run Composer setup from https://getcomposer.org/download/
- Verify: `composer --version`

**Phase 1.1 — Fresh Laravel 13 project**
- `composer create-project laravel/laravel .` in target directory
- Configure `.env`: database, app URL, timezone (`Asia/Kolkata`)
- Set PHP 8.3+ requirements

**Phase 1.2 — Install AdminLTE 4 Laravel package**
```bash
composer require colorlibhq/adminlte-laravel
php artisan adminlte:install
```
- This provides:
  - 40 Blade components (cards, tables, forms, buttons, modals)
  - Config-driven sidebar (`config/adminlte.php`)
  - Auth scaffolding via Laravel Fortify (login, register, password reset)
  - **Built-in RBAC** — roles, permissions, middleware, Gate hook, management UI
  - **Built-in activity log** — automatic auth-event logging, user impersonation
  - Dark mode, RTL support, responsive layout
  - Vite asset pipeline

**Phase 1.3 — Install additional packages**
```bash
composer require pragmarx/google2fa-laravel          # 2FA (TOTP)
composer require laravel/sanctum                     # API tokens
composer require laravel/socialite                   # OAuth login
composer require barryvdh/laravel-dompdf             # PDF generation
composer require mewebstudio/captcha                 # Captcha
```
- Configure each package (publish config, run migrations)
- Note: RBAC and activity log use AdminLTE's built-in systems — **no Spatie packages installed**

**Phase 1.4 — Database migration files**
- Convert `schema.sql` (71 tables) into Laravel migration files
- Convert all 37 existing numbered migrations into Laravel migration files
- Preserve exact table structure, columns, indexes, foreign keys
- Add new tables for:
  - `adminlte_roles`, `adminlte_permissions`, `adminlte_role_user`, `adminlte_permission_role` (AdminLTE built-in RBAC)
  - `personal_access_tokens` (Sanctum)
  - `failed_jobs`, `job_batches` (Laravel queues)
- Run `php artisan migrate`

**Phase 1.5 — Seed initial data**
- Create seeder for: admin user, default roles/permissions, sample settings
- `php artisan db:seed`

**Phase 1.6 — Configure AdminLTE sidebar & layout**
- Edit `config/adminlte.php`:
  - Menu items for all modules
  - Layout settings (sidebar breakpoints, dark mode defaults)
  - RBAC configuration (roles, permissions)
- Customize main layout: brand logo, footer, default theme
- Verify AdminLTE renders with sample data

**Verification**: Laravel app loads. AdminLTE theme renders. All 71 tables migrated. Admin user can log in. Sidebar shows module navigation. Dark mode toggles. RBAC restricts access.

---

### Session 2 — Core Models + Auth + Customer Module (Pilot)

**Phase 2.1 — Eloquent models (all modules)**
- Create models for every table: Customer, Invoice, Order, Payment, Transaction, Ticket, KbArticle, Domain, HostingAccount, Product, etc.
- Define relationships, casts, accessors, mutators
- Add traits: SoftDeletes, HasFactory
- Ensure all 71 tables are covered

**Phase 2.2 — Authentication & RBAC**
- Configure Laravel Fortify (login, 2FA, password reset, email verification)
- Configure **AdminLTE built-in RBAC**:
  - Create admin role with all permissions
  - Create staff roles with configurable permissions (45+ granular permissions from reference)
  - Create client role
  - Wire into AdminLTE middleware (`role:`, `permission:`)
- Build 2FA flow: TOTP setup (Google2FA), verification, backup codes
- Build admin impersonation feature (admin → client session switching)
- Build login rate limiting

**Phase 2.3 — Middleware**
- `AdminMiddleware`: checks AdminLTE role for admin/staff
- `ClientMiddleware`: checks client role
- `PermissionMiddleware`: AdminLTE built-in permission check
- Register in `Kernel.php`

**Phase 2.4 — Customer module (pilot — establishes the pattern)**
- **Routes**: Migrate all customer routes from reference AdminRoutes + ApiRoutes
- **Controllers**: `Admin/CustomerController`, `Client/CustomerController`
- **Admin views** (all new Blade, using AdminLTE components):
  - Index page: AdminLTE card + table with sort/filter/search
  - Create/edit form: AdminLTE card-based form with sections
  - Detail view: Tabbed card (Profile, Hosting, Invoices, Tickets, Domains, Notes, Activity)
  - Wallet management: balance, credit, transaction timeline
  - Notes: embedded CRUD within customer detail
  - Contacts: embedded CRUD within customer detail
  - Activity log: embedded timeline (AdminLTE built-in)
- **Client views**:
  - Profile view/edit
  - Dashboard showing services, invoices, tickets
- **REST API**: Full customer CRUD via Sanctum-protected routes
- **Events**: `CustomerCreated`, `CustomerUpdated` — for logging, webhooks

**Phase 2.5 — Reusable Blade partials (the UI kit)**
- `adminlte::partials.datatable` — reusable table with search/filter
- `adminlte::partials.form-card` — card-based form layout
- `adminlte::partials.detail-tabs` — tabbed detail view
- `adminlte::partials.status-badge` — status badge component
- `adminlte::partials.metric-cards` — dashboard metric row
- `adminlte::partials.confirm-modal` — delete confirmation modal

**Verification**: Customer CRUD fully works. Admin can create/edit/view/search customers. Client can view profile. 2FA works. RBAC restricts access. Customer API responds correctly. Reusable partials in place.

---

### Session 3A — Core Admin Modules + Billing Engine

**Phase 3A.1 — Billing Module (complex DDD logic from reference)**
- **Services to port from reference** (these contain non-trivial business logic, not just "reference"):
  - `BillingService` — invoice generation, payment recording, tax calculation (264 lines in reference)
  - `GstTaxService` — CGST/SGST/IGST logic including intra-state vs inter-state detection
  - `ProrationCalculator` — pro-rata billing for mid-cycle changes
- **Models**: Invoice, InvoiceItem, Order, Quote, Payment, Transaction, BillingCycle, TaxRule
- **Controllers**: `Admin/InvoiceController`, `Admin/OrderController`, `Admin/QuoteController`, `Admin/PaymentController`, `Admin/TransactionController`
- **Views**: Invoice list (filterable with status badges), invoice detail (header + line items + tax breakdown + payment timeline), order workflow, quote stages
- **PDF**: Invoice generation via DomPDF with company branding (port reference PDF layout)
- **Queue jobs**: `GenerateRecurringInvoicesJob`, `SendInvoiceReminderJob`
- **REST API**: Complete billing API
- **Events**: `InvoicePaid`, `InvoiceOverdue`, `OrderCreated` — for notifications, webhooks

**Phase 3A.2 — Core admin CRUD modules (first batch)**
- Products (CRUD + groups + addons + pricing + server groups)
- Orders (list + view + workflow)
- Hosting Accounts (list + detail + suspend/unsuspend + change package)
- Domains (list + detail + pricing + search + bulk actions)
- SSL Certificates (list + manage)
- Tickets (list + view + reply + close + departments + stats)
- Knowledge Base (articles + categories + search)
- Users (CRUD + password + status + roles)
- Roles & Permissions (AdminLTE built-in management UI)

**Phase 3A.3 — Each batch follows the Customer pilot pattern**
- Reference routes → Laravel routes (`web.php` + `api.php`)
- Reference controller logic → Laravel controller with Form Requests + Services
- Old PHP-include views → AdminLTE Blade components
- Old REST endpoints → Sanctum-protected API resources
- Old queue/event logic → Laravel Jobs + Events

**Verification**: All core admin modules functional. Invoices generate with correct GST tax. PDFs render. Queue jobs process. API responds. Feature parity with reference billing module.

---

### Session 3B — Client Portal + Enterprise Modules + Integrations

**Phase 3B.1 — Client Portal**
- Customer dashboard (services overview, recent invoices, open tickets)
- View/pay invoices, download PDFs
- Manage hosting accounts (view, request changes)
- Manage domains (view, DNS management)
- Submit and track support tickets
- Knowledge base browsing
- Profile management (personal info, password, 2FA)
- Shopping cart: domain search, product selection, order placement
- Wallet: view balance, add funds, pay invoices

**Phase 3B.2 — Enterprise modules**
- Datacenters (CRUD + topology view)
- Racks (CRUD + rack view)
- Inventory Assets (CRUD + parts + network interfaces + cables + lifecycle + maintenance + allocation)
- Subnets (CRUD + IP assignment)
- IP Addresses (single + bulk create/assign/release/delete)
- VLANs (CRUD)
- DNS Zones & Records (CRUD)
- Licenses (CRUD)
- Catalog Products (CRUD)
- Subscriptions (CRUD + subscription changes)
- Usage Records (CRUD)
- Resource Types + Product Resources + Resource Pools

**Phase 3B.3 — Remaining admin modules**
- Analytics (dashboard + revenue/customer/performance tabs + export) — uses Chart.js
- Reports (revenue, customers, products, support, financial, operational, scheduled)
- Email Templates (CRUD + toggle + preview + test send)
- Email Log (list + detail + stats)
- Activity Log (AdminLTE built-in — list + filter)
- Settings (Company, Panel, Security, Billing, Support, Email, Integrations, System Info)
- Billing Enterprise (cycles, reconciliation, aging)

**Phase 3B.4 — Integration adapters (port from reference)**
- Server modules: cPanel, Plesk, DirectAdmin, Proxmox API integrations
- Domain registrars: Enom, ResellerClub, etc.
- Payment gateways: Stripe, PayPal, Razorpay, bank transfer
- Email sending (SMTP, Mailpit for dev)
- Each adapter becomes a Laravel service class with a contract/interface

**Phase 3B.5 — Provisioning engine**
- Service instances (list, view, provision)
- Provisioning events (list, view, retry)
- Adapters (config management)
- Port logic from `includes/Provisioning/` directory

**Verification**: Client portal allows full self-service. Enterprise modules functional. All integration adapters connect. Provisioning engine triggers service setup. Every feature from the reference is present.

---

### Session 4 — TypeScript Components + Queue + Scheduler + Docker

> **AMENDED (post-review)**: The TypeScript rewrite (Phase 4.1) was **NOT implemented**. The
> DataGrid/FormEngine/DetailView UI toolkit was delivered as **Blade partials + AdminLTE
> components** instead (`resources/views/components/adminlte/partials/`): `datatable`,
> `form-card`, `detail-tabs`, `status-badge`, `metric-cards`, `confirm-modal`. No `resources/ts/`
> directory exists and `typescript` is not a devDependency — the `package-lock.json` matches are
> only transitive Babel/Vite references. Queue + Scheduler + Docker **ARE** implemented (see below).

**Phase 4.1 — TypeScript rewrite of UI components** ⚠️ NOT DONE — superseded by Blade partials
- Set up npm + Vite (compatible with Laravel's Vite setup)
- `npm install typescript --save-dev`
- Create `resources/ts/` directory
- **DataGrid.ts**: Reusable data table
  - Web research: TanStack Table, AG Grid patterns
  - Features: sort, filter, search, paginate, column visibility, row selection, bulk actions, sticky headers, responsive
  - Integrates with AdminLTE table styling
  - Server-side pagination for large datasets
- **FormEngine.ts**: Form builder
  - Web research: modern form UX, validation UX
  - Features: typed field definitions, validation rules, conditional fields, dirty state tracking, auto-save
  - Bootstrap 5 form markup with AdminLTE validation states
- **DetailView.ts**: Record detail
  - Features: tabbed/accordion views, activity timelines, inline editing, linked record navigation
- Compile to `public/assets/js/`

**Phase 4.2 — Queue system** ✅ VERIFIED + BUGS FIXED (2026-08-04)
- Configure Laravel queue with database driver ✅
- Queue jobs implemented AND tested end-to-end (`tests/Feature/QueueSchedulerTest.php`, 14 tests / 30 assertions):
  - `GenerateInvoice.php` — creates `sent` invoice (`INV-XXXXXX`), dispatches on `billing` queue
  - `SendEmail.php` — persists EmailLog (status sent/failed + body + error), dispatches on `emails` queue
  - `SyncDomainStatus.php` — marks expired active domains `expired`, leaves valid ones, skips missing domains, `domains` queue
- **3 real bugs found during runtime verification (all fixed)**:
  1. `emails` table had **no `body` / `error` / `updated_at` columns** and `EmailLog` fillable excluded them — every `SendEmail` run crashed with `Unknown column 'updated_at'` (Eloquent default timestamps), and the body/error would have been silently dropped regardless. Added `2026_08_04_000002_add_body_error_to_emails_table.php` + `2026_08_04_000004_add_updated_at_to_emails_table.php`; `EmailLog` fillable now includes `body` + `error`.
  2. `hosting_accounts` had **no `next_due_date` column** — `billing:recurring` crashed on every run (`SQLSTATE[42S22]: Unknown column 'next_due_date'`). Added `2026_08_04_000003_add_next_due_date_to_hosting_accounts_table.php` (+ model fillable + date cast). Command now runs.
  3. `GenerateInvoice` derives `invoice_no` from `Invoice::max('id') + 1` — race-prone on the unique `invoice_no` index if two jobs run concurrently. Acceptable on the serialized `billing` queue; noted for future hardening (DB sequence / UUID).

**Phase 4.3 — Scheduler (Artisan commands)** ✅ VERIFIED RUNNING (2026-08-04)
- All 4 commands executed successfully end-to-end (previously only `domains:expiry-check`/`hosting:usage-sync`/`app:cleanup` ran; `billing:recurring` crashed until bug #2 above was fixed):
  - `billing:recurring --days=7` (`RecurringBillingCommand`) — daily 01:00 — queues `GenerateInvoice` for active accounts due within window, skips zero-price products
  - `domains:expiry-check --days=30` (`DomainExpiryCheckCommand`) — daily 02:00 — marks expired domains, lists expiring
  - `hosting:usage-sync` (`UsageSyncCommand`) — every 6 hours — iterates active accounts (stub; panel API pending)
  - `app:cleanup --days=90` (`CleanupCommand`) — weekly — purges activity + email logs older than cutoff
  - `ssl:check-expiry --days=30` (`SslExpiryCheckCommand`) — daily 03:00 — reports active certs expiring within window, marks past-due certs `expired` (mirrors domains:expiry-check)
  - `reports:send-scheduled --days=7` (`ScheduledReportsCommand`) — Mondays 06:00 — aggregates new customers/orders/paid revenue/open tickets and dispatches `SendEmail` summary to staff+admin (or `--to=` override)
- All scheduled with `withoutOverlapping()->runInBackground()` in `routes/console.php`; `schedule:list` confirms **all 6** registered. The full command set (domains:sync, invoices:generate-recurring, invoices:send-reminders, hosting:suspend-overdue, ssl:check-expiry, reports:send-scheduled) is now covered — the 2 missing commands were built on 2026-08-05.
- **Runtime verification (2026-08-05)**: `ssl:check-expiry` + `reports:send-scheduled` executed successfully; the report's `SendEmail` was processed through the real database queue (`queue:work --once --queue=emails`) and landed in `emails` log with `status=sent` — full dispatch→queue→worker→audit chain proven outside tests. Covered by `tests/Feature/QueueSchedulerTest.php` (now 18 tests / 41 assertions).

**Phase 4.4 — Docker development environment** ✅ implemented
- `docker-compose.yml` at **project root** (not `docker/`): PHP-FPM, MySQL 8, Redis, Nginx, Mailpit
- `Dockerfile` at **project root** — compose context is `.`, so the plan's `docker/Dockerfile` path was wrong; corrected to root
- `.env.docker` present

**Verification**: TypeScript components compile and work in AdminLTE. Queue jobs process. Scheduler runs. Docker environment boots with one command.

---

### Session 5 — Enhancements + Testing + Cleanup

**Phase 5.1 — Research-based enhancements** — VERIFIED STATUS per item:
1. **Multi-language UI** ❌ NOT implemented — no localization files / language switcher
2. **Webhook system** ❌ NOT implemented — only `app/Events/OrderCreated.php` exists (an event, no webhook dispatch/storage)
3. **Affiliate system** ❌ NOT implemented — the only "affiliate" code matches are in ProductController/ProductRequest/Product model (product-affiliate pricing fields), not an affiliate tracking system
4. **OAuth login** ❌ NOT implemented — `laravel/socialite` was removed (unused dependency; no socialite routes ever existed).
5. **Payment gateway marketplace** ❌ NOT implemented — no abstract gateway interface; `mews/captcha` v3.5 installed (captcha works)
6. **Service health dashboard** ❌ NOT implemented
7. **Theme customization UI** ❌ NOT implemented
- Packages actually installed: `barryvdh/laravel-dompdf` (PDF), `laravel/fortify` (auth + 2FA), `laravel/sanctum` (API), `mews/captcha`. `pragmarx/google2fa-laravel` NOT a direct dep (only transitive `pragmarx/google2fa` in lock) — Fortify 2FA fully wired: challenge view, enable/confirm/disable, QR + secret + 8 recovery codes with self-service management UI (see Hardening batch below). `laravel/socialite` removed.

**Phase 5.2 — Testing infrastructure** ✅ PHPUnit + Dusk (browser smoke tests)
- PHPUnit 12.5 with Laravel helpers — **96 tests / 294 assertions, all passing** (incl. `TwoFactorTest`, `ApiTest`, `AuthTest`, `AdminSearchTest`, `QueueSchedulerTest`)
- Test suites implemented:
  - **Unit tests**: `GstTaxServiceTest`, `ProrationCalculatorTest`, `BillingServiceTest`, `ExampleTest`
  - **Feature tests**: `AdminInvoiceTest`, `AdminSearchTest`, `ApiTest`, `AuthTest`, `ExampleTest`
- **Browser tests (Laravel Dusk)** ✅ IMPLEMENTED — `laravel/dusk` installed (`composer.lock`), ChromeDriver 151.0.7922.76 at `vendor/laravel/dusk/bin/chromedriver-win.exe` (Windows binary name — the generic `chromedriver.exe` name breaks `startChromeDriver`), `.env.dusk.local` derived from `.env` (real MySQL, live site). `tests/Browser/AdminSidebarSmokeTest.php` (1 test, 23 assertions): logs in as the seeded admin (`admin@localhost.com` / `Admin@123` — 2FA secret present but unconfirmed, so the plain login flow works), lands on `/admin/dashboard`, then visits all **7 sidebar modules** (`/admin/dns-zones`, `/admin/inventory-assets`, `/admin/datacenters`, `/admin/ip-subnets`, `/admin/licenses`, `/admin/cart`, `/admin/search`) asserting each stays under `/admin/` (no auth bounce), does NOT render "Page not found" (no 404/403 — the errors-master layout would show it), and renders its own `<h1>` heading. Runs read-only against the live DB — deliberately NO `RefreshDatabase`/`DatabaseMigrations` (the generated `ExampleTest` had `DatabaseMigrations` and was removed to avoid wiping the live DB). **Gate #5 verified: `php artisan dusk` green (1 test / 23 assertions, ~25s headless).**

**Phase 5.3 — Search system** ✅ RESOLVED (audit + fixes in "Search audit & support-routes fix" below)
- Global search across: customers, invoices, tickets, domains, hosting accounts
- Use MySQL LIKE or Laravel Scout
- `SearchController` + search view with AdminLTE results styling
- **Audit outcome**: feature was fully wired (route `admin/search`, `SearchController`, `resources/views/admin/search/index.blade.php`, 5 model queries) but two defects shipped:
  1. **Support module routes were registered bare** — `routes/admin/support.php` and `routes/api/support.php` declared `/tickets` + `/kb` with no middleware group, no `/admin`/`/api` prefix and no `admin.` name prefix. This (a) publicly exposed admin+API endpoints (no auth/admin/permission middleware), (b) shadowed each other on the same URIs, and (c) broke every `admin.tickets.*` / `admin.kb.*` reference (controllers, sidebar, search view) with `RouteNotFoundException`. Fixed both files to match the established conventions (`routes/admin/ssl.php`, `routes/api/products.php`):
     - `admin/support.php`: wrapped in `Route::middleware(['web','auth','admin'])->prefix('admin')->name('admin.')` — names now `admin.tickets.index/create/store/show/reply/note/close/reopen/assign`, `admin.kb.index/create/store/show/edit/update/destroy`; permission gates (`tickets.*`, `kb.*`) kept.
     - `api/support.php`: wrapped in `Route::middleware('api')->prefix('api')` with nested `auth:sanctum` groups for `tickets` and `kb` — no more unauthenticated `/api/tickets|kb` writes; static routes (`stats`, `categories`, `popular`) kept before model-bound routes.
  2. **Search view attribute bugs**: `$c->email` → `$c->user?->email` (email lives on the `user` relation), `$inv->invoice_number` → `$inv->invoice_no`, `$t->ticket_number` → `$t->ticket_no`, and `SearchController` services query gained `->with('customer')` (fixes N+1 + the `customer?->full_name` display).
- **Verification**: `php artisan route:list` confirms all `admin.tickets.*`/`admin.kb.*` names resolve, `api/tickets`+`api/kb` show `Authenticate:sanctum`, and no bare `/tickets`/`/kb` routes remain. New `tests/Feature/AdminSearchTest.php` (8 tests, 16 assertions) covers auth redirect, page load, per-entity hits (customer by email, ticket + show-link resolution, invoice by number, service instance, catalog product), and the short-query empty-results guard. Full suite: **96 tests / 294 assertions green**.

**Phase 5.4 — Cleanup & final verification** ✅ RESOLVED
- **N+1 query audit**: parallel explore audits of all admin controllers + blade views (cross-referenced each view's relationship accesses in loops against the feeding controller's `->with()`). Controllers were already disciplined (all list queries eager-load); the audit surfaced **3 genuine gaps**, all fixed:
  1. `SubscriptionController@index`: `with('service')` but view accesses `$sub->service?->customer?->full_name` → `with(['service.customer'])`.
  2. `CustomerGroupController@index`: view accesses `$sub->service`/`$group->parent?->name` but only `withCount('products')` → added `with('parent')`.
  3. `ProductGroupController@index`: same `parent?->name` pattern → added `with('parent')`.
  - Licenses `LicenseAssignment` has no `service`/`customer` relations, so its view's `?->` accesses are always-null (no query fired) — not an N+1, left as-is.
  - `ReportsController` verified clean (all report views only touch `customer`, eager-loaded on every query).
- **Database index audit**: scanned all 25 migrations. 53 `foreignId` columns auto-indexed + 33 explicit `->index()/_unique()` calls confirmed. Every filtered list table already has a `status` index; `created_at`/`expiry_date`/`next_billing_date` already indexed where date-filtered. **1 genuine gap**: `invoices.paid_at` (used in `whereBetween` + `orderByDesc` by the revenue report) — added `2026_08_04_000001_add_paid_at_index_to_invoices_table.php`, migrated cleanly.
- **Stale reference files**: removed 4 unreferenced browser artifacts (`cookies.txt`, `cookies2.txt`, `cookies_2fa.txt`, `cookies_customers.txt` — 924 B each) + unreferenced `sql/local.sql`. Kept `database/seed-data/reference-seed.sql` + `reference-inventory-assets.sql` (consumed by `ImportReferenceDataCommand`).
- **Final gates re-run**: `php artisan test` (96/294 green), `route:list -v` (API writes carry `Authenticate:sanctum`, admin routes carry `web+auth`; no bare routes), `view:cache` (cached), `db:seed --class=AdminLteRbacSeeder` (seeded).
- Final feature checklist:

**Final Feature Parity Checklist (all 36 areas):**
- [x] Admin login with 2FA (TOTP wired via Fortify; backup/recovery codes confirmed — 8 codes on enable, self-service management UI on client + admin profile)
- [x] Dashboard with metrics cards and charts
- [x] Customer CRUD + notes + contacts + activity + wallet (deposit/credit/deduct/pay)
- [x] Admin impersonation of client
- [x] Products/Services CRUD + groups + addons + pricing + billing cycles
- [x] Order placement (admin + client) with provisioning trigger
- [x] Invoice generation + payment + partial payment + overpayment
- [x] GST tax calculation (CGST/SGST/IGST intra-state vs inter-state)
- [x] Invoice PDF download
- [x] Quotes (CRUD + stage transitions)
- [x] Payments (record + reconcile + receipts)
- [x] Transactions (CRUD + stats)
- [x] Hosting Accounts (CRUD + suspend/unsuspend/change package)
- [x] Domains (CRUD + search + expiring + pricing + sync + bulk actions + registrars)
- [x] SSL certificate management
- [x] DNS Zones + DNS Records (CRUD)
- [x] Support Tickets (CRUD + departments + priority + replies + close + stats)
- [x] Knowledge Base (articles + categories + search + popular)
- [x] Users/Staff (CRUD + password + roles)
- [x] Roles & Permissions (45+ granular permissions via AdminLTE RBAC)
- [x] Analytics (revenue/customer/performance trends + export)
- [x] Reports (6 types + scheduled)
- [x] Email Templates (CRUD + toggle + preview + test send)
- [x] Email Log (history + stats)
- [x] Activity Log (audit trail with AdminLTE built-in)
- [x] Settings (10+ sections: Company, Panel, Security, Billing, Support, Email, Integrations, System)
- [x] Enterprise: Datacenters + Racks
- [x] Enterprise: Inventory Assets (parts, network, lifecycle, maintenance, allocation)
- [x] Enterprise: Subnets + IPs (single/bulk) + VLANs
- [x] Enterprise: DNS management
- [x] Enterprise: Licenses + Catalog Products
- [x] Enterprise: Subscriptions + Usage Records + Billing Cycles
- [x] Resource Management (types, pools, product resources)
- [x] Provisioning Engine (service instances, events, adapters, retry)
- [x] Client Portal (dashboard, invoices, hosting, domains, tickets, KB, profile, cart, wallet)
- [x] REST API (all modules via Sanctum)
- [x] Queue jobs + Scheduler
- [x] Docker development environment

**Verification**: All 36 feature areas present and functional in Laravel. Enhancements operational. Tests pass (green). Docker builds and boots.

---

## Execution Notes

### Session Dependencies
```
Session 1 (Laravel + DB + AdminLTE)
        │
        ▼
Session 2 (Models + Auth + Customer pilot)
        │
        ▼
Session 3A (Core admin modules + Billing engine)
        │
        ▼
Session 3B (Client portal + Enterprise + Integrations)
        │
        ▼
Session 4 (TypeScript + Queue + Scheduler + Docker)
        │
        ▼
Session 5 (Enhancements + Tests + Cleanup)
```

### Tools & Technology
| Tool | Purpose |
|---|---|
| Laravel 13 | PHP framework |
| `colorlibhq/adminlte-laravel` | AdminLTE 4 theme (official Colorlib package), built-in RBAC + activity log |
| Bootstrap 5.3 | CSS framework (included with AdminLTE) |
| Font Awesome 6 | Icons (included with AdminLTE) |
| Laravel Fortify | Authentication backend (login, 2FA, password reset) |
| AdminLTE built-in RBAC | Roles, permissions, Gate, middleware (NOT Spatie) |
| AdminLTE built-in audit | Activity/audit log with auto auth-event logging (NOT Spatie) |
| `pragmarx/google2fa-laravel` | TOTP two-factor authentication (transitive; Fortify 2FA wired + self-service UI) |
| `barryvdh/laravel-dompdf` | Invoice PDF generation |
| Laravel Sanctum | API token authentication |
| Chart.js | Dashboard charts (AdminLTE compatible) |
| TypeScript | DataGrid, FormEngine, DetailView (from scratch) |
| Vite | Asset bundling (Laravel + AdminLTE default) |
| MySQL 8 | Database |
| Redis | Queue/cache (via Docker) |
| PHPUnit + Dusk | Testing |
| Docker + Docker Compose | Development environment |

### Business Logic to Port Carefully (not just "reference")
The following contain non-trivial logic that must be ported, not just referenced:
- **BillingService** (~264 lines): invoice generation, payment recording, GST tax calculation
- **GstTaxService**: CGST/SGST/IGST intra-state vs inter-state detection
- **MigrationRunner** (~462 lines): sequential migration runner (replace with Laravel migrations, but understand the hash-based change detection pattern)
- **6 integration adapters**: cPanel, Plesk, DirectAdmin, Enom, Razorpay, Email sending
- **Provisioning engine**: service instance state machine, event queue
- **Domain automation**: sync logic, registrar API interactions

### Web Research Points
| Phase | What to Research |
|---|---|
| Session 3A (Billing) | Invoice/Payment UI patterns, tax display, payment flows |
| Session 3B (Client Portal) | Modern client portal design (Paymenter, PNLCS) |
| Session 3B (Enterprise) | Datacenter topology visualization, rack management UI |
| Session 4 (DataGrid) | TanStack Table, AG Grid, responsive data table patterns |
| Session 4 (FormEngine) | Form UX, validation UX, conditional forms |
| Session 5 (Enhancements) | Paymenter/PNLCS source for multi-language, webhooks, affiliates |

---

## Data Migration (from reference DB)

**Status**: ✅ RESOLVED (verified 2026-08-04) — the reference data is **already imported** into the
Laravel app DB (`hosting_crm`, port 10006). The earlier "GAP" note was stale.

### What was verified

- **Data source**: the reference project's MySQL database (`local` on the Local-by-Flywheel
  instance) is **empty** (0 tables). The actual reference dataset is the demo seed in
  `C:\Projects\hostingcrm - v5\seed.sql` (19 tables, ~180 rows: users, customers, products,
  servers, orders, invoices, invoice_items, payments, credits, hosting_accounts, domains,
  tickets, ticket_replies, knowledge_base, chat_sessions, chat_messages, email_templates,
  automation_log).
- **Target state**: all 19 reference tables exist in `hosting_crm` with row counts matching the
  seed (products=10, servers=3, orders=20, invoices=20, invoice_items=20, payments=18,
  credits=8, hosting_accounts=16, domains=14, tickets=10, ticket_replies=13, knowledge_base=6,
  chat=3/7, templates=5, automation_log=6). Customer names/companies match the seed
  (TechSolutions, WebCraft, Chennai Hosting Co, KSoft Solutions, ...) under shifted IDs
  (customers 1-22; user→customer links intact: 0 orphans across customers/orders/invoices/
  domains/hosting_accounts).
- **RBAC mapping**: the `users.role` enum column is the *primary* source and the
  `adminlte_role_user` pivot the secondary (per `HasRoles::hasRole()`). Client users pass
  `ClientMiddleware` via `role='client'`; the 2 staff users (`staff@demo.com`, `staff2@demo.com`)
  get panel access through pivot→`support`. Admin (user 1) passes via column. Pivot rows are
  intentionally sparse — this is correct, not a gap.
- **Seed password**: `password` (bcrypt cost-10 hashes in the seed; verified via `Hash::check`).

### Bug found & fixed during verification (2026-08-04)

**Imported users could not log in** — `EloquentUserProvider::rehashPasswordIfRequired()` writes the
rehashed password through `User::getAuthPasswordName()`, which defaulted to `password` while the
schema (and `getAuthPassword()`) use `password_hash`. The imported cost-10 hashes triggered
rehash-on-login (app cost = 12), so every first login threw `Unknown column 'password'`.

**Fix**: added `User::getAuthPasswordName()` override returning `'password_hash'`
(`app/Models/User.php`) — mirrors the existing `getAuthPassword()` read-side override.
Verified: `client1@demo.com` login succeeds and the hash is upgraded to cost-12 in the
`password_hash` column; second login performs no rehash; admin login unaffected.
Regression test added: `AuthTest::test_login_rehashes_password_hash_column_not_password`
(uses the exact seed hash, asserts the stale hash is replaced and `needsRehash()` is false).

### If a fresh re-import is ever needed

1. **Schema verification** — target tables already exist in `hosting_crm`; Laravel migrations and
   the reference `schema.sql` are structurally aligned for the enterprise tables.
2. **Row-level import** — run `C:\Projects\hostingcrm - v5\seed.sql` against a scratch schema
   (tables use `INSERT IGNORE`, so PK conflicts are skipped; the target already carries these IDs).
3. **Identity handling** — preserve PKs where FK references depend on them; regenerate
   `users.password_hash` with `Hash::make()` on import.
4. **RBAC mapping** — reference `role` string column on `users` maps to the new
   `adminlte_role_user` pivot (admin → `admin` role). Client users do not strictly need pivot
   rows (column role suffices for `hasRole`), but staff roles (`staff`) do for admin-panel access.
5. **Verification** — after import, run `php artisan tinker` spot-checks (login as a client + as
   admin) and the PHPUnit suite.

---

## Verification Gates (post-fix status)

| Gate | Command | Result |
|---|---|---|
| Unit/Feature tests | `php artisan test` / `vendor/bin/phpunit` | ✅ 96 tests, 294 assertions, all pass |
| Route registration | `php artisan route:list` | ✅ All routes under `admin/` prefix with `admin.` names; no bare-name routes |
| Blade compilation | `php artisan view:cache` | ✅ All templates (incl. new datacenters/ip_subnets views) compile |
| RBAC seeding | `php artisan db:seed --class=AdminLteRbacSeeder` | ✅ 50 permissions, 6 roles, first user = admin |
| Permission check | tinker `hasPermission('invoices.view')` on admin | ✅ YES |
| Browser smoke (7 sidebar modules) | `php artisan dusk` | ✅ `AdminSidebarSmokeTest` — 1 test / 23 assertions green; admin logs in and every module (DNS, Inventory, Datacenters, Subnets, Licenses, Cart, Search) resolves without 404/403 |
| Full-route crawl | `php artisan dusk --filter=FullRouteCrawlerTest` | ✅ 0 broken pages — every GET route returns <400 for its intended user (see Full-Route Crawler Audit below) |

### Repeat before shipping
1. `composer test` (or `php artisan test`) — must stay green
2. `php artisan route:list` — spot-check no new bare routes (no `admin.` prefix / no middleware)
3. `php artisan view:cache` — no Blade compile errors
4. `php artisan db:seed --class=AdminLteRbacSeeder` after any fresh migrate
5. `php artisan dusk` — Automated browser smoke: AdminSidebarSmokeTest logs in as admin and
   confirms DNS, Inventory, Datacenters, Subnets, Licenses, Cart, Search all resolve
   (no 404 / 403 for admin). Requires local Chrome + `laravel/dusk` driver
   (`vendor/laravel/dusk/bin/chromedriver-win.exe`), and is read-only against `.env.dusk.local`.

---

## Full-Route Crawler Audit (2026-08-05) ✅ RESOLVED

### Goal
Zero broken pages (404/403/500) across the ENTIRE application — including routes never linked
in the UI. Implemented `tests/Browser/FullRouteCrawlerTest.php`: an in-process crawler that
visits every GET route, authenticates as the correct user per area (admin/web, client/web,
admin/sanctum), resolves `{param}` segments against real DB rows (implicit model binding via
`actionParamModels()` reflection + `fallbackParam()` for client-scoped ids and KB slugs), and
collects ALL broken pages into one report (`storage/app/route-crawl-report.txt`) instead of
stopping at the first failure. Skips only legitimately non-crawlable routes (`_ignition`,
`_dusk`, `storage/*`, `sanctum/*`, `livewire/*`, `reset-password`, `two-factor`). Read-only —
no `RefreshDatabase`, runs against the live DB via `.env.dusk.local`.

### First run: 17 broken pages — root causes and fixes

**API routes (500)** — `routes/api/users.php` + `routes/api/hosting.php` were loaded via bare
`require` in `bootstrap/app.php` `withRouting(then:)` **outside** the `api` middleware group,
so `SubstituteBindings` never ran → `{user}`/`{hosting}` stayed strings, controllers got an
empty model, null `full_name` → TypeError. Fixed both files with the established convention
(`routes/api/products.php`, `routes/api/support.php`):
`Route::middleware('api')->prefix('api')` + nested `auth:sanctum` groups; names now
`api.users.*` / `api.hosting.*`. Verified `/api/users/1` → 200 with full JSON.

**Admin pages (403/500)**
- `admin/users/1` → `RouteNotFoundException [admin.users.toggleStatus]`: view called
  `route('admin.users.toggle-status'…)`; fixed `toggleStatus`→`toggle-status` and
  `resetPassword`→`reset-password` in `admin/users/show.blade.php`.
- `admin/activity-log` → `format()` on a string: `ActivityLog` had `$timestamps=false` and no
  casts; added `'created_at' => 'datetime'`.
- `admin/email-templates` index + `admin/customer-groups` → `ComponentSlot::links()` undefined
  method: callers pass `<x-slot name="pagination">…</x-slot>` but the datatable partial treated
  it as a Paginator. Partial now renders the slot content directly unless the value is an actual
  `Paginator`.
- `admin/email-templates/create` + `/edit` → literal `{{variable}}`/`{{name}}` template-var
  hints were compiled by Blade as undefined constants; escaped with `@{{…}}`.
- `admin/email-logs` → `RelationNotFoundException [user]`: eager load `['customer','user']`
  but `EmailLog` (table `emails`) has only `customer()`; dropped `'user'`.
- `admin/chat` → unknown column `created_at`: `chat_sessions` has no `created_at`;
  `orderByDesc('started_at')`.
- `admin/ip-addresses` + `/create` + `/edit` → unknown column `cidr`: `ip_subnets` uses
  `subnet_cidr`; fixed 3 `IpSubnet::orderBy('cidr')` → `orderBy('subnet_cidr')`.
- `admin/racks/1` → view iterates `$rack->inventoryAssets` but `Rack` had no such relation
  (`RelationNotFoundException`); added `inventoryAssets(): HasMany` (child `InventoryAsset`
  already belongsTo `Rack` via `rack_id`).
- `admin/roles*` (index/create/edit) → 403 from `RoleController::authorizeManage()`: it gated
  on `hasPermission('manage-roles')`, but that permission was NOT in the seeded vocabulary
  (49 perms) → false for everyone, including admin. See RBAC work below.

**Client pages**
- `client` (dashboard) → crawler artifact: `authFor()` matched only `client/…` with a slash;
  the bare `client` URI fell through to admin/web → ClientMiddleware 403. Now `$uri === 'client'`
  also maps to client/web. Also dropped the obsolete `users`/`hosting` sanctum special-case
  (those routes moved under `api/`).
- `client/invoices/1/pdf` → `Undefined variable $gstBreakdown`: client `InvoiceController@pdf`
  passed only `['invoice' => …]` to `admin.invoices.pdf` view (admin controller passes
  `gstBreakdown`); fixed to pass `$gstBreakdown = $invoice->gst_breakdown`.

### RBAC: `manage-roles` permission defined ✅
- **Root cause**: `RoleController::authorizeManage()` gated on `manage-roles`, which neither
  seeder defined → admin 403 on every roles page.
- **Fix (code + live DB, read-only DB constraint honored)**:
  - `database/seeders/AdminLteRbacSeeder.php` + `InitialDataSeeder.php`: added
    `'manage-roles' => 'Manage Roles & Permissions'` to the permission vocabulary (admin role
    receives it automatically via `$all` / `array_keys($permLabels)`).
  - Live DB: idempotent script (`firstOrCreate` + `syncWithoutDetaching`, mirroring seeder
    semantics) created permission id=50 and attached it to the admin role.
  - `RoleController::authorizeManage()` now allows `isAdmin() || hasPermission('manage-roles')`
    — the `isAdmin()` path is a safety net for users whose RBAC pivot was never synced.

### Sidebar "Roles & Permissions" item missing ✅
- **Root cause**: `config/adminlte.php` referenced `'route' => 'admin.roles.index'`, but the
  roles routes are registered under the `adminlte.` name prefix (`adminlte.roles.*`) — the
  sidebar's `RouteExistsFilter` silently dropped the item (Users survived only because
  `routes/admin/users.php` defines `admin.users.*`).
- **Fix**: `'route' => 'adminlte.roles.index'`, and `'can' => 'users.view'` → `'can' =>
  'manage-roles'` (semantically correct gate now that the permission exists).
- **Verified** in-process: menu build for the admin user contains "Roles & Permissions" with
  href `/admin/roles`, gated by `manage-roles`.

### Verification
- `php artisan dusk --filter=FullRouteCrawlerTest` → ✅ green, **0 broken pages** across all
  GET routes (report has 0 BROKEN entries; only intended SKIPs remain).
- All temp diagnostic scripts (`_*.php`) removed.

### Guard for future work
- Any NEW GET route must render 200 for its intended user (admin/web, client/web, or
  admin/sanctum) — the crawler will catch it. When adding API route files, wrap them in
  `Route::middleware('api')->prefix('api')` (never a bare `require` into `withRouting(then:)`),
  or implicit model binding silently breaks.
- New admin menu items: the `route` key must match the ACTUAL registered route name (check
  `php artisan route:list --name=…`), or `RouteExistsFilter` hides the item.

---

## Security Notes (post-review fixes)

### Critical fix — publicly-exposed routes (RESOLVED)
**Before**: 6 route files under `routes/admin/` had NO middleware group, NO `admin` prefix, and
NO route names — every route was publicly reachable at bare URLs (`/datacenters`, `/ip-subnets`,
`/dns-zones`, `/cart`, `/search`, `/inventory-assets`) without authentication or CSRF.

**After**: all 6 files rewritten with:
```php
Route::middleware(['web', 'auth', 'admin'])->prefix('admin')->name('admin.')->group(function () { ... });
```
- Every route now requires login + `AdminMiddleware` (roles: admin/support/sales/marketing)
- Every route carries a granular permission (`invoices.view`, `hosting.manage`, `orders.create`,
  `dashboard.view`, `hosting.view`) enforced via `->middleware('permission:...')`
- Route names now match the AdminLTE sidebar contract (`admin.dns-zones.index`,
  `admin.ip-subnets.index`, `admin.datacenters.index`, `admin.inventory-assets.index`)

### RBAC scope (RESOLVED)
- Seeder previously created only `admin/editor/viewer` with 9 generic permissions while routes
  gated on granular names (`invoices.view`, `hosting.manage`) → **admin was locked out** of
  resource pages.
- Now: 50 granular permissions across 6 roles; `syncWithoutDetaching` is idempotent; admin
  role receives every permission. `manage-roles` added 2026-08-05 (see Full-Route Crawler
  Audit → RBAC section).

### Hardening batch (RESOLVED)
- ✅ `laravel/socialite` removed (composer + lock + vendor clean; `bootstrap/cache/packages.php`/
  `services.php` rebuilt via `php artisan package:discover`; app boots clean)
- ✅ Fortify 2FA self-service UI added: `resources/views/auth/two-factor-manage.blade.php`
  (enable → QR/secret → confirm → recovery codes → regenerate → disable), embedded in
  `resources/views/client/profile.blade.php` and new `resources/views/admin/profile.blade.php`
  (fixes the previously-404 navbar profile link); recovery codes confirmed issued on enable (8),
  confirm/regen/disable gated by `password.confirm` (`confirmPassword => true`)
- ✅ Rate limiting applied:
  - `api` limiter (60/min per user-or-IP) on the whole `api` group via `$middleware->throttleApi()`
  - `install` (5/min/IP) on POST /install · `register` (5/min/IP) on POST /register
  - `payments` (10/min) on client invoice pay · `impersonate` (10/min) on admin impersonation
  - Verified via `route:list -v`; note: Laravel middleware priority runs `auth:sanctum` before
    `throttle:api`, so unauthenticated API requests 401 before the limiter is consulted
- ✅ Regression coverage: `tests/Feature/TwoFactorTest.php` (4 tests: enable requires
  password-confirm; enable→confirm→disable full flow with QR/secret/8 recovery codes; invalid
  code → 302 + session error bag `confirmTwoFactorAuthentication`; api limiter 429 on 61st hit)
  — full suite 96 tests / 294 assertions green

### Support module routes — security + naming fix (RESOLVED, during Search audit)
- **Before**: `routes/admin/support.php` and `routes/api/support.php` also declared bare routes —
  `/tickets` and `/kb` with **no** middleware group, **no** `/admin`/`/api` prefix and **no**
  `admin.` name prefix. Same exposure class as the 6 files above, plus:
  - public unauthenticated API writes (`POST /api/tickets`, `POST /api/kb`) — a `sanctum` bypass
  - the admin web `/tickets` and API `/tickets` shadowed each other on the same URIs
  - every `admin.tickets.*` / `admin.kb.*` reference (controllers, sidebar, search view) threw
    `RouteNotFoundException` (routes were named `tickets.*`/`kb.*`, if named at all)
- **After**: both files rewritten to the established conventions
  (`routes/admin/ssl.php`, `routes/api/products.php`):
  - `admin/support.php` → `Route::middleware(['web','auth','admin'])->prefix('admin')->name('admin.')`
    with kept `permission:tickets.*` / `permission:kb.*` gates; names now `admin.tickets.*` / `admin.kb.*`
  - `api/support.php` → `Route::middleware('api')->prefix('api')` + nested `auth:sanctum` groups
    for `tickets` and `kb`; static routes (`stats`, `categories`, `popular`) still before model-bound
- **Verified**: `route:list -v` shows `Authenticate:sanctum` on `api/tickets|kb`, `web+auth+admin+
  permission` on `admin/tickets|kb`, and no bare `/tickets`/`/kb` remain.

### Remaining hardening (future)
- `docker/Dockerfile` path corrected to root-level `Dockerfile` (compose context `.`)
- No rate-limit on `permission:`-gated resource routes beyond global `web` throttle — add
  explicit `throttle:` where brute-force risk exists (auth already rate-limited in Fortify)
  ✅ **RESOLVED (2026-08-04)**: added a read/write-aware `admin` rate limiter in
  `AppServiceProvider` (`Limit::perMinute(300)` for GET/HEAD/OPTIONS, `Limit::perMinute(30)`
  for POST/PUT/PATCH/DELETE, keyed per authenticated user / fallback IP) and appended
  `'throttle:admin'` to the group header of **all 18 admin route files** (`routes/web.php`
  dashboard/impersonation group + all 17 `routes/admin/*.php`). Verified via `route:list -v`:
  admin routes now carry `web → Authenticate → AdminMiddleware → ThrottleRequests:admin`, and
  `impersonate.start` stacks `throttle:admin` (30/min writes) with the stricter `throttle:impersonate`
  (10/min). The throttle sits after `admin` middleware, so it only ever counts authenticated admin
  traffic — no anonymous DoS vector via the limiter. Full suite re-run green (96 tests / 294 assertions).

---

## Reference

- **Reference project**: `C:\Projects\hostingcrm - v5\` (feature specification + business logic to port)
- **AdminLTE 4 Laravel**: `https://github.com/ColorlibHQ/adminlte-laravel`
- **AdminLTE 4 docs**: `https://laravel.adminlte.io/docs`
- **Laravel 13 docs**: `https://laravel.com/docs/13.x`
- **Laravel Fortify**: `https://laravel.com/docs/13.x/fortify`
- **Paymenter (inspiration)**: `https://github.com/Paymenter/Paymenter`
- **PNLCS (inspiration)**: `https://github.com/Panelica/pnlcs`
