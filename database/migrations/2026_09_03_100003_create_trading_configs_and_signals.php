<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_configs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('key')->unique();
            $table->string('group')->default('general');
            $table->string('type')->default('string'); // string|integer|float|boolean|array
            $table->text('value')->nullable();
            $table->string('label')->nullable();
            $table->text('description')->nullable();
            $table->boolean('is_editable')->default(true);
            $table->timestamps();
        });

        Schema::create('trading_signals', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('trading_account_id')->nullable()->constrained()->nullOnDelete();
            $table->date('signal_date');
            $table->time('signal_time')->nullable();
            $table->decimal('price', 18, 4)->nullable();        // latest close / reference
            $table->float('score')->default(0);
            $table->float('decline_score')->default(0);
            $table->float('reversal_score')->default(0);
            $table->float('volume_score')->default(0);
            $table->float('technical_score')->default(0);
            $table->float('liquidity_score')->default(0);
            $table->float('volatility_score')->default(0);
            $table->decimal('proposed_sl', 18, 4)->nullable();
            $table->decimal('proposed_target1', 18, 4)->nullable();
            $table->decimal('proposed_target2', 18, 4)->nullable();
            $table->decimal('proposed_target3', 18, 4)->nullable();
            $table->decimal('proposed_quantity', 18, 0)->nullable();
            $table->decimal('risk_per_share', 18, 4)->nullable();
            $table->decimal('reward_per_share', 18, 4)->nullable();
            $table->float('risk_reward_ratio')->nullable();
            $table->json('entry_reasons')->nullable();
            $table->json('indicators_at_entry')->nullable();
            $table->string('market_condition')->nullable(); // favorable | neutral | adverse
            $table->string('status')->default('new'); // new | eligible | executed | rejected | expired
            $table->string('rejection_reason')->nullable();
            $table->timestamps();

            $table->index(['signal_date', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trading_signals');
        Schema::dropIfExists('trading_configs');
    }
};
