# Proxmox VE

Creates and manages QEMU/KVM virtual machines on a Proxmox VE node or cluster.
Built on `App\Contracts\Integrations\AbstractComputeModule`, driven by
`Services/ProxmoxClient` over the PVE REST API (`/api2/json`).

> **Verification status.** The read paths — ticket auth, `/version`, `/nodes`,
> `/cluster/status`, `/cluster/resources`, `/cluster/nextid`, `listVms()`,
> `vmExists()`, `vmConfig()`, `listTemplates()` and `storageFor()` — are
> verified against a live **PVE 9.1** cluster (3 nodes). The mutating paths
> (clone / create / resize / start / destroy) are written against the documented
> API shape and covered by faked tests: **check the first provision on a staging
> node before trusting it in production.**

## Setup

1. Add the server (Admin → Servers), `server_type` = `proxmox`:

   | Field | Notes |
   |---|---|
   | `host` | IP or FQDN of any cluster node. The API is cluster-wide, so one node is enough. |
   | `port` | PVE's default is **8006**. |
   | `auth_type` | `token` (recommended) or `ticket`. |
   | `verify_tls` | PVE ships a self-signed certificate by default — uncheck only if you accept that. |

   The host must be reachable **from the app server**. A hostname that resolves
   publicly but is not port-forwarded will fail while the private IP works, so
   prefer the address the app can actually dial.

2. **Token auth (recommended).** Datacenter → Permissions → API Tokens → Add.
   Give the token its own role (a privilege-separated token, e.g.
   `PVEVMAdmin` scoped to a pool) instead of using `root@pam`. Then set:
   - `api_username` — the token **id**, `user@realm!tokenid` (e.g. `automation@pve!hostvex`);
   - `api_password` — the token **secret** shown once at creation.

3. **Ticket auth.** Set `auth_type` = ticket, `ticket_username` = `user@realm`
   (e.g. `root@pam` — the realm is required), and the account password in
   `api_password`. The client caches the ticket per (server, username, hashed
   password) and re-authenticates once on a 401, so a page render does not log
   in for every call.

4. Put the server in a server group and point the product at it with
   *Provisioning module* = `proxmox`.

## Product config

`cpu`, `ram` (MB) and `disk` (GB) are the required provisioning options.
Optional: `node` (blank = first online node), `template_vmid`, `storage`
(blank = the roomiest usable pool), `vmid` (fixed id; errors if taken),
`vmid_floor`, `bridge`, `vlan_tag`, `iso` (empty-VM builds), `iso_storage`
(blank = the roomiest ISO-capable pool), `start_after_create`, `full_clone`,
`clone_timeout` (seconds, default 900), `delete_vm_on_terminate`,
`guest_username` / `guest_password` (cloud-init templates), `contact_email`.

`bridge`, `vlan_tag` and `iso` are validated before the build: PVE config values
are comma-joined option strings, so a comma in any of them would silently inject
extra options.

## Templates

Templates are curated **per server**, then optionally restricted **per product**.

**Curating (server edit page).** The *Clone templates* panel lists every template
PVE reports across all reachable nodes — a VMID is not something an operator
should have to memorise, and a cluster splits its templates across nodes, so
discovery spans all of them and skips unreachable ones. Tick the ones to offer,
give each an optional label, and pick a default. A template that later vanishes
from the cluster still renders (badged *not on cluster*) so it can be seen and
removed rather than silently disappearing.

Only entries PVE marks as a **template** are offered. Convert a VM in Proxmox
with right-click → *Convert to template*.

Note: discovery needs a credential that can read the cluster — a
privilege-separated API token with no ACL sees zero templates. `testConnection()`
fails loudly for that credential, and the *Clone templates* panel shows the
discovery error instead of an empty picker (a cluster that genuinely has no
templates still reads as empty).

**Restricting (product → Modules tab).** Either *All curated templates* (the
default, unrestricted) or *Restrict to selected*, which limits that product to a
subset — so a budget tier can only clone approved images.

**How a provision resolves a template:**

1. The server's curated list is narrowed by the product's `allowed_templates`
   (empty = everything curated).
2. An explicit product `template_vmid` must be inside that narrowed set. A
   restricted product **cannot** reach a template outside its tier.
3. Otherwise the server's default is used — but only if it too is inside the set,
   so a default can never widen a restriction.
4. Templates curated but nothing resolves → the provision **fails loudly**.
   Silently building an empty VM instead would ship a machine with no OS.
5. Nothing curated at all → the plain-VMID workflow still works: an explicit
   `template_vmid` is honoured as given (its node is looked up live), and with no
   VMID the VM is built empty.

**Clone routing.** The clone is addressed to the node the *template* lives on,
with `target` naming the node the new VM should land on. A template on one node
can therefore provision onto another; using a single node for both would ask the
target node for a template it does not have.

**Use a cloud-init template.** A clone inherits the template's baked-in identity
— network config, SSH keys, login — so cloning a plain template hands the
customer a duplicate of the template itself. A template with a cloud-init drive
(`citype=nocloud`) is different: PVE regenerates the payload per boot and derives
the guest hostname from the VM name the module assigned, so every clone is
distinct. Network configuration is **inherited from the template's `ipconfig0`**
— build the template with `ip=dhcp` so each clone gets its own address; a static
`ipconfig0` is copied to every clone.

When the product sets `guest_username`, the module writes `ciuser` (and
`cipassword`: the product's `guest_password`, or the generated panel password
when blank) on clones that expose a cloud-init drive, and reports those
credentials back so the welcome email delivers a login that actually exists. On
a template without a cloud-init drive the keys are inert, so the credentials are
**not** stored or mailed — the provision warns instead and the clone keeps the
template's own login.

## Lifecycle

| Action | PVE call |
|---|---|
| provision (template) | `POST /nodes/{node}/qemu/{tpl}/clone`, then resize + `PUT .../config` (spec, then cloud-init credentials) |
| provision (no template) | `POST /nodes/{node}/qemu` |
| suspend | `POST .../status/shutdown` (force-stop fallback) |
| unsuspend | `POST .../status/start` |
| terminate | `DELETE /nodes/{node}/qemu/{vmid}?purge=1` |

The VMID is stored as `panel_accounts.external_id`; every later action addresses
the VM by it. A provision that cannot establish a VMID fails loudly rather than
recording a machine nothing can manage.

## Things that will bite

**The API is task-based.** Clone, resize and power actions answer with a UPID and
return long before the work is done, so `waitForTask()` polls to `exitstatus`
before anything is believed. Bounded rather than infinite: clone 900s (a product
`clone_timeout` overrides), destroy 180s. A clone that times out reports that the
VM's state is **unknown** and names the node/VMID to check, because PVE may still
finish the clone after the timeout.

**A node outage is not a deleted VM.** A VM on an offline node answers **HTTP
595**; a VM on the wrong online node answers **500 `Configuration file ... does
not exist`**. `isMissingVm()` matches the *message*, never a bare 500 — reading a
transient fault as "gone" would flip a live record to terminated and build a
duplicate VM. Unknown failures fail closed.

**`/cluster/nextid` has two different behaviours.** With no parameter it answers
with the cluster's next free id and ignores any floor. With `?vmid=N` it
**validates** N — a taken id answers `400 VM N already exists`. It is not
"search from N", so a floor is honoured by taking PVE's suggestion when it is at
or above the floor and otherwise probing upward.

**A clone inherits the template's disk.** `applySpec()` applies the product's
size as a **grow-only** resize; PVE cannot shrink a disk, so a smaller product
size is skipped rather than sent.

**A failed build does not leak the VM.** If anything fails after the VM was
created but before the panel record is written (resize, config update, existence
probe), the driver best-effort destroys it, so the VMID is reusable and the admin
VM table does not accumulate machines nothing addresses. Only a clone that timed
out while PVE was still running the task can finish later — the error names the
node/VMID to check, and the finished VM appears under *On host, not provisioned*
on the server page, where an admin with `hosting.manage` can destroy it (typed
VMID confirmation; VMs owned by a service and templates are refused). The
destroy stops a running VM first, because PVE refuses to delete one that is
running — which also makes terminate work on a live VM.

**Without `purge` a destroy leaves disks behind** and the VMID stays unusable.
Terminate passes `purge=1` and tolerates an already-missing VM.

**No template means an empty VM.** The build attaches the ISO as a CD-ROM; the
guest still needs an OS install, and the provision says so in its message. The
ISO is resolved on an `iso`-capable pool (`iso_storage`, else the roomiest one)
and the filename is verified to exist there before the VM is created — a wrong
name fails instead of building a VM that boots to "no bootable device".
