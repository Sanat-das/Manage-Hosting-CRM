<?php

namespace App\Jobs;

use App\Models\Domain;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Sync domain status with registrar (stub for Session 4 registrar API).
 */
class SyncDomainStatus implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function __construct(public int $domainId)
    {
        $this->onQueue('domains');
    }

    public function handle(): void
    {
        $domain = Domain::find($this->domainId);

        if (! $domain) {
            Log::warning('Domain not found for sync', ['domain_id' => $this->domainId]);

            return;
        }

        Log::info('Syncing domain status', ['domain' => $domain->name]);

        // TODO: Session 4 — actual registrar API call
        // For now, check expiry locally
        if ($domain->expiry_date && $domain->expiry_date->isPast() && $domain->status === 'active') {
            $domain->update(['status' => 'expired']);
            Log::info('Domain expired', ['domain' => $domain->name]);
        }
    }
}
