<?php

namespace App\Services;

use App\Models\Stock;
use App\Models\SystemEvent;
use App\Models\TradingSignal;
use Illuminate\Support\Collection;

/**
 * Runs the scanner over a watchlist, applies the market-wide filter and
 * generates / persists TradingSignal rows.
 */
class SignalEngine
{
    public function __construct(
        protected SignalScanner $scanner,
        protected MarketDataService $marketData,
        protected TradingConfigService $config,
    ) {}

    /**
     * @param  Collection<int, Stock>  $stocks  watchlist (defaults to all active stocks)
     * @return array{
     *     generated: array<int, TradingSignal>,
     *     rejected: array<int, array{stock: string, reason: string}>,
     *     marketOk: bool
     * }
     */
    public function run(Collection $stocks, ?string $asOf = null): array
    {
        if ($stocks->isEmpty()) {
            $stocks = Stock::where('active', true)->get();
        }

        $generated = [];
        $rejected = [];
        $marketOk = $this->marketConditionOk();

        foreach ($stocks as $stock) {
            $rows = $this->marketData->dailyData($stock);

            if (! $marketOk) {
                $this->persistRejected($stock, 'market_filter', 'Market filter blocked');
                $rejected[] = ['stock' => $stock->symbol ?? $stock->yfinance_symbol, 'reason' => 'market_filter'];

                continue;
            }

            $setup = $this->scanner->scan($stock, $rows);

            if (! $setup || $setup['tradable'] !== true) {
                $reason = $setup['rejection'] ?? 'no_setup';
                $this->persistRejected($stock, $reason, $setup['reasons']['pullback'] ?? null);
                $rejected[] = ['stock' => $stock->symbol ?? $stock->yfinance_symbol, 'reason' => $reason];

                continue;
            }

            $signal = $this->createSignal($stock, $setup, $asOf);
            $generated[] = $signal;

            SystemEvent::create([
                'type' => 'signal',
                'action' => 'generated',
                'subject_type' => TradingSignal::class,
                'subject_id' => $signal->id,
                'description' => "Signal generated for {$stock->symbol} (score {$setup['score']})",
            ]);
        }

        return compact('generated', 'rejected', 'marketOk');
    }

    protected function marketConditionOk(): bool
    {
        $marketEnabled = $this->config->bool('market.filter_enabled', false);
        if (! $marketEnabled) {
            return true;
        }

        $filter = $this->config->get('market.filter', 'none');
        $custom = $this->config->float('market.custom_index_change', 0);

        switch ($filter) {
            case 'nifty_sensex':
                // Aggregated index proxy: approximated later with real index data.
                $temp = $this->config->get('market.temp_index_change');
                if ($temp === null) {
                    return false; // no index feed yet → conservative block
                }

                return (float) $temp >= $this->config->float('market.min_index_change', -0.5);
            case 'custom':
                return $custom >= $this->config->float('market.min_index_change', -0.5);
            case 'none':
            default:
                return true;
        }
    }

    /**
     * @param  array<string, mixed>  $setup
     */
    protected function createSignal(Stock $stock, array $setup, ?string $asOf): TradingSignal
    {
        $ind = $setup['indicators'];
        $price = (float) $ind['last_close'];

        $declinePct = abs((float) ($ind['dist_from_20d_high'] ?? 0));

        // Risk/target geometry from config (percentages of entry).
        $slPct = $this->config->float('risk.stop_loss_pct', 2);
        $t1Pct = $this->config->float('risk.target1_pct', 3);
        $t2Pct = $this->config->float('risk.target2_pct', 4);
        $t3Pct = $this->config->float('risk.target3_pct', 5);

        $sl = round($price * (1 - $slPct / 100), 2);
        $t1 = round($price * (1 + $t1Pct / 100), 2);
        $t2 = round($price * (1 + $t2Pct / 100), 2);
        $t3 = round($price * (1 + $t3Pct / 100), 2);

        $riskPerShare = $price - $sl;
        $rewardPerShare = $price - $sl; // to T1 as the primary target
        $rr = $rewardPerShare > 0 ? (($t1 - $price) / $riskPerShare) : 0;

        return TradingSignal::updateOrCreate(
            ['stock_id' => $stock->id, 'signal_date' => $asOf ?? today()->toDateString()],
            [
                'price' => $price,
                'score' => $setup['score'],
                'decline_score' => $setup['decline_score'],
                'reversal_score' => $setup['reversal_score'],
                'volume_score' => $setup['volume_score'],
                'technical_score' => $setup['technical_score'],
                'liquidity_score' => $setup['liquidity_score'],
                'volatility_score' => $setup['volatility_score'],
                'news_score' => $setup['news_score'] ?? null,
                'proposed_sl' => $sl,
                'proposed_target1' => $t1,
                'proposed_target2' => $t2,
                'proposed_target3' => $t3,
                'risk_per_share' => $riskPerShare,
                'reward_per_share' => $rewardPerShare,
                'risk_reward_ratio' => round($rr, 2),
                'decline_percent' => $declinePct,
                'entry_reasons' => $setup['reasons'],
                'indicators_at_entry' => $ind,
                'status' => 'candidate',
            ],
        );
    }

    protected function persistRejected(Stock $stock, string $reason, ?string $detail): void
    {
        TradingSignal::updateOrCreate(
            ['stock_id' => $stock->id, 'signal_date' => today()->toDateString()],
            [
                'score' => 0,
                'status' => 'rejected',
                'rejection_reason' => $detail ? "{$reason}: {$detail}" : $reason,
            ],
        );
    }
}
