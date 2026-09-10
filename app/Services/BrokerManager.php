<?php

namespace App\Services;

use App\Contracts\Brokers\BrokerAdapter;
use App\Contracts\Brokers\PaperBroker;
use App\Contracts\Brokers\UpstoxBroker;
use App\Contracts\Brokers\ZerodhaBroker;
use App\Models\Broker;
use App\Models\TradingAccount;
use Illuminate\Support\Collection;
use InvalidArgumentException;

/**
 * Resolves a broker slug to its adapter implementation. Adding a broker only
 * requires mapping its slug here; nothing downstream changes.
 *
 * The adapter for a user's account is chosen by the account's 'broker_id'
 * (defaults to 'paper').
 */
class BrokerManager
{
    public function __construct(protected TradingConfigService $config) {}

    /**
     * Build the adapter for a broker bound to a user's account.
     */
    public function adapterFor(Broker $broker, TradingAccount $account): BrokerAdapter
    {
        $account->setRelation('broker', $broker);

        return match ($broker->slug) {
            'paper' => new PaperBroker($account, $broker, $this->config),
            'zerodha' => new ZerodhaBroker($account, $broker, $this->config),
            'upstox' => new UpstoxBroker($account, $broker, $this->config),
            default => throw new InvalidArgumentException("Unsupported broker slug: {$broker->slug}"),
        };
    }

    /**
     * Resolve the adapter for a user's trading account. Defaults to PaperBroker
     * when the account has no live broker attached.
     */
    public function activeAdapter(?TradingAccount $account = null): BrokerAdapter
    {
        $account ??= $this->defaultAccount();

        $broker = $account->broker
            ?? Broker::where('slug', 'paper')->first();

        if (! $broker) {
            throw new InvalidArgumentException('No broker configured. Run the seeder first.');
        }

        return $this->adapterFor($broker, $account);
    }

    /**
     * Resolve the broker model bound to an account, falling back to paper.
     */
    public function brokerFor(TradingAccount $account): Broker
    {
        return $account->broker
            ?? Broker::where('slug', 'paper')->first();
    }

    /**
     * The user's first trading account (fallback when none is passed explicitly).
     */
    protected function defaultAccount(): TradingAccount
    {
        $user = auth()->user();

        if ($user) {
            $account = $user->tradingAccounts()->first();

            if ($account) {
                return $account->loadMissing('broker');
            }
        }

        throw new InvalidArgumentException('No trading account found for the current user.');
    }

    /**
     * All active (non-paper) brokers registered in the system.
     *
     * @return Collection<int, Broker>
     */
    public function liveBrokers()
    {
        return Broker::where('active', true)
            ->where('paper', false)
            ->orderBy('name')
            ->get();
    }
}
