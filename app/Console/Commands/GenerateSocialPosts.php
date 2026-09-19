<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiPostJob;
use App\Models\AiPostRequest;
use App\Models\ZernioAccount;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ensures an AiPostRequest slot exists for every (date, active Zernio
 * account) in the requested range, then dispatches GenerateAiPostJob for
 * every slot still needing work (pending, or failed — a manual retry).
 *
 * Dispatching all of them up front is safe: GenerateAiPostJob's queue
 * middleware (RateLimited + WithoutOverlapping) is what actually paces the
 * Gemini calls to the configured model's RPM, one at a time — this command
 * never calls Gemini directly.
 */
class GenerateSocialPosts extends Command
{
    protected $signature = 'posts:generate
        {--date= : Generate for a single date (Y-m-d)}
        {--from= : Start of a date range (Y-m-d), defaults to today}
        {--to= : End of a date range (Y-m-d), defaults to --from}
        {--today : Shortcut for --date=today}
        {--tomorrow : Shortcut for --date=tomorrow}
        {--category=general : Content category to use for newly created slots}
        {--retry-failed : Also (re-)dispatch slots currently marked failed}';

    protected $description = 'Ensure AI post-generation slots exist for a date range and queue Gemini generation for them';

    public function handle(): int
    {
        [$from, $to] = $this->resolveRange();
        if ($from === null) {
            $this->error('Invalid date option(s).');

            return self::FAILURE;
        }

        $category = (string) $this->option('category');
        $accounts = ZernioAccount::where('is_active', true)->get();

        if ($accounts->isEmpty()) {
            $this->warn('No active Zernio accounts found — nothing to generate for. Run `admin/zernio/accounts/sync` first.');

            return self::SUCCESS;
        }

        $created = 0;
        $queued = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            foreach ($accounts as $account) {
                $request = AiPostRequest::firstOrCreate(
                    [
                        'scheduled_date' => $date->toDateString(),
                        'zernio_account_id' => $account->id,
                        'content_category' => $category,
                    ],
                    ['status' => AiPostRequest::STATUS_PENDING],
                );

                if ($request->wasRecentlyCreated) {
                    $created++;
                }

                $dispatchable = $request->status === AiPostRequest::STATUS_PENDING
                    || ($request->status === AiPostRequest::STATUS_FAILED && $this->option('retry-failed'));

                if ($dispatchable) {
                    GenerateAiPostJob::dispatch($request->id);
                    $queued++;
                }
            }
        }

        $this->info("Slots ensured for {$from->toDateString()} .. {$to->toDateString()} across {$accounts->count()} account(s): {$created} created, {$queued} queued for generation.");

        return self::SUCCESS;
    }

    /**
     * @return array{0: ?Carbon, 1: ?Carbon}
     */
    protected function resolveRange(): array
    {
        if ($this->option('today')) {
            $date = Carbon::today();

            return [$date, $date];
        }

        if ($this->option('tomorrow')) {
            $date = Carbon::tomorrow();

            return [$date, $date];
        }

        if ($this->option('date')) {
            try {
                $date = Carbon::parse((string) $this->option('date'))->startOfDay();
            } catch (\Throwable) {
                return [null, null];
            }

            return [$date, $date];
        }

        try {
            $from = Carbon::parse((string) ($this->option('from') ?: 'today'))->startOfDay();
            $to = Carbon::parse((string) ($this->option('to') ?: $from->toDateString()))->startOfDay();
        } catch (\Throwable) {
            return [null, null];
        }

        if ($to->lt($from)) {
            return [null, null];
        }

        return [$from, $to];
    }
}
