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