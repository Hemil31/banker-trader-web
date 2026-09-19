<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_post_requests', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->date('scheduled_date')->index();
            $table->time('scheduled_time')->default('10:00:00');
            $table->foreignUuid('zernio_account_id')->constrained()->cascadeOnDelete();
            $table->string('content_category')->default('general');
            $table->string('status')->default('pending')->index(); // pending|generating|generated|scheduled|published|failed
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->string('festival_name')->nullable();
            $table->string('festival_type')->nullable();
            $table->text('caption')->nullable();
            $table->json('hashtags')->nullable();
            $table->string('cta')->nullable();
            $table->string('content_type')->nullable();
            $table->string('gemini_model')->nullable();
            $table->json('raw_response')->nullable();
            $table->text('last_error')->nullable();
            $table->foreignUuid('zernio_post_id')->nullable()->constrained('zernio_posts')->nullOnDelete();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // Idempotency: one generation slot per date + account + category —
            // firstOrCreate() on these three columns is what prevents duplicate
            // Gemini calls/posts for the same scheduled slot.
            $table->unique(['scheduled_date', 'zernio_account_id', 'content_category'], 'ai_post_requests_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_post_requests');
    }
};
