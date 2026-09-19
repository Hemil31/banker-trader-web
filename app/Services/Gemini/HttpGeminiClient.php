<?php

namespace App\Services\Gemini;

use App\Contracts\Gemini\GeminiClient;
use App\Exceptions\Gemini\GeminiException;
use App\Exceptions\Gemini\GeminiRetryableException;
use App\Services\TradingConfigService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * REST adapter over the Google Gemini "generateContent" endpoint
 * (X-goog-api-key header, per Google's documented API). Normalizes the
 * response into a plain array so callers (GeminiPostGenerationService) never
 * depend on Gemini's response shape — mirrors SdkZernioClient /
 * FreeNewsApiProvider in this codebase.
 *
 * The API key resolves as trading_configs `gemini.api_key` first (rotatable
 * from the admin Integration settings) with an env fallback (GEMINI_API_KEY).
 */
class HttpGeminiClient implements GeminiClient
{
    public function __construct(protected TradingConfigService $config) {}

    protected function apiKey(): string
    {
        return (string) ($this->config->get('gemini.api_key') ?: config('gemini.api_key'));
    }

    protected function model(): string
    {
        return (string) ($this->config->get('gemini.model') ?: config('gemini.model', 'gemini-flash-lite-latest'));
    }

    /**
     * @param  array<string, mixed>  $responseSchema
     */
    public function generate(string $prompt, array $responseSchema = []): array
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new GeminiException('Gemini API key is not configured (set gemini.api_key or GEMINI_API_KEY).');
        }

        $model = $this->model();

        $generationConfig = ['responseMimeType' => 'application/json'];
        if ($responseSchema !== []) {
            $generationConfig['responseSchema'] = $responseSchema;
        }

        $payload = [
            'contents' => [
                ['parts' => [['text' => $prompt]]],
            ],
            'generationConfig' => $generationConfig,
        ];

        try {
            $response = Http::timeout((int) config('gemini.timeout', 30))
                ->connectTimeout((int) config('gemini.connect_timeout', 5))
                ->withHeaders([
                    'X-goog-api-key' => $key,
                    'Content-Type' => 'application/json',
                ])
                ->post(
                    rtrim((string) config('gemini.api_base', 'https://generativelanguage.googleapis.com/v1beta'), '/')
                        ."/models/{$model}:generateContent",
                    $payload,
                );
        } catch (ConnectionException $e) {
            throw new GeminiRetryableException("Gemini request failed to connect: {$e->getMessage()}", 0, $e);
        } catch (Throwable $e) {
            throw new GeminiException("Gemini request failed: {$e->getMessage()}", 0, $e);
        }

        if ($response->status() === 429) {
            throw new GeminiRetryableException('Gemini rate limit / quota exceeded (HTTP 429): '.$this->errorMessage($response));
        }

        if ($response->serverError()) {
            throw new GeminiRetryableException("Gemini server error (HTTP {$response->status()}): ".$this->errorMessage($response));
        }

        if ($response->failed()) {
            throw new GeminiException("Gemini request failed (HTTP {$response->status()}): ".$this->errorMessage($response));
        }

        $body = (array) $response->json();
        $text = $this->extractText($body);

        if ($text === '') {
            throw new GeminiException('Gemini response contained no content.');
        }

        return ['text' => $text, 'model' => $model, 'raw' => $body];
    }

    /**
     * @param  array<string, mixed>  $body
     */
    protected function extractText(array $body): string
    {
        $parts = $body['candidates'][0]['content']['parts'] ?? [];
        if (! is_array($parts)) {
            return '';
        }

        $texts = array_filter(array_map(
            fn (mixed $part): string => is_array($part) ? (string) ($part['text'] ?? '') : '',
            $parts,
        ));

        return implode('', $texts);
    }

    protected function errorMessage(Response $response): string
    {
        $decoded = $response->json();
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? null;
            if (is_string($message) && $message !== '') {
                return $message;
            }
        }

        return $response->body();
    }
}
