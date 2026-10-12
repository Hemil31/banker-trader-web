<?php

namespace App\Console\Commands;

use App\Models\Backtest;
use App\Models\MarketData;
use App\Models\Stock;
use App\Services\StrategyBacktester;
use Illuminate\Console\Command;

/**
 * Runs every registered entry strategy over the same walk-forward window and
 * ranks them so the best candidate for permanent deployment is obvious.
 */
class StrategyCompare extends Command
{
    protected $signature = 'trader:strategies
        {--from= : Start date (Y-m-d); defaults to earliest stored bar}
        {--to= : End date (Y-m-d); defaults to latest stored bar}
        {--capital= : Starting capital; defaults to risk.capital config}
        {--no-persist : Do not write results to the backtests table}';

    protected $description = 'Backtest 10 strategy types and rank which is best for permanent use';

    public function handle(StrategyBacktester $engine): int
    {
        $stocks = Stock::where('active', true)->where('in_watchlist', true)->orderBy('symbol')->get();

        if ($stocks->isEmpty()) {
            $this->error('No active watchlist stocks found.');

            return self::FAILURE;
        }

        $from = $this->option('from') ?? MarketData::min('trade_date');
        $to = $this->option('to') ?? MarketData::max('trade_date');

        if (! $from || ! $to || $from > $to) {
            $this->error('Invalid or empty date range — run `php artisan market:ingest` first.');

            return self::FAILURE;
        }

        $strategyCount = count($engine->strategies());
        $this->info("Comparing {$strategyCount} strategies over {$stocks->count()} stocks");
        $this->line("Period: {$from} → {$to}");

        $progress = $this->output->createProgressBar($strategyCount);
        $progress->start();

        $results = $engine->run($stocks, $from, $to, array_filter([
            'capital' => $this->option('capital') ? (float) $this->option('capital') : null,
            'persist' => ! $this->option('no-persist'),
        ], fn ($v) => $v !== null));

        $progress->finish();
        $this->newLine(2);

        $rows = [];
        foreach ($engine->strategies() as $strategy) {
            $bt = $results[$strategy->key()] ?? null;
            if ($bt === null) {
                continue;
            }
            $rows[] = [
                'key' => $strategy->key(),
                'name' => $strategy->name(),
                'bt' => $bt,
                'score' => $this->score($bt),
                'eligible' => $this->eligible($bt),
            ];
        }

        usort($rows, fn ($a, $b) => $b['score'] <=> $a['score']);

        $this->renderTable($rows);
        $this->renderVerdict($rows);

        return self::SUCCESS;
    }

    /**
     * Composite 0-100 score: growth 40, profit factor 25, win rate 15, drawdown 20.
     */
    protected function score(Backtest $bt): float
    {
        $cagr = (float) $bt->cagr;
        $pf = $bt->profit_factor === null ? 3.0 : min((float) $bt->profit_factor, 3.0);
        $wr = (float) $bt->win_rate;
        $dd = (float) $bt->max_drawdown_pct;

        $cagrScore = max(-1.0, min(1.0, $cagr / 40));
        $pfScore = max(0.0, min(1.0, $pf / 3));
        $wrScore = max(0.0, min(1.0, $wr / 100));
        $ddScore = max(0.0, min(1.0, 1 - $dd / 40));

        return round($cagrScore * 40 + $pfScore * 25 + $wrScore * 15 + $ddScore * 20, 1);
    }

    protected function eligible(Backtest $bt): bool
    {
        $pf = $bt->profit_factor === null ? 3.0 : (float) $bt->profit_factor;

        return (int) $bt->total_trades >= 20
            && (float) $bt->net_profit > 0
            && $pf > 1.2
            && (float) $bt->max_drawdown_pct < 25;
    }

    /**
     * @param  list<array{key: string, name: string, bt: Backtest, score: float, eligible: bool}>  $rows
     */
    protected function renderTable(array $rows): void
    {
        $table = array_map(fn ($r) => [
            $r['eligible'] ? '*' : '',
            number_format($r['score'], 1),
            $r['key'],
            (string) $r['bt']->total_trades,
            number_format((float) $r['bt']->win_rate, 1).'%',
            $r['bt']->profit_factor === null ? 'INF' : number_format((float) $r['bt']->profit_factor, 2),
            number_format((float) $r['bt']->cagr, 1).'%',
            '₹'.number_format((float) $r['bt']->net_profit, 0),
            number_format((float) $r['bt']->max_drawdown_pct, 1).'%',
            number_format((float) $r['bt']->avg_holding_days, 1),
        ], $rows);

        $this->table(
            ['OK', 'Score', 'Strategy', 'Trades', 'WinRate', 'PF', 'CAGR', 'Net ₹', 'MaxDD', 'AvgHold'],
            $table
        );

        $this->line('* = eligible for permanent deployment (>=20 trades, net>0, PF>1.2, DD<25%). All results persisted to `backtests` (name = strategy:<key>).');
    }

    /**
     * @param  list<array{key: string, name: string, bt: Backtest, score: float, eligible: bool}>  $rows
     */
    protected function renderVerdict(array $rows): void
    {
        $this->newLine();
        $this->line('=== BEST SUITED FOR PERMANENT USE ===');

        $eligible = array_values(array_filter($rows, fn ($r) => $r['eligible']));

        if ($eligible === []) {
            $best = $rows[0] ?? null;
            $this->error('No strategy cleared the deployment bar.');
            if ($best !== null) {
                $this->warn(sprintf('Best of a weak field: %s (score %.1f) — candidates still lose or are too volatile; do not deploy yet.',
                    $best['name'], $best['score']));
            }

            return;
        }

        $best = $eligible[0];
        $bt = $best['bt'];

        // Runner-up for comparison.
        $runnerUp = $eligible[1] ?? null;

        $this->info(sprintf('%s  [%s]', $best['name'], $best['key']));
        $this->line(sprintf(
            'CAGR %s%% | PF %s | WinRate %.1f%% | MaxDD %.1f%% | Trades %s | Net ₹%s',
            number_format((float) $bt->cagr, 1),
            $bt->profit_factor === null ? 'INF' : number_format((float) $bt->profit_factor, 2),
            (float) $bt->win_rate,
            (float) $bt->max_drawdown_pct,
            (string) $bt->total_trades,
            number_format((float) $bt->net_profit, 0),
        ));

        if ($runnerUp !== null) {
            $this->line(sprintf('Runner-up: %s (score %.1f vs %.1f).', $runnerUp['name'], $runnerUp['score'], $best['score']));
        }

        $this->line('Recommendation: deploy this strategy as the permanent entry rule; keep the runner-up as a secondary sleeve.');
    }
}
