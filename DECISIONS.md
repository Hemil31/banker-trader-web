# BankerTrader — Decisions Log

## 2026-09-09 — Upstox live broker integration (backend + Flutter)
**Status:** completed

**Changes:**
- Backend (Phase 1, already done): brokers table seeded (`upstox` active, `zerodha` inactive, `paper`), per-user OAuth flow (`BrokerOAuthService`) storing tokens encrypted in `trading_accounts.credentials`, `BrokerManager` slug→adapter registry, `UpstoxBroker` live adapter (place/cancel orders via V3, positions, balances, status polling), `PaperBroker`/`ZerodhaBroker` scaffold. 69 tests green, PHPStan + Pint pass.
- Backend (Phase 2 — this session):
  - `UpstoxBroker::marketDataFeedUri()` / `portfolioFeedUri()`: authorize the live V3 WebSocket feed per user token via `WebsocketApi`.
  - `GET /api/broker/accounts`: user trading accounts + connection state (id, name, mode, connected, broker, connected_at). Needed by mobile since the app previously had no trading-account id.
  - `GET /api/broker/feed/{tradingAccount}/{type}` (type=market|portfolio): returns the authorized WebSocket URI for an account (422 unless a connected Upstox account).
  - 4 new feature tests → 69 total.
- Flutter (Phase 2 — this session):
  - New `lib/features/broker/` feature (entity/Api/repository/usecases/cubit + pages), following the existing auth/trading clean-architecture pattern.
  - `BrokerConnectPage` (trading-home AppBar icon) lists accounts + live brokers with connect/disconnect.
  - `BrokerOAuthPage`: in-app `webview_flutter` that intercepts the `bankertrader://` deep-link callback and pops with success/failure.
  - `MarketDataStreamer` + `PortfolioStreamer` (`web_socket_channel`) mirroring the SDK's `MarketDataFeederV3`/`PortfolioDataFeeder`; V3 feed is JSON-based (`{guid, method, data}`, sub/change_mode/unsub), acks the server `100` heartbeat.
  - DI wired in `core/di/injection.dart`; `BlocProvider<BrokerCubit>` added in `app.dart`.
  - deps: `webview_flutter`, `web_socket_channel`, `uuid`.

**Pending:**
- Live end-to-end validation against real Upstox credentials (connect → authorize → TCP WebSocket).
- Wire market/portfolio WebSockets into a live-trading screen (streamers exist, not yet consumed by UI).
- `webview_flutter` requires Android minSdk 21 (already) and iOS platform setup if used on iOS.

**Notes:**
- Upstox V3 feed is JSON over binary — much simpler than v1/v2. Server heartbeat is `100`, client must reply `100` to stay alive.
- Dart 3.12 removed `Stream.whereType` (only `Iterable.whereType` exists) — use `.where(...).cast<T>()`.
- `flutter analyze` clean except pre-existing `prefer_initializing_formals` infos (codebase-wide style).
- Backend default is live (`UPSTOX_SANDBOX=false`) because Upstox sandbox middleware blocks feed/portfolio/status endpoints.

## 2026-09-10 — MySQL + UUID primary keys + company admin (backend)
**Status:** completed

**Changes:**
- **MySQL:** DB switched from sqlite to MySQL 8 (`bankertrader` dev, `bankertrader_test` for phpunit). `.env`, `.env.example`, `phpunit.xml` updated. Local MySQL runs on `127.0.0.1:3306` (root / `aveo@@123`) until host creds are swapped in.
- **UUID for all app tables:** every domain migration now uses `$table->uuid('id')->primary()` (users, passkeys, trading_accounts, brokers, stocks, trading_configs, trading_signals, orders, order_executions, positions, trading_pnl_daily, trading_pnl_ledger, backtests, paper_trades, error_logs, system_events, market_data, market_indicators). All FKs → `foreignUuid` (user_id, broker_id, stock_id, trading_account_id, trading_signal_id, order_id, position_id). `system_events.subject` → `nullableUuidMorphs`. Passport oauth tables: `user_id`/`owner` → uuid, `oauth_access_tokens.id` + `oauth_refresh_tokens.access_token_id` stay char(80). `trading_accounts.credentials` `json` → `longText` (it stores `encrypted:json` ciphertext, which MySQL's json type rejects). Backtest `win_rate/profit_factor/avg_holding_days/buyhold_cagr` `float` → `double` (MySQL strict mode rejects single-precision round-off; `profit_factor` also normalized to `null` instead of `INF`).
- All models: `use HasUuids` + `@property string $id` docblocks.
- id type-hints moved `int` → `string`: `BrokerController::status`, `BrokerConnectionController` (connect/disconnect/feed/callbackTargetUrl), `RiskManager` (metrics/isDuplicateSignal/usedCapital/evaluateHalt), `PortfolioManager` (openPositions/rebuildUnrealized/summary), `ExecutionEngine::updateDailyPnl`, `BacktestEngine` internal stock-id maps, `AuthRepository`/`AuthRepositoryInterface` (updateLastLogin/updateLoginStatus/updatePassword), `ProfileValidationRules::profileRules/emailRules`. Removed the `(int)` casts that silently zeroed UUIDs: `BrokerOAuthService::verifyState`, `PaperTradingService` (priceMap keys + bounceIds).
- **Company admin:** `users.is_admin` boolean; `EnsureUserIsAdmin` middleware (`is_admin` alias); `GET /api/admin/users` (master table: every user + per-account realized/unrealized PnL, broker connection, counts of orders/signals/open positions, plus platform summary) via `AdminDashboardService`; `GET /api/admin/users/{user}` (account breakdown + 25 recent paper trades). Both behind `auth:api` + `is_admin`.
- Seeder: `admin@bankertrader.local` (is_admin=true, password `adminpassword`).

**Verification:** 73 backend tests pass (MySQL), PHPStan level 7 clean, Pint clean. Flutter: `BrokerAccount.id` now String (uuid) end-to-end incl. `BrokerOAuthPage` outcome; `flutter analyze` 0 errors/warnings, all 7 tests pass.

**Pending:**
- Host MySQL creds + SSL still to swap into `.env` at deploy.
- Inertia web admin UI (the API + master table is ready; the web dashboard redesign is a follow-up).
- Swagger/API docs for `/api/admin/*`.

**Notes:**
- PHPStan needs `--memory-limit=1G` on this box (128M default crashes the parallel worker).
- Laravel `nullableUuidMorphs()` already creates the composite index itself — do not add a manual `index(['subject_type','subject_id'])` on top (MySQL "Duplicate key name").
## 2026-09-12 — Per-account automation engine (news → AI-ready sentiment → auto trade)
**Status:** completed

**Changes:**
- `trading_accounts.settings` json column (migration `2026_09_12_100001_...`). `TradingAccount` gains `isAutomationEnabled()` (master+strategy must both be true), `effectiveCapital()`, `configOverrides()` (keys: `risk.capital`, `risk.daily_target` (pct), `risk.daily_loss_cap` (pct), plus passthrough of `position.*`, `risk.max_trades_per_day`, `entry.*`, `exit.*`).
- `TradingConfigService`: new `useOverrides()`/`clearOverrides()` add an override layer; resolution order = overrides → snapshot → DB. Registered `scoped()` in AppServiceProvider so one account's overrides reach every trading service in a request cycle.
- Sentiment is now pluggable: `app/Contracts/Analysis/SentimentAnalyzer.php` + `KeywordSentimentAnalyzer` (moved wordlists from NewsService). `config/news.php` `news.sentiment_driver` (env `NEWS_SENTIMENT_DRIVER`). Swap an LLM later by binding a new implementation.
- `RiskManager::evaluateHalt()` adds `daily_target_reached` (blocks NEW entries once the day's realized profit ≥ daily target; open positions still exit normally).
- `PaperTradingService::runForAccount()` extracted so automation reuses the ranked-scan + sizing + execution path.
- `AutoTradingService`: loops enabled accounts; live accounts with no connected broker are skipped; per-account overrides applied; SystemEvent log + per-account result array.
- `trader:auto` command (`--account=`, `--symbol=`). Scheduler in `bootstrap/app.php`: `trader:auto` every 15 min, weekdays 09:15–15:25 IST; `news:fetch` hourly; both `withoutOverlapping()`.
- **Bug fix:** `PositionSizer` treated `position.max_pct_per_stock` / `max_exposure_pct` as fractions though they are stored/labeled as percentages (20/70 in DB). Now divided by 100. Was latent because riskQty capped below priceQty at default capital.
- `TradingAccountFactory`: `automated()` + `withSettings()` states. 6 new feature tests. Full suite 127/127 green; PHPStan clean; affected files Pint-clean.

**Verification:** `vendor/bin/phpunit` → 127 passed/502 assertions. `phpstan analyse <touched files> --memory-limit=1G` → 0 errors. `php artisan schedule:list` shows trader:auto/news:fetch windows. `php artisan trader:auto` smoke test returns the seeded paper account with 0 signals (expected — no tradable setup today).

**Pending:**
- Deploy host cron: `* * * * * php artisan schedule:run` (scheduler itself is wired in-app).
- Bag of words is English/equity NSE; review NIFTY/technical-only headlines (no keyword override data yet).
- Per-user UI to toggle automation + set capital/target (API + settings column exist; admin/web screen is follow-up).
- Consider a dedicated queue (horizon) once volume grows; 15-min news chunks also count vs FreeNewsApi daily quota.

**Notes:**
- `brokers.credentials` json-column fallback in `BrokerOAuthService` is an unrelated pre-existing uncommitted change (present before this session) — do not revert; commit separately.
- Repo-wide Pint/PHPStan already drift on OLD files (`NewsController`, `FreeNewsApiProvider`, migrations, `NewsSentimentTest`, `recentNews()` generics fixed) — pre-existing; not caused by this session.
- PHPStan needs `--memory-limit=1G` on this box.

## 2026-09-12 — Frontend build fix: finish admin-only web cleanup (dead route/settings imports)
**Status:** completed

**Changes:**
- A prior session made the web admin-only (login → `/admin` only) and deleted all web settings/profile/2FA/passkey pages + `routes/settings.php`, but left several live files importing now-nonexistent `@/routes/*` modules, breaking `npm run build`. This session finished that cleanup, frontend-only.
- Rewired `resources/js/components/app-sidebar.tsx`: `import { dashboard } from '@/routes'` → `@/routes/admin`; sidebar nav reduced to a single `NavMain` item (`Admin console` → `dashboard()`, `LayoutGrid` icon); logo `Link` now points at `dashboard()`; removed the Laravel starter-kit `NavFooter`/footer nav items (Repository/Documentation links) — no longer relevant to a single-page admin console. `nav-footer.tsx` itself left in place (unused but out of scope).
- Rewired `resources/js/components/user-menu-content.tsx`: removed the dead `import { edit } from '@/routes/profile'` and the Settings/profile dropdown entry; kept `logout` from `@/routes`. Also dropped the now-unused `DropdownMenuGroup` import left over from removing that entry.
- `resources/js/app.tsx`: removed `import SettingsLayout from '@/layouts/settings/layout'` and the `name.startsWith('settings/')` Inertia layout-resolver case (kept `welcome`/`auth/` cases as-is, minimal diff).
- **Deleted dead files** (verified via `grep -r` that nothing outside their own cluster imports them):
  - `resources/js/layouts/settings/layout.tsx` (imported `@/routes/{appearance,profile,security}`)
  - `resources/js/layouts/app/app-header-layout.tsx` + `resources/js/components/app-header.tsx` (imported dead `@/routes` `dashboard`; confirmed unused elsewhere — `app-layout.tsx` renders `app-sidebar-layout`, not `app-header-layout`)
  - `resources/js/components/manage-two-factor.tsx`, `two-factor-recovery-codes.tsx`, `two-factor-setup-modal.tsx`, `resources/js/hooks/use-two-factor-auth.ts` (all imported `@/routes/two-factor`)
  - `resources/js/components/manage-passkeys.tsx`, `passkey-item.tsx`, `passkey-register.tsx`, `passkey-verify.tsx` (passkey family, only referenced each other)
  - `resources/js/components/delete-user.tsx` — **not in the original plan**, found during `tsc --noEmit` verification: imported `@/actions/App/Http/Controllers/Settings/ProfileController`, which no longer exists (backend action deleted in the prior session along with the profile settings page). Confirmed zero importers anywhere in `resources/js/`. Deleted as the same class of orphaned leftover as the files above.
- `layouts/settings/` directory removed (now empty).

**Verification:** `npm run build` succeeds (manifest regenerated). `npm run types:check` (`tsc --noEmit`) clean, zero errors. `npm run check` (eslint/prettier) still reports its pre-existing 14-file formatting drift (`DECISIONS.md`, `README.md`, built `public/build/**` assets, `resources/js/lib/format.ts`, `resources/js/pages/admin/index.tsx`, `resources/js/types/trading.ts`) — none of these are files touched this session; no new failures introduced.

**Pending:**
- None for this cleanup. The pre-existing `npm run check` formatting drift (14 files, unrelated to this session) is still there — not fixed, per instructions.

**Notes:**
- `resources/js/routes/` now only contains: `index`, `admin`, `api`, `login`, `password`, `passport`, `storage` — every `@/routes/...` import in the tree resolves to one of these.
- Did not touch backend (PHP), `routes/`, or `resources/js/types/` (not needed — `tsc --noEmit` had nothing to complain about there once `delete-user.tsx` was gone).

## 2026-09-12 — MegaBull paper-broker integration (backend + Flutter) + DB-backed news API key
**Status:** completed

**Changes:**
- **New broker: MegaBull** (`app/Contracts/Brokers/MegaBullBroker.php`) — a free India paper-trading API (`api.megabull.in`), each user supplies their own personal `api-key` (no app-level secret). Registered `paper => true` (virtual money) but is a genuinely *connectable* broker, unlike the built-in zero-config simulator (slug `paper`).
  - `placeOrder`/`cancelOrder`/`getPositions`/`getBalances`/`getOrderStatus` all verified **live** against a real account (not just `Http::fake`): confirmed exact `OrderRequest` schema from the public spec at `https://megabull.in/api/megabull-openapi.json` (the `api.megabull.in` copy requires a browser session, not the api-key, to fetch) — `type` (BUY/SELL), `orderType` (LIMIT/MKT/SL — not "MARKET"), `duration` (MIS/CNC — actually the product type despite the name; we always send CNC since our strategy holds positions across sessions), and confirmed live that `price` is required on every order (not just LIMIT, despite the docs). Instrument tokens resolved from MegaBull's CSV instrument master (Zerodha-numbered), cached 12h per account.
  - Confirmed live that a filled CNC order settles into `/api/holding/my`, not `/api/position/my` — `getPositions()` checks both.
- **Broker-framework fixes needed for any non-real-money-but-real-credentials broker** (not MegaBull-specific, but MegaBull is the first to hit this path):
  - `BrokerOAuthService::persistConnection()`: mode is now `$broker->paper ? 'paper' : 'live'` (was hardcoded `'live'`) — a paper-flagged broker must not flip the account into live mode.
  - `TradingAccount::isLiveBrokerConnected()` / `BrokerOAuthService::status()`: switched the "is anything connected" check from `!$broker->paper` to `$broker->slug !== 'paper'`, so an externally-connected paper broker shows as connected (only the built-in simulator, which has nothing to connect, reports false) — also now checks for `credentials.api_key` in addition to `access_token`.
  - `connectMegaBull()` added to `BrokerOAuthService` (verifies the key via `GET /api/user/my`, no OAuth/token exchange) + `megabullConnect` on `BrokerConnectionController` (`POST /api/broker/connect/{account}/megabull`, body `{api_key}}`) — same shape as the existing Kotak credential-form flow.
  - **Real balance sync**: `getBalances()` existed on every broker adapter but was never called anywhere. `PaperTradingService::reconcile()` now pulls the connected broker's real balance for any externally-connected account (falls back to the old local-ledger math for the simulator or on broker error); `BrokerOAuthService::persistConnection()` also syncs it once immediately at connect-time so the UI is correct before the next automation run. Verified live: app-side `available_cash`/`invested_amount` matched MegaBull's real `virtualMoneyLeft`/`virtualMoneyBlocked` exactly after a real order fill.
- **Per-user dashboard fix**: `PaperTradingService::paperAccount()` ignored the `$user` argument and always returned the first paper-mode account platform-wide — every user's `/api/portfolio`/`/api/positions`/`/api/paper-trades` showed the same shared account. Now scoped by `user_id`. (Note: the automation *loop* was already correctly per-account via `AutoTradingService`/`trader:auto` from a parallel session — this fix was only needed for the interactive dashboard/API read-side.) `TradingDashboardService::positions()`/`paperTrades()` now thread the user through; `PaperTrade` rows are now actually filtered by `trading_account_id` (previously unfiltered — returned every account's rows).
- **Flutter**: `BrokerConnectPage` dropdown gained a flat "MegaBull" entry (grouped with paper-type brokers, before live brokers) with a one-field API-key form (`_MegaBullConnectForm`), mirroring the existing Kotak credential-form pattern exactly — `ConnectMegaBullUseCase` → `BrokerCubit.connectMegaBull` → `BrokerRepository`/`BrokerApi`, wired in `injection.dart`.
- **News API key moved to the DB** (`trading_configs.news.api_key`, `is_editable = false`) with an env fallback (`NEWS_API_KEY`) — same DB-first/env-fallback shape as broker app-level credentials. Deliberately kept `is_editable = false` (unlike the other `news.*` tuning knobs) so it never appears on `GET /api/trading/config` or accepts a `PATCH` from the per-user mobile Config tab — it's a shared secret, not a per-account setting. `FreeNewsApiProvider` now takes `TradingConfigService` in its constructor.
- Environment fixes made along the way (this dev box, not the app): WAMP's PHP 8.4.0 had no CA bundle (`curl.cainfo`/`openssl.cafile`) — broke outbound HTTPS for every broker, not just MegaBull; WAMP's MySQL instance defaulted new tables to MyISAM (breaks UUID/unique-key migrations) — switched to InnoDB; Android SDK Platform 37 installed under a `37.0`-suffixed folder Gradle couldn't find (junction fix) plus `kotlin.incremental=false` for a Windows cross-drive (project on `D:`, Pub cache on `C:`) Kotlin compiler bug.

**Verification:** 141/141 backend tests green (113 after the parallel admin-only-web session removed ~28 web-facing tests unrelated to this work), PHPStan clean (pre-existing 3 `FreeNewsApiProvider` errors untouched), Pint clean. `flutter analyze` 0 errors (mobile). Live end-to-end: connected the real MegaBull account through the running server (not mocks), placed and cancelled a real order, ran `trader:auto` and confirmed it resolved the `megabull` adapter and correctly skipped (0 qualifying signals at the time, not an error).

**Pending:**
- `news.api_key` DB row exists but is empty — needs a real `freenewsapi.io` key set (`TradingConfigService::set('news.api_key', '<key>')` or a direct DB update; user will set it themselves).
- No explicit "update key" UI affordance once MegaBull is already connected — re-submitting the same connect form on mobile does update it, but isn't labeled for that use case.
- MegaBull keys expire after 1 month per their docs; no expiry tracking/warning yet.

**Notes:**
- MegaBull's `/api/order/bulk/cancel` takes a plain JSON array of **integer** order ids (not strings) — confirmed live.
- `AutoTradingService::run()`'s live-broker-connected guard only applies to `mode === 'live'` accounts, so it never affects MegaBull (always `mode === 'paper'`) — no change needed there.
