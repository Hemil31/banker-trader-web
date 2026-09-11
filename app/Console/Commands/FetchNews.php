<?php

namespace App\Console\Commands;

use App\Models\Stock;
use App\Services\NewsService;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

/**
 * Fetches headlines for the watchlist from the news provider, persists them
 * to news_articles (for the mobile feed) and enriches the newest ones with
 * article details (link, thumbnail). Runs throttled within the free-tier
 * daily quota — respects the same cap as signal scans.
 */
class FetchNews extends Command
{
    protected $signature = 'news:fetch
        {--symbol=* : Restrict to these stock IDs (repeatable)}';

    protected $description = 'Fetch and persist news headlines for watchlist stocks';

    public function handle(NewsService $news): int
    {
        $stocks = $this->targets();

        if ($stocks->isEmpty()) {
            $this->error('No active watchlist stocks found. Run `php artisan db:seed` first.');

            return self::FAILURE;
        }

        $this->info("Fetching news for {$stocks->count()} stocks…");

        $headlines = $enriched = 0;

        foreach ($stocks as $stock) {
            try {
                $before = $news->count($stock->symbol);
                $articles = $news->fetch($stock->symbol, $stock->name);
                $stored = $news->count($stock->symbol) - $before;
                $detailCount = $news->enrich();

                $headlines += $stored;
                $enriched += $detailCount;

                if ($stored === 0 && $detailCount === 0) {
                    $this->line("  ~ {$stock->symbol} — no new headlines (skip? paused/same)");
                } else {
                    $this->line("  ✓ {$stock->symbol} — {$stored} new headlines, {$detailCount} enriched");
                }
            } catch (\Throwable $e) {
                $this->error("  ✗ {$stock->symbol} — {$e->getMessage()}");
            }
        }

        $this->info("Done. {$headlines} new headlines, {$enriched} articles enriched.");

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, Stock>
     */
    protected function targets(): Collection
    {
        $ids = array_filter($this->option('symbol'));

        return $ids === []
            ? Stock::where('active', true)->where('in_watchlist', true)->get()
            : Stock::whereIn('id', $ids)->get();
    }
}