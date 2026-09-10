<?php

namespace App\Http\Controllers\Api\Broker;

use App\Contracts\Brokers\UpstoxBroker;
use App\Http\Controllers\Controller;
use App\Models\Broker;
use App\Services\BrokerManager;
use App\Services\BrokerOAuthService;
use App\Traits\ResponseStructure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use RuntimeException;

class BrokerConnectionController extends Controller
{
    use ResponseStructure;

    public function __construct(
        protected BrokerOAuthService $brokerOAuth,
        protected BrokerManager $brokers,
    ) {}

    /**
     * Start the OAuth flow: returns the authorization URL for a user's account.
     */
    public function connect(Request $request, string $tradingAccount, string $slug): JsonResponse
    {
        $account = $request->user()->tradingAccounts()->findOrFail($tradingAccount);

        $broker = Broker::where('slug', $slug)->active()->firstOrFail();

        try {
            $url = $this->brokerOAuth->authorizationUrl($account, $broker);
        } catch (RuntimeException $e) {
            return $this->errorResponse(422, $e->getMessage());
        }

        return $this->successResponse(['authorization_url' => $url], 'Authorization URL generated.');
    }

    /**
     * OAuth callback (browser redirect from Upstox). Unauthenticated.
     */
    public function callback(string $slug, Request $request): RedirectResponse
    {
        $broker = Broker::where('slug', $slug)->firstOrFail();

        $code = $request->query('code');
        $state = $request->query('state');

        if (! $code || ! $state) {
            return redirect()->to($this->callbackTargetUrl($request, false, 'Missing authorization code.'));
        }

        try {
            $account = $this->brokerOAuth->handleCallback($broker, $code, $state);
        } catch (RuntimeException $e) {
            return redirect()->to($this->callbackTargetUrl($request, false, $e->getMessage()));
        }

        return redirect()->to($this->callbackTargetUrl($request, true, 'Broker connected successfully.', $account->id));
    }

    /**
     * Disconnect a broker from the user's account.
     */
    public function disconnect(Request $request, string $tradingAccount): JsonResponse
    {
        $account = $request->user()->tradingAccounts()->findOrFail($tradingAccount);

        $this->brokerOAuth->disconnect($account);

        return $this->successResponse(null, 'Broker disconnected successfully.');
    }

    /**
     * Authorize a live WebSocket feed (market or portfolio) for an account.
     */
    public function feed(Request $request, string $tradingAccount, string $type): JsonResponse
    {
        $account = $request->user()->tradingAccounts()->findOrFail($tradingAccount);

        if (! in_array($type, ['market', 'portfolio'], true)) {
            return $this->errorResponse(422, 'Feed type must be market or portfolio.');
        }

        $adapter = $this->brokers->activeAdapter($account);

        if (! $adapter instanceof UpstoxBroker) {
            return $this->errorResponse(422, 'Live streaming requires a connected Upstox account.');
        }

        try {
            $uri = $type === 'portfolio'
                ? $adapter->portfolioFeedUri()
                : $adapter->marketDataFeedUri();
        } catch (RuntimeException $e) {
            return $this->errorResponse(422, $e->getMessage());
        }

        return $this->successResponse(['uri' => $uri, 'type' => $type], 'Feed authorized.');
    }

    /**
     * Build the deep-link target the browser is redirected to after OAuth.
     */
    protected function callbackTargetUrl(Request $request, bool $success, string $message, ?string $tradingAccountId = null): string
    {
        $base = (string) config('brokers.redirect_success_base', 'bankertrader://broker/connected');

        $query = http_build_query([
            'success' => $success ? 'true' : 'false',
            'message' => $message,
            'trading_account_id' => $tradingAccountId,
        ]);

        return rtrim($base, '/').'?'.$query;
    }
}
