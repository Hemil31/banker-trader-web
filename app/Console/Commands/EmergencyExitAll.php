<?php

namespace App\Console\Commands;

use App\Models\TradingAccount;
use App\Services\EmergencyControlService;
use Illuminate\Console\Command;

class EmergencyExitAll extends Command
{
    protected $signature = 'trader:emergency-exit
        {--account= : Only close open positions for this trading account id}
        {--confirm : Required — without it, nothing is closed}';

    protected $description = 'Force-close every open position at the latest stored price, regardless of SL/target (best-effort, per-position)';

    public function handle(EmergencyControlService $emergency): int
    {
        if (! $this->option('confirm')) {
            $this->error('Refusing to close positions without --confirm.');

            return self::FAILURE;
        }

        $account = null;
        if ($accountId = $this->option('account')) {
            $account = TradingAccount::findOrFail($accountId);
        }

        $result = $emergency->emergencyExitAll($account, actor: 'cli');

        $this->info("Closed {$result['closed']}/{$result['total']} open position(s), {$result['failed']} failed.");

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
