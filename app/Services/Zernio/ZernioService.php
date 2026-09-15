<?php

namespace App\Services\Zernio;

use App\Contracts\Zernio\ZernioClient;
use App\Exceptions\ZernioException;
use App\Models\ZernioAccount;
use App\Models\ZernioPost;
use Illuminate\Support\Str;
use Throwable;

/**
 * Admin-facing business logic for the Zernio integration: keeps a local
 * mirror of the connected accounts, composes posts (now or scheduled), and
 * persists Zernio's response back onto the post + per-account pivot rows.
 */
class ZernioService
{
    public function __construct(protected ZernioClient $client) {}

    /**
     * Pull every connected account from Zernio and upsert it locally.
     * Accounts removed on Zernio's side are kept locally but flagged inactive.
     *
     * @return array{added: int, updated: int}
     */
    public function syncAccounts(): array
    {
        $remote = $this->client->listAccounts();

        $remoteIds = collect($remote)->pluck('id')->all();
        $added = 0;
        $updated = 0;

        foreach ($remote as $account) {
            $existing = ZernioAccount::where('zernio_account_id', $account['id'])->first();

            if ($existing) {
                $existing->update([
                    'platform' => $account['platform'],
                    'name' => $account['name'],
                    'username' => $account['username'],
                    'avatar_url' => $account['avatar_url'],
                    'is_active' => $account['is_active'],
                    'needs_reconnection' => $account['needs_reconnection'],
                    'raw_payload' => $account,
                    'synced_at' => now(),
                ]);
                $updated++;
            } else {
                ZernioAccount::create([
                    'zernio_account_id' => $account['id'],
                    'platform' => $account['platform'],
                    'name' => $account['name'],
                    'username' => $account['username'],
                    'avatar_url' => $account['avatar_url'],
                    'is_active' => $account['is_active'],
                    'needs_reconnection' => $account['needs_reconnection'],
                    'raw_payload' => $account,
                    'synced_at' => now(),
                ]);
                $added++;
            }
        }

        ZernioAccount::whereNotIn('zernio_account_id', $remoteIds)
            ->update(['is_active' => false]);

        return ['added' => $added, 'updated' => $updated];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function accounts(): array
    {
        return ZernioAccount::query()
            ->orderBy('platform')
            ->orderBy('name')
            ->get()
            ->map(fn (ZernioAccount $account): array => $account->toAdminRow())
            ->all();
    }

    /**
     * Send a post to Zernio for the given locally-known accounts, immediately
     * or scheduled. Persists local rows first (with an idempotency key) so a
     * retry after a network failure reuses the same x-request-id instead of
     * creating a duplicate post on Zernio.
     *
     * @param  array<int, string>  $accountIds  local ZernioAccount uuids
     * @param  array<int, array{url: string, type?: string}>  $media
     * @return array<string, mixed>
     */
    public function createPost(
        string $content,
        array $accountIds,
        array $media = [],
        ?string $scheduledAt = null,
        ?string $timezone = null,
        ?string $createdBy = null,
    ): array {
        $accounts = ZernioAccount::whereIn('id', $accountIds)->get();
        if ($accounts->count() !== count($accountIds)) {
            throw new ZernioException('One or more target accounts could not be found.');
        }

        $publishNow = $scheduledAt === null || $scheduledAt === '';
        $resolvedTimezone = $timezone ?: (string) config('zernio.timezone', 'Asia/Kolkata');

        $post = ZernioPost::create([
            'created_by' => $createdBy ?? auth()->id(),
            'content' => $content,
            'publish_now' => $publishNow,
            'scheduled_at' => $publishNow ? null : $scheduledAt,
            'timezone' => $resolvedTimezone,
            'status' => $publishNow ? 'draft' : 'scheduled',
            'idempotency_key' => (string) Str::uuid(),
        ]);

        $post->accounts()->attach($accounts->pluck('id'), ['status' => 'pending']);

        $targets = $accounts->map(fn (ZernioAccount $a): array => [
            'platform' => $a->platform,
            'account_id' => $a->zernio_account_id,
        ])->all();

        try {
            $result = $this->client->createPost(
                $content,
                $targets,
                $media,
                $publishNow ? null : $post->scheduled_at,
                $resolvedTimezone,
                $post->idempotency_key,
            );

            $post->update([
                'status' => $this->mapPostStatus($result),
                'zernio_post_id' => $result['id'] ?? null,
                'error' => null,
            ]);

            $this->applyPlatformResults($post, $result['platforms'] ?? []);
        } catch (Throwable $e) {
            $post->update([
                'status' => 'failed',
                'error' => $e->getMessage(),
            ]);
            foreach ($accounts as $account) {
                $post->accounts()->updateExistingPivot($account->id, ['status' => 'failed']);
            }

            throw $e;
        }

        return $post->fresh(['accounts'])?->toAdminRow() ?? [];
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function posts(): array
    {
        return ZernioPost::query()
            ->with('creator', 'accounts')
            ->latest('created_at')
            ->get()
            ->map(fn (ZernioPost $post): array => $post->toAdminRow())
            ->all();
    }

    /**
     * Ask Zernio for a presigned upload target for a media file. The caller
     * PUTs the bytes to `upload_url` (browser-side), then reuses `public_url`
     * as the media url when creating the post.
     *
     * @return array<string, mixed>
     */
    public function presignMedia(string $filename, string $contentType, int $size = 0): array
    {
        return $this->client->requestPresignedUpload($filename, $contentType, $size);
    }

    /**
     * @param  array<int, array<string, mixed>>  $platforms
     */
    private function applyPlatformResults(ZernioPost $post, array $platforms): void
    {
        foreach ($platforms as $result) {
            $account = ZernioAccount::where('zernio_account_id', $result['account_id'])->first();
            if (! $account) {
                continue;
            }

            $post->accounts()->updateExistingPivot($account->id, [
                'status' => $result['status'],
                'platform_post_url' => $result['platform_post_url'],
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $result
     */
    private function mapPostStatus(array $result): string
    {
        return match ($result['status'] ?? '') {
            'scheduled', 'SCHEDULED' => 'scheduled',
            'published', 'PUBLISHED', 'completed', 'COMPLETED' => 'published',
            'failed', 'FAILED' => 'failed',
            default => 'pending',
        };
    }
}
