<?php

namespace App\Services;

use App\Models\MarketData;
use App\Models\Stock;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;

/**
 * Detects pullback + reversal setups and produces a composite trade score.
 *
 * Score weights come from config (overrides.product.weights.*), never hard-coded.
 * The scan is run over a stock's stored MarketData series.
 */
class SignalScanner
{
    public const WEIGHTS = [
        'decline' => 'product.weights.decline',
        'reversal' => 'product.weights.reversal',
        'volume' => 'product.weights.volume',
        'technical' => 'product.weights.technical',
        'liquidity' => 'product.weights.liquidity',
        'volatility' => 'product.weights.volatility',
        'news' => 'product.weights.news',
    ];

    public function __construct(
        protected IndicatorCalculator $indicators,
        protected TradingConfigService $config,
        protected NewsService $news,
    ) {}

    /**
     * Serve config reads from an in-memory snapshot (used by the backtester).
     *
     * @param  array<string, mixed>  $snapshot
     */
    public function useSnapshot(array $snapshot): void
    {
        $this->config->useSnapshot($snapshot);
    }

    /**
     * Build a setup + component scores for a stock. Returns null if not tradable.
     *
     * @param  Collection<int, MarketData>  $rows
     * @return array{
     *     stock: Stock,
     *     indicators: array<string, float|int|null>,
     *     score: float,
     *     weights: array<string, float>,
     *     components: array<string, float>,
     *     decline_score: float,
     *     reversal_score: float,
     *     volume_score: float,
     *     technical_score: float,
     *     liquidity_score: float,
     *     volatility_score: float,
     *     news_score: ?float,
     *     news: ?array{score: float, positive: int, negative: int, neutral: int, sample: ?string},
     *     reasons: array<string, mixed>,
     *     tradable: bool,
     *     rejection?: string
     * }|null
     */
    public function scan(Stock $stock, Collection $rows): ?array
    {
        $indicators = $this->indicators->compute($rows);

        if (! $indicators['last_close']) {
            return null;
        }

        $minPrice = $this->config->float('product.min_price', 20);
        if ($indicators['last_close'] < $minPrice) {
            return null;
        }

        $weights = $this->weights();

        $decline = $this->declineScore($indicators);
        $reversal = $this->reversalScore($indicators);
        $volume = $this->volumeScore($indicators);
        $technical = $this->technicalScore($indicators);
        $liquidity = $this->liquidityScore($indicators);
        $volatility = $this->volatilityScore($indicators);

        $newsDoc = $this->newsScore($stock);
        $newsScore = $newsDoc['score'] ?? null;

        $components = [
            'decline' => $decline,
            'reversal' => $reversal,
            'volume' => $volume,
            'technical' => $technical,
            'liquidity' => $liquidity,
            'volatility' => $volatility,
        ];

        if ($newsScore !== null) {
            $components['news'] = (float) $newsScore;
        }

        $score = 0.0;
        $totalWeight = 0.0;
        foreach ($components as $key => $componentScore) {
            $score += $componentScore * $weights[$key];
            $totalWeight += $weights[$key];
        }

        // Normalize by the weights actually present so the weighted-average scale is
        // preserved whether or not the news component participates.
        if ($totalWeight > 0) {
            $score /= $totalWeight;
        }

        $minScore = $this->config->float('product.min_score', 60);
        $reasons = $this->buildReasons($indicators, $newsDoc);

        // The entry rule is a hard gate on top of the continuous score. The
        // composite-score threshold applies to the native rule only — the
        // rsi_oversold rule relies on its own (backtested) entry condition.
        $entryRule = strtolower((string) $this->config->get('product.entry_rule', 'native'));
        $rsiRule = $entryRule === 'rsi_oversold';

        if (! $rsiRule && $score < $minScore) {
            return $this->scanResult($stock, $indicators, $score, $weights, $components, $newsScore, $newsDoc, $reasons, false, 'score_below_minimum');
        }

        // No-chase guard (both rules): never buy a stock on the day it spikes
        // or one extended far above its 20-day mean — those entries fill near
        // the top and gap down against the stop.
        $chase = $this->chaseRejection($indicators);
        if ($chase !== null) {
            return $this->scanResult($stock, $indicators, $score, $weights, $components, $newsScore, $newsDoc, $reasons, false, $chase);
        }

        if ($rsiRule) {
            $rsi = $indicators['rsi14'] ?? null;
            $close = (float) $indicators['last_close'];
            $prev = $indicators['prev_close'] ?? null;
            $ok = $rsi !== null && $rsi < 32 && $prev !== null && $close > $prev;

            if (! $ok) {
                return $this->scanResult($stock, $indicators, $score, $weights, $components, $newsScore, $newsDoc, $reasons, false, 'entry_rule_rsi_oversold');
            }

            return $this->scanResult($stock, $indicators, $score, $weights, $components, $newsScore, $newsDoc, $reasons, true);
        }

        // Native rule: the reversal confirmation is a hard gate on the composite score.
        $minConfirm = $this->config->int('product.min_reversal_confirmations', 2);
        $confirmations = $reasons['reversal_confirmations'] ?? 0;

        if ($confirmations < $minConfirm) {
            return $this->scanResult($stock, $indicators, $score, $weights, $components, $newsScore, $newsDoc, $reasons, false, 'insufficient_reversal_confirmations');
        }

        return $this->scanResult($stock, $indicators, $score, $weights, $components, $newsScore, $newsDoc, $reasons, true);
    }

    /**
     * No-chase guard: reject entries on spike days or stocks extended well
     * above their 20-day MA. Returns a rejection reason, or null when the
     * setup is not extended.
     *
     * @param  array<string, float|int|null>  $ind
     */
    protected function chaseRejection(array $ind): ?string
    {
        $close = $ind['last_close'] ?? null;
        $prev = $ind['prev_close'] ?? null;
        $ma20 = $ind['ma20'] ?? null;

        $maxDayGain = $this->config->float('product.max_prev_day_gain_pct', 5);
        $maxAboveMa20 = $this->config->float('product.max_ext_above_ma20_pct', 15);

        if ($prev !== null && (float) $prev > 0 && $close !== null) {
            $dayGain = ((float) $close - (float) $prev) / (float) $prev * 100;
            if ($dayGain > $maxDayGain) {
                return 'extended_prev_day_gain';
            }
        }

        if ($ma20 !== null && (float) $ma20 > 0 && $close !== null) {
            $aboveMa20 = ((float) $close - (float) $ma20) / (float) $ma20 * 100;
            if ($aboveMa20 > $maxAboveMa20) {
                return 'extended_above_ma20';
            }
        }

        return null;
    }

    /**
     * Pullback score (higher = steeper/later decline). [0..100]
     */
    /**
     * @param  array<string, float|int|null>  $ind
     */
    protected function declineScore(array $ind): float
    {
        $dist = $ind['dist_from_20d_high'] ?? 0;
        if ($dist >= 0) {
            return 10; // no visible pullback
        }

        $depth = abs($dist);

        // Gentle pullbacks worth more than crash-like breaks below MA20.
        if ($depth <= 2) {
            return 25 + $depth * 15;
        }
        if ($depth <= 6) {
            return 40 + ($depth - 2) * 10;
        }

        return 60 * max(0, 1 - min(1, ((float) $ind['ma20'] > 0 && (float) $ind['last_close'] < (float) $ind['ma20'] ? 0.55 : 0)));
    }

    /**
     * Reversal confirmation score based on candlesticks / RSI / momentum. [0..100]
     */
    /**
     * @param  array<string, float|int|null>  $ind
     */
    protected function reversalScore(array $ind): float
    {
        $confirmations = 0;
        $score = 0;
        $rsi = $ind['rsi14'] ?? 100;

        // RSI turning up from oversold region.
        if ($rsi < 30) {
            $confirmations++;
            $score += 30;
        } elseif ($rsi > 30 && $rsi < 55) {
            $confirmations++;
            $score += 25;
        }

        // Short-term momentum turn (5D return positive after the pullback).
        if (($ind['ret5d'] ?? 0) > 0) {
            $confirmations++;
            $score += 30;
        }

        // Price reclaimed the 5-day MA (reversal anchor).
        if (($ind['ma5'] ?? null) !== null && (float) $ind['last_close'] > (float) $ind['ma5']) {
            $confirmations++;
            $score += 30;
        }

        return min($score, 100);
    }

    /**
     * @param  array<string, float|int|null>  $ind
     */
    protected function volumeScore(array $ind): float
    {
        $ratio = $ind['volume_ratio'] ?? 0;
        if ($ratio >= 2) {
            return 100;
        }
        if ($ratio >= 1.5) {
            return 75;
        }
        if ($ratio >= 1.2) {
            return 55;
        }
        if ($ratio >= 1) {
            return 40;
        }

        return 25;
    }

    /**
     * @param  array<string, float|int|null>  $ind
     */
    protected function technicalScore(array $ind): float
    {
        $score = 0;

        if (($ind['ma20'] ?? null) !== null && (float) $ind['last_close'] > (float) $ind['ma20']) {
            $score += 30; // above 20DMA = trend intact
        } else {
            $score -= 5;
        }

        if (($ind['ma50'] ?? null) !== null && (float) $ind['last_close'] > (float) $ind['ma50']) {
            $score += 30;
        }

        if (($ind['rsi14'] ?? null) !== null && (float) $ind['rsi14'] > 30 && (float) $ind['rsi14'] < 70) {
            $score += 30;
        }

        // Not extended far above MA20 keeps entry risk reasonable.
        if (($ind['ma20'] ?? null) !== null) {
            $pctAbove = ((float) $ind['last_close'] - (float) $ind['ma20']) / (float) $ind['ma20'] * 100;
            if ($pctAbove < 4) {
                $score += 10;
            }
        }

        return max(0, min(100, $score));
    }

    /**
     * @param  array<string, float|int|null>  $ind
     */
    protected function liquidityScore(array $ind): float
    {
        $avgVol = $ind['avg_volume_20d'] ?? 0;
        $minAvg = $this->config->float('product.min_avg_volume', 50000);

        if ($avgVol >= $minAvg * 4) {
            return 100;
        }
        if ($avgVol >= $minAvg * 2) {
            return 75;
        }
        if ($avgVol >= $minAvg) {
            return 55;
        }

        return 30;
    }

    /**
     * @param  array<string, float|int|null>  $ind
     */
    protected function volatilityScore(array $ind): float
    {
        $atr = $ind['atr14'] ?? null;
        $price = (float) $ind['last_close'];

        if ($atr === null || $price <= 0) {
            return 50;
        }

        $atrPct = ($atr / $price) * 100;

        if ($atrPct >= 1 && $atrPct <= 3) {
            return 100; // ideal swing volatility
        }
        if ($atrPct < 1) {
            return 60; // too quiet
        }
        if ($atrPct <= 5) {
            return 70; // a bit hot but tradable
        }

        return 40; // too volatile for the risk model
    }

    /**
     * News sentiment for a stock (0..100) or null when the component should not
     * participate: feature disabled, API failure, or no recent coverage at all.
     *
     * @return array{score: float, positive: int, negative: int, neutral: int, sample: ?string}|null
     */
    protected function newsScore(Stock $stock): ?array
    {
        if (! $this->config->bool('news.enabled', true)) {
            return null;
        }

        try {
            $articles = $this->news->fetch($stock->symbol, $stock->name);
        } catch (\Throwable $e) {
            Log::warning("News component skipped for {$stock->symbol}: {$e->getMessage()}");

            return null;
        }

        if ($articles === []) {
            return null;
        }

        return $this->news->sentiment($articles);
    }

    /**
     * @return array<string, float>
     */
    protected function weights(): array
    {
        $out = [];
        foreach (self::WEIGHTS as $key => $keychain) {
            $out[$key] = $this->config->float($keychain, $this->defaultWeights()[$key]);
        }

        return $out;
    }

    /**
     * @return array<string, float>
     */
    protected function defaultWeights(): array
    {
        return [
            'decline' => 0.15,
            'reversal' => 0.20,
            'volume' => 0.15,
            'technical' => 0.15,
            'liquidity' => 0.10,
            'volatility' => 0.10,
            'news' => 0.15,
        ];
    }

    /**
     * @param  array<string, float|int|null>  $ind
     * @param  array{score: float, positive: int, negative: int, neutral: int, sample: ?string}|null  $news
     * @return array<string, mixed>
     */
    protected function buildReasons(array $ind, ?array $news = null): array
    {
        $reasons = [];

        $dist = $ind['dist_from_20d_high'] ?? 0;
        if ($dist < 0) {
            $reasons['pullback'] = sprintf('Pulled back %.1f%% from 20-day high', abs($dist));
        }

        $rsi = $ind['rsi14'] ?? null;
        $reasons['reversal_confirmations'] = 0;
        $reasons['reversal_details'] = [];

        if ($rsi !== null && $rsi < 30) {
            $reasons['reversal_confirmations']++;
            $reasons['reversal_details'][] = 'RSI oversold (<30)';
        } elseif ($rsi !== null && $rsi < 55) {
            $reasons['reversal_confirmations']++;
            $reasons['reversal_details'][] = 'RSI recovering from oversold';
        }

        if (($ind['ret5d'] ?? 0) > 0) {
            $reasons['reversal_confirmations']++;
            $reasons['reversal_details'][] = 'Positive 5-day momentum';
        }

        if (($ind['ma5'] ?? null) !== null && (float) $ind['last_close'] > (float) $ind['ma5']) {
            $reasons['reversal_confirmations']++;
            $reasons['reversal_details'][] = 'Price above 5-day MA';
        }

        if (($ind['volume_ratio'] ?? 0) >= 1.2) {
            $reasons['volume'] = 'Volume expanding into the move';
        }

        if ($news !== null && ($news['sample'] ?? null) !== null) {
            $reasons['news'] = sprintf(
                'News %+.0f (pos %d / neg %d): %s',
                (float) $news['score'] - 50,
                $news['positive'],
                $news['negative'],
                $news['sample'],
            );
        }

        return $reasons;
    }

    /**
     * @param  array<string, float|int|null>  $ind
     * @param  array<string, float>  $weights
     * @param  array<string, float>  $components
     * @param  array{score: float, positive: int, negative: int, neutral: int, sample: ?string}|null  $news
     * @param  array<string, mixed>  $reasons
     * @return array{
     *     stock: Stock,
     *     indicators: array<string, float|int|null>,
     *     score: float,
     *     weights: array<string, float>,
     *     components: array<string, float>,
     *     decline_score: float,
     *     reversal_score: float,
     *     volume_score: float,
     *     technical_score: float,
     *     liquidity_score: float,
     *     volatility_score: float,
     *     news_score: ?float,
     *     news: ?array{score: float, positive: int, negative: int, neutral: int, sample: ?string},
     *     reasons: array<string, mixed>,
     *     tradable: bool,
     *     rejection?: string
     * }
     */
    protected function scanResult(
        Stock $stock,
        array $ind,
        float $score,
        array $weights,
        array $components,
        ?float $newsScore,
        ?array $news,
        array $reasons,
        bool $tradable,
        ?string $rejection = null,
    ): array {
        return [
            'stock' => $stock,
            'indicators' => $ind,
            'score' => $score,
            'weights' => $weights,
            'components' => $components,
            'decline_score' => $components['decline'],
            'reversal_score' => $components['reversal'],
            'volume_score' => $components['volume'],
            'technical_score' => $components['technical'],
            'liquidity_score' => $components['liquidity'],
            'volatility_score' => $components['volatility'],
            'news_score' => $newsScore,
            'news' => $news,
            'reasons' => $reasons,
            'tradable' => $tradable,
            'rejection' => $rejection,
        ];
    }
}
