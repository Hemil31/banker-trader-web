<?php

namespace App\Console\Commands;

use App\Services\EmergencyControlService;
use Illuminate\Console\Command;

class TradingResume extends Command
{
    protected $signature = 'trader:resume';

    protected $description = 'Lift the platform-wide trading halt set by trader:halt or a reconciliation mismatch';

    public function handle(EmergencyControlService $emergency): int
    {
        $emergency->resumeNewOrders(actor: 'cli');

        $this->info('Trading resumed platform-wide.');

        return self::SUCCESS;
    }
}
