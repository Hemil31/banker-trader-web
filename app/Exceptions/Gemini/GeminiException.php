<?php

namespace App\Exceptions\Gemini;

use RuntimeException;

/**
 * Raised when a Gemini API call fails in a way that is not worth retrying
 * (bad request, invalid API key, response failed validation after all
 * repair attempts). GenerateAiPostJob fails the request immediately on this,
 * without burning through its retry budget.
 */
class GeminiException extends RuntimeException {}
