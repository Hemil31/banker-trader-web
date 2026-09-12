<?php

namespace App\Services\Analysis;

use App\Contracts\Analysis\SentimentAnalyzer;

/**
 * Free keyword-based sentiment analyzer — no paid NLP. A headline is scanned
 * for positive / negative wordlists; the net count buckets it into positive,
 * negative or neutral and maps it onto the 10 / 50 / 90 scale the composite
 * signal score expects (50 ± 40). Neutral / no-opinion always lands on 50.
 *
 * An LLM provider can later implement SentimentAnalyzer and be registered as
 * the 'ai' driver — nothing downstream changes.
 */
class KeywordSentimentAnalyzer implements SentimentAnalyzer
{
    /** @var array<int, string> */
    protected const POSITIVE = [
        'surge', 'surges', 'soar', 'soars', 'rall', 'jump', 'jumps', 'gain', 'gains',
        'rise', 'rises', 'record high', 'all-time high', 'beats estimates', 'beat estimates',
        'upgrade', 'upgraded', 'outperform', 'profit', 'profits', 'recovery', 'growth',
        'bull', 'positive', 'strong', 'boost', 'wins', 'launch', 'partnership', 'expansion',
        'dividend', 'order win', 'approval',
    ];

    /** @var array<int, string> */
    protected const NEGATIVE = [
        'fall', 'falls', 'drop', 'drops', 'plunge', 'plunges', 'plummet', 'crash', 'rout',
        'slump', 'slips', 'slide', 'slides', 'selloff', 'sell-off', 'downgrade', 'downgraded',
        'loss', 'losses', 'investigation', 'probe', 'lawsuit', 'fraud', 'scandal', 'slashes',
        'cuts', 'weak', 'bearish', 'negative', 'concern', 'warning', 'bankruptcy', 'layoff',
        'layoffs', 'penalty', 'fine', 'decline', 'declines', 'warnings', 'deadlock', 'sanction',
    ];

    public function analyze(string $text): array
    {
        $polarity = $this->polarity($text);

        return [
            'label' => $this->label($polarity),
            'score' => (float) (50 + $polarity * 40),
            'polarity' => $polarity,
        ];
    }

    /**
     * @return 'negative'|'neutral'|'positive'
     */
    protected function label(int $polarity): string
    {
        return $polarity > 0 ? 'positive' : ($polarity < 0 ? 'negative' : 'neutral');
    }

    /**
     * Net positive - negative word hits, bucketed to +1 / -1 / 0.
     */
    protected function polarity(string $title): int
    {
        $text = mb_strtolower($title);
        $pos = $neg = 0;

        foreach (self::POSITIVE as $word) {
            if (str_contains($text, $word)) {
                $pos++;
            }
        }

        foreach (self::NEGATIVE as $word) {
            if (str_contains($text, $word)) {
                $neg++;
            }
        }

        return $pos <=> $neg;
    }
}
