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

        // Refresh the last week of OHLCV bars daily after close so trader:auto
        // never scans off stale data (was previously manual-only — see
        // RiskManager's stale-data staleness gate, which depends on this
        // running regularly). --from is a rolling 7-day window (idempotent
        // upsert), not the full history, to keep the daily run light.
        $schedule->command('market:ingest', ['--from' => now()->subDays(7)->toDateString()])
            ->weekdays()
            ->timezone('Asia/Kolkata')
            ->dailyAt('15:45')
            ->withoutOverlapping()
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
