<?php

namespace App\Console\Commands;

use App\Jobs\GenerateAiPostJob;
use App\Models\AiPostRequest;
use App\Models\ZernioAccount;
use App\Services\TradingConfigService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Ensures an AiPostRequest slot exists for every (date, scheduled_time, active
 * Zernio account) in the requested range, then dispatches GenerateAiPostJob for
 * every slot still needing work (pending, or failed — a manual retry).
 *
 * Every newly created slot gets an automated "prompt first" brief: a rolling
 * content category (ctype), a post title (the name), and a direction prompt —
 * unless overridden with --category/--title/--prompt. The same (date, ctype)
 * slot is re-discovered on later runs, so this stays idempotent even with two
 * slots per day (morning + evening) as long as their auto categories differ.
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
        {--time=09:00:00 : scheduled_time to assign to newly created slots}
        {--category= : Content category for new slots (defaults to a rolling auto-pick)}
        {--title= : Post title/name for new slots (defaults to an auto title)}
        {--prompt= : Gemini direction text for new slots (defaults to an auto prompt)}
        {--retry-failed : Also (re-)dispatch slots currently marked failed}';

    protected $description = 'Ensure AI post-generation slots (with prompt/title briefs) exist for a date range and queue Gemini generation for them';

    /** Content categories rotated across the daily slots, in order. */
    protected const CATEGORIES = ['engagement', 'educational', 'promotional', 'festival'];

    public function handle(TradingConfigService $config): int
    {
        [$from, $to] = $this->resolveRange();
        if ($from === null) {
            $this->error('Invalid date option(s).');

            return self::FAILURE;
        }

        $time = $this->normalizeTime((string) $this->option('time'));
        $slotIndex = $time < '12:00:00' ? 0 : 1;
        $accounts = ZernioAccount::where('is_active', true)->get();

        if ($accounts->isEmpty()) {
            $this->warn('No active Zernio accounts found — nothing to generate for. Run `admin/zernio/accounts/sync` first.');

            return self::SUCCESS;
        }

        $created = 0;
        $queued = 0;

        for ($date = $from->copy(); $date->lte($to); $date->addDay()) {
            $brief = $this->brief($config, $date, $slotIndex);

            foreach ($accounts as $account) {
                $request = AiPostRequest::firstOrCreate(
                    [
                        'scheduled_date' => $date->toDateString(),
                        'zernio_account_id' => $account->id,
                        'content_category' => $brief['content_category'],
                    ],
                    [
                        'scheduled_time' => $time,
                        'title' => $brief['title'],
                        'prompt' => $brief['prompt'],
                        'status' => AiPostRequest::STATUS_PENDING,
                    ],
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

        $this->info("Slots ensured for {$from->toDateString()} .. {$to->toDateString()} at {$time} across {$accounts->count()} account(s): {$created} created, {$queued} queued for generation.");

        return self::SUCCESS;
    }

    /**
     * @return array{content_category: string, title: string, prompt: string}
     */
    protected function brief(TradingConfigService $config, Carbon $date, int $slotIndex): array
    {
        $category = (string) ($this->option('category') ?: self::CATEGORIES[($date->dayOfYear - 1 + $slotIndex) % count(self::CATEGORIES)]);
        $brand = (string) $config->get('content.brand_name', '');
        $audience = (string) $config->get('content.target_audience', 'general audience');
        $description = (string) $config->get('content.business_description', '');

        $title = (string) $this->option('title');
        if ($title === '') {
            $label = ucfirst($category);
            $title = trim($brand.' · '.$date->format('l').' '.$label);
        }

        $prompt = (string) $this->option('prompt');
        if ($prompt === '') {
            $prompt = $this->directionFor($category, $brand, $audience, $description);
        }

        return [
            'content_category' => $category,
            'title' => $title,
            'prompt' => $prompt,
        ];
    }

    protected function directionFor(string $category, string $brand, string $audience, string $description): string
    {
        $context = $description !== '' ? " {$description}" : '';

        return match ($category) {
            'engagement' => "Write a conversational post that starts a discussion with {$audience}: ask one sharp, open-ended trading or market question related to the day's theme, invite replies, keep it light and interactive.{$context}",
            'educational' => "Teach {$audience} one practical trading or market concept in simple language, with a concrete example they can apply that day. Make it genuinely useful, not generic.{$context}",
            'promotional' => "Show why {$brand} is useful to {$audience}: one concrete benefit plus a specific, non-salesy reason to start this week.{$context}",
            default => "Mark today's occasion in a way that fits {$brand} — a confident, on-brand post for {$audience}{$context}",
        };
    }

    protected function normalizeTime(string $time): string
    {
        if (preg_match('/^(2[0-3]|[01]\d):[0-5]\d(?::[0-5]\d)?$/', $time) === 1) {
            return strlen($time) === 5 ? $time.':00' : $time;
        }

        return '09:00:00';
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
