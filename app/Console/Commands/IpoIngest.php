<?php

namespace App\Console\Commands;

use App\Contracts\Ipo\JsonFileIpoProvider;
use App\Services\IpoIngestService;
use Illuminate\Console\Command;
use Throwable;

/**
 * Ingests the local IPO Ji mirror (database/data/ipos.json by default) into
 * the ipos / ipo_subscription_snapshots / ipo_gmp_history tables. Idempotent:
 * master rows upsert by slug, day-wise children by (ipo_id, as_on/exchange).
 */
class IpoIngest extends Command
{
    protected $signature = 'ipo:ingest
        {--file= : Path to the JSON mirror (defaults to config ipos.source_file)}';

    protected $description = 'Upsert IPO master rows + subscription/GMP history from the JSON mirror';

    public function handle(IpoIngestService $service): int
    {
        $path = $this->option('file') ?: (string) config('ipos.source_file');

        try {
            $provider = new JsonFileIpoProvider($path);
            $items = $provider->fetchAll();
        } catch (Throwable $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        }

        if ($items === []) {
            $this->warn('No IPO items in "'.basename($path).'".');

            return self::FAILURE;
        }

        $this->info('Ingesting '.count($items).' IPOs from "'.basename($path).'"…');

        $result = $service->ingest($items);

        $this->table(
            ['IPOs created', 'IPOs updated', 'Subscriptions', 'GMP quotes'],
            [[
                $result['ipos_created'],
                $result['ipos_updated'],
                $result['snapshots_written'],
                $result['gmp_written'],
            ]],
        );

        $this->info('Done.');

        return self::SUCCESS;
    }
}
