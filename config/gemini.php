<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Gemini API configuration
    |--------------------------------------------------------------------------
    |
    | App-level credentials for the Google Gemini API (used to auto-generate
    | Zernio social post content). The API key stays in .env (GEMINI_API_KEY)
    | and also in the trading_configs table as a non-editable row — the
    | client prefers the DB value so a key rotated from the admin console
    | applies without a deploy. Mirrors config/zernio.php / config/news.php.
    |
    */

    'api_base' => env('GEMINI_API_BASE_URL', 'https://generativelanguage.googleapis.com/v1beta'),

    'api_key' => env('GEMINI_API_KEY'),

    'timeout' => env('GEMINI_TIMEOUT', 30),

    'connect_timeout' => env('GEMINI_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Model + rate limits
    |--------------------------------------------------------------------------
    |
    | Set these to match whatever model is actually selected in Google AI
    | Studio / the Gemini API console — model availability and its RPM/RPD
    | quota change over time, so they are not hardcoded here. All three are
    | also DB-backed (trading_configs: gemini.model / gemini.rpm / gemini.rpd)
    | and editable from the admin settings page without a deploy.
    |
    | rpm feeds the `gemini-generation` rate limiter (App\Providers\
    | AppServiceProvider) that GenerateAiPostJob's queue middleware enforces;
    | rpd is a second, per-day limiter on the same job so a burst of queued
    | requests can never exceed the model's daily quota either.
    |
    */

    'model' => env('GEMINI_MODEL', 'gemini-flash-lite-latest'),

    // Conservative defaults (10 RPM / 20 RPD) — safe even for the
    // lowest-quota model in the current lineup; raise via trading_configs
    // once the actual configured model's limits are confirmed.
    'rpm' => env('GEMINI_RPM', 10),

    'rpd' => env('GEMINI_RPD', 20),

];
