<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A headline persisted from the news provider during signal scans / news:fetch.
 *
 * @property string $id
 */
class NewsArticle extends Model
{
    use HasUuids;

    protected $table = 'news_articles';

    protected $fillable = [
        'stock_id', 'symbol', 'article_uuid', 'title', 'publisher',
        'published_at', 'sentiment', 'sentiment_score',
        'original_url', 'thumbnail', 'authors', 'topics',
    ];

    protected $casts = [
        'published_at' => 'datetime',
        'sentiment_score' => 'float',
        'authors' => 'array',
        'topics' => 'array',
    ];

    /**
     * @return BelongsTo<Stock, $this>
     */
    public function stock(): BelongsTo
    {
        return $this->belongsTo(Stock::class);
    }
}
