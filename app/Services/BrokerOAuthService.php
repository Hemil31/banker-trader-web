<?php

namespace App\Services;

use App\Models\Broker;
use App\Models\TradingAccount;
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
 * Works for any broker that implements a standard OAuth authorization_code
 * flow (Upstox today; Zerodha, Angel, etc. plug in with their own URLs).
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

        $account->update([
            'broker_id' => $broker->id,
            'credentials' => $credentials,
            'mode' => 'live',
        ]);

        return $account->loadMissing('broker');
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
     * @return array<string, string>
     */
    protected function brokerConfig(Broker $broker): array
    {
        $config = config("brokers.{$broker->slug}");

        if (! is_array($config) || empty($config['app_id']) || empty($config['app_secret'])) {
            throw new RuntimeException(
                "Broker '{$broker->slug}' is not configured. Set its env credentials first."
            );
        }

        return $config;
    }
}
