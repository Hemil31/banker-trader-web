<?php

namespace App\Services;

use App\Models\Ipo;
use Carbon\CarbonImmutable;

/**
 * Upserts IPO master rows (by slug) plus their day-wise subscription and GMP
 * history from a provider payload. Drives `php artisan ipo:ingest`. Child
 * rows are keyed (ipo_id, as_on | recorded_at) so re-runs are idempotent.
 */
class IpoIngestService
{
    /**
     * @param  array<int, array<string, mixed>>  $items
     * @return array{ipos_created: int, ipos_updated: int, snapshots_written: int, gmp_written: int}
     */
    public function ingest(array $items): array
    {
        $iposCreated = 0;
        $iposUpdated = 0;
        $snapshots = 0;
        $gmp = 0;

        foreach ($items as $item) {
            $slug = isset($item['slug']) && is_string($item['slug']) ? $item['slug'] : null;

            if ($slug === null || $slug === '') {
                continue;
            }

            $payload = $this->ipoPayload($item);

            if (Ipo::where('slug', $slug)->exists()) {
                $iposUpdated++;
            } else {
                $iposCreated++;
            }

            $ipo = Ipo::updateOrCreate(['slug' => $slug], $payload);

            $snapshots += $this->ingestSubscriptions($ipo, $item['subscription'] ?? []);
            $gmp += $this->ingestGmp($ipo, $item['gmp'] ?? []);

            $ipo->synced_at = CarbonImmutable::now();
            $this->syncCardValues($ipo, $item);
            $ipo->save();
        }

        return [
            'ipos_created' => $iposCreated,
            'ipos_updated' => $iposUpdated,
            'snapshots_written' => $snapshots,
            'gmp_written' => $gmp,
        ];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    protected function ipoPayload(array $item): array
    {
        $columns = array_fill_keys((new Ipo)->getFillable(), true);
        $payload = [];

        foreach ($item as $key => $value) {
            if ($key === 'subscription' || $key === 'gmp' || $key === 'current_gmp' || $key === 'current_subscription') {
                continue; // handled/persisted from children or synced below
            }

            if (! isset($columns[$key]) || $value === null) {
                continue;
            }

            $payload[$key] = $key === 'status' ? $this->normalizeStatus($value) : $value;
        }

        return $payload;
    }

    /**
     * @param  array<mixed>  $rows
     */
    protected function ingestSubscriptions(Ipo $ipo, array $rows): int
    {
        $written = 0;
        $columns = array_fill_keys([
            'qib', 'nii', 'bhni', 'shni', 'retail', 'employee', 'total',
        ], true);

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['as_on'])) {
                continue;
            }

            $values = [];
            foreach ($columns as $key => $_) {
                if (array_key_exists($key, $row)) {
                    $values[$key] = $row[$key];
                }
            }

            $ipo->subscriptionSnapshots()->updateOrCreate(
                ['as_on' => CarbonImmutable::parse($row['as_on'])->toDateTimeString()],
                $values,
            );
            $written++;
        }

        return $written;
    }

    /**
     * @param  array<mixed>  $rows
     */
    protected function ingestGmp(Ipo $ipo, array $rows): int
    {
        $written = 0;
        $columns = ['gmp', 'premium_pct', 'indicative_price'];

        foreach ($rows as $row) {
            if (! is_array($row) || empty($row['recorded_at'])) {
                continue;
            }

            $values = [];
            foreach ($columns as $key) {
                if (array_key_exists($key, $row)) {
                    $values[$key] = $row[$key];
                }
            }

            $ipo->gmpHistory()->updateOrCreate(
                ['recorded_at' => CarbonImmutable::parse($row['recorded_at'])->toDateTimeString()],
                $values,
            );
            $written++;
        }

        return $written;
    }

    /**
     * Refresh the denormalized card-level values from the child tables, with
     * the payload's own expected-premium values taking source priority.
     *
     * @param  array<string, mixed>  $item
     */
    protected function syncCardValues(Ipo $ipo, array $item): void
    {
        $latestSubscription = $ipo->subscriptionSnapshots()->orderByDesc('as_on')->value('total');
        $latestGmp = $ipo->gmpHistory()->orderByDesc('recorded_at')->value('gmp');

        $ipo->current_subscription = $latestSubscription ?? ($item['current_subscription'] ?? null);
        $ipo->current_gmp = $latestGmp ?? ($item['current_gmp'] ?? null);

        if (array_key_exists('expected_premium', $item)) {
            $ipo->expected_premium = $item['expected_premium'];
        }

        if (array_key_exists('expected_premium_pct', $item)) {
            $ipo->expected_premium_pct = $item['expected_premium_pct'];
        }
    }

    /**
     * Accept the provider's own wording (e.g. "Pre-Apply", "Allotment Awaited")
     * or the normalized enum already used in the payload.
     */
    protected function normalizeStatus(string $status): string
    {
        return match (strtolower(trim($status))) {
            'pre-apply', 'pre apply', 'announced' => 'upcoming',
            'allotment awaited', 'allotment_awaited' => 'allotment_awaited',
            'drhp approved', 'drhp_approved' => 'drhp_approved',
            'listed today', 'listed' => 'listed',
            default => $status,
        };
    }
}
