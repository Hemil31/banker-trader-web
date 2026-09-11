<?php

namespace Tests\Feature\News;

use App\Models\NewsArticle;
use App\Models\Stock;
use App\Models\User;
use App\Services\NewsService;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Laravel\Passport\Passport;
use Tests\TestCase;

/**
 * News persistence + mobile feed: fetch → news_articles, /details enrichment,
 * server-reported daily quota, GET /api/news, and the news:fetch command.
 */
class NewsArticlesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config(['news.api_key' => 'test-key']);
    }

    public function test_fetch_persists_articles_and_attributes_stock(): void
    {
        $stock = $this->makeStock('SBIN');

        Http::fake([
            'api.freenewsapi.io/*' => Http::response([
                'data' => [
                    ['uuid' => 'u1', 'title' => 'SBIN surges to record high', 'published_at' => '2026-09-10T00:00:00Z', 'publisher' => 'Reuters'],
                    ['uuid' => 'u2', 'title' => 'SBIN posts strong profit growth', 'published_at' => '2026-09-10T01:00:00Z', 'publisher' => 'Bloomberg'],
                ],
                'meta' => [],
            ], 200),
        ]);

        app(NewsService::class)->fetch('SBIN', 'State Bank of India');

        $rows = NewsArticle::orderBy('published_at')->get();
        $this->assertCount(2, $rows);
        $this->assertSame('SBIN', $rows[0]->symbol);
        $this->assertSame($stock->id, $rows[0]->stock_id);
        $this->assertSame('SBIN surges to record high', $rows[0]->title);
    }

    public function test_persisted_article_carries_sentiment_label_and_score(): void
    {
        $this->makeStock('SBIN');

        Http::fake([
            'api.freenewsapi.io/*' => Http::response([
                'data' => [
                    ['uuid' => 'u1', 'title' => 'SBIN plunges on fraud probe', 'published_at' => '2026-09-10T00:00:00Z', 'publisher' => 'Reuters'],
                    ['uuid' => 'u2', 'title' => 'SBIN schedules annual meeting', 'published_at' => '2026-09-10T01:00:00Z', 'publisher' => 'Bloomberg'],
                ],
                'meta' => [],
            ], 200),
        ]);

        app(NewsService::class)->fetch('SBIN', 'State Bank of India');

        $negative = NewsArticle::where('article_uuid', 'u1')->first();
        $neutral = NewsArticle::where('article_uuid', 'u2')->first();

        $this->assertSame('negative', $negative->sentiment);
        $this->assertSame(10.0, (float) $negative->sentiment_score);
        $this->assertSame('neutral', $neutral->sentiment);
        $this->assertSame(50.0, (float) $neutral->sentiment_score);
    }

    public function test_repeated_fetch_deduplicates_by_article_uuid(): void
    {
        $this->makeStock('SBIN');

        Http::fake([
            'api.freenewsapi.io/*' => Http::response([
                'data' => [
                    ['uuid' => 'u1', 'title' => 'SBIN rallies on strong results', 'published_at' => '2026-09-10T00:00:00Z', 'publisher' => 'Reuters'],
                ],
                'meta' => [],
            ], 200),
        ]);

        app(NewsService::class)->fetch('SBIN');
        app(NewsService::class)->fetch('SBIN');

        $this->assertSame(1, NewsArticle::where('symbol', 'SBIN')->count());
        $this->assertSame(1, NewsArticle::where('article_uuid', 'u1')->count());
    }

    public function test_enrich_fetches_details_for_unenriched_articles(): void
    {
        $this->makeStock('SBIN');

        Http::fake([
            'api.freenewsapi.io/v1/news*' => Http::response([
                'data' => [
                    ['uuid' => 'u1', 'title' => 'SBIN surges to record high', 'published_at' => '2026-09-10T00:00:00Z', 'publisher' => 'Reuters'],
                ],
                'meta' => [],
            ], 200),
            'api.freenewsapi.io/v1/details*' => Http::response([
                'data' => [
                    'uuid' => 'u1',
                    'title' => 'SBIN surges to record high',
                    'published_at' => '2026-09-10T00:00:00Z',
                    'publisher' => 'Reuters',
                    'original_url' => 'https://example.com/sbin',
                    // Long signed CDN asset URLs easily exceed 255 chars; the
                    // columns must be TEXT, not VARCHAR.
                    'thumbnail' => 'https://cdn.example.com/'.str_repeat('x', 600),
                    'authors' => ['Jane Trader'],
                    'topics' => ['markets', 'banking'],
                ],
            ], 200),
        ]);

        app(NewsService::class)->fetch('SBIN');

        $enriched = app(NewsService::class)->enrich(10);

        $this->assertSame(1, $enriched);

        $row = NewsArticle::where('article_uuid', 'u1')->first();
        $this->assertSame('https://example.com/sbin', $row->original_url);
        $this->assertStringStartsWith('https://cdn.example.com/', $row->thumbnail);
        $this->assertGreaterThan(255, mb_strlen($row->thumbnail));
        $this->assertSame(['Jane Trader'], $row->authors);
        $this->assertSame(['markets', 'banking'], $row->topics);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/details') && $request['uuid'] === 'u1');
    }

    public function test_server_reported_zero_quota_pauses_component_for_the_day(): void
    {
        $this->makeStock('SBIN');

        Http::fake([
            'api.freenewsapi.io/*' => Http::response([
                'data' => [
                    ['uuid' => 'u1', 'title' => 'SBIN rallies', 'published_at' => '2026-09-10T00:00:00Z', 'publisher' => 'Reuters'],
                ],
                'meta' => [],
            ], 200, ['X-RateLimit-Limit-Day' => '5000', 'X-RateLimit-Remaining-Day' => '0']),
        ]);

        app(NewsService::class)->fetch('SBIN');
        app(NewsService::class)->fetch('SBIN');

        $this->assertTrue(Cache::get('news_quota_exhausted:'.now()->toDateString()));
        $this->assertSame(1, count(Http::recorded()));
        $this->assertSame(1, NewsArticle::count());
    }

    public function test_news_api_requires_authentication(): void
    {
        $this->getJson('/api/news')->assertUnauthorized();
    }

    public function test_news_api_lists_articles_latest_first_with_symbol_filter(): void
    {
        Passport::actingAs(User::factory()->create());

        NewsArticle::create([
            'symbol' => 'SBIN', 'article_uuid' => 'u1', 'title' => 'Older SBIN headline',
            'published_at' => now()->subDays(2), 'sentiment' => 'neutral', 'sentiment_score' => 50,
            'original_url' => 'https://example.com/old', 'authors' => [], 'topics' => ['markets'],
        ]);
        NewsArticle::create([
            'symbol' => 'SBIN', 'article_uuid' => 'u2', 'title' => 'Fresher SBIN headline',
            'published_at' => now()->subHours(2), 'sentiment' => 'positive', 'sentiment_score' => 90,
            'original_url' => null, 'authors' => ['Jane'], 'topics' => [],
        ]);
        NewsArticle::create([
            'symbol' => 'RELIANCE', 'article_uuid' => 'u3', 'title' => 'Reliance headline',
            'published_at' => now()->subHour(), 'sentiment' => 'negative', 'sentiment_score' => 10,
            'original_url' => null, 'authors' => [], 'topics' => [],
        ]);

        $response = $this->getJson('/api/news?symbol=SBIN');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.0.title', 'Fresher SBIN headline')
            ->assertJsonPath('data.1.title', 'Older SBIN headline')
            ->assertJsonStructure([
                'pagination' => ['page', 'per_page', 'total', 'last_page'],
                'data' => [
                    '*' => ['id', 'symbol', 'title', 'published_at', 'sentiment', 'sentiment_score', 'original_url', 'authors', 'topics'],
                ],
            ]);
    }

    public function test_news_fetch_command_ingests_watchlist_stocks(): void
    {
        $this->makeStock('SBIN');
        $this->makeStock('RELIANCE');

        Http::fake([
            'api.freenewsapi.io/v1/news*' => Http::response([
                'data' => [
                    ['uuid' => 'u1', 'title' => 'SBIN rallies', 'published_at' => '2026-09-10T00:00:00Z', 'publisher' => 'Reuters'],
                ],
                'meta' => [],
            ], 200),
            'api.freenewsapi.io/v1/details*' => Http::response([
                'data' => [
                    'uuid' => 'u1', 'title' => 'SBIN rallies', 'published_at' => '2026-09-10T00:00:00Z',
                    'publisher' => 'Reuters', 'original_url' => 'https://example.com/sbin',
                    'thumbnail' => null, 'authors' => [], 'topics' => [],
                ],
            ], 200),
        ]);

        $this->artisan('news:fetch')
            ->expectsOutputToContain('Done.')
            ->assertSuccessful();

        $this->assertGreaterThanOrEqual(2, NewsArticle::count());
        $this->assertSame(1, NewsArticle::where('symbol', 'SBIN')->count());
        $this->assertSame(1, NewsArticle::where('symbol', 'RELIANCE')->count());
        $this->assertSame('https://example.com/sbin', NewsArticle::where('symbol', 'SBIN')->first()->original_url);
    }

    private function makeStock(string $symbol = 'SBIN'): Stock
    {
        return Stock::updateOrCreate(
            ['symbol' => $symbol],
            [
                'name' => 'State Bank of India',
                'exchange' => 'NSE',
                'active' => true,
                'in_watchlist' => true,
            ]
        );
    }
}