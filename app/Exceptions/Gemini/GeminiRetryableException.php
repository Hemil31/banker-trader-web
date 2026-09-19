<?php

namespace App\Exceptions\Gemini;

/**
 * Raised for a Gemini failure worth retrying: HTTP 429 (rate limit / quota)
 * or a transient 5xx. GenerateAiPostJob rethrows this so Laravel's queue
 * retry + backoff() takes over, instead of failing the request outright.
 */
class GeminiRetryableException extends GeminiException {}
