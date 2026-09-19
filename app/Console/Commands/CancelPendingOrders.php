<?php

namespace App\Console\Commands;

use App\Models\TradingAccount;
use App\Services\EmergencyControlService;
use Illuminate\Console\Command;

class CancelPendingOrders extends Command
{
    protected $signature = 'trader:cancel-pending
        {--account= : Only cancel pending orders for this trading account id}
        {--confirm : Required — without it, nothing is cancelled}';

    protected $description = 'Cancel every non-terminal order at the broker (best-effort, per-order)';

    public function handle(EmergencyControlService $emergency): int
    {
        if (! $this->option('confirm')) {
            $this->error('Refusing to cancel orders without --confirm.');

            return self::FAILURE;
        }

        $account = null;
        if ($accountId = $this->option('account')) {
            $account = TradingAccount::findOrFail($accountId);
        }

        $result = $emergency->cancelPendingOrders($account, actor: 'cli');

        $this->info("Cancelled {$result['cancelled']}/{$result['total']} pending order(s), {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
