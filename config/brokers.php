<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Broker Configuration
    |--------------------------------------------------------------------------
    |
    | App-level credentials for each supported broker. User-specific tokens
    | (access_token, refresh_token) are stored encrypted in the
    | trading_accounts.credentials JSON column — never in config.
    |
    */

    // Deep link the Flutter app registers for OAuth callback redirects.
    'redirect_success_base' => env('BROKER_REDIRECT_SUCCESS_BASE', 'bankertrader://broker/connected'),

    'upstox' => [
        'app_id' => env('UPSTOX_APP_ID'),
        'app_secret' => env('UPSTOX_APP_SECRET'),
        'redirect_url' => env('UPSTOX_REDIRECT_URL', 'http://localhost:8000/api/broker/upstox/callback'),
        'sandbox' => env('UPSTOX_SANDBOX', false),
        'api_base' => env('UPSTOX_API_BASE', 'https://api.upstox.com/v2'),
        'login_url' => env('UPSTOX_LOGIN_URL', 'https://api.upstox.com/v2/login/authorization/dialog'),
        'token_url' => env('UPSTOX_TOKEN_URL', 'https://api.upstox.com/v2/login/authorization/token'),
    ],

    // Future brokers:
    // 'zerodha' => [...],
    // 'angel'   => [...],
    // 'kotak'   => [...],

];
