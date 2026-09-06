# cPanel/WHM Provisioning

Creates and manages real cPanel accounts on a WHM server when an order is paid.

Implements `App\Contracts\Module\Capabilities\ProvisioningModule`, so
`OrderService::advanceAfterPayment()` routes to it through
`App\Services\Provisioning\ProvisioningDispatcher`.

## Setup

1. **Activate the module** — Admin → Modules → cPanel/WHM Provisioning →
   Activate.
2. **Add the WHM server** — Admin → Servers. Set `panel_type` to `cpanel` and
   fill in:
   - `api_url` — e.g. `https://whm.example.net:2087`. Optional; when blank the
     module uses `https://<ip_address>:2087`.
   - `api_username` — the WHM user the token belongs to (usually `root`).
   - `api_key` — a **WHM API token** (WHM → Development → Manage API Tokens),
     not the account password.
   - `max_accounts` — capacity ceiling; `0` means unlimited.
3. **Group the servers** — Admin → Server Groups. Put the server in a group and
   set each member's `priority` (lower is preferred).
4. **Point the product at it** — on the product, set *Provisioning module* to
   `cPanel/WHM` and the *Server group* to that group. Then enable this module on
   the product and set its config:
   - `plan` — the WHM package name. Leave blank to let WHM apply its default.
   - `contact_email` — overrides the customer's email on the account.
   - `verify_tls` — on by default. Turn off only for self-signed WHM certs.

## What happens on payment

`invoice paid` → order `pending → paid → provisioning` → `provision()`:

- a server is chosen by `ServerAllocator` (product's group, by priority, then
  least-loaded, skipping servers at `max_accounts`);
- `createacct` is called with a generated username and password;
- on success the order goes `active` and a `panel_accounts` row is written;
- on failure the order goes `failed` and the WHM reason is recorded on the
  `provisioning_events` row.

Suspend / unsuspend / terminate map to `suspendacct`, `unsuspendacct` and
`removeacct` (`keepdns=0`).

## Credentials

The generated password is stored encrypted in `panel_accounts.password_encrypted`
(Laravel's `encrypted` cast — write only through the model, never a raw
`DB::table()->update()`, or reads will throw). It is returned from `provision()`
so a caller can deliver it, and `ProvisioningDispatcher` redacts it before
writing the `provisioning_events` audit row.

`panel_accounts` is a core table shared by all four panel modules, with a
`panel` discriminator — the row records something living on someone else's
server, so it has to outlive deactivating the module.

**Not wired yet:** nothing delivers that password to the customer. The product's
`welcome_email_template_id` is the intended hook. Until that exists, read it
from the `panel_accounts` row or reset the password in WHM.

## Notes

- WHM answers HTTP 200 for application-level errors, so `WhmClient` decides
  success on `metadata.result === 1` and surfaces `metadata.reason` on failure.
  Some commands nest the same pair under `result[0]`; both shapes are handled.
- Usernames are derived from the domain plus the service id (uniqueness),
  forced to start with a letter, and capped at 16 characters.
- Nothing throws out of the module: every failure is a
  `ProvisioningResult::fail()` so it becomes a failed order plus an audit row
  rather than an exception in the payment path.
