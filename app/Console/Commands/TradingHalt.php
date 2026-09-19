<?php

namespace App\Console\Commands;

use App\Services\EmergencyControlService;
use Illuminate\Console\Command;

class TradingHalt extends Command
{
    protected $signature = 'trader:halt {--reason=manual_halt : Recorded as the RiskManager halt reason}';

    protected $description = 'Stop all new order entries platform-wide (open positions still exit normally)';

    public function handle(EmergencyControlService $emergency): int
    {
        $emergency->haltNewOrders(actor: 'cli', reason: (string) $this->option('reason'));

        $this->warn('Trading halted platform-wide. New entries are blocked; open positions still monitor/exit. Run trader:resume to lift it.');

        return self::SUCCESS;
    }
}
