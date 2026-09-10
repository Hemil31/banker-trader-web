# BankerTrader — Backend & Web

Trading-automation platform backend. Hosts the JSON API consumed by the [BankerTrader Flutter client](https://github.com/Hemil31/banker-trader-mobile) plus a web frontend (Inertia + React) for the company admin dashboard.

**Database:** MySQL only. All application tables use UUID primary keys — sqlite is not supported.

## Stack

- Laravel 12 / PHP 8.3
- MySQL 8 / Redis
- Laravel Passport (OAuth2) for API authentication
- Inertia + React + Vite for the web frontend

> The web frontend is built **on a machine that has Node.js** and the compiled assets in `public/build/` are committed on purpose. The production server has no Node.js — it serves the prebuilt assets, and server-side rendering (SSR) is disabled (`config/inertia.php`).

## Local setup

```bash
composer install
cp .env.example .env
php artisan key:generate
# MySQL: create `bankertrader` (and `bankertrader_test` for tests),
# set DB_* in .env to match. See phpunit.xml for the test DB.
php artisan migrate --seed          # seeds dev users + admin user
php artisan passport:install --force
npm install
npm run build                      # frontend assets (Node needed locally)

# API
php artisan serve

# Web dev server (optional, Node needed)
npm run dev
```

Default seeded login (dev only): `admin@bankertrader.local` / `adminpassword`.

## API

Everything is JSON with the envelope `{ "success": bool, "message": string, "data": ... }`. Public routes use Passport OAuth; mutual (Upstox-live-trading) routes require scopes. See `routes/api.php`.

### Company admin (master table)

| Route | Auth | Description |
|---|---|---|
| `GET /api/admin/users` | `auth:api`, `is_admin` | Every user with per-account PnL (realized + unrealized) and product-usage counts + company summary |
| `GET /api/admin/users/{user}` | `auth:api`, `is_admin` | User profile, account breakdown, 25 most recent paper trades |

Users with `is_admin = true` can access the admin endpoints. The web sign-in is the company admin sign-in.

## Quality gates

```bash
composer test        # PHPUnit (73 tests) — runs on MySQL, see phpunit.xml
./vendor/bin/phpstan analyse --memory-limit=1G
./vendor/bin/pint
npm run check
```

## Deploy

`git push` → on server: `git pull` → `php artisan migrate --force` → `php artisan optimize` (cache config/routes). No build step on the server — Node is never required.

## Related

- Mobile trading client: https://github.com/Hemil31/banker-trader-mobile
- Decision log: `DECISIONS.md`