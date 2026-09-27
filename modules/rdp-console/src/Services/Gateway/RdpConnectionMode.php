<?php

declare(strict_types=1);

namespace Modules\RdpConsole\Services\Gateway;

/**
 * Which RDP target a gateway token describes. Server-side only: the mode is
 * chosen by the controller action that mints the token, never inferred from
 * request input, and it selects both the guacd security mode and the shape of
 * the connection settings.
 *
 * - GuestRdp:      the guest's own RDP service (port 3389 by default) with the
 *                  guest's credentials — the original console mode.
 * - HyperVVmConnect: the Hyper-V host's vmrdp listener (port 2179) with the
 *                  HOST's administrator credentials plus the VM GUID in the
 *                  preconnection BLOB — works with no guest network and at
 *                  boot/BIOS screens. Same mechanism Windows Admin Center's
 *                  console uses.
 * - ProxmoxVnc:    a Proxmox VE QEMU VM reached through the PVE VNC websocket
 *                  (`GET /nodes/{node}/qemu/{vmid}/vncwebsocket`). The token
 *                  carries the PVE API facts plus a one-shot vncproxy ticket;
 *                  the sidecar opens the PVE websocket and bridges it to guacd
 *                  as plain VNC, so guacd only ever sees 127.0.0.1 + a relay
 *                  port. Type stays `vnc` (a guacd-known type) so
 *                  guacamole-lite never rejects it.
 */
enum RdpConnectionMode: string
{
    case GuestRdp = 'guest-rdp';

    case HyperVVmConnect = 'hyperv-vmconnect';

    case ProxmoxVnc = 'proxmox-vnc';
}
