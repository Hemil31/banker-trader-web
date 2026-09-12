# CLAUDE.md

This file provides guidance to Claude Code (claude.ai/code) when working with code in this repository.

## Project overview

BankerTrader is a trading-automation platform backend: Laravel 12 / PHP 8.3, MySQL 8, Laravel Passport (OAuth2) for the JSON API, and Inertia + React + Vite for a web admin dashboard. It hosts the API consumed by a separate Flutter mobile client (`banker-trader-mobile`, not in this repo) and runs an automated paper/live trading engine (signal scanning → risk sizing → order execution) on a schedule.

**Database is MySQL only** — every table uses UUID primary keys (`HasUuids` on all models); sqlite is not supported anywhere, including tests.

## Commands

```bash
# Setup
composer setup                 # install, .env, key:generate, migrate, npm install+build

# Dev servers
php artisan serve              # API (also `composer dev` runs the full artisan dev stack)
npm run dev                    # Vite dev server for the Inertia/React frontend (Node needed locally only)

# Full CI gate (what .github/workflows/tests.yml runs)
composer ci:check              # npm run check && tsc --noEmit && phpunit

# Individual gates
composer test                  # config:clear + pint --test + phpstan + php artisan test
php artisan test                                   # PHPUnit, all suites
php artisan test --filter=PaperTradingServiceTest   # single test class
php artisan test tests/Feature/Broker/AngelBrokerTest.php
./vendor/bin/phpstan analyse --memory-limit=1G      # level 7; default memory limit crashes on this box
./vendor/bin/pint                                   # fix style (laravel preset)
./vendor/bin/pint --test                            # check only
npm run check                                       # eslint/prettier for resources/js
npm run types:check                                 # tsc --noEmit

# Domain artisan commands (also scheduled — see bootstrap/app.php)
php artisan trader:auto [--account=] [--symbol=]    # run the automation engine for enabled accounts
php artisan news:fetch                              # ingest news for sentiment
php artisan trader:paper                            # (PaperTradingRun command)
php artisan market:ingest                           # (MarketDataIngest command)
```

Tests run against a real MySQL DB (`bankertrader_test`, see `phpunit.xml`), not sqlite — create it locally before running `php artisan test`.

## Architecture

### Request flow & response envelope

`Request → Controller → Service → (Contract/Model) → Response`. Controllers are thin (validate via FormRequest, call one service, format the response) — business logic lives in `app/Services/`. Most services operate on Eloquent models directly; the repository/interface pattern (`app/Contracts/*`, bound in `AppServiceProvider::register()`) is reserved for things that need a swappable backend: `AuthRepositoryInterface`, `MarketDataProvider`, `NewsProvider`, `SentimentAnalyzer`, and the broker adapters. Don't assume a repository exists for every model — check `app/Repositories/` (currently just `AuthRepository`) before creating one.

Every API response uses the same JSON envelope via `App\Traits\ResponseStructure` (`successResponse`/`errorResponse`/`paginated`): `{ "success": bool, "message": string, "data"|"errors": ... }`. Controllers extending it should use these helpers rather than ad-hoc `response()->json(...)`.

### API authentication (Passport)

- `api` guard uses the `passport` driver (`config/auth.php`); `User` uses `HasApiTokens` + `Laravel\Passport\Contracts\OAuthenticatable`.
- The password-grant OAuth client is app-level (`config('passport.client_id/secret')`, from `.env`); `Passport::enablePasswordGrant()` is called in `AppServiceProvider::boot()`.
- **Tokens are issued in-process**, not via a nested HTTP call to `/oauth/token` (that deadlocks under the single-worker `php -S` dev server). `App\Services\AuthService` builds a `Symfony\Component\HttpFoundation\Request`, converts it with `PsrHttpFactory`, and calls `AuthorizationServer::respondToAccessTokenRequest()` directly. Follow this pattern for any new grant-based flow.
- New authenticated API routes go behind `auth:api` in `routes/api.php`; admin-only routes additionally use the `is_admin` middleware alias (`EnsureUserIsAdmin`).

### Trading engine

Core pipeline, wired together per run (manual `/api/trading/run`, `paper-trades`/`positions` reads, or the scheduled `trader:auto`):

- `SignalScanner` / `SignalEngine` — rank tradable setups using `MarketDataProvider` (bound to `YahooFinanceProvider`) + `IndicatorCalculator`.
- `RiskManager` — position/day-level guardrails (`evaluateHalt()`: daily loss cap, duplicate-signal check, and `daily_target_reached`, which blocks new entries but lets open positions exit normally).
- `PositionSizer` — converts a signal into an order size; `position.max_pct_per_stock` / `max_exposure_pct` are **percentages** (e.g. `20`, `70`), not fractions — divide by 100, don't re-introduce the old fraction bug.
- `ExecutionEngine` — places orders through a `BrokerAdapter` and updates daily PnL.
- `PortfolioManager` — open positions, unrealized PnL rebuild, summaries.
- `BacktestEngine` — historical simulation (`Backtest` model); `win_rate`/`profit_factor`/`avg_holding_days`/`buyhold_cagr` are `double` columns (MySQL strict mode rejects `float`), and `profit_factor` is normalized to `null` instead of `INF`.
- `PaperTradingService::runForAccount()` — the ranked-scan → size → execute path, shared by manual paper runs and `AutoTradingService`.
- `AutoTradingService` — loops accounts where automation is enabled (see below), skips live accounts with no connected broker, logs a `SystemEvent`, and applies per-account config overrides for the duration of the run.

### Brokers

`app/Contracts/Brokers/BrokerAdapter` is the interface every broker implements (`placeOrder`, `cancelOrder`, `getPositions`, `getBalances`, `getOrderStatus`, `isApiAvailable`); `BrokerManager` resolves a slug (`paper`, `upstox`, `zerodha`, `angel`, `kotak`) to its adapter, so sizing/execution/reconciliation call sites never change when a broker is added. `UpstoxBroker` is the working live implementation (REST + V3 WebSocket feed via `WebsocketApi`); `PaperBroker` is the simulator; others are scaffolds. Per-user OAuth tokens are stored encrypted in `trading_accounts.credentials` (`longText`, not `json` — it holds `encrypted:json` ciphertext which MySQL's `json` type rejects) via `BrokerOAuthService`; app-level broker credentials live in `config/brokers.php`. Angel uses a publisher-login redirect (token comes back in the query string, no code exchange); Kotak authenticates server-side (TOTP + MPIN).

### Per-account automation config

`trading_accounts.settings` (json) holds per-account overrides. `TradingAccount::isAutomationEnabled()` requires both a master switch and a strategy switch; `configOverrides()` exposes `risk.capital`, `risk.daily_target`/`daily_loss_cap` (percentages), and passthrough `position.*`/`entry.*`/`exit.*` keys. `TradingConfigService` is bound `scoped()` (one instance per request/command run) and resolves config as **overrides → snapshot → DB**; `useOverrides()` lets `AutoTradingService` apply one account's overrides so every downstream service (scanner, sizer, risk, execution) sees them without leaking across accounts or requests.

### News & sentiment

`NewsProvider` (bound to `FreeNewsApiProvider`, `config/news.php`) feeds headlines into `SentimentAnalyzer` (bound to `KeywordSentimentAnalyzer` by default, selectable via `NEWS_SENTIMENT_DRIVER`) — sentiment score feeds into the signal score. Swapping in an LLM-based analyzer means implementing `SentimentAnalyzer` and rebinding it in `AppServiceProvider`, not touching engine code.

### Scheduler

Defined in `bootstrap/app.php` (`->withSchedule()`), not `app/Console/Kernel.php`: `trader:auto` every 15 min on weekdays 09:15–15:25 IST, `news:fetch` hourly in the same window, both `withoutOverlapping()`. The host still needs `* * * * * php artisan schedule:run` in cron — the schedule definition alone doesn't run anything.

## Coding conventions

1. **Think before coding** — state ambiguity, present options, ask don't guess.
2. **Simplicity first** — build only what was asked, no speculative abstractions.
3. **Surgical changes** — every line traces to the request; don't "improve" adjacent code.
4. **Goal-driven execution** — define success criteria, loop until met.

- Controllers stay thin: FormRequest validation → one service call → response via `ResponseStructure`. No direct queries or business logic in controllers.
- Authorization goes through Policies (`$this->authorize(...)`), not ad-hoc checks in controllers.
- New pluggable integrations (broker, market data source, news source, sentiment) get a `Contracts/` interface + binding in `AppServiceProvider`, mirroring the existing ones — don't hardcode a new external API call directly into a service.
- Run `pint`, `phpstan`, and the relevant `php artisan test` filter before considering a backend change done; run `npm run check` / `types:check` for frontend changes.

## Deploy

`git push` → on server: `git pull` → `php artisan migrate --force` → `php artisan optimize`. No build step on the server — Node is never required there; frontend assets are built locally/CI and `public/build/` is committed on purpose (SSR is disabled in `config/inertia.php`).

## Related

- Decision log: `DECISIONS.md` (read the last few entries before starting work — it has context not in this file, e.g. in-progress/pending items).
- Mobile client (separate repo): `banker-trader-mobile`.
