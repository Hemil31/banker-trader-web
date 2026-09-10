<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('paper_trades', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('trading_account_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('trading_signal_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol');
            $table->string('direction')->default('buy');
            $table->decimal('signal_price', 18, 4)->nullable();
            $table->decimal('intended_entry', 18, 4)->nullable();
            $table->decimal('fill_price', 18, 4)->nullable();
            $table->decimal('quantity', 18, 0)->nullable();
            $table->decimal('stop_loss', 18, 4)->nullable();
            $table->decimal('target', 18, 4)->nullable();
            $table->decimal('slippage', 18, 4)->nullable();
            $table->decimal('pnl', 18, 2)->nullable();
            $table->decimal('pnl_net', 18, 2)->nullable();
            $table->string('status')->default('open'); // open | closed
            $table->string('entry_reason')->nullable();
            $table->string('exit_reason')->nullable();
            $table->dateTime('signal_at')->nullable();
            $table->dateTime('executed_at')->nullable();
            $table->dateTime('exited_at')->nullable();
            $table->json('simulation_data')->nullable();
            $table->timestamps();

            $table->index(['trading_account_id', 'status']);
        });

        Schema::create('error_logs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('level')->default('error'); // info | warning | error | critical
            $table->string('component')->nullable();   // broker | data | engine | scheduler | api
            $table->string('code')->nullable();
            $table->text('message');
            $table->json('context')->nullable();
            $table->timestamp('logged_at')->useCurrent();
            $table->index('logged_at');
            $table->index('level');
        });

        Schema::create('system_events', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type')->nullable();        // signal | order | position | config | system | risk
            $table->string('action')->nullable();      // created | executed | stopped | etc.
            $table->string('actor')->default('system'); // system | user | admin
            $table->nullableUuidMorphs('subject');
            $table->text('description')->nullable();
            $table->json('data')->nullable();
            $table->timestamp('occurred_at')->useCurrent();
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('system_events');
        Schema::dropIfExists('error_logs');
        Schema::dropIfExists('paper_trades');
    }
};
