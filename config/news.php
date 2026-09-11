<?php

return [

    /*
    |--------------------------------------------------------------------------
    | News API Configuration
    |--------------------------------------------------------------------------
    |
    | App-level credentials for the news provider (FreeNewsApi.io by default).
    | The API key stays in .env (NEWS_API_KEY) and is sent as the x-api-key
    | header. Tuning knobs (lookback, weight, enable flag) live in the
    | trading_configs table so they are editable via the web dashboard.
    |
    */

    'api_base' => env('NEWS_API_BASE_URL', 'https://api.freenewsapi.io/v1'),

    'api_key' => env('NEWS_API_KEY'),

    'enabled' => env('NEWS_ENABLED', true),

    'language' => env('NEWS_LANGUAGE', 'en'),

    'country' => env('NEWS_COUNTRY', 'in'),

    'results' => env('NEWS_RESULTS', 10),

    'timeout' => env('NEWS_TIMEOUT', 15),

];