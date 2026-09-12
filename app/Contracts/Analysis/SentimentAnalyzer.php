<?php

namespace App\Contracts\Analysis;

/**
 * Pluggable sentiment analyzer. Today we ship the free KeywordSentimentAnalyzer
 * (no paid NLP). The news service and the signal scanner consume only this
 * interface, so an LLM-backed provider (OpenAI / Anthropic / local) can be
 * swapped in via config('news.sentiment_driver') without touching engine code.
 */
interface SentimentAnalyzer
{
    /**
     * Score the sentiment of a single headline.
     *
     * @return array{
     *     label: 'positive'|'negative'|'neutral',
     *     score: float,   // 0..100 continuous, 50 = neutral
     *     polarity: int   // -1, 0, +1 — bucketed class (drives pos/neg/neutral counts)
     * }
     */
    public function analyze(string $text): array;
}
