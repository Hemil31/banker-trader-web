<?php

use App\Http\Middleware\EnsureUserIsAdmin;
use App\Http\Middleware\HandleAppearance;
use App\Http\Middleware\HandleInertiaRequests;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Middleware\AddLinkHeadersForPreloadedAssets;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule) {
        $schedule->command('trader:auto')
            ->weekdays()
            ->between('9:15', '15:25')
            ->timezone('Asia/Kolkata')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        $schedule->command('news:fetch')
            ->weekdays()
            ->between('9:15', '15:25')
            ->timezone('Asia/Kolkata')
            ->hourly()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // Refresh market data after every close so scans always use the latest
        // bar. Runs Mon–Fri 16:05 IST (after the 15:30 close) plus once more in
        // the evening; Cron (schedule:run on the host) fires this automatically.
        // (Was previously manual-only — see RiskManager's stale-data gate,
        // which depends on this running regularly.)
        $schedule->command('market:ingest --from='.now()->subDays(10)->toDateString())
            ->weekdays()
            ->at('16:05')
            ->timezone('Asia/Kolkata')
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        $schedule->command('market:ingest --from='.now()->subDays(10)->toDateString())
            ->weekdays()
            ->at('20:30')
            ->timezone('Asia/Kolkata')
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // Mandatory DB-vs-broker position check (Step 14 of the platform
        // spec) — the DB is never assumed correct. A mismatch halts new
        // orders platform-wide via EmergencyControlService.
        $schedule->command('trader:reconcile')
            ->weekdays()
            ->between('9:15', '15:25')
            ->timezone('Asia/Kolkata')
            ->everyFifteenMinutes()
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // Daily social-post deployment: two windows (09:00 and 21:00 IST).
        // Each run ensures AI post-generation slots (auto prompt + title +
        // rolling content category) exist for TODAY through the next 3 days at
        // its scheduled_time and queues GenerateAiPostJob for anything still
        // pending/failed — so a missing run is self-healed by the next window.
        // The job's own rate-limiter + WithoutOverlapping middleware (see
        // AppServiceProvider) is what actually paces Gemini calls. queue:work
        // then executes the queued jobs a few minutes after each window.
        $schedule->command('posts:generate --time=09:00:00 --from=today --to=+3 days --retry-failed')
            ->dailyAt('09:00')
            ->timezone('Asia/Kolkata')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        $schedule->command('posts:generate --time=21:00:00 --from=today --to=+3 days --retry-failed')
            ->dailyAt('21:00')
            ->timezone('Asia/Kolkata')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        $schedule->command('queue:work database --stop-when-empty --timeout=300')
            ->dailyAt('09:05')
            ->timezone('Asia/Kolkata')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        $schedule->command('queue:work database --stop-when-empty --timeout=300')
            ->dailyAt('21:05')
            ->timezone('Asia/Kolkata')
            ->withoutOverlapping()
            ->appendOutputTo(storage_path('logs/scheduler.log'));

        // Holiday data changes rarely (bundled static file, see
        // database/data/NOTICE.md) — a daily pre-market refresh of the
        // market_holidays table is more than enough for RiskManager's
        // market_holiday halt check to stay accurate.
        $schedule->command('market-calendar:sync')
            ->dailyAt('05:45')
            ->timezone('Asia/Kolkata')
            ->appendOutputTo(storage_path('logs/scheduler.log'));
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->encryptCookies(except: ['appearance', 'sidebar_state']);

        $middleware->web(append: [
            HandleAppearance::class,
            HandleInertiaRequests::class,
            AddLinkHeadersForPreloadedAssets::class,
        ]);

        $middleware->alias([
            'is_admin' => EnsureUserIsAdmin::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );
    })->create();
