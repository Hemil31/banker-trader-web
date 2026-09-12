<?php

namespace App\Services;

use App\Models\Broker;
use App\Models\TradingAccount;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Http;
use RuntimeException;

/**
 * Handles the broker OAuth connection flow per user account.
 *
 * The authorization URL is generated with a signed `state` payload so the
 * callback (a browser redirect unauthenticated by Passport) can be matched back
 * to the correct trading account. The exchanged access token is stored
 * encrypted in trading_accounts.credentials.
 *
 * Works for standard OAuth authorization_code brokers (Upstox today) and for
 * Angel's publisher-login redirect, which returns the session token directly.
 */
class BrokerOAuthService
{
    public function __construct(protected BrokerManager $brokers) {}

    /**
     * Build the authorization URL for connecting a broker to an account.
     */
    public function authorizationUrl(TradingAccount $account, Broker $broker): string
    {
        $config = $this->brokerConfig($broker);

        $state = $this->buildState($account);

        if ($broker->slug === 'angel') {
            // Angel uses a publisher-login redirect: the browser returns
            // auth_token + feed_token directly in the callback query string.
            $query = http_build_query([
                'api_key' => $config['api_key'],
                'redirect_url' => $config['redirect_url'],
                'state' => $state,
            ]);

            return $config['login_url'].'?'.$query;
        }

        $query = http_build_query([
            'response_type' => 'code',
            'client_id' => $config['app_id'],
            'redirect_uri' => $config['redirect_url'],
            'state' => $state,
        ]);

        return $config['login_url'].'?'.$query;
    }

    /**
     * Exchange the authorization code and persist the user token.
     */
    public function handleCallback(Broker $broker, string $code, string $state): TradingAccount
    {
        $account = $this->verifyState($state);

        $config = $this->brokerConfig($broker);

        $response = Http::asForm()->post($config['token_url'], [
            'code' => $code,
            'client_id' => $config['app_id'],
            'client_secret' => $config['app_secret'],
            'redirect_uri' => $config['redirect_url'],
            'grant_type' => 'authorization_code',
        ]);

        if ($response->failed()) {
            throw new RuntimeException('Upstox token exchange failed: '.$response->body());
        }

        $tokens = $response->json() ?? [];

        $credentials = array_merge((array) ($account->credentials ?? []), [
            'access_token' => $tokens['access_token'] ?? null,
            'refresh_token' => $tokens['refresh_token'] ?? null,
            'expires_in' => $tokens['expires_in'] ?? null,
            'token_type' => $tokens['token_type'] ?? null,
            'connected_at' => now()->toISOString(),
        ]);

        return $this->persistConnection($account, $broker, $credentials);
    }

    /**
     * Persist tokens returned by Angel's publisher-login redirect. Unlike the
     * OAuth code flow there is no token exchange: `auth_token` is the Bearer
     * session (valid until midnight) and `feed_token` powers the live market
     * feed.
     */
    public function handleAngelCallback(Broker $broker, string $state, string $authToken, ?string $feedToken = null, ?string $clientId = null): TradingAccount
    {
        $account = $this->verifyState($state);

        $credentials = array_merge((array) ($account->credentials ?? []), [
            'access_token' => $authToken,
            'feed_token' => $feedToken,
            'client_id' => $clientId,
            'connected_at' => now()->toISOString(),
        ]);

        return $this->persistConnection($account, $broker, $credentials);
    }

    /**
     * Connect a Kotak Neo account via server-side TOTP login (no redirect).
     *
     * The flow mirrors the Kotak Neo SDK: totp_login returns a short-lived
     * view token + sid, then totp_validate swaps those for the edit token +
     * sid (plus the account's data-center base URL) used by the trade APIs.
     */
    public function connectKotak(TradingAccount $account, Broker $broker, string $mobileNumber, string $ucc, string $totp, string $mpin): TradingAccount
    {
        $config = $this->brokerConfig($broker);

        $loginHeaders = [
            'Authorization' => $config['consumer_key'],
            'neo-fin-key' => 'neotradeapi',
        ];

        $login = Http::baseUrl($config['api_base'])
            ->withHeaders($loginHeaders)
            ->asJson()
            ->acceptJson()
            ->timeout(15)
            ->post('/login/1.0/tradeApiLogin', [
                'mobileNumber' => $mobileNumber,
                'ucc' => $ucc,
                'totp' => $totp,
            ]);

        $loginData = $this->kotakDataOrFail($login, 'totp_login');

        $viewToken = (string) ($loginData['token'] ?? '');
        $sid = (string) ($loginData['sid'] ?? '');

        if ($viewToken === '' || $sid === '') {
            throw new RuntimeException('Kotak login did not return a session.');
        }

        $validate = Http::baseUrl($config['api_base'])
            ->withHeaders($loginHeaders + [
                'Sid' => $sid,
                'Auth' => $viewToken,
            ])
            ->asJson()
            ->acceptJson()
            ->timeout(15)
            ->post('/login/1.0/tradeApiValidate', ['mpin' => $mpin]);

        $validateData = $this->kotakDataOrFail($validate, 'totp_validate');

        $editToken = (string) ($validateData['token'] ?? '');
        $editSid = (string) ($validateData['sid'] ?? '');

        if ($editToken === '' || $editSid === '') {
            throw new RuntimeException('Kotak MPIN validation did not return a session.');
        }

        $credentials = array_merge((array) ($account->credentials ?? []), [
            'access_token' => $editToken,
            'sid' => $editSid,
            'rid' => $validateData['rid'] ?? null,
            'mobile_number' => $mobileNumber,
            'ucc' => $ucc,
            'base_url' => $validateData['baseUrl'] ?? null,
            'data_center' => $validateData['dataCenter'] ?? null,
            'connected_at' => now()->toISOString(),
        ]);

        return $this->persistConnection($account, $broker, $credentials);
    }

    /**
     * Decode the Kotak login/validate envelope (2xx with a `data` payload).
     *
     * @return array<string, mixed>
     */
    protected function kotakDataOrFail(ClientResponse $response, string $operation): array
    {
        $body = $response->json();

        if ($response->failed() || ! is_array($body)) {
            $message = is_array($body) ? (string) ($body['errMsg'] ?? $body['stat'] ?? 'unknown error') : $response->body();

            throw new RuntimeException("Kotak {$operation} failed: {$message}");
        }

        $data = $body['data'] ?? null;

        if (is_array($data)) {
            return $data;
        }

        $message = (string) ($body['errMsg'] ?? $body['stat'] ?? 'unexpected response');

        throw new RuntimeException("Kotak {$operation} failed: {$message}");
    }

    /**
     * Remove the broker connection from an account.
     */
    public function disconnect(TradingAccount $account): void
    {
        $account->update([
            'broker_id' => null,
            'credentials' => null,
            'mode' => 'paper',
        ]);
    }

    /**
     * Bind the broker and persist the user's tokens.
     *
     * @param  array<string, mixed>  $credentials
     */
    protected function persistConnection(TradingAccount $account, Broker $broker, array $credentials): TradingAccount
    {
        $account->update([
            'broker_id' => $broker->id,
            'credentials' => $credentials,
            'mode' => 'live',
        ]);

        return $account->loadMissing('broker');
    }

    /**
     * Current connection status for an account.
     *
     * @return array<string, mixed>
     */
    public function status(TradingAccount $account): array
    {
        $account->loadMissing('broker');

        $broker = $account->broker;

        if (! $broker || $broker->paper) {
            return ['connected' => false, 'broker' => null, 'mode' => $account->mode];
        }

        return [
            'connected' => $account->isLiveBrokerConnected(),
            'broker' => [
                'slug' => $broker->slug,
                'name' => $broker->name,
            ],
            'connected_at' => $account->getCredential('connected_at'),
            'mode' => $account->mode,
        ];
    }

    /**
     * Build a signed, account-scoped OAuth state string.
     */
    protected function buildState(TradingAccount $account): string
    {
        $json = json_encode([
            'account_id' => $account->id,
            'exp' => now()->addMinutes(30)->timestamp,
        ], JSON_THROW_ON_ERROR);

        $payload = base64_url_encode($json);

        return $payload.'.'.hash_hmac('sha256', $payload, (string) config('app.key'));
    }

    /**
     * Verify the OAuth state and recover the originating account.
     */
    protected function verifyState(string $state): TradingAccount
    {
        $parts = explode('.', $state);

        if (count($parts) !== 2) {
            $this->abortInvalidState();
        }

        [$payload, $signature] = $parts;

        $expected = hash_hmac('sha256', (string) $payload, (string) config('app.key'));

        if (! hash_equals($expected, (string) $signature)) {
            $this->abortInvalidState();
        }

        $data = json_decode((string) base64_url_decode($payload), true);

        if (! is_array($data) || empty($data['account_id']) || (int) $data['exp'] < now()->timestamp) {
            $this->abortInvalidState();
        }

        if (! is_string($data['account_id'])) {
            $this->abortInvalidState();
        }

        $account = TradingAccount::find($data['account_id']);

        if ($account === null) {
            $this->abortInvalidState();
        }

        return $account;
    }

    protected function abortInvalidState(): never
    {
        abort(response()->json([
            'success' => false,
            'message' => 'Invalid or expired OAuth state.',
        ], Response::HTTP_BAD_REQUEST));
    }

    /**
     * Resolve app-level credentials for a broker.
     *
     * Credentials are read from the `brokers.credentials` JSON column first
     * (database-side), falling back to env config (`config/brokers.php`) so
     * existing environments keep working. Every user still connects their own
     * account — this data is app-level, not user-level.
     *
     * @return array<string, mixed>
     */
    protected function brokerConfig(Broker $broker): array
    {
        $db = is_array($broker->credentials) ? $broker->credentials : [];
        $env = is_array(config("brokers.{$broker->slug}")) ? config("brokers.{$broker->slug}") : [];

        $config = match ($broker->slug) {
            'angel' => [
                'api_key' => $db['api_key'] ?? $env['api_key'] ?? null,
                'api_base' => $db['api_base'] ?? $env['api_base'] ?? 'https://apiconnect.angelone.in',
                'login_url' => $db['login_url'] ?? $env['login_url'] ?? 'https://smartapi.angelone.in/publisher-login',
                'redirect_url' => $db['redirect_url'] ?? $env['redirect_url'] ?? $this->callbackUrl($broker),
            ],
            'kotak' => [
                'consumer_key' => $db['consumer_key'] ?? $env['consumer_key'] ?? null,
                'api_base' => $db['api_base'] ?? $env['api_base'] ?? 'https://mis.kotaksecurities.com',
            ],
            default => [
                'app_id' => $db['app_id'] ?? $env['app_id'] ?? null,
                'app_secret' => $db['app_secret'] ?? $env['app_secret'] ?? null,
                'api_base' => $db['api_base'] ?? $env['api_base'] ?? 'https://api.upstox.com/v2',
                'login_url' => $db['login_url'] ?? $env['login_url'] ?? 'https://api.upstox.com/v2/login/authorization/dialog',
                'token_url' => $db['token_url'] ?? $env['token_url'] ?? 'https://api.upstox.com/v2/login/authorization/token',
                'redirect_url' => $db['redirect_url'] ?? $env['redirect_url'] ?? $this->callbackUrl($broker),
                'sandbox' => (bool) ($db['sandbox'] ?? $env['sandbox'] ?? false),
            ],
        };

        $required = match ($broker->slug) {
            'angel' => ['api_key', 'redirect_url', 'login_url'],
            'kotak' => ['consumer_key', 'api_base'],
            default => ['app_id', 'app_secret', 'redirect_url', 'login_url'],
        };

        foreach ($required as $key) {
            if (empty($config[$key])) {
                throw new RuntimeException(
                    "Broker '{$broker->slug}' is not configured. Set its database credentials first."
                );
            }
        }

        return $config;
    }

    /**
     * Derive the OAuth callback URL for a broker from the app URL when it is
     * not explicitly configured, so a locally-hosted backend is reachable from
     * a phone on the same network.
     */
    protected function callbackUrl(Broker $broker): string
    {
        return rtrim((string) config('app.url'), '/')."/api/broker/{$broker->slug}/callback";
    }
}
