<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trading_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->default('Default');
            $table->decimal('starting_capital', 18, 2)->default(100000);
            $table->decimal('available_cash', 18, 2)->default(100000);
            $table->decimal('invested_amount', 18, 2)->default(0);
            $table->string('mode')->default('paper'); // paper | live
            $table->boolean('master_enabled')->default(false);
            $table->boolean('strategy_enabled')->default(true);
            $table->timestamp('started_at')->nullable();
            $table->timestamps();
        });

        Schema::create('brokers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique(); // paper, zerodha, upstox, angel, kotak
            $table->string('name');
            $table->boolean('paper')->default(false);
            $table->boolean('active')->default(false);
            $table->json('credentials')->nullable();
            $table->string('api_status')->default('unknown'); // unknown | ok | error
            $table->text('api_status_message')->nullable();
            $table->timestamp('last_checked_at')->nullable();
            $table->timestamps();
        });

        Schema::create('stocks', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('symbol')->unique();       // NSE symbol, e.g. RELIANCE
            $table->string('name')->nullable();
            $table->string('exchange')->default('NSE'); // NSE | BSE
            $table->boolean('active')->default(true);
            $table->boolean('in_watchlist')->default(false);
            $table->string('sector')->nullable();
            $table->decimal('lot_size', 12, 0)->default(1);
            $table->boolean('under_surveillance')->default(false);
            $table->string('yfinance_symbol')->nullable(); // e.g. RELIANCE.NS
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('stocks');
        Schema::dropIfExists('brokers');
        Schema::dropIfExists('trading_accounts');
    }
};
