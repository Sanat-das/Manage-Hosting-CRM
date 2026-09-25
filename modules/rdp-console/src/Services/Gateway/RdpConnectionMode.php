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
 */
enum RdpConnectionMode: string
{
    case GuestRdp = 'guest-rdp';

    case HyperVVmConnect = 'hyperv-vmconnect';
}
