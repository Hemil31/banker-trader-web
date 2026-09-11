<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('news_articles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('stock_id')->nullable()->constrained()->nullOnDelete();
            $table->string('symbol')->nullable()->index();
            $table->string('article_uuid')->nullable();
            $table->string('title');
            $table->string('publisher')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->string('sentiment')->default('neutral'); // positive | neutral | negative
            $table->float('sentiment_score')->nullable();
            $table->text('original_url')->nullable();
            $table->text('thumbnail')->nullable();
            $table->json('authors')->nullable();
            $table->json('topics')->nullable();
            $table->timestamps();

            $table->unique(['article_uuid', 'symbol']);
            $table->index(['published_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('news_articles');
    }
};