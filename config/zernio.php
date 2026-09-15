<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Zernio API Configuration
    |--------------------------------------------------------------------------
    |
    | App-level credentials for the Zernio social media publishing API
    | (zernio.com). The API key stays in .env (ZERNIO_API_KEY) and also in the
    | trading_configs table as a non-editable row — the client prefers the DB
    | value so a key rotated from the admin console applies without a deploy.
    |
    */

    'api_base' => env('ZERNIO_API_BASE_URL', 'https://zernio.com/api'),

    'api_key' => env('ZERNIO_API_KEY'),

    'timeout' => env('ZERNIO_TIMEOUT', 15),

    'connect_timeout' => env('ZERNIO_CONNECT_TIMEOUT', 5),

    /*
    |--------------------------------------------------------------------------
    | Publishing defaults
    |--------------------------------------------------------------------------
    |
    | timezone is the IANA zone used when interpreting the admin-chosen
    | "scheduled at" datetime for posts. Overridable per row via the web
    | dashboard (zernio.timezone trading_config row).
    |
    */

    'timezone' => env('ZERNIO_TIMEZONE', 'Asia/Kolkata'),

];
