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

    // Angel One uses a publisher-login redirect (not OAuth code exchange):
    // the browser redirects back with `auth_token`, `feed_token` and the client
    // id in the query string, which are stored directly as the account token.
    'angel' => [
        'api_key' => env('ANGEL_API_KEY'),
        'api_base' => env('ANGEL_API_BASE', 'https://apiconnect.angelone.in'),
        'login_url' => env('ANGEL_LOGIN_URL', 'https://smartapi.angelone.in/publisher-login'),
        'redirect_url' => env('ANGEL_REDIRECT_URL', 'http://localhost:8000/api/broker/angel/callback'),
    ],

    // Kotak Neo authenticates server-side (TOTP + MPIN); the account session
    // (token + sid) is stored per user, the consumer key is app-level.
    'kotak' => [
        'consumer_key' => env('KOTAK_CONSUMER_KEY'),
        'api_base' => env('KOTAK_API_BASE', 'https://mis.kotaksecurities.com'),
    ],

    // MegaBull is a free India paper-trading API — each user generates and
    // supplies their own personal api-key (no app-level secret required).
    'megabull' => [
        'api_base' => env('MEGABULL_API_BASE', 'https://api.megabull.in'),
    ],

    // Future brokers:
    // 'zerodha' => [...],
    // 'paytm'   => [...],

];
