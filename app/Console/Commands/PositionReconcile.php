<?php

namespace App\Console\Commands;

use App\Services\PositionReconciliationService;
use Illuminate\Console\Command;

class PositionReconcile extends Command
{
    protected $signature = 'trader:reconcile';

    protected $description = 'Compare DB open positions against every externally-connected broker; halts new orders platform-wide on a mismatch';

    public function handle(PositionReconciliationService $reconciliation): int
    {
        $result = $reconciliation->reconcileAll();

        $this->info("Checked {$result['checked']} connected account(s), {$result['unreachable']} unreachable, {$result['mismatched']} mismatched.");

        if ($result['mismatched'] > 0) {
            $this->error('Mismatch found — trading halted platform-wide (system.trading_halted). Review error_logs/system_events before resuming.');

            foreach ($result['accounts'] as $account) {
                if ($account['status'] === 'mismatched') {
                    $this->line("  Account {$account['account_id']}: ".json_encode($account['mismatches']));
                }
            }

            return self::FAILURE;
        }

        return self::SUCCESS;
    }
}
