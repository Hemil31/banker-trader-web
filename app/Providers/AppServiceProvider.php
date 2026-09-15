<?php

namespace App\Providers;

use App\Contracts\Analysis\SentimentAnalyzer;
use App\Contracts\MarketData\MarketDataProvider;
use App\Contracts\MarketData\YahooFinanceProvider;
use App\Contracts\News\FreeNewsApiProvider;
use App\Contracts\News\NewsProvider;
use App\Contracts\Repositories\AuthRepositoryInterface;
use App\Contracts\Zernio\ZernioClient;
use App\Repositories\AuthRepository;
use App\Services\Analysis\KeywordSentimentAnalyzer;
use App\Services\TradingConfigService;
use App\Services\Zernio\SdkZernioClient;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
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
        $this->app->bind(NewsProvider::class, FreeNewsApiProvider::class);
        $this->app->bind(ZernioClient::class, SdkZernioClient::class);

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
