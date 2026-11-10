# Changelog

All notable user-facing changes to this project are documented in this file.

## [Unreleased]

### Fixed

- **The overdue check flipped drafts to overdue.**
  A draft invoice was never sent, so its due date means nothing to the
  customer — but the check marked it overdue anyway, which then showed up in
  the staff digest and the customer's notifications. It now only touches
  invoices the customer has actually seen, and a partly paid invoice may
  legitimately become overdue (its transition map said it could not).

- **Editing one service's billing date moved every other service on the order.**
  The form wrote its date to the ORDER, and the order's date is what the
  billing engine reads when it picks the order's items — so moving a hosting
  service's renewal date also moved an add-on's, and the order's summary
  stopped being the earliest of its items. The form now edits the service's
  own schedule, and the order's summary and cycle are derived from its items.

- **A customer profile showed a next-billing date with nothing next to it.**
  A cycle that was never invoiced, or one that was invoiced and voided, read
  exactly like a settled one. The profile now names the invoice for the
  cycle and says whether it is paid.

- **A fresh install could not build its assets on Linux.**
  `composer run setup` installed the npm packages with `--ignore-scripts` and
  then built — but the bundler's native binaries (rolldown, the Tailwind oxide
  engine) are fetched by a postinstall script, so the build ran without them
  and failed. The compiled assets are committed anyway, so the setup now
  builds them only when they are genuinely missing, and installs with scripts
  enabled when it has to.

- **A fresh checkout ran the suite in a different timezone than the application.**
  There was no `.env` outside a working copy (it holds the real credentials
  and is ignored), so the suite fell back to UTC while the application runs
  on Asia/Kolkata. A one-day difference moves a billing anchor by a day, and
  the once-per-cycle guard then skips a renewal that should have billed —
  three tests failed on a fresh checkout for that reason alone. `.env.testing`
  is now committed: a fixed test key, no real host, and the same timezone the
  application runs on.

- **The installer guard cached its answer for the whole process.**
  The "is this app installed?" probe was memoized in a static, so its answer
  from the first request of a process was reused forever: a long-lived worker
  (Octane, a queue worker) would keep answering "not installed" after the app
  was installed, and a test process would fix the answer for the whole suite —
  the first request that ran before any user existed sent every later request
  to the installer wizard. The answer is now cached for the current request
  only.

- **A fresh clone could not render a single page.**
  `public/build` was gitignored, so a new checkout (CI, a new server, a
  colleague) had no Vite manifest and every page that renders the AdminLTE
  head died on it — and the pipeline's test job has no Node step, so the HTTP
  tests failed on the missing manifest before they reached a single
  assertion. The compiled assets are tracked again, as the "Compiled Vite
  assets committed" note above already promised.

- **The billing anniversary followed the activation date, not the purchase date.**
  An order bought on the 1st and activated on the 20th renewed on the 20th:
  the customer was handed a free period every time activation slipped, and the
  renewal could land weeks after the first year had been paid. The schedule
  is now anchored on the order's start date.

- **The admin billing edit wiped the fields it was not asked to change.**
  A partial request (an API call, a script) nulled the billing cycle, the
  payment method, the subscription and the schedule — and because the cycle
  column is NOT NULL, the whole update rolled back with "Could not update
  billing info". A field that is not posted now keeps its stored value; a
  field posted empty still clears it. The schedule may also no longer run
  backwards.

- **The test suite never seeded the panel RBAC, so every panel route
  answered 403.**
  Four migrations create a few roles and ~35 permissions of their own, and
  the bootstrap seeded the full graph only "when the roles table was empty" —
  which it never was. The admin, support, sales and marketing roles were
  missing and the permission inventory unseeded, so 109 tests failed on a 403
  and the pipeline had been red on `main` since before this work. The
  seeder is idempotent and now runs for every test.

- **Editing an invoice could mark it paid or refunded.**
  The status dropdown offered `paid` and `refunded` on an invoice that had
  moved no money: the invoice was marked paid with `paid_amount = 0`, no
  payment row and no `InvoicePaid` event (so the linked order never
  advanced), and it could no longer be paid through the app at all. A paid
  invoice could be flipped to `refunded` without the customer's wallet ever
  being credited — after which the real refund action refused to run. Those
  statuses now go through the payment and refund actions only.

- **An unpaid renewal invoice was never chased, and the service looked due next year.**
  The renewal engine advances a service's billing schedule when it issues the
  invoice, so an invoice the customer never paid left the schedule pointing
  at the following year: nothing flagged the service overdue, no reminder
  ladder ran, and no suspension could ever trigger. The overdue check now
  reads the oldest unpaid invoice as well, and `invoices:remind` reminds the
  customer every `automation_invoice_reminder_days` until the invoice is paid.

- **Voiding an invoice left the service billed a year ahead.**
  The renewal invoice and the schedule advance are committed together, so an
  invoice that was voided or cancelled still held the period it had billed:
  the service was not billed for it again until the next cycle, and the cycle
  counter stayed consumed. Voiding now hands the period back.

- **The invoice form's "Add Line" button had never worked.**
  The two invoice forms pushed their script to a `scripts` stack the theme
  never renders (the layout renders `js`), so the browser silently dropped it:
  an invoice could only ever carry the one line the server rendered, and the
  per-line remove button did nothing. Both forms now push to the stack the
  layout actually outputs.

- **A discount larger than the invoice wrote a negative total.**
  The discount was only checked for being non-negative, so `total = amount +
  tax - discount` could go below zero and be stored, printed and reported.
  The discount is now refused when it exceeds the amount, and the service
  clamps it as well.

- **A cleared due date was a 500.**
  `invoices.due_date` is `NOT NULL`, but the form left it optional and the
  update wrote the empty value straight through, so clearing the date failed
  the query instead of keeping the date it already had.

- **The invoice header could disagree with its own line items.**
  The create form posted a "Subtotal" that was stored as the invoice amount
  while the tax was computed from the lines, so a stored invoice could total
  more than its lines were worth — and the first edit silently corrected it.
  The amount is now derived from the line items on create as well as on
  update.

- **An invoice edit could reassign the customer.**
  The edit form offered the full customer list, so an invoice could be moved
  to another customer (including on an order-linked invoice, where the order
  kept its own customer). The customer is now shown, not offered.

- **Product default-template save looked like a 405 even though it worked.**
  The compute default-template card saved over fetch, and the endpoint answered
  with a redirect. Fetch follows a 302 with the *same* method (only POST is
  rewritten to GET), so the `PUT` landed on the GET-only edit route as
  **405 Method Not Allowed** — the card showed "Could not save the default
  template" while the value had in fact been saved. The endpoint now answers
  AJAX callers (`Accept: application/json`) directly with JSON, and the card's
  error message includes the HTTP status when a save really fails.

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

- **The invoice edit is now a contract.**
  Store and update share one request class, so their rules cannot drift
  apart; an `InvoicePolicy` is the authority the controller asks on every
  write; every edit writes an `activity_log` row with the money fields before
  and after; and a form that was opened before someone else changed the
  invoice is refused with a clear message instead of failing every line id it
  posts.

- **The invoice form shows what it is about to save.**
  The line totals, the subtotal, the discount and the total before tax are
  live, a line the admin added survives a failed save, the last line cannot
  be removed, the locked banner now names every status that locks the
  amounts, and the form says who last edited the invoice.

- **Tax is computed on the discounted value.**
  A discount shown on the invoice reduces the taxable value: it is split
  across the lines pro-rata and every line is taxed on what it bills after
  its share, so the GST on the document matches the discounted amount. The
  line totals still read gross, and `invoices.tax_rate` now carries the
  effective rate instead of staying at zero.

- **A service can be suspended for non-payment.**
  `hosting_suspend_on_overdue` (declared but never read) now switches the
  behaviour on, `hosting_suspend_after_days` sets the grace period (default
  7 days) and the customer is told with the `service_suspended` template.
  Both live in Settings → Hosting, and the suspension is off until the
  switch is turned on.

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
