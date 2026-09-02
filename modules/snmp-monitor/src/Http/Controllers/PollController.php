<?php

declare(strict_types=1);

namespace Modules\SnmpMonitor\Http\Controllers;

use App\Http\Controllers\Controller;
use App\Models\HostingAccount;
use App\Services\Modules\ModuleManager;
use Illuminate\Http\RedirectResponse;
use Modules\SnmpMonitor\Exceptions\UnlinkedAccountException;
use Modules\SnmpMonitor\Jobs\PollHostBatch;
use Modules\SnmpMonitor\Services\SnmpMetricRepository;
use Modules\SnmpMonitor\Services\TargetService;

/**
 * Manual poll trigger behind the hosting-page card's Refresh button. It only
 * QUEUES a PollHostBatch on the snmp-poll queue — SNMP collection never runs
 * inside the HTTP request.
 *
 * Guard replicates the retired SshConsoleController::refresh validation: the
 * account's product must carry an enabled snmp-monitor module link, enforced
 * through the same repository guard as the dashboard (403 otherwise).
 */
final class PollController extends Controller
{
    public function __construct(
        private readonly ModuleManager $manager,
        private readonly SnmpMetricRepository $repository,
    ) {}

    public function __invoke(HostingAccount $hostingAccount): RedirectResponse
    {
        try {
            $module = $this->repository->assertLinked($hostingAccount);
        } catch (UnlinkedAccountException $exception) {
            abort(403, $exception->getMessage());
        }

        // The link's config is decrypted through the module manager — never
        // read raw from the pivot. ensureForAccount provisions the target on
        // first use so the queued batch finds it even before the first render.
        $link = $hostingAccount->product?->moduleLinks->firstWhere('module_id', $module->id);
        $config = $this->manager->decryptConfig($module, $link->config ?? []);

        $target = app(TargetService::class)
            ->ensureForAccount($hostingAccount, TargetService::osFor($hostingAccount, $config));

        PollHostBatch::dispatch([$target->id]);

        return back()->with('success', 'Queued refresh');
    }
}
