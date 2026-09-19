<?php

namespace Tests\Unit;

use App\Contracts\Gemini\GeminiClient;
use App\Exceptions\Gemini\GeminiException;
use App\Services\Gemini\HttpGeminiClient;
use App\Services\TradingConfigService;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class HttpGeminiClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config(['gemini.api_key' => 'test-gemini-key']);
    }

    protected function client(): GeminiClient
    {
        return new HttpGeminiClient(app(TradingConfigService::class));
    }

    public function test_generate_image_decodes_inline_data_into_raw_bytes(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [
                        ['inlineData' => ['mimeType' => 'image/png', 'data' => base64_encode('png-bytes')]],
                    ]]],
                ],
            ], 200),
        ]);

        $result = $this->client()->generateImage('A premium trading post image.');

        $this->assertSame('png-bytes', $result['bytes']);
        $this->assertSame('image/png', $result['mime']);
        $this->assertSame('gemini-2.5-flash-image', $result['model']);
    }

    public function test_generate_image_throws_when_the_response_has_no_image(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'candidates' => [
                    ['content' => ['parts' => [['text' => 'Here is your image!']]]],
                ],
            ], 200),
        ]);

        $this->expectException(GeminiException::class);
        $this->expectExceptionMessage('no image data');

        $this->client()->generateImage('A premium trading post image.');
    }

    public function test_generate_image_maps_failures_to_gemini_exceptions(): void
    {
        Http::fake([
            'https://generativelanguage.googleapis.com/*' => Http::response([
                'error' => ['message' => 'image model disabled'],
            ], 400),
        ]);

        $this->expectException(GeminiException::class);
        $this->expectExceptionMessage('image model disabled');

        $this->client()->generateImage('A premium trading post image.');
    }
}
