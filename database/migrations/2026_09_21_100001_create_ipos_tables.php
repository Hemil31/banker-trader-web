<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ipos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('slug')->unique();                       // stable id from source, e.g. nse, hero-motors
            $table->string('name');                                 // full company name
            $table->string('symbol')->nullable();                   // ticker, e.g. NSE (pre-listing often null)
            $table->string('board')->default('mainboard')->index(); // mainboard | sme
            $table->string('status')->default('drhp_approved')->index(); // drhp_approved | upcoming | live | closed | allotment_awaited | listed | withdrawn
            $table->decimal('price_min', 12, 2)->nullable();
            $table->decimal('price_max', 12, 2)->nullable();
            $table->unsignedInteger('lot_size')->nullable();
            $table->decimal('issue_size_cr', 18, 2)->nullable();    // issue size in ₹ crore
            $table->string('issue_size_text')->nullable();          // raw display text (e.g. "27,00,00,000 shares")
            $table->unsignedBigInteger('total_issue_shares')->nullable();
            $table->unsignedBigInteger('ofs_shares')->nullable();
            $table->date('open_date')->nullable()->index();
            $table->date('close_date')->nullable();
            $table->date('allotment_date')->nullable();
            $table->date('listing_date')->nullable()->index();
            $table->string('listing_at')->nullable();               // e.g. "BSE (Not on NSE)"
            $table->string('face_value')->nullable();               // e.g. "₹1 Per Equity Share"
            $table->string('registrar')->nullable();
            $table->json('lead_managers')->nullable();
            $table->json('reservation')->nullable();                // category => {shares_offered, pct, max_allottees}
            $table->text('issue_objectives')->nullable();
            $table->longText('about')->nullable();
            $table->string('promoters')->nullable();
            $table->string('pre_issue_holding')->nullable();
            $table->string('post_issue_holding')->nullable();
            $table->json('financials')->nullable();                 // EPS, P/E, ROE, ROCE, RoNW, PAT margin, P/B, market cap
            $table->json('peers')->nullable();                      // rows of [name, p_b, p_e, ronw, revenue_cr]
            $table->json('strengths')->nullable();
            $table->json('risks')->nullable();
            $table->json('contact_details')->nullable();
            $table->text('logo_url')->nullable();
            $table->text('source_url')->nullable(); // dedup via slug; MySQL can't unique-index a text column
            $table->decimal('current_subscription', 8, 2)->nullable(); // latest total subscription (x)
            $table->decimal('current_gmp', 12, 2)->nullable();         // latest GMP quote (₹)
            $table->decimal('expected_premium', 12, 2)->nullable();    // exp. premium shown on cards (₹)
            $table->decimal('expected_premium_pct', 8, 2)->nullable(); // exp. premium percentage
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('ipo_subscription_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ipo_id')->constrained('ipos')->cascadeOnDelete();
            $table->timestamp('as_on');                     // snapshot time, e.g. day close "17-09-26 05:00 PM"
            $table->decimal('qib', 8, 2)->nullable();
            $table->decimal('nii', 8, 2)->nullable();
            $table->decimal('bhni', 8, 2)->nullable();
            $table->decimal('shni', 8, 2)->nullable();
            $table->decimal('retail', 8, 2)->nullable();
            $table->decimal('employee', 8, 2)->nullable();
            $table->decimal('total', 8, 2)->nullable();
            $table->timestamps();

            $table->unique(['ipo_id', 'as_on']);
            $table->index('as_on');
        });

        Schema::create('ipo_gmp_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('ipo_id')->constrained('ipos')->cascadeOnDelete();
            $table->timestamp('recorded_at');
            $table->decimal('gmp', 12, 2)->nullable();              // ₹ premium
            $table->decimal('premium_pct', 8, 2)->nullable();       // vs upper price band
            $table->decimal('indicative_price', 12, 2)->nullable(); // = upper band + gmp
            $table->timestamps();

            $table->unique(['ipo_id', 'recorded_at']);
            $table->index('recorded_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ipo_gmp_history');
        Schema::dropIfExists('ipo_subscription_snapshots');
        Schema::dropIfExists('ipos');
    }
};
