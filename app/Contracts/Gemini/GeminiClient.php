<?php

namespace App\Contracts\Gemini;

use App\Exceptions\Gemini\GeminiException;
use App\Exceptions\Gemini\GeminiRetryableException;

/**
 * Boundary between the app and the Google Gemini API. Implementations
 * (e.g. HttpGeminiClient) normalize Gemini's response into a plain array so
 * the rest of the app never depends on the HTTP/SDK shape — mirrors
 * ZernioClient / NewsProvider in this codebase.
 *
 * Implementations must distinguish retryable failures (HTTP 429 rate limit,
 * transient 5xx) by throwing App\Exceptions\Gemini\GeminiRetryableException,
 * so GenerateAiPostJob knows which failures are worth another attempt.
 */
interface GeminiClient
{
    /**
     * Ask the configured Gemini model to generate content for a prompt,
     * requesting a JSON object back where practical.
     *
     * @param  array<string, mixed>  $responseSchema  Gemini `responseSchema` (structured output)
     * @return array{text: string, model: string, raw: array<string, mixed>}
     *
     * @throws GeminiRetryableException on HTTP 429 / 5xx
     * @throws GeminiException on any other failure
     */
    public function generate(string $prompt, array $responseSchema = []): array;

    /**
     * Ask the configured Gemini image model to render a fresh image for a
     * prompt — replaces any static/placeholder artwork: every post gets a
     * unique, brand-styled image generated from its own content.
     *
     * @return array{bytes: string, mime: string, model: string}
     *                                                           Raw image bytes ready for upload (already decoded,
     *                                                           never base64), the image MIME type, and the model id
     *
     * @throws GeminiRetryableException on HTTP 429 / 5xx
     * @throws GeminiException on any other failure
     */
    public function generateImage(string $prompt): array;
}
