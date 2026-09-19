<?php

namespace App\Exceptions;

use RuntimeException;
use Zernio\ApiException as ZernioApiException;

/**
 * Raised when a Zernio API call fails (network, auth, validation or upstream
 * error). Carries the HTTP status and, when available, the parsed error
 * message from the JSON error envelope.
 */
class ZernioException extends RuntimeException
{
    public static function fromApiException(ZernioApiException $e): self
    {
        $message = $e->getMessage();

        $body = $e->getResponseBody();
        if (is_string($body) && $body !== '') {
            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $message = (string) ($decoded['error'] ?? $message);
                if (isset($decoded['code'])) {
                    $message .= " (code: {$decoded['code']})";
                }
            }
        }

        return new self($message, (int) $e->getCode(), $e);
    }
}
