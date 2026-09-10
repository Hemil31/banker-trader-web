<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_data', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->decimal('open', 18, 4);
            $table->decimal('high', 18, 4);
            $table->decimal('low', 18, 4);
            $table->decimal('close', 18, 4);
            $table->decimal('adjusted_close', 18, 4)->nullable();
            $table->bigInteger('volume')->default(0);
            $table->float('turnover')->nullable();
            $table->unique(['stock_id', 'trade_date']);
            $table->index('trade_date');
            $table->timestamps();
        });

        Schema::create('market_indicators', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_id')->constrained()->cascadeOnDelete();
            $table->date('trade_date');
            $table->float('rsi14')->nullable();
            $table->float('atr14')->nullable();
            $table->float('ma20')->nullable();
            $table->float('ma50')->nullable();
            $table->float('ma200')->nullable();
            $table->float('ret3d')->nullable();
            $table->float('ret5d')->nullable();
            $table->float('dist_from_20d_high')->nullable();
            $table->float('volume_ratio')->nullable();
            $table->float('avg_volume_20d')->nullable();
            $table->float('daily_range_pct')->nullable();
            $table->float('beta')->nullable();
            $table->unique(['stock_id', 'trade_date']);
            $table->index('trade_date');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_indicators');
        Schema::dropIfExists('market_data');
    }
};
