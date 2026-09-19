<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The original unique(['trading_account_id','stock_id','status']) constraint
 * broke the second time any account closed a position in a stock it had
 * already round-tripped: every closed position keeps status='closed', so a
 * second closed row for the same account+stock collides on that key.
 *
 * A generated-column workaround (unique index over a column that is only
 * populated while status='open') was tried but MySQL/InnoDB refuses to add
 * a STORED generated column to a table that already has foreign keys on it
 * (error 1215, "Cannot add foreign key constraint" — a known engine
 * limitation, not specific to this schema). So "at most one open position
 * per account+stock" is now enforced at the application level instead
 * (PortfolioManager::hasOpenPosition(), checked in
 * ExecutionEngine::enterLocked() before a new position is opened) and this
 * migration just removes the broken constraint.
 */
return new class extends Migration
{
    public function up(): void
    {
        // The unique index being dropped also serves as the supporting index
        // for the trading_account_id foreign key (MySQL reuses a compatible
        // leftmost-prefix index instead of creating a redundant one), so a
        // replacement index must exist first.
        Schema::table('positions', function (Blueprint $table) {
            $table->index('trading_account_id', 'positions_trading_account_id_fk_index');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropUnique(['trading_account_id', 'stock_id', 'status']);
        });

        // A plain (non-unique) index still speeds up the app-level
        // "already has an open position in this stock" lookup.
        Schema::table('positions', function (Blueprint $table) {
            $table->index(['trading_account_id', 'stock_id', 'status'], 'positions_account_stock_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex('positions_account_stock_status_index');
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->unique(['trading_account_id', 'stock_id', 'status']);
        });

        Schema::table('positions', function (Blueprint $table) {
            $table->dropIndex('positions_trading_account_id_fk_index');
        });
    }
};
