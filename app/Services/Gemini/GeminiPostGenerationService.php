<?php

namespace App\Services\Gemini;

use App\Contracts\Festival\FestivalProvider;
use App\Contracts\Gemini\GeminiClient;
use App\Exceptions\Gemini\GeminiRetryableException;
use App\Models\AiPostRequest;
use App\Services\TradingConfigService;
use Carbon\CarbonImmutable;

/**
 * Builds the structured Gemini prompt for one AiPostRequest slot (brand info,
 * date/day/month, festival if any, category, platform, tone, hashtag/CTA
 * requirements), calls GeminiClient, and validates the JSON response before
 * it is ever saved. This is the only class that knows the Gemini prompt
 * shape — GenerateAiPostJob just calls generate() and persists the result.
 */
class GeminiPostGenerationService
{
    public function __construct(
        protected GeminiClient $client,
        protected FestivalProvider $festivals,
        protected TradingConfigService $config,
    ) {}

    /**
     * @return array{
     *     caption: string,
     *     hashtags: array<int, string>,
     *     cta: string,
     *     content_type: string,
     *     festival_name: ?string,
     *     festival_type: ?string,
     *     model: string,
     *     raw: array<string, mixed>,
     * }
     */
    public function generate(AiPostRequest $request): array
    {
        $date = $request->scheduled_date;
        $festival = $this->festivals->forDate($date);
        $platform = $request->zernioAccount->platform;

        $prompt = $this->buildPrompt($request, $date, $platform, $festival);

        $result = $this->client->generate($prompt, $this->responseSchema());

        $decoded = $this->validate($result['text']);

        return [
            'caption' => $decoded['caption'],
            'hashtags' => $decoded['hashtags'],
            'cta' => $decoded['cta'],
            'content_type' => $decoded['content_type'],
            // Never trust the model for which festival it is — we told it,
            // so we echo back what we told it, not what it repeated.
            'festival_name' => $festival['name'] ?? null,
            'festival_type' => $festival['type'] ?? null,
            'model' => $result['model'],
            'raw' => $result['raw'],
        ];
    }

    /**
     * @param  array{name: string, type: string}|null  $festival
     */
    protected function buildPrompt(AiPostRequest $request, CarbonImmutable $date, string $platform, ?array $festival): string
    {
        $brandName = (string) $this->config->get('content.brand_name', 'our brand');
        $businessDescription = (string) $this->config->get('content.business_description', '');
        $targetAudience = (string) $this->config->get('content.target_audience', 'general audience');
        $tone = (string) $this->config->get('content.tone', 'friendly and professional');
        $defaultHashtags = (array) $this->config->get('content.default_hashtags', []);

        $lines = [
            'You are a social media copywriter. Write ONE social media post based on the brief below.',
            '',
            '# Brand',
            "Name: {$brandName}",
            $businessDescription !== '' ? "Description: {$businessDescription}" : null,
            "Target audience: {$targetAudience}",
            '',
            '# Schedule',
            'Date: '.$date->toDateString(),
            'Day: '.$date->format('l'),
            'Month: '.$date->format('F'),
            'Platform: '.$platform,
            'Content category: '.$request->content_category,
            'Post title/name: '.($request->title ?: ucfirst($request->content_category)),
        ];

        if ($request->prompt) {
            $lines[] = '';
            $lines[] = '# Direction';
            $lines[] = 'Write the post around this direction: '.$request->prompt;
        }

        if ($festival !== null) {
            $lines[] = '';
            $lines[] = '# Festival / event';
            $lines[] = "Today is {$festival['name']} ({$festival['type']}). The post MUST be themed around this festival/event while staying true to the brand above.";
        } else {
            $lines[] = '';
            $lines[] = '# Festival / event';
            $lines[] = 'No festival or special event falls on this date. Write a normal, on-brand post for the content category above instead — do not invent or mention a festival.';
        }

        $lines[] = '';
        $lines[] = '# Requirements';
        $lines[] = "Tone: {$tone}.";
        $lines[] = 'Caption: 1-3 short paragraphs, no markdown, no emojis unless natural for the tone.';
        $lines[] = 'Hashtags: 3-8 relevant hashtags (each starting with #, no spaces), do not repeat the brand name as a hashtag more than once.';
        if ($defaultHashtags !== []) {
            $lines[] = 'Always include these hashtags if relevant: '.implode(', ', $defaultHashtags).'.';
        }
        $lines[] = 'CTA: one short call-to-action sentence appropriate for the platform.';
        $lines[] = '';
        $lines[] = 'Respond with ONLY a JSON object matching this shape, no other text:';
        $lines[] = '{"caption": string, "hashtags": string[], "cta": string, "content_type": string}';
        $lines[] = 'content_type should be a short label such as "festival", "promotional", "engagement", or "educational".';

        return implode("\n", array_filter($lines, fn (?string $line): bool => $line !== null));
    }

    /**
     * @return array<string, mixed>
     */
    protected function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'caption' => ['type' => 'STRING'],
                'hashtags' => ['type' => 'ARRAY', 'items' => ['type' => 'STRING']],
                'cta' => ['type' => 'STRING'],
                'content_type' => ['type' => 'STRING'],
            ],
            'required' => ['caption', 'hashtags', 'cta', 'content_type'],
        ];
    }

    /**
     * @return array{caption: string, hashtags: array<int, string>, cta: string, content_type: string}
     */
    protected function validate(string $text): array
    {
        $decoded = json_decode($text, true);

        if (! is_array($decoded)) {
            throw new GeminiRetryableException('Gemini response was not valid JSON.');
        }

        $caption = trim((string) ($decoded['caption'] ?? ''));
        $cta = trim((string) ($decoded['cta'] ?? ''));
        $contentType = trim((string) ($decoded['content_type'] ?? ''));
        $hashtags = array_values(array_filter(array_map(
            fn (mixed $tag): string => trim((string) $tag),
            (array) ($decoded['hashtags'] ?? []),
        ), fn (string $tag): bool => $tag !== ''));

        if ($caption === '' || $cta === '' || $contentType === '' || $hashtags === []) {
            throw new GeminiRetryableException('Gemini response was missing required fields (caption/hashtags/cta/content_type).');
        }

        $hashtags = array_map(
            fn (string $tag): string => str_starts_with($tag, '#') ? $tag : '#'.ltrim($tag, '#'),
            $hashtags,
        );

        return [
            'caption' => $caption,
            'hashtags' => $hashtags,
            'cta' => $cta,
            'content_type' => $contentType,
        ];
    }
}
