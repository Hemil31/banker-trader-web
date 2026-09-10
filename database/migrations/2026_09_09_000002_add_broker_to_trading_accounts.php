<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trading_accounts', function (Blueprint $table) {
            $table->foreignUuid('broker_id')->nullable()->after('user_id')->constrained('brokers')->nullOnDelete();
            $table->longText('credentials')->nullable()->after('mode');
            $table->string('algo_name')->nullable()->after('credentials');
        });
    }

    public function down(): void
    {
        Schema::table('trading_accounts', function (Blueprint $table) {
            $table->dropForeign(['broker_id']);
            $table->dropColumn(['broker_id', 'credentials', 'algo_name']);
        });
    }
};
