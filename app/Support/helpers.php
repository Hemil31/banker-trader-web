<?php

if (! function_exists('base64_url_encode')) {
    /**
     * URL-safe Base64 encoding (no padding, matches most OAuth conventions).
     */
    function base64_url_encode(string $data): string
    {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }
}

if (! function_exists('base64_url_decode')) {
    /**
     * Decode a URL-safe Base64 string (adds back padding if missing).
     */
    function base64_url_decode(string $data): string
    {
        return base64_decode(strtr($data, '-_', '+/'), true) ?: '';
    }
}
