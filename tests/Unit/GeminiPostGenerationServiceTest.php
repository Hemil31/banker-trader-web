<?php

namespace Tests\Unit;

use App\Contracts\Festival\FestivalProvider;
use App\Contracts\Gemini\GeminiClient;
use App\Exceptions\Gemini\GeminiRetryableException;
use App\Models\AiPostRequest;
use App\Models\TradingConfig;
use App\Models\ZernioAccount;
use App\Services\Gemini\GeminiPostGenerationService;
use App\Services\TradingConfigService;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GeminiPostGenerationServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        TradingConfig::pluckDefaults();
    }

    protected function service(StubGeminiClient $client, ?array $festival = null): GeminiPostGenerationService
    {
        return new GeminiPostGenerationService(
            $client,
            new StubFestivalProvider($festival),
            app(TradingConfigService::class),
        );
    }

    protected function request(): AiPostRequest
    {
        $account = ZernioAccount::factory()->create(['platform' => 'instagram']);

        return AiPostRequest::factory()->create([
            'zernio_account_id' => $account->id,
            'scheduled_date' => '2026-10-20',
            'content_category' => 'promo',
        ]);
    }

    public function test_generate_returns_normalized_content_and_hashes_hashtags(): void
    {
        $client = new StubGeminiClient(json_encode([
            'caption' => 'Great day to trade.',
            'hashtags' => ['trading', '#markets'],
            'cta' => 'Open the app now',
            'content_type' => 'promotional',
        ]));

        $result = $this->service($client)->generate($this->request());

        $this->assertSame('Great day to trade.', $result['caption']);
        $this->assertSame(['#trading', '#markets'], $result['hashtags']);
        $this->assertSame('Open the app now', $result['cta']);
        $this->assertSame('promotional', $result['content_type']);
        $this->assertNull($result['festival_name']);
        $this->assertNull($result['festival_type']);
    }

    public function test_generate_uses_the_authoritative_festival_not_whatever_gemini_echoes_back(): void
    {
        $client = new StubGeminiClient(json_encode([
            'caption' => 'Happy Diwali!',
            'hashtags' => ['diwali'],
            'cta' => 'Shop now',
            'content_type' => 'festival',
            'festival' => 'Made-up Festival', // must be ignored
        ]));

        $result = $this->service($client, ['name' => 'Diwali', 'type' => 'religious'])->generate($this->request());

        $this->assertSame('Diwali', $result['festival_name']);
        $this->assertSame('religious', $result['festival_type']);
    }

    public function test_generate_throws_a_retryable_exception_on_invalid_json(): void
    {
        $client = new StubGeminiClient('not json at all');

        $this->expectException(GeminiRetryableException::class);

        $this->service($client)->generate($this->request());
    }

    public function test_generate_throws_a_retryable_exception_on_missing_fields(): void
    {
        $client = new StubGeminiClient(json_encode(['caption' => 'Hi']));

        $this->expectException(GeminiRetryableException::class);

        $this->service($client)->generate($this->request());
    }

    public function test_prompt_tells_gemini_not_to_invent_a_festival_when_there_is_none(): void
    {
        $client = new StubGeminiClient(json_encode([
            'caption' => 'x', 'hashtags' => ['x'], 'cta' => 'x', 'content_type' => 'x',
        ]));

        $this->service($client)->generate($this->request());

        $this->assertStringContainsString('do not invent or mention a festival', $client->lastPrompt);
        $this->assertStringContainsString('Platform: instagram', $client->lastPrompt);
        $this->assertStringContainsString('Content category: promo', $client->lastPrompt);
    }
}

class StubGeminiClient implements GeminiClient
{
    public ?string $lastPrompt = null;

    public function __construct(protected string $responseText) {}

    public function generate(string $prompt, array $responseSchema = []): array
    {
        $this->lastPrompt = $prompt;

        return ['text' => $this->responseText, 'model' => 'gemini-flash-lite-latest', 'raw' => []];
    }
}

class StubFestivalProvider implements FestivalProvider
{
    /**
     * @param  array{name: string, type: string}|null  $festival
     */
    public function __construct(protected ?array $festival) {}

    public function forDate(DateTimeInterface $date): ?array
    {
        return $this->festival;
    }
}
