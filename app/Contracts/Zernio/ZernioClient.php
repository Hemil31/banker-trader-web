<?php

namespace App\Contracts\Zernio;

use DateTimeInterface;

/**
 * Boundary between the app and the Zernio social publishing API. Implementations
 * (e.g. the generated `zernio-dev/zernio-php` SDK) normalize Zernio's models
 * into plain arrays so the rest of the app never depends on the SDK. This lets
 * us swap the transport, or extend it with more Zernio features, without
 * touching controllers/services.
 *
 * Account shape (listAccounts): {
 *   id, platform, name, username, avatar_url, is_active, needs_reconnection, raw *
 * }
 * Post shape (create/get): {
 *   id, status, content, platforms: [{platform, account_id, status, platform_post_url}]
 * }
 * Presigned upload shape (requestPresignedUpload): {
 *   upload_url, public_url, key, expires_in
 * }
 */
interface ZernioClient
{
    /**
     * Fetch every connected social account from Zernio.
     *
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(): array;

    /**
     * Create (or schedule) a post on Zernio for the given platforms/accounts.
     *
     * @param  array<int, array{platform: string, account_id: string}>  $targets
     * @param  array<int, array{url: string, type?: string}>  $media
     * @return array<string, mixed>
     */
    public function createPost(
        string $content,
        array $targets,
        array $media = [],
        ?DateTimeInterface $scheduledAt = null,
        ?string $timezone = null,
        ?string $idempotencyKey = null,
    ): array;

    /**
     * Fetch a single post (and its per-platform delivery results) by Zernio id.
     *
     * @return array<string, mixed>
     */
    public function getPost(string $postId): array;

    /**
     * Ask Zernio for a presigned upload target for a media file. The caller
     * PUTs the raw bytes to `upload_url`, then uses `public_url` as the media
     * url in createPost().
     *
     * @return array<string, mixed>
     */
    public function requestPresignedUpload(string $filename, string $contentType, int $size = 0): array;
}
