<?php

namespace App\Providers;

use App\Contracts\Analysis\SentimentAnalyzer;
use App\Contracts\Festival\FestivalProvider;
use App\Contracts\Gemini\GeminiClient;
use App\Contracts\MarketCalendar\JsonTradingCalendarProvider;
use App\Contracts\MarketCalendar\MarketCalendarProvider;
use App\Contracts\MarketData\MarketDataProvider;
use App\Contracts\MarketData\YahooFinanceProvider;
use App\Contracts\News\FreeNewsApiProvider;
use App\Contracts\News\NewsProvider;
use App\Contracts\Repositories\AuthRepositoryInterface;
use App\Contracts\Zernio\ZernioClient;
use App\Models\TradingConfig;
use App\Repositories\AuthRepository;
use App\Services\Analysis\KeywordSentimentAnalyzer;
use App\Services\Festival\MarketHolidayFestivalProvider;
use App\Services\Gemini\HttpGeminiClient;
use App\Services\TradingConfigService;
use App\Services\Zernio\SdkZernioClient;
use Carbon\CarbonImmutable;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\Rules\Password;
use Laravel\Passport\Passport;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(AuthRepositoryInterface::class, AuthRepository::class);
        $this->app->bind(MarketDataProvider::class, YahooFinanceProvider::class);
        $this->app->bind(MarketCalendarProvider::class, JsonTradingCalendarProvider::class);
        $this->app->bind(NewsProvider::class, FreeNewsApiProvider::class);
        $this->app->bind(ZernioClient::class, SdkZernioClient::class);
        $this->app->bind(GeminiClient::class, HttpGeminiClient::class);

        // Backed by the synced Indian market-holiday calendar (market_holidays
        // — see MarketCalendarService); GeminiPostGenerationService gets a
        // festival-themed prompt on named holidays, normal content otherwise.
        $this->app->bind(FestivalProvider::class, MarketHolidayFestivalProvider::class);

        // The strategy config is state shared across the whole engine (signal
        // scanner, sizer, risk, execution). Scoping it means the automation
        // loop can apply per-account overrides once and every downstream
        // service sees them, without leaking across HTTP requests.
        $this->app->scoped(TradingConfigService::class);

        // Pluggable news sentiment. Default is the free keyword heuristic; an
        // LLM provider can later implement SentimentAnalyzer and be selected
        // here via config('news.sentiment_driver') without touching engine code.
        $this->app->bind(SentimentAnalyzer::class, fn (): SentimentAnalyzer => new KeywordSentimentAnalyzer);

        Passport::enablePasswordGrant();
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureDefaults();
        $this->configureRateLimiters();
    }

    /**
     * Rate limiter enforced on GenerateAiPostJob via its RateLimited queue
     * middleware — throttles Gemini calls to the configured model's RPM/RPD
     * (trading_configs gemini.rpm/gemini.rpd, env/config fallback) instead of
     * firing requests as fast as the queue can pop jobs.
     */
    protected function configureRateLimiters(): void
    {
        RateLimiter::for('gemini-generation', function (): array {
            $rpm = (int) (TradingConfig::get('gemini.rpm') ?: config('gemini.rpm', 10));
            $rpd = (int) (TradingConfig::get('gemini.rpd') ?: config('gemini.rpd', 20));

            return [
                Limit::perMinute(max(1, $rpm)),
                Limit::perDay(max(1, $rpd)),
            ];
        });
    }

    /**
     * Configure default behaviors for production-ready applications.
     */
    protected function configureDefaults(): void
    {
        Date::use(CarbonImmutable::class);

        DB::prohibitDestructiveCommands(
            app()->isProduction(),
        );

        Password::defaults(fn (): ?Password => app()->isProduction()
            ? Password::min(12)
                ->mixedCase()
                ->letters()
                ->numbers()
                ->symbols()
                ->uncompromised()
            : null,
        );
    }
}
