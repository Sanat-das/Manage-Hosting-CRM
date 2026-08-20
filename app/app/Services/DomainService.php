<?php

namespace App\Services;

use App\Models\Domain;
use App\Models\DomainPricing;
use App\Models\DomainSearchLog;
use App\Models\RegistrarSetting;
use App\Services\Registrars\RegistrarManager;
use Carbon\Carbon;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Http;
use InvalidArgumentException;
use Throwable;

/**
 * Domain lifecycle service.
 *
 * Owns the domain state machine (transition guards), the expiring query
 * scope, bulk suspend/terminate, and the whois-style availability check.
 *
 * The registrar API integration is Session 3B/4 — availability and pricing
 * below are deterministic LOCAL heuristics (domain already registered in the
 * CRM + a default per-TLD price table). `searchAvailability()` is the seam
 * where the real registrar lookup will replace the heuristics.
 */
class DomainService
{
    /**
     * All values the domains.status enum accepts (after the additive
     * 'suspended' migration). Source of truth: database/migrations/
     * 2026_07_30_120030_create_order_tables.php + 2026_08_01_000001.
     */
    public const STATUSES = [
        'pending', 'active', 'suspended', 'expired', 'cancelled',
        'transferred', 'pending_transfer', 'redemption',
    ];

    /**
     * State machine: source status => allowed destination statuses.
     * Terminal states (cancelled / transferred) have no outgoing edges.
     */
    private const TRANSITIONS = [
        'pending' => ['active', 'suspended', 'expired', 'cancelled', 'pending_transfer'],
        'active' => ['suspended', 'expired', 'cancelled', 'transferred', 'pending_transfer'],
        'suspended' => ['active', 'expired', 'cancelled'],
        'expired' => ['active', 'redemption', 'cancelled'],
        'pending_transfer' => ['active', 'cancelled'],
        'redemption' => ['active', 'cancelled'],
        'cancelled' => [],
        'transferred' => [],
    ];

    /**
     * Reference RegisterDomainCommand name regex.
     */
    private const NAME_REGEX = '/^[a-zA-Z0-9]([a-zA-Z0-9\-]*[a-zA-Z0-9])?(\.[a-zA-Z]{2,})+$/';

    /**
     * Stub per-TLD annual price (INR). Real prices come from the registrar
     * API / domain_pricing module in Session 3B/4; unknown TLDs fall back to
     * the default of 999.00.
     */
    private const DEFAULT_TLD_PRICES = [
        'com' => 999.00, 'net' => 899.00, 'org' => 899.00, 'biz' => 799.00,
        'info' => 799.00, 'in' => 499.00, 'co.in' => 399.00, 'org.in' => 399.00,
        'net.in' => 399.00, 'io' => 2499.00, 'dev' => 1499.00, 'app' => 1699.00,
        'xyz' => 199.00, 'site' => 149.00, 'online' => 199.00,
    ];

    /**
     * Statuses under which a domain name counts as "taken" for availability
     * checks (an expired/cancelled/transferred registration can be re-registered).
     */
    private const TAKEN_STATUSES = ['pending', 'active', 'suspended', 'pending_transfer', 'redemption'];

    // ─────────────────────────── State machine ───────────────────────────

    /**
     * Apply a guarded status transition and persist it.
     *
     * @throws InvalidArgumentException when the transition is not allowed
     */
    public function transition(Domain $domain, string $to): Domain
    {
        $to = strtolower(trim($to));

        if (! in_array($to, self::STATUSES, true)) {
            throw new InvalidArgumentException("Unknown domain status: {$to}");
        }

        if ($domain->status === $to) {
            return $domain; // idempotent
        }

        if (! $this->canTransition($domain, $to)) {
            throw new InvalidArgumentException(
                "Domain cannot move from '{$domain->status}' to '{$to}'."
            );
        }

        $domain->status = $to;
        $domain->save();

        return $domain;
    }

    /**
     * Whether the state machine allows the given transition (no side effects).
     */
    public function canTransition(Domain $domain, string $to): bool
    {
        return in_array($to, self::TRANSITIONS[$domain->status] ?? [], true);
    }

    public function activate(Domain $domain): Domain
    {
        return $this->transition($domain, 'active');
    }

    public function suspend(Domain $domain): Domain
    {
        return $this->transition($domain, 'suspended');
    }

    public function expire(Domain $domain): Domain
    {
        return $this->transition($domain, 'expired');
    }

    public function cancel(Domain $domain): Domain
    {
        return $this->transition($domain, 'cancelled');
    }

    /**
     * Start a transfer-out: active -> pending_transfer.
     */
    public function startTransfer(Domain $domain): Domain
    {
        return $this->transition($domain, 'pending_transfer');
    }

    /**
     * Complete a transfer-out: active -> transferred (terminal).
     */
    public function markTransferred(Domain $domain): Domain
    {
        return $this->transition($domain, 'transferred');
    }

    /**
     * Approve an inbound transfer: pending_transfer -> active.
     */
    public function approveTransfer(Domain $domain): Domain
    {
        return $this->transition($domain, 'active');
    }

    /**
     * Move a lapsed registration into the registry redemption period.
     */
    public function moveToRedemption(Domain $domain): Domain
    {
        return $this->transition($domain, 'redemption');
    }

    /**
     * Renew a domain: guarded transition to active + new expiry date.
     */
    public function renew(Domain $domain, string|CarbonInterface $newExpiryDate): Domain
    {
        $date = $newExpiryDate instanceof CarbonInterface
            ? $newExpiryDate
            : Carbon::parse($newExpiryDate);

        $this->transition($domain, 'active');

        $domain->expiry_date = $date;
        $domain->next_due_date = $date->copy();
        $domain->save();

        return $domain;
    }

    /**
     * Bulk expire: mark every active domain whose expiry date has passed as
     * expired (mirrors the reference DomainAutomation pre-sync step).
     */
    public function expirePastDue(): int
    {
        $count = 0;

        Domain::where('status', 'active')
            ->whereNotNull('expiry_date')
            ->where('expiry_date', '<', now()->toDateString())
            ->get()
            ->each(function (Domain $domain) use (&$count): void {
                try {
                    $this->expire($domain);
                    $count++;
                } catch (InvalidArgumentException) {
                    // state machine refused — skip
                }
            });

        return $count;
    }

    // ───────────────────────── Expiring query scope ─────────────────────────

    /**
     * Apply the "expiring within $days" filter to a query: active domains
     * whose expiry date falls today..today+$days (inclusive).
     */
    public function expiringFilter(Builder $query, int $days): Builder
    {
        return $query
            ->where('status', 'active')
            ->whereNotNull('expiry_date')
            ->whereBetween('expiry_date', [now()->toDateString(), now()->addDays($days)->toDateString()]);
    }

    /**
     * Query for domains expiring within the given number of days.
     */
    public function expiringQuery(int $days): Builder
    {
        return $this->expiringFilter(Domain::query(), $days);
    }

    // ─────────────────────────── Bulk actions ───────────────────────────

    /**
     * Bulk suspend (active -> suspended). Returns a summary; domains that
     * fail the state machine guard are skipped and reported.
     *
     * @param  array<int, int|string>  $ids
     * @return array{updated: int, skipped: int, errors: list<string>}
     */
    public function bulkSuspend(array $ids): array
    {
        return $this->bulkTransition($ids, 'suspended');
    }

    /**
     * Bulk terminate (any non-terminal status -> cancelled).
     *
     * @param  array<int, int|string>  $ids
     * @return array{updated: int, skipped: int, errors: list<string>}
     */
    public function bulkTerminate(array $ids): array
    {
        return $this->bulkTransition($ids, 'cancelled');
    }

    /**
     * @param  array<int, int|string>  $ids
     * @return array{updated: int, skipped: int, errors: list<string>}
     */
    private function bulkTransition(array $ids, string $to): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));

        $updated = 0;
        $skipped = 0;
        $errors = [];

        if ($ids === []) {
            return ['updated' => 0, 'skipped' => 0, 'errors' => $errors];
        }

        Domain::whereIn('id', $ids)->get()->each(function (Domain $domain) use ($to, &$updated, &$skipped, &$errors): void {
            try {
                $this->transition($domain, $to);
                $updated++;
            } catch (InvalidArgumentException $e) {
                $skipped++;
                $errors[] = "{$domain->name}: {$e->getMessage()}";
            }
        });

        return ['updated' => $updated, 'skipped' => $skipped, 'errors' => $errors];
    }

    // ─────────────────── Availability search (RDAP-first) ───────────────────

    /**
     * Whois-style availability check.
     *
     * Lookup order:
     *  1. Local CRM — a domain already registered in the CRM is never available.
     *  2. RDAP bootstrap ("https://rdap.org/domain/{name}") — authoritative
     *     availability from the registry when the network is reachable.
     *  3. Registrar driver (via RegistrarManager) — for the current registrar.
     *  4. Local `domain_pricing` table — as a price/sellable fallback.
     *
     * A network or parser failure never fabricates availability: when both
     * RDAP and the registrar are unreachable, the result surfaces
     * `available: null` and only a deterministic default price is returned.
     *
     * - "example.com"  → checks just that name
     * - "example"      → checks the label against the default TLD list
     *
     * @return array{
     *     query: string,
     *     valid: bool,
     *     error: string|null,
     *     results: list<array{domain: string, tld: string, label: string,
     *         available: bool|null, price: float, currency: string, premium: bool}>
     * }
     */
    public function searchAvailability(string $query): array
    {
        $query = strtolower(trim($query));
        $query = preg_replace('#^https?://#i', '', (string) $query) ?? $query;
        $query = rtrim($query, '/');

        $valid = (bool) preg_match(self::NAME_REGEX, $query);
        $candidates = [];

        if (str_contains($query, '.')) {
            if ($valid) {
                $candidates[] = $query;
            }
        } elseif ($query !== '' && preg_match('/^[a-z0-9]([a-z0-9-]*[a-z0-9])?$/i', $query)) {
            foreach (array_keys(self::DEFAULT_TLD_PRICES) as $tld) {
                $candidates[] = $query.'.'.$tld;
            }
        }

        $results = [];
        foreach ($candidates as $candidate) {
            $results[] = $this->resolveCandidate($candidate);
        }

        return [
            'query' => $query,
            'valid' => $valid,
            'error' => ($valid || $query === '' || $results !== [])
                ? null
                : 'Enter a valid domain name (e.g. example.com).',
            'results' => $results,
        ];
    }

    /**
     * Resolve availability + pricing for a single fully-qualified candidate.
     *
     * @return array{domain: string, tld: string, label: string, available: bool|null,
     *     price: float, currency: string, premium: bool}
     */
    private function resolveCandidate(string $candidate): array
    {
        [$label, $tld] = $this->splitDomain($candidate);

        // 1. Local CRM — a domain already tracked as taken is never offered.
        if (Domain::query()
            ->where('name', strtolower($candidate))
            ->whereIn('status', self::TAKEN_STATUSES)
            ->exists()) {
            return $this->buildResult($candidate, $label, $tld, false);
        }

        // 2. Authoritative RDAP check (404 -> available, 200 -> taken, null -> unknown).
        $rdap = $this->rdapAvailability($candidate);
        if ($rdap !== null) {
            return $this->buildResult($candidate, $label, $tld, $rdap);
        }

        // 3. RDAP unreachable/failed → consult an enabled registrar driver.
        $registrar = $this->registrarAvailability($candidate);
        if ($registrar !== null) {
            return $this->buildResult($candidate, $label, $tld, $registrar['available'], $registrar);
        }

        // 4. Unknown — never fabricate availability; price falls back to the
        //    domain_pricing table / deterministic defaults.
        return $this->buildResult($candidate, $label, $tld, null);
    }

    /**
     * Build a results row for the view, resolving price/currency/premium from
     * the registrar result first, then the `domain_pricing` table, then the
     * deterministic default table.
     *
     * @param  array{price: float|null, currency: string|null, premium: bool}|null  $registrar
     */
    private function buildResult(string $domain, string $label, string $tld, ?bool $available, ?array $registrar = null): array
    {
        return [
            'domain' => $domain,
            'tld' => $tld,
            'label' => $label,
            'available' => $available,
            'price' => $registrar['price'] ?? $this->priceFor($tld),
            'currency' => $registrar['currency'] ?? $this->currencyFor($tld),
            'premium' => $registrar['premium'] ?? $this->isPremium($label, $tld),
        ];
    }

    /**
     * RDAP bootstrap availability for a domain. Returns:
     *  - true  when the registry returns 404 (available),
     *  - false when the registry returns 200 (registered),
     *  - null  when the registry is unreachable / errors (unknown).
     */
    private function rdapAvailability(string $domain): ?bool
    {
        try {
            $response = Http::timeout(5)
                ->accept('application/rdap+json')
                ->get('https://rdap.org/domain/'.rawurlencode($domain));

            if ($response->notFound()) {
                return true; // registry has no record → available
            }

            if ($response->successful()) {
                return false; // registry has a record → taken
            }

            return null; // 5xx / rate-limited → unknown
        } catch (Throwable) {
            return null; // network failure → unknown, never fabricate
        }
    }

    /**
     * Registrar driver availability lookup (best-effort, never throws to caller).
     *
     * Uses the first enabled registrar whose driver is configured and online.
     *
     * @return array{available: bool, premium: bool, price: float|null, currency: string|null}|null
     */
    private function registrarAvailability(string $domain): ?array
    {
        try {
            $manager = app(RegistrarManager::class);

            foreach ($manager->enabled() as $code) {
                try {
                    $driver = $manager->driverFor($code);
                } catch (InvalidArgumentException) {
                    continue;
                }

                if (! $driver->isConfigured() || ! $driver->isOnline()) {
                    continue;
                }

                $result = $driver->checkAvailability($domain);

                return [
                    'available' => (bool) ($result['available'] ?? false),
                    'premium' => (bool) ($result['premium'] ?? false),
                    'price' => isset($result['price']) ? (float) $result['price'] : null,
                    'currency' => $result['currency'] ?? null,
                ];
            }

            return null;
        } catch (Throwable) {
            return null;
        }
    }

    /**
     * Resolve the per-TLD register price from the `domain_pricing` table,
     * falling back to the deterministic default when unlisted.
     */
    private function priceFor(string $tld): float
    {
        $pricing = DomainPricing::query()
            ->where('tld', $tld)
            ->where('enabled', true)
            ->first();

        return $pricing && $pricing->register_price !== null
            ? (float) $pricing->register_price
            : (float) (self::DEFAULT_TLD_PRICES[$tld] ?? 999.0);
    }

    private function currencyFor(string $tld): string
    {
        $pricing = DomainPricing::query()
            ->where('tld', $tld)
            ->where('enabled', true)
            ->first();

        return $pricing->currency ?? 'INR';
    }

    private function isPremium(string $label, string $tld): bool
    {
        $pricing = DomainPricing::query()
            ->where('tld', $tld)
            ->where('enabled', true)
            ->first();

        return $pricing ? (bool) $pricing->premium : strlen($label) <= 3;
    }

    /**
     * Whether a fully-qualified domain name is currently unregistered.
     *
     * Checks the local CRM first (registrability is never assumed for a domain
     * already tracked as taken), then the RDAP bootstrap registry.
     *
     * Returns null when the registry is unreachable and the CRM gives no signal.
     */
    public function isAvailable(string $domain): ?bool
    {
        if (Domain::query()
            ->where('name', strtolower(trim($domain)))
            ->whereIn('status', self::TAKEN_STATUSES)
            ->exists()) {
            return false;
        }

        return $this->rdapAvailability(strtolower(trim($domain)));
    }

    /**
     * Record a search in the domain_search_logs history table.
     */
    public function logSearch(string $domainName, array $results, ?int $customerId = null): DomainSearchLog
    {
        return DomainSearchLog::create([
            'customer_id' => $customerId,
            'domain_name' => $domainName,
            'results' => $results,
        ]);
    }

    // ─────────────────────────── Lookup helpers ───────────────────────────

    /**
     * Registrars available for domain records: configured registrar_settings
     * names merged with the reference defaults (resellerclub/openprovider/
     * cloudflare/custom).
     */
    public function registrars(): array
    {
        $defaults = ['resellerclub', 'openprovider', 'cloudflare', 'custom'];

        return array_values(array_unique(array_merge($defaults, RegistrarSetting::registrars())));
    }

    /**
     * Valid registration term lengths (years) — the "pricing term" selector.
     */
    public function pricingTerms(): array
    {
        return range(1, 10);
    }

    /**
     * @return array{0: string, 1: string} [label, tld]
     */
    private function splitDomain(string $domain): array
    {
        $parts = explode('.', $domain);
        $label = array_shift($parts);

        return [$label, implode('.', $parts)];
    }
}
