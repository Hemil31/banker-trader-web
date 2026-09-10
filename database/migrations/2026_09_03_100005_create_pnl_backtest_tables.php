<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_pnl_daily', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trading_account_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('realized', 18, 2)->default(0);
            $table->decimal('unrealized', 18, 2)->default(0);
            $table->decimal('gross_pnl', 18, 2)->default(0);
            $table->decimal('costs', 18, 2)->default(0);
            $table->decimal('net_pnl', 18, 2)->default(0);
            $table->decimal('charges', 18, 2)->default(0);
            $table->integer('trades_count')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->decimal('target_progress', 18, 2)->default(0);
            $table->unique(['trading_account_id', 'trade_date']);
            $table->timestamps();
        });

        Schema::create('trading_pnl_ledger', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trading_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('position_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('gross', 18, 2);
            $table->decimal('brokerage', 18, 2)->default(0);
            $table->decimal('stt', 18, 2)->default(0);
            $table->decimal('exchange_charges', 18, 2)->default(0);
            $table->decimal('gst', 18, 2)->default(0);
            $table->decimal('sebi', 18, 2)->default(0);
            $table->decimal('stamp_duty', 18, 2)->default(0);
            $table->decimal('slippage_cost', 18, 2)->default(0);
            $table->decimal('total_costs', 18, 2)->default(0);
            $table->decimal('net', 18, 2)->default(0);
            $table->string('direction')->default('buy'); // buy | sell
            $table->timestamps();
        });

        Schema::create('backtests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('train_start')->nullable();
            $table->string('train_end')->nullable();
            $table->string('val_start')->nullable();
            $table->string('val_end')->nullable();
            $table->string('oos_start')->nullable();
            $table->string('oos_end')->nullable();
            $table->decimal('starting_capital', 18, 2)->nullable();
            $table->decimal('ending_capital', 18, 2)->nullable();
            $table->decimal('total_return_pct', 10, 4)->nullable();
            $table->decimal('cagr', 10, 4)->nullable();
            $table->integer('total_trades')->default(0);
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->double('win_rate')->nullable();
            $table->decimal('avg_profit', 18, 2)->nullable();
            $table->decimal('avg_loss', 18, 2)->nullable();
            $table->double('profit_factor')->nullable();
            $table->decimal('max_drawdown', 18, 2)->nullable();
            $table->decimal('max_drawdown_pct', 10, 4)->nullable();
            $table->integer('max_consecutive_losses')->default(0);
            $table->decimal('largest_loss', 18, 2)->nullable();
            $table->decimal('largest_gain', 18, 2)->nullable();
            $table->double('avg_holding_days')->nullable();
            $table->decimal('total_costs', 18, 2)->default(0);
            $table->decimal('net_profit', 18, 2)->nullable();
            $table->decimal('buyhold_return_pct', 10, 4)->nullable();
            $table->double('buyhold_cagr')->nullable();
            $table->json('monthly_pnl')->nullable();
            $table->json('daily_pnl')->nullable();
            $table->json('equity_curve')->nullable();
            $table->json('config_snapshot')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('backtests');
        Schema::dropIfExists('trading_pnl_ledger');
        Schema::dropIfExists('trading_pnl_daily');
    }
};
