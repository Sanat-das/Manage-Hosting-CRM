# Virtualizor Provisioning

Creates and manages VPS instances when an order is paid. Built on
`App\Contracts\Module\AbstractPanelModule`.

> Written against the documented Virtualizor admin API shape and verified
> against faked responses. It has **not** been run against a live Virtualizor
> master — check the first provision on a staging node.

## Setup

1. Activate the module (Admin → Modules).
2. Add the server (Admin → Servers), `panel_type` = `virtualizor`:
   - `api_url` — e.g. `https://vps.example.net:4085`. Blank uses
     `https://<ip_address>:4085`.
   - `api_username` — the API **key id**.
   - `api_key` — the API **pass**. Virtualizor issues them as a pair under
     Configuration → API Credentials.
3. Put the server in a server group and point the product at it, with
   *Provisioning module* = `Virtualizor`.
4. Module config on the product — **both are required**:
   - `plan` — the numeric Plan ID (`plid`);
   - `osid` — the numeric OS template ID;
   - `virt` — kvm / openvz / lxc / proxk / proxl (default kvm);
   - plus `contact_email` and `verify_tls`.

   These are Virtualizor's own numeric ids and differ per installation, so
   there is no sensible default. Provisioning fails before any API call if
   either is blank.

## How it differs from the control panels

This provisions a **virtual machine, not a hosting account**, which changes two
things in the shared lifecycle:

- **A domain is optional** (`requiresDomain()` is false). A VPS has a hostname;
  an order with no domain gets `<username>.vps`.
- **Actions address the VPS by numeric `vpsid`**, which only exists after
  creation. It is stored as `panel_accounts.external_id`. If `addvs` returns no
  id the provision is failed deliberately rather than recording a machine
  nothing can later manage.

| Action | Call |
|---|---|
| provision | `act=addvs` |
| suspend | `act=vs&suspend=<vpsid>` |
| unsuspend | `act=vs&unsuspend=<vpsid>` |
| terminate | `act=vs&delete=<vpsid>` |

## Error handling

A failed call still answers **HTTP 200** with `{"error": ...}`, where the value
is sometimes a string, sometimes a list, sometimes a field → message map. A
successful call simply omits the key. So success is "no `error` key", and
`VirtualizorClient::flattenError()` reduces all three shapes to one readable
line.
