<?php

namespace App\Services;

use App\Contracts\Analysis\SentimentAnalyzer;
use App\Contracts\News\NewsProvider;
use App\Models\NewsArticle;
use App\Models\Stock;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Fetches headlines for a stock (symbol first, then the company name as a
 * fallback) and derives a 0..100 sentiment score that feeds the composite
 * signal score. Sentiment is produced by an injectable SentimentAnalyzer — the
 * free keyword heuristic today, swappable for an LLM provider via
 * config('news.sentiment_driver'). A neutral/no-opinion state always lands on 50.
 *
 * Every list fetch is persisted to news_articles (deduped by article_uuid +
 * symbol) so the mobile app can display the headline feed; the news:fetch
 * command additionally enriches recent articles with /details (link,
 * thumbnail, topics) without touching signal latency.
 *
 * Respects the free-tier limits: 2 req/sec (throttle) and a daily request cap
 * enforced against the app cache, plus the server-reported remaining-day
 * quota header (authoritative; free plan resets at UTC midnight). When the
 * cap is reached the component is paused for the rest of the day — the
 * strategy keeps running without news.
 *
 * Degrades gracefully: when the feature is disabled or the API is unreachable
 * the service returns an empty document and the scanner skips the component.
 */
class NewsService
{
    public function __construct(
        protected NewsProvider $provider,
        protected TradingConfigService $config,
        protected SentimentAnalyzer $sentiment,
    ) {}

    /**
     * Per-day request counter key (app cache; the DB cache store is persisted,
     * so counts survive restarts without extra tables).
     */
    protected function cacheKey(): string
    {
        return 'news_requests:'.now()->toDateString();
    }

    /**
     * Whether the daily free-tier request cap has been reached. Two signals
     * pause the component: our own cache counter and the server-reported
     * remaining-day quota (authoritative; free plan resets at UTC midnight).
     */
    protected function overDailyCap(): bool
    {
        $cap = $this->config->int('news.daily_cap', 1000);

        $exhaustedKey = 'news_quota_exhausted:'.now()->toDateString();

        if (Cache::get($exhaustedKey, false) === true) {
            if (! $this->capLogged) {
                Log::warning('FreeNewsApi reports the daily quota exhausted — news component paused for the day.');
                $this->capLogged = true;
            }

            return true;
        }

        if ($cap <= 0) {
            return false;
        }

        $used = (int) Cache::get($this->cacheKey(), 0);

        if ($used >= $cap) {
            if (! $this->capLogged) {
                Log::warning("FreeNewsApi daily cap ({$cap}) reached — news component paused for the day.");
                $this->capLogged = true;
            }

            return true;
        }

        return false;
    }

    protected bool $capLogged = false;

    /**
     * Pause the day when the provider reports 0 remaining requests and cache
     * that until the quota resets (per the reset header when present).
     */
    protected function applyServerQuota(): void
    {
        $remaining = $this->provider->quota()['remaining_day'] ?? null;

        if ($remaining !== null && $remaining <= 0) {
            Cache::put(
                'news_quota_exhausted:'.now()->toDateString(),
                true,
                $this->quotaResetAt() ?? now()->endOfDay(),
            );
        }
    }

    protected function quotaResetAt(): ?\DateTimeImmutable
    {
        $reset = $this->provider->quota()['reset_day'] ?? null;

        if ($reset === null || ! strtotime($reset)) {
            return null;
        }

        return new \DateTimeImmutable($reset);
    }

    /**
     * Whether the news component is wired on (env kill-switch + strategy toggle).
     */
    public function available(): bool
    {
        return (bool) config('news.enabled', true)
            && (bool) config('news.api_key')
            && $this->config->bool('news.enabled', true);
    }

    /**
     * Fetch headlines for a stock, searching the symbol first and falling back
     * to the company name when the ticker yields no results.
     *
     * @return array<int, array{uuid: string, title: string, published_at: string, publisher: string}>
     */
    public function fetch(string $symbol, string $name = ''): array
    {
        if (! $this->available()) {
            return [];
        }

        if ($this->overDailyCap()) {
            return [];
        }

        $lookbackHours = $this->config->int('news.lookback_hours', 48);
        $filters = [
            'published_after' => now()->subHours($lookbackHours)->toIso8601String(),
        ];

        $best = $this->tryFetch($symbol, $filters);

        if ($best === [] && $name !== '' && $name !== $symbol) {
            $best = $this->tryFetch($name, $filters);
        }

        $this->applyServerQuota();

        if ($best !== []) {
            $this->persist($symbol, $best);
        }

        return $best;
    }

    /**
     * Store headline articles with their per-article sentiment so the mobile
     * app can list them. Idempotent per (article_uuid, symbol).
     *
     * @param  array<int, array{uuid: string, title: string, published_at: string, publisher: string}>  $articles
     */
    public function persist(string $symbol, array $articles): void
    {
        $stockId = Stock::where('symbol', $symbol)->value('id');

        foreach ($articles as $article) {
            $analysis = $this->sentiment->analyze($article['title']);

            NewsArticle::updateOrCreate(
                ['article_uuid' => (string) $article['uuid'], 'symbol' => $symbol],
                [
                    'stock_id' => $stockId,
                    'title' => (string) $article['title'],
                    'publisher' => (string) $article['publisher'],
                    'published_at' => $this->parsePublishedAt($article['published_at']),
                    'sentiment' => $analysis['label'],
                    'sentiment_score' => round($analysis['score'], 2),
                ],
            );
        }
    }

    /**
     * Pull full /details (link, thumbnail, authors, topics) for the most
     * recent articles that don't have them yet. Runs in the news:fetch
     * command, throttled and counted against the same daily quota as scans.
     */
    public function enrich(?int $symbolLimit = null): int
    {
        $limit = $symbolLimit ?? max(1, $this->config->int('news.results', 10));
        $enriched = 0;

        $articles = NewsArticle::query()
            ->whereNull('original_url')
            ->latest('published_at')
            ->limit($limit)
            ->get();

        foreach ($articles as $article) {
            if ($this->overDailyCap()) {
                break;
            }

            try {
                $details = $this->provider->fetchDetails($article->article_uuid ?? '');
                $this->hitProvider();
            } catch (\Throwable $e) {
                $this->hitProvider();
                Log::warning("News detail enrichment failed for {$article->article_uuid}: {$e->getMessage()}");

                continue;
            }

            if ($details['original_url'] !== null) {
                $article->forceFill([
                    'original_url' => $details['original_url'],
                    'thumbnail' => $details['thumbnail'],
                    'authors' => $details['authors'],
                    'topics' => $details['topics'],
                ])->save();
                $enriched++;
            }
        }

        return $enriched;
    }

    /**
     * Read the persisted headline feed for the mobile app, newest first.
     * Optionally narrowed to a single ticker via ?symbol=.
     *
     * @return LengthAwarePaginator<int, NewsArticle>
     */
    public function recentNews(?string $symbol = null, int $perPage = 20): LengthAwarePaginator
    {
        return NewsArticle::query()
            ->when($symbol !== null && $symbol !== '', fn ($q) => $q->where('symbol', $symbol))
            ->latest('published_at')
            ->paginate(max(1, $perPage));
    }

    /**
     * Persisted headline count for a ticker (used by the news:fetch command
     * to report how many rows a run added).
     */
    public function count(string $symbol): int
    {
        return NewsArticle::where('symbol', $symbol)->count();
    }

    /**
     * Aggregate sentiment over a set of headlines. Each article is scored by
     * the active SentimentAnalyzer; the document score is the average of the
     * per-article scores (0..100), with counts bucketed by polarity.
     *
     * @param  array<int, array{uuid: string, title: string, published_at: string, publisher: string}>  $articles
     * @return array{
     *     score: float,
     *     positive: int,
     *     negative: int,
     *     neutral: int,
     *     sample: ?string
     * }
     */
    public function sentiment(array $articles): array
    {
        if ($articles === []) {
            return ['score' => 50.0, 'positive' => 0, 'negative' => 0, 'neutral' => 0, 'sample' => null];
        }

        $positive = $negative = $neutral = 0;
        $total = 0.0;
        $sample = null;

        foreach ($articles as $article) {
            $analysis = $this->sentiment->analyze($article['title']);
            $total += $analysis['score'];

            if ($analysis['polarity'] > 0) {
                $positive++;
            } elseif ($analysis['polarity'] < 0) {
                $negative++;
            } else {
                $neutral++;
            }

            if ($sample === null && $article['title'] !== '') {
                $sample = $article['title'];
            }
        }

        return [
            'score' => round($total / count($articles), 2),
            'positive' => $positive,
            'negative' => $negative,
            'neutral' => $neutral,
            'sample' => $sample,
        ];
    }

    /**
     * Request a single query, throttled to the provider's 2 req/sec limit.
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array{uuid: string, title: string, published_at: string, publisher: string}>
     */
    protected function tryFetch(string $query, array $filters): array
    {
        $results = $this->config->int('news.results', 10);

        $articles = $this->provider->fetch($query, $filters);

        $this->hitProvider();

        return array_slice($articles, 0, max(1, $results));
    }

    /**
     * Count one request against the daily cap and throttle to the provider's
     * 2 req/sec free-tier limit (skipped under PHPUnit).
     */
    protected function hitProvider(): void
    {
        // Track against the daily cap, throttle to the 2 req/sec free tier.
        Cache::increment($this->cacheKey());
        if (! app()->runningUnitTests()) {
            usleep(500_000);
        }
    }

    /**
     * Keep the raw ISO string (the model's datetime cast parses it), avoiding
     * coupling to a specific Carbon implementation.
     */
    protected function parsePublishedAt(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
