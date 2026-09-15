<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ZernioException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Zernio\CreateZernioPostRequest;
use App\Http\Requests\Zernio\PresignZernioMediaRequest;
use App\Services\Zernio\ZernioService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

class ZernioController extends Controller
{
    public function __construct(protected ZernioService $zernio) {}

    /**
     * Connected social accounts (from zernio.com, mirrored locally).
     */
    public function accounts(): Response
    {
        return Inertia::render('admin/zernio/accounts', [
            'accounts' => $this->zernio->accounts(),
        ]);
    }

    /**
     * Pull the connected accounts from Zernio into the local mirror.
     */
    public function syncAccounts(Request $request): RedirectResponse
    {
        try {
            $result = $this->zernio->syncAccounts();
        } catch (ZernioException $e) {
            throw ValidationException::withMessages(['sync' => $e->getMessage()]);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => "Accounts synced ({$result['added']} added, {$result['updated']} updated).",
        ]);
    }

    /**
     * Compose-and-send screen: previous posts + the create form.
     */
    public function posts(): Response
    {
        return Inertia::render('admin/zernio/posts', [
            'posts' => $this->zernio->posts(),
            'accounts' => $this->zernio->accounts(),
            'timezone' => (string) config('zernio.timezone', 'Asia/Kolkata'),
        ]);
    }

    public function storePost(CreateZernioPostRequest $request): RedirectResponse
    {
        try {
            $post = $this->zernio->createPost(
                content: $request->content,
                accountIds: $request->account_ids,
                media: $request->media ?? [],
                scheduledAt: $request->scheduled_at,
                timezone: $request->timezone,
                createdBy: (string) $request->user()->id,
            );
        } catch (ZernioException $e) {
            throw ValidationException::withMessages(['post' => $e->getMessage()]);
        } catch (Throwable $e) {
            throw ValidationException::withMessages(['post' => 'Something went wrong sending the post.']);
        }

        return back()->with('toast', [
            'type' => 'success',
            'message' => $post['publish_now']
                ? 'Post sent to Zernio.'
                : 'Post scheduled with Zernio.',
        ]);
    }

    /**
     * Get a presigned upload target so the browser can PUT the media bytes
     * straight to the storage provider. Response is plain JSON for the SPA.
     */
    public function presignMedia(PresignZernioMediaRequest $request): JsonResponse
    {
        try {
            $presign = $this->zernio->presignMedia(
                $request->filename,
                $request->content_type,
                (int) ($request->input('size') ?? 0),
            );
        } catch (ZernioException $e) {
            throw ValidationException::withMessages(['media' => $e->getMessage()]);
        }

        return response()->json($presign);
    }
}
