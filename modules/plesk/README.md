# Plesk Provisioning

Creates and manages Plesk subscriptions when an order is paid. Built on
`App\Contracts\Module\AbstractPanelModule`.

> Written against the documented Plesk REST API v2 shape and verified against
> faked responses. It has **not** been run against a live Plesk server — check
> the first provision on a staging node.

## Setup

1. Activate the module (Admin → Modules).
2. Add the server (Admin → Servers), `panel_type` = `plesk`:
   - `api_url` — e.g. `https://plesk.example.net:8443`. Blank uses
     `https://<ip_address>:8443`.
   - `api_key` — a Plesk API key. **Or** set `api_username` as well and
     `api_key` becomes the admin password (basic auth). Both are supported
     because which one an estate uses depends on how the token was issued.
3. Put the server in a server group and point the product at it, with
   *Provisioning module* = `Plesk`.
4. Module config on the product: `plan` (service plan name — blank uses Plesk's
   default), `contact_email`, `verify_tls`.

## How it maps

Plesk models hosting as a **subscription owned by a customer**, so there is no
single "create account" call:

| Action | Call |
|---|---|
| provision | `POST /api/v2/clients` then `POST /api/v2/domains` with the returned client id |
| suspend | CLI gateway → `subscription --suspend <domain>` |
| unsuspend | CLI gateway → `subscription --activate <domain>` |
| terminate | CLI gateway → `subscription --remove <domain>` |

Suspend/unsuspend/remove are not first-class REST v2 endpoints, so they go
through the documented CLI gateway (`POST /api/v2/cli/{utility}/call`). That
gateway returns HTTP 200 even when the utility fails — the real outcome is the
`code` field, which `PleskClient::cli()` checks. The `subscription` utility
addresses a subscription by **domain name**, not the numeric id the REST API
returns, which is why the id is stored but the name is used.
