<?php

namespace Tests\Feature\News;

use App\Contracts\News\FreeNewsApiProvider;
use App\Contracts\News\NewsProvider;
use App\Models\MarketData;
use App\Models\Stock;
use App\Models\TradingConfig;
use App\Models\TradingSignal;
use App\Services\NewsService;
use App\Services\SignalEngine;
use App\Services\SignalScanner;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class NewsSentimentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(DatabaseSeeder::class);
        config(['news.api_key' => 'test-key']);
    }

    public function test_provider_sends_api_key_and_parses_articles(): void
    {
        Http::fake([
            'api.freenewsapi.io/*' => Http::response([
                'data' => [
                    ['uuid' => 'u1', 'title' => 'Reliance shares rally', 'published_at' => '2026-04-01T00:00:00Z', 'publisher' => 'Reuters'],
                    ['uuid' => 'u2', 'title' => 'Reliance drops on probe', 'published_at' => '2026-04-02T00:00:00Z', 'publisher' => 'Bloomberg'],
                ],
                'meta' => ['returned' => 2],
            ], 200),
        ]);

        $articles = app(FreeNewsApiProvider::class)->fetch('Reliance', ['language' => 'en', 'country' => 'in']);

        Http::assertSent(fn ($request) => str_contains($request->url(), '/news')
            && $request->hasHeader('x-api-key', 'test-key')
            && $request['in_title'] === 'Reliance');

        // FreeNewsApi 500s when country/language filters are combined with
        // in_title, so title searches must travel without geo filters.
        Http::assertNotSent(fn ($request) => isset($request['language']) || isset($request['country']));

        $this->assertCount(2, $articles);
        $this->assertSame('Reliance shares rally', $articles[0]['title']);
        $this->assertSame('Reliance drops on probe', $articles[1]['title']);
    }

    public function test_provider_throws_when_api_key_missing(): void
    {
        config(['news.api_key' => null]);

        $this->expectExceptionMessage('FreeNewsApi key is not configured');

        app(FreeNewsApiProvider::class)->fetch('Reliance');
    }

    public function test_provider_throws_on_failed_response(): void
    {
        Http::fake(['*' => Http::response(['error' => 'upstream'], 500)]);

        $this->expectExceptionMessage('FreeNewsApi request failed');

        app(FreeNewsApiProvider::class)->fetch('Reliance');
    }

    public function test_sentiment_scores_positive_headlines_high(): void
    {
        $doc = app(NewsService::class)->sentiment([
            $this->article('SBIN surges to record high, analysts upgrade'),
            $this->article('Strong profit growth continues'),
        ]);

        $this->assertSame(90.0, $doc['score']);
        $this->assertSame(2, $doc['positive']);
        $this->assertSame(0, $doc['negative']);
    }

    public function test_sentiment_scores_negative_headlines_low(): void
    {
        $doc = app(NewsService::class)->sentiment([
            $this->article('SBIN plunges on fraud probe'),
            $this->article('Losses widen, warnings issued'),
        ]);

        $this->assertSame(10.0, $doc['score']);
        $this->assertSame(0, $doc['positive']);
        $this->assertSame(2, $doc['negative']);
    }

    public function test_sentiment_is_neutral_without_signal_keywords(): void
    {
        $doc = app(NewsService::class)->sentiment([$this->article('SBIN announces AGM date')]);

        $this->assertSame(50.0, $doc['score']);
        $this->assertSame(1, $doc['neutral']);
    }

    public function test_sentiment_of_empty_set_is_neutral(): void
    {
        $doc = app(NewsService::class)->sentiment([]);

        $this->assertSame(50.0, $doc['score']);
        $this->assertSame(0, $doc['positive']);
        $this->assertSame(0, $doc['negative']);
        $this->assertNull($doc['sample']);
    }

    public function test_news_service_falls_back_to_company_name_when_symbol_yields_nothing(): void
    {
        $provider = new class implements NewsProvider
        {
            /** @var array<int, string> */
            public array $calls = [];

            public function fetch(string $query, array $filters = []): array
            {
                $this->calls[] = $query;

                if ($query === 'SBIN') {
                    return [];
                }

                return [$this->article('State Bank of India posts record profit')];
            }

            public function fetchDetails(string $uuid): array
            {
                return ['uuid' => $uuid, 'title' => '', 'published_at' => '', 'publisher' => null, 'original_url' => null, 'thumbnail' => null, 'authors' => [], 'topics' => []];
            }

            public function quota(): array
            {
                return ['limit_day' => null, 'remaining_day' => null, 'reset_day' => null];
            }

            private function article(string $title): array
            {
                return ['uuid' => 'u1', 'title' => $title, 'published_at' => now()->toIso8601String(), 'publisher' => 'Reuters'];
            }
        };

        $this->app->instance(NewsProvider::class, $provider);

        $articles = app(NewsService::class)->fetch('SBIN', 'State Bank of India');

        $this->assertSame(['SBIN', 'State Bank of India'], $provider->calls);
        $this->assertCount(1, $articles);
        $this->assertSame('State Bank of India posts record profit', $articles[0]['title']);
    }

    public function test_news_service_pauses_when_daily_cap_is_reached(): void
    {
        TradingConfig::set('news.daily_cap', 5);
        Cache::forever('news_requests:'.now()->toDateString(), 5);

        $provider = $this->createMock(NewsProvider::class);
        $provider->expects($this->never())->method('fetch');
        $this->app->instance(NewsProvider::class, $provider);

        $this->assertSame([], app(NewsService::class)->fetch('SBIN', 'State Bank of India'));
    }

    public function test_scanner_incorporates_news_sentiment_into_the_composite_score(): void
    {
        $this->app->instance(NewsProvider::class, $this->providerReturning([
            $this->article('SBIN surges to record high, analysts upgrade'),
            $this->article('SBIN stock rally continues'),
        ]));

        $stock = $this->makeStock();
        $setup = app(SignalScanner::class)->scan($stock, $this->seedSeries($stock));

        $this->assertNotNull($setup);
        $this->assertTrue($setup['tradable']);
        $this->assertArrayHasKey('news', $setup['components']);
        $this->assertSame(90.0, $setup['news_score']);
        $this->assertArrayHasKey('news', $setup['reasons']);
        $this->assertStringContainsString('News +40', $setup['reasons']['news']);
    }

    public function test_scanner_skips_news_component_when_disabled(): void
    {
        TradingConfig::set('news.enabled', false);
        $provider = $this->createMock(NewsProvider::class);
        $provider->expects($this->never())->method('fetch');
        $this->app->instance(NewsProvider::class, $provider);

        $stock = $this->makeStock();
        $setup = app(SignalScanner::class)->scan($stock, $this->seedSeries($stock));

        $this->assertNotNull($setup);
        $this->assertArrayNotHasKey('news', $setup['components']);
        $this->assertNull($setup['news_score']);
    }

    public function test_scanner_skips_news_component_on_provider_failure(): void
    {
        $provider = $this->createMock(NewsProvider::class);
        $provider->method('fetch')->willThrowException(new \RuntimeException('upstream down'));
        $this->app->instance(NewsProvider::class, $provider);

        $stock = $this->makeStock();
        $setup = app(SignalScanner::class)->scan($stock, $this->seedSeries($stock));

        $this->assertNotNull($setup);
        $this->assertArrayNotHasKey('news', $setup['components']);
        $this->assertNull($setup['news_score']);
    }

    public function test_signal_engine_persists_news_score_on_trading_signal(): void
    {
        $this->app->instance(NewsProvider::class, $this->providerReturning([
            $this->article('SBIN surges to record high, analysts upgrade'),
        ]));

        $stock = $this->makeStock();
        $this->seedSeries($stock);

        $result = app(SignalEngine::class)->run(collect([$stock]));

        $this->assertNotEmpty($result['generated']);

        $signal = TradingSignal::where('stock_id', $stock->id)->first();
        $this->assertNotNull($signal);
        $this->assertSame('candidate', $signal->status);
        $this->assertNotNull($signal->news_score);
        $this->assertSame(90.0, (float) $signal->news_score);
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

    /**
     * A gentle pullback with a late bounce: last close sits ~4% below the
     * 20-day high, above the 5/20/50-day MAs, and above the min price floor.
     */
    private function seedSeries(Stock $stock): Collection
    {
        $closes = [100, 99.5, 99, 99, 98.5, 98, 97.5, 97, 96.5, 96, 95.5, 95, 93.5, 92.5, 92, 91.5, 91, 93, 95, 96];
        $rows = collect();

        foreach ($closes as $i => $close) {
            $rows->push(MarketData::create([
                'stock_id' => $stock->id,
                'trade_date' => today()->subDays(count($closes) - $i),
                'open' => $close,
                'high' => $close + 0.5,
                'low' => $close - 0.5,
                'close' => $close,
                'adjusted_close' => $close,
                'volume' => $i === count($closes) - 1 ? 200000 : 100000,
            ]));
        }

        return $rows;
    }

    private function providerReturning(array $articles): NewsProvider
    {
        $provider = $this->createMock(NewsProvider::class);
        $provider->method('fetch')->willReturn($articles);

        return $provider;
    }

    private function article(string $title): array
    {
        return ['uuid' => 'u-'.md5($title), 'title' => $title, 'published_at' => now()->toIso8601String(), 'publisher' => 'Test Wire'];
    }
}
