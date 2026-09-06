# DirectAdmin Provisioning

Creates and manages DirectAdmin user accounts when an order is paid. Built on
`App\Contracts\Module\AbstractPanelModule`.

> Written against the documented `CMD_API_*` shape and verified against faked
> responses. It has **not** been run against a live DirectAdmin server — check
> the first provision on a staging node.

## Setup

1. Activate the module (Admin → Modules).
2. Add the server (Admin → Servers), `panel_type` = `directadmin`:
   - `api_url` — e.g. `https://da.example.net:2222`. Blank uses
     `https://<ip_address>:2222`.
   - `api_username` — an admin or reseller username.
   - `api_key` — a **Login Key** (DirectAdmin → Login Keys), not the account
     password.
3. Put the server in a server group and point the product at it, with
   *Provisioning module* = `DirectAdmin`.
4. Module config on the product: `plan` (package name), `ip` (blank = the
   server's shared IP), `contact_email`, `verify_tls`.

## How it maps

| Action | Call |
|---|---|
| provision | `CMD_API_ACCOUNT_USER` with `action=create` |
| suspend | `CMD_API_SELECT_USERS` with `suspend=Suspend` |
| unsuspend | `CMD_API_SELECT_USERS` with `suspend=Unsuspend` |
| terminate | `CMD_API_SELECT_USERS` with `delete=yes&confirmed=Confirm` |

## Two things that bite

DirectAdmin returns **URL-encoded query strings, not JSON** —
`error=1&text=Cannot%20Create%20User&details=...`. `DirectAdminClient` parses
with `parse_str()` and treats `error=0` as success; a JSON decode would yield
null and make every call look like a transport failure.

Wrong credentials produce **HTTP 200 with an HTML login form**, which parses to
no `error` key at all. A body without that key is therefore treated as a
failure, not a success — otherwise a bad login key would look like a silent
successful provision.

Usernames are capped at **10 characters** here (`USERNAME_MAX`), stricter than
the 16 the base class allows, because that is DirectAdmin's default limit.
