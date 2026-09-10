<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trading_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('broker_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignUuid('trading_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('order_ref')->unique();                 // broker/order id
            $table->string('side')->default('buy');                // buy | sell
            $table->string('type')->default('market');             // market | limit | stop
            $table->string('status')->default('pending');          // pending|acknowledged|filled|partial|cancelled|rejected|failed|timeout
            $table->decimal('requested_quantity', 18, 0);
            $table->decimal('filled_quantity', 18, 0)->default(0);
            $table->decimal('price', 18, 4)->nullable();           // limit/trigger price
            $table->decimal('avg_fill_price', 18, 4)->nullable();
            $table->decimal('slippage', 18, 4)->nullable();
            $table->string('purpose')->default('entry');           // entry | take_profit | stop_loss | trailing | exit
            $table->string('trailing_mode')->nullable();           // percent | atr_multiple
            $table->decimal('trailing_value', 18, 4)->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamp('requested_at')->nullable();
            $table->timestamp('filled_at')->nullable();
            $table->timestamp('timeout_at')->nullable();
            $table->timestamps();

            $table->index(['trading_account_id', 'status']);
        });

        Schema::create('order_executions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_id')->constrained()->cascadeOnDelete();
            $table->decimal('quantity', 18, 0);
            $table->decimal('price', 18, 4);
            $table->decimal('brokerage', 18, 2)->default(0);
            $table->decimal('charges', 18, 2)->default(0);
            $table->timestamp('executed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('positions', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trading_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('stock_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('broker_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status')->default('open');             // open | closed | stopped | target_hit
            $table->decimal('quantity', 18, 0)->default(0);
            $table->decimal('avg_entry_price', 18, 4)->nullable();
            $table->decimal('entry_value', 18, 2)->nullable();
            $table->decimal('stop_loss', 18, 4)->nullable();
            $table->decimal('target1', 18, 4)->nullable();
            $table->decimal('target2', 18, 4)->nullable();
            $table->decimal('target3', 18, 4)->nullable();
            $table->decimal('current_stop', 18, 4)->nullable();    // may be trailed up
            $table->decimal('realized_pnl', 18, 2)->default(0);
            $table->decimal('realized_pnl_net', 18, 2)->default(0);
            $table->decimal('unrealized_pnl', 18, 2)->default(0);
            $table->decimal('unrealized_pnl_net', 18, 2)->default(0);
            $table->decimal('partial_booked_qty', 18, 0)->default(0);
            $table->boolean('trailing_enabled')->default(false);
            $table->timestamp('opened_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->string('close_reason')->nullable();
            $table->decimal('exit_price', 18, 4)->nullable();
            $table->decimal('net_pnl', 18, 2)->default(0);
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->unique(['trading_account_id', 'stock_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('positions');
        Schema::dropIfExists('order_executions');
        Schema::dropIfExists('orders');
    }
};
