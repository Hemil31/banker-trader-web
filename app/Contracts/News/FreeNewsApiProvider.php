<?php

namespace App\Contracts\News;

use App\Services\TradingConfigService;
use Illuminate\Support\Facades\Http;

/**
 * FreeNewsApi.io REST adapter. Sends the API key in the x-api-key header and
 * searches article titles only (in_title), as the API removed body/subtitle
 * search in 2026. Returns the normalized article listing; full /details is
 * fetched only during explicit enrichment (news:fetch), never during signal
 * scans, to stay well inside the free quota.
 *
 * The free plan reports daily quota via X-RateLimit-* response headers; the
 * latest values are exposed through quota() so NewsService can halt the
 * component once the server says the day is exhausted.
 *
 * The key itself is DB-backed (trading_configs: news.api_key, not editable
 * via the per-user config API) with an env fallback (NEWS_API_KEY), mirroring
 * how broker app-level credentials resolve — see BrokerOAuthService.
 */
class FreeNewsApiProvider implements NewsProvider
{
    /** @var array{limit_day: ?int, remaining_day: ?int, reset_day: ?string} */
    protected array $lastQuota = ['limit_day' => null, 'remaining_day' => null, 'reset_day' => null];

    public function __construct(protected TradingConfigService $config) {}

    protected function apiKey(): string
    {
        return (string) ($this->config->get('news.api_key') ?: config('news.api_key'));
    }

    public function fetch(string $query, array $filters = []): array
    {
        $key = $this->apiKey();
        if (! $key) {
            throw new \RuntimeException('FreeNewsApi key is not configured (set news.api_key or NEWS_API_KEY).');
        }

        // FreeNewsApi currently 500s when country/language filters are combined
        // with in_title (upstream regression, verified 2026-09). Since every
        // request here is a title search, we intentionally send no geo filters
        // (the API already defaults to en + US server-side).
        $isTitleSearch = $query !== '';

        try {
            $response = Http::timeout((int) config('news.timeout', 15))
                ->retry(2, 500)
                ->withHeaders(['x-api-key' => $key, 'Accept' => 'application/json'])
                ->get(
                    rtrim((string) config('news.api_base', 'https://api.freenewsapi.io/v1'), '/').'/news',
                    array_filter([
                        'in_title' => $query,
                        'language' => $isTitleSearch ? null : ($filters['language'] ?? config('news.language', 'en')),
                        'country' => $isTitleSearch ? null : ($filters['country'] ?? config('news.country', 'in')),
                        'topic' => $filters['topic'] ?? null,
                        'published_after' => $filters['published_after'] ?? null,
                        'published_before' => $filters['published_before'] ?? null,
                        'order_by' => $filters['order_by'] ?? 'recent',
                        'offset' => $filters['offset'] ?? null,
                    ], fn ($v) => $v !== null && $v !== ''),
                );
        } catch (\Throwable $e) {
            throw new \RuntimeException("FreeNewsApi request failed for \"{$query}\": {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            throw new \RuntimeException("FreeNewsApi request failed for \"{$query}\" (HTTP {$response->status()}).");
        }

        $this->recordQuota($response->headers());

        $articles = $response->json('data') ?? [];

        return array_values(array_map(
            fn (array $a) => [
                'uuid' => (string) ($a['uuid'] ?? ''),
                'title' => (string) ($a['title'] ?? ''),
                'published_at' => (string) ($a['published_at'] ?? ''),
                'publisher' => (string) ($a['publisher'] ?? ''),
            ],
            $articles,
        ));
    }

    public function fetchDetails(string $uuid): array
    {
        $key = $this->apiKey();
        if (! $key) {
            throw new \RuntimeException('FreeNewsApi key is not configured (set news.api_key or NEWS_API_KEY).');
        }

        if ($uuid === '') {
            return $this->emptyDetails();
        }

        try {
            $response = Http::timeout((int) config('news.timeout', 15))
                ->retry(2, 500)
                ->withHeaders(['x-api-key' => $key, 'Accept' => 'application/json'])
                ->get(
                    rtrim((string) config('news.api_base', 'https://api.freenewsapi.io/v1'), '/').'/details',
                    ['uuid' => $uuid],
                );
        } catch (\Throwable $e) {
            throw new \RuntimeException("FreeNewsApi details failed for \"{$uuid}\": {$e->getMessage()}", 0, $e);
        }

        if ($response->failed()) {
            throw new \RuntimeException("FreeNewsApi details failed for \"{$uuid}\" (HTTP {$response->status()}).");
        }

        $d = (array) ($response->json('data') ?? []);

        return [
            'uuid' => (string) ($d['uuid'] ?? $uuid),
            'title' => (string) ($d['title'] ?? ''),
            'published_at' => (string) ($d['published_at'] ?? ''),
            'publisher' => isset($d['publisher']) ? (string) $d['publisher'] : null,
            'original_url' => isset($d['original_url']) ? (string) $d['original_url'] : null,
            'thumbnail' => isset($d['thumbnail']) ? (string) $d['thumbnail'] : null,
            'authors' => array_values(array_map('strval', (array) ($d['authors'] ?? []))),
            'topics' => array_values(array_map('strval', (array) ($d['topics'] ?? []))),
        ];
    }

    public function quota(): array
    {
        return $this->lastQuota;
    }

    /**
     * Capture the free-plan daily quota from a list response's headers:
     * X-RateLimit-Limit-Day / X-RateLimit-Remaining-Day / X-RateLimit-Reset-Day.
     *
     * @param  array<int, array<int, string>|string>  $headers
     */
    protected function recordQuota(array $headers): void
    {
        $this->lastQuota = [
            'limit_day' => $this->headerInt($headers, 'X-RateLimit-Limit-Day'),
            'remaining_day' => $this->headerInt($headers, 'X-RateLimit-Remaining-Day'),
            'reset_day' => isset($headers['X-RateLimit-Reset-Day'][0]) ? (string) $headers['X-RateLimit-Reset-Day'][0] : null,
        ];
    }

    /**
     * @param  array<int, array<int, string>|string>  $headers
     */
    protected function headerInt(array $headers, string $name): ?int
    {
        $value = $headers[$name][0] ?? null;

        return $value !== null && is_numeric($value) ? (int) $value : null;
    }

    /**
     * @return array{uuid: string, title: string, published_at: string, publisher: null, original_url: null, thumbnail: null, authors: array, topics: array}
     */
    protected function emptyDetails(): array
    {
        return [
            'uuid' => '',
            'title' => '',
            'published_at' => '',
            'publisher' => null,
            'original_url' => null,
            'thumbnail' => null,
            'authors' => [],
            'topics' => [],
        ];
    }
}
