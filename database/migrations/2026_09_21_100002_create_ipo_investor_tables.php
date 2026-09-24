<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pan_cards', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('pan_number', 10)->unique();            // normalized uppercase, e.g. ABCPD1234F
            $table->string('holder_name')->nullable();             // name as printed on the PAN (allotment matching)
            $table->date('date_of_birth')->nullable();
            $table->string('status')->default('unverified')->index(); // unverified|verified|rejected
            $table->timestamp('verified_at')->nullable();
            $table->json('verification_details')->nullable();      // DB/registrar or external PAN API response
            $table->boolean('is_primary')->default(false);
            $table->timestamps();
        });

        Schema::create('demat_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->string('provider')->index();                   // nsdl | cdsl | cdsl_other
            $table->string('dp_id', 16)->nullable();               // depository participant ID, e.g. 12081600 (CDSL)
            $table->string('client_id', 32);                       // BO/client ID: 8-char CDSL BO, 16-char NSDL BO
            $table->string('account_name')->nullable();            // holder name as per depository
            $table->string('status')->default('unverified')->index(); // unverified|verified|rejected
            $table->timestamp('verified_at')->nullable();
            $table->json('verification_details')->nullable();
            $table->boolean('is_primary')->default(false);
            $table->timestamps();

            $table->unique(['user_id', 'provider', 'client_id']);
        });

        Schema::create('ipo_applications', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ipo_id')->constrained('ipos')->cascadeOnDelete();
            $table->foreignUuid('pan_card_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('demat_account_id')->constrained()->restrictOnDelete();
            $table->foreignUuid('trading_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('batch_id')->nullable()->index();       // groups one bulk-apply submission (uuid)
            $table->unsignedInteger('lots')->default(1);           // number of lots bid
            $table->unsignedBigInteger('shares')->nullable();      // = lots * ipo.lot_size at submit time
            $table->decimal('amount', 18, 2)->nullable();          // = shares * ipo.price_max at submit time
            $table->decimal('price_per_share', 12, 2)->nullable(); // band top used for cutoff
            $table->string('application_number')->nullable()->unique(); // registrar/broker application number
            $table->string('status')->default('draft')->index();   // draft|queued|submitted|failed|allotment_pending|allotted|not_allotted|withdrawn
            $table->text('failure_reason')->nullable();
            $table->json('request_payload')->nullable();
            $table->json('response_payload')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('allotment_checked_at')->nullable();
            $table->timestamps();

            // One retail bid per PAN per IPO — the idempotency/duplicate-app guard.
            $table->unique(['user_id', 'ipo_id', 'pan_card_id']);
            $table->index(['ipo_id', 'status']);
        });

        Schema::create('ipo_allotments', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ipo_application_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('ipo_id')->constrained('ipos')->cascadeOnDelete();
            $table->string('pan_number', 10)->index();             // denormalized for registrar lookups
            $table->string('result')->default('pending')->index(); // pending|allotted|not_allotted
            $table->unsignedBigInteger('shares_allotted')->nullable();
            $table->string('registrar')->nullable();
            $table->string('source')->nullable();                  // which lookup path produced the result
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->timestamp('checked_at')->nullable();
            $table->json('raw_payload')->nullable();
            $table->timestamps();

            $table->unique('ipo_application_id');                  // one latest result per application, updated in place
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipo_allotments');
        Schema::dropIfExists('ipo_applications');
        Schema::dropIfExists('demat_accounts');
        Schema::dropIfExists('pan_cards');
    }
};
