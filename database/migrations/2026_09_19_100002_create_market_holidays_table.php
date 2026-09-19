<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('market_holidays', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('mic');
            $table->string('exchange');
            $table->date('date');
            $table->string('day_of_week');
            $table->boolean('is_weekend')->default(false);
            $table->boolean('is_business_day')->default(false);
            $table->string('holiday_name')->nullable();
            $table->boolean('is_early_close')->default(false);
            $table->time('open_time')->nullable();
            $table->time('close_time')->nullable();
            $table->timestamps();

            $table->unique(['mic', 'date']);
            $table->index('date');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('market_holidays');
    }
};
