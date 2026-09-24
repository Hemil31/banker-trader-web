<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * UPI ID for IPO payment mandates, captured when the demat BO is linked.
     */
    public function up(): void
    {
        Schema::table('demat_accounts', function (Blueprint $table) {
            $table->string('upi_id', 128)->nullable()->after('account_name');
        });
    }

    public function down(): void
    {
        Schema::table('demat_accounts', function (Blueprint $table) {
            $table->dropColumn('upi_id');
        });
    }
};
