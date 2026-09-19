<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('zernio_accounts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('zernio_account_id')->unique();
            $table->string('platform')->index();
            $table->string('name');
            $table->string('username')->nullable();
            $table->text('avatar_url')->nullable();
            $table->boolean('is_active')->default(true);
            $table->boolean('needs_reconnection')->default(false);
            $table->json('raw_payload')->nullable();
            $table->timestamp('synced_at')->nullable();
            $table->timestamps();
        });

        Schema::create('zernio_posts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('content');
            $table->boolean('publish_now')->default(true);
            $table->timestamp('scheduled_at')->nullable();
            $table->string('timezone')->default('Asia/Kolkata');
            $table->string('status')->default('draft')->index(); // draft|published|scheduled|failed
            $table->string('zernio_post_id')->nullable()->unique();
            $table->string('idempotency_key')->nullable()->unique();
            $table->text('error')->nullable();
            $table->timestamps();
        });

        Schema::create('zernio_post_account', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('zernio_post_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('zernio_account_id')->constrained()->cascadeOnDelete();
            $table->text('platform_post_url')->nullable();
            $table->string('status')->default('pending'); // pending|published|failed
            $table->timestamps();

            $table->unique(['zernio_post_id', 'zernio_account_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('zernio_post_account');
        Schema::dropIfExists('zernio_posts');
        Schema::dropIfExists('zernio_accounts');
    }
};
