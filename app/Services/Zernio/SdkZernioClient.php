<?php

namespace App\Services\Zernio;

use App\Contracts\Zernio\ZernioClient;
use App\Exceptions\ZernioException;
use App\Services\TradingConfigService;
use DateTimeInterface;
use GuzzleHttp\Client;
use Zernio\Api\AccountsApi;
use Zernio\Api\MediaApi;
use Zernio\Api\PostsApi;
use Zernio\ApiException as ZernioApiException;
use Zernio\Configuration;
use Zernio\Model\AccountsListResponse;
use Zernio\Model\CreatePost200Response;
use Zernio\Model\CreatePostRequest;
use Zernio\Model\CreatePostRequestPlatformsInner;
use Zernio\Model\GetMediaPresignedUrl200Response;
use Zernio\Model\GetMediaPresignedUrlRequest;
use Zernio\Model\MediaItem;
use Zernio\Model\PlatformTarget;
use Zernio\Model\Post;
use Zernio\Model\PostCreateResponse;
use Zernio\Model\PostGetResponse;
use Zernio\Model\PostPublishIncompleteResponse;
use Zernio\Model\SocialAccount;

/**
 * Zernio adapter over the generated `zernio-dev/zernio-php` SDK. Normalizes the
 * SDK types into plain arrays so callers (ZernioService, controllers) never
 * depend on the generated classes — swap the SDK out and only this class
 * changes.
 *
 * The API key resolves as trading_configs `zernio.api_key` first (rotatable
 * from the admin Integration settings) with an env fallback (ZERNIO_API_KEY),
 * mirroring FreeNewsApiProvider.
 */
class SdkZernioClient implements ZernioClient
{
    public function __construct(protected TradingConfigService $config) {}

    protected function apiKey(): string
    {
        return (string) ($this->config->get('zernio.api_key') ?: config('zernio.api_key'));
    }

    protected function configuration(): Configuration
    {
        $key = $this->apiKey();
        if ($key === '') {
            throw new ZernioException('Zernio API key is not configured (set zernio.api_key or ZERNIO_API_KEY).');
        }

        $configuration = Configuration::getDefaultConfiguration();
        $configuration->setHost((string) config('zernio.api_base', 'https://zernio.com/api'));
        $configuration->setAccessToken($key);
        // The Zernio API expects "true"/"false" string query booleans; the SDK
        // defaults to 0/1 integers, which the server rejects.
        $configuration->setBooleanFormatForQueryString(Configuration::BOOLEAN_FORMAT_STRING);

        return $configuration;
    }

    protected function accountsApi(): AccountsApi
    {
        return new AccountsApi(new Client([
            'timeout' => (float) config('zernio.timeout', 15),
            'connect_timeout' => (float) config('zernio.connect_timeout', 5),
        ]), $this->configuration());
    }

    protected function postsApi(): PostsApi
    {
        return new PostsApi(new Client([
            'timeout' => (float) config('zernio.timeout', 15),
            'connect_timeout' => (float) config('zernio.connect_timeout', 5),
        ]), $this->configuration());
    }

    protected function mediaApi(): MediaApi
    {
        return new MediaApi(new Client([
            'timeout' => (float) config('zernio.timeout', 15),
            'connect_timeout' => (float) config('zernio.connect_timeout', 5),
        ]), $this->configuration());
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function listAccounts(): array
    {
        try {
            $response = $this->accountsApi()->listAccounts();
        } catch (ZernioApiException $e) {
            throw ZernioException::fromApiException($e);
        }

        if (! $response instanceof AccountsListResponse) {
            throw new ZernioException('Unexpected Zernio response for account list.');
        }

        return array_map(
            fn (SocialAccount $account): array => $this->normalizeAccount($account),
            (array) $response->getAccounts(),
        );
    }

    /**
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
    ): array {
        $request = (new CreatePostRequest)
            ->setContent($content)
            ->setPublishNow($scheduledAt === null)
            ->setPlatforms(array_map(
                fn (array $target): CreatePostRequestPlatformsInner => (new CreatePostRequestPlatformsInner)
                    ->setPlatform((string) $target['platform'])
                    ->setAccountId((string) $target['account_id']),
                $targets,
            ));

        if (count($media) > 0) {
            $request->setMediaItems(array_map(
                function (array $item): MediaItem {
                    $mediaItem = (new MediaItem)->setUrl((string) $item['url']);
                    if (isset($item['type']) && $item['type'] !== '') {
                        $mediaItem->setType((string) $item['type']);
                    }

                    return $mediaItem;
                },
                $media,
            ));
        }

        if ($scheduledAt !== null) {
            $request->setScheduledFor(\DateTime::createFromInterface($scheduledAt));
        }

        if ($timezone !== null) {
            $request->setTimezone($timezone);
        }

        try {
            $response = $this->postsApi()->createPost($request, $idempotencyKey);
        } catch (ZernioApiException $e) {
            throw ZernioException::fromApiException($e);
        }

        // 200 is the dry-run preflight response (only when dryRun=true),
        // 201 the publish success and 207 "created but publishing failed"
        // (platform statuses carry the errors).
        if ($response instanceof PostCreateResponse || $response instanceof PostPublishIncompleteResponse) {
            return $this->normalizePost($response->getPost());
        }

        if ($response instanceof CreatePost200Response) {
            return $this->normalizePost($response->getPost());
        }

        throw new ZernioException('Unexpected Zernio response for post creation.');
    }

    /**
     * @return array<string, mixed>
     */
    public function getPost(string $postId): array
    {
        try {
            $response = $this->postsApi()->getPost($postId);
        } catch (ZernioApiException $e) {
            throw ZernioException::fromApiException($e);
        }

        if (! $response instanceof PostGetResponse) {
            throw new ZernioException('Unexpected Zernio response for post lookup.');
        }

        return $this->normalizePost($response->getPost());
    }

    /**
     * @return array<string, mixed>
     */
    public function requestPresignedUpload(string $filename, string $contentType, int $size = 0): array
    {
        // Populate via the array constructor: the SDK's setContentType() is
        // typed to the MediaContentType enum class, but accepts a plain MIME
        // string; the array form keeps the value untyped and serializes the
        // same way.
        $request = new GetMediaPresignedUrlRequest([
            'filename' => $filename,
            'content_type' => $contentType,
            'size' => $size > 0 ? $size : null,
        ]);

        try {
            $response = $this->mediaApi()->getMediaPresignedUrl($request);
        } catch (ZernioApiException $e) {
            throw ZernioException::fromApiException($e);
        }

        if (! $response instanceof GetMediaPresignedUrl200Response) {
            throw new ZernioException('Unexpected Zernio response for media upload.');
        }

        return [
            'upload_url' => (string) ($response->getUploadUrl() ?? ''),
            'public_url' => (string) ($response->getPublicUrl() ?? ''),
            'key' => (string) ($response->getKey() ?? ''),
            'expires_in' => (int) ($response->getExpiresIn() ?? 0),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeAccount(SocialAccount $account): array
    {
        return [
            'id' => (string) $account->getId(),
            'platform' => (string) $account->getPlatform(),
            'name' => (string) ($account->getDisplayName() ?? ''),
            'username' => $account->getUsername() !== null ? (string) $account->getUsername() : null,
            'avatar_url' => $account->getProfilePicture() !== null ? (string) $account->getProfilePicture() : null,
            'profile_url' => $account->getProfileUrl() !== null ? (string) $account->getProfileUrl() : null,
            'is_active' => (bool) $account->getIsActive(),
            'needs_reconnection' => (bool) ($account->getNeedsReconnection() ?? false),
            'enabled' => (bool) ($account->getEnabled() ?? false),
        ];
    }

    /**
     * Zernio's post payloads return `accountId` as a JSON-encoded account
     * object (e.g. {"_id": "...", "platform": "reddit", ...}) rather than a
     * plain string id — extract the id defensively.
     */
    protected function normalizeAccountId(mixed $accountId): string
    {
        $value = trim((string) ($accountId ?? ''));
        if (str_starts_with($value, '{')) {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = (string) ($decoded['_id'] ?? '');
            }
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizePost(?Post $post): array
    {
        if (! $post) {
            return [];
        }

        return [
            'id' => (string) $post->getId(),
            'status' => (string) ($post->getStatus() ?? ''),
            'content' => (string) ($post->getContent() ?? ''),
            'platforms' => array_map(
                fn (PlatformTarget $target): array => [
                    'platform' => (string) ($target->getPlatform() ?? ''),
                    'account_id' => $this->normalizeAccountId($target->getAccountId()),
                    'status' => (string) ($target->getStatus() ?? ''),
                    'platform_post_url' => $target->getPlatformPostUrl() !== null ? (string) $target->getPlatformPostUrl() : null,
                    'error_message' => $target->getErrorMessage() !== null ? (string) $target->getErrorMessage() : null,
                ],
                (array) $post->getPlatforms(),
            ),
        ];
    }
}
