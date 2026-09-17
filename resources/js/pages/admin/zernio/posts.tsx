import { Form, Head } from '@inertiajs/react';
import { useState } from 'react';
import InputError from '@/components/input-error';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { presign } from '@/routes/admin/zernio/media';
import { refresh, store } from '@/routes/admin/zernio/posts';

type Account = {
    id: string;
    zernio_account_id: string;
    platform: string;
    name: string;
    username: string | null;
    avatar_url: string | null;
    is_active: boolean;
    needs_reconnection: boolean;
    synced_at: string | null;
};

type PostAccount = {
    id: string;
    name: string;
    platform: string;
    status: string | null;
    platform_post_url: string | null;
};

type Post = {
    id: string;
    content: string;
    publish_now: boolean;
    scheduled_at: string | null;
    timezone: string | null;
    status: string;
    zernio_post_id: string | null;
    created_by: string;
    error: string | null;
    created_at: string | null;
    accounts: PostAccount[];
};

type MediaItem = {
    url: string;
    type: string;
    name: string;
};

type Props = {
    posts: Post[];
    accounts: Account[];
    timezone: string;
};

const TIMEZONES = [
    'Asia/Kolkata',
    'UTC',
    'Asia/Dubai',
    'Asia/Singapore',
    'Asia/Tokyo',
    'Europe/London',
    'America/New_York',
    'America/Los_Angeles',
    'Australia/Sydney',
];

function xsrfToken(): string {
    const match = document.cookie.match(/(?:^|;\s*)XSRF-TOKEN=([^;]+)/);
    return match ? decodeURIComponent(match[1]) : '';
}

function zernioMediaType(name: string, contentType: string): string | null {
    if (contentType.includes('image/gif')) {
        return 'gif';
    }
    if (contentType.startsWith('image/')) {
        return 'image';
    }
    if (contentType.startsWith('video/')) {
        return 'video';
    }
    if (contentType === 'application/pdf') {
        return 'document';
    }
    return null;
}

function statusBadge(post: Post): string {
    switch (post.status) {
        case 'published':
            return 'bg-green-500/10 text-green-600';
        case 'scheduled':
            return 'bg-blue-500/10 text-blue-600';
        case 'failed':
            return 'bg-destructive/10 text-destructive';
        default:
            return 'bg-muted text-muted-foreground';
    }
}

export default function ZernioPosts({ posts, accounts, timezone }: Props) {
    const [media, setMedia] = useState<MediaItem[]>([]);
    const [uploading, setUploading] = useState(false);
    const [uploadError, setUploadError] = useState<string | null>(null);

    const addMedia = async (files: File[]) => {
        setUploading(true);
        setUploadError(null);

        const next: MediaItem[] = [];
        for (const file of files) {
            const type = zernioMediaType(file.name, file.type);
            if (type === null) {
                setUploadError(
                    'Only images, videos, GIFs and PDFs are supported.',
                );
                continue;
            }

            const pending = await fetch(presign().url, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    'X-XSRF-TOKEN': xsrfToken(),
                    'X-Requested-With': 'XMLHttpRequest',
                },
                body: JSON.stringify({
                    filename: file.name,
                    content_type: file.type,
                    size: file.size,
                }),
            });

            let target: { upload_url?: string; public_url?: string };
            try {
                target = await pending.json();
            } catch {
                setUploadError('Could not prepare the upload target.');
                continue;
            }

            if (!pending.ok || !target.upload_url || !target.public_url) {
                setUploadError('Could not prepare the upload target.');
                continue;
            }

            const uploaded = await fetch(target.upload_url, {
                method: 'PUT',
                headers: { 'Content-Type': file.type },
                body: file,
            });

            if (!uploaded.ok) {
                setUploadError('Could not upload the file.');
                continue;
            }

            next.push({ url: target.public_url, type, name: file.name });
        }

        setMedia((current) => [...current, ...next]);
        setUploading(false);
    };

    return (
        <>
            <Head title="Zernio posts" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Zernio posts</h1>
                    <p className="text-muted-foreground text-sm">
                        Compose a post and send it to every selected account.
                    </p>
                </div>

                <Form
                    {...store.form()}
                    resetOnSuccess={true}
                    className="grid gap-6"
                >
                    {({ processing, errors, recentlySuccessful }) => (
                        <>
                            <Card>
                                <CardHeader>
                                    <CardTitle>Compose post</CardTitle>
                                    <CardDescription>
                                        One post, delivered to all selected
                                        accounts.
                                    </CardDescription>
                                </CardHeader>
                                <CardContent className="grid gap-4">
                                    <div className="grid gap-2">
                                        <Label htmlFor="content">Content</Label>
                                        <textarea
                                            id="content"
                                            name="content"
                                            required
                                            rows={4}
                                            maxLength={2000}
                                            placeholder="What would you like to post?"
                                            className="border-input focus-visible:border-ring focus-visible:ring-ring/50 dark:bg-input/30 dark:hover:bg-input/50 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs transition-[color,box-shadow] outline-none focus-visible:ring-[3px] disabled:cursor-not-allowed disabled:opacity-50"
                                        />
                                        <InputError message={errors.content} />
                                    </div>

                                    <div className="grid gap-2">
                                        <Label>Accounts</Label>
                                        {accounts.length === 0 ? (
                                            <p className="text-muted-foreground text-sm">
                                                No accounts mirrored yet.
                                                Refresh accounts on the accounts
                                                page.
                                            </p>
                                        ) : (
                                            <div className="grid gap-2">
                                                {accounts
                                                    .filter((a) => a.is_active)
                                                    .map((account) => (
                                                        <label
                                                            key={account.id}
                                                            className="flex items-center gap-3 rounded-md border p-3 text-sm"
                                                        >
                                                            <Checkbox
                                                                name="account_ids[]"
                                                                value={
                                                                    account.id
                                                                }
                                                                defaultChecked
                                                            />
                                                            <div className="flex min-w-0 flex-1 items-center gap-2">
                                                                <span className="font-medium">
                                                                    {
                                                                        account.name
                                                                    }
                                                                </span>
                                                                <span className="text-muted-foreground text-xs uppercase">
                                                                    {
                                                                        account.platform
                                                                    }
                                                                </span>
                                                            </div>
                                                        </label>
                                                    ))}
                                            </div>
                                        )}
                                        <InputError
                                            message={errors.account_ids}
                                        />
                                    </div>

                                    <div className="grid gap-4 sm:grid-cols-2">
                                        <div className="grid gap-2">
                                            <Label htmlFor="scheduled_at">
                                                Schedule (leave empty to post
                                                now)
                                            </Label>
                                            <Input
                                                id="scheduled_at"
                                                name="scheduled_at"
                                                type="datetime-local"
                                            />
                                            <InputError
                                                message={errors.scheduled_at}
                                            />
                                        </div>
                                        <div className="grid gap-2">
                                            <Label htmlFor="timezone">
                                                Timezone
                                            </Label>
                                            <select
                                                id="timezone"
                                                name="timezone"
                                                defaultValue={timezone}
                                                className="border-input dark:bg-input/30 dark:hover:bg-input/50 rounded-md border bg-transparent px-3 py-2 text-sm shadow-xs outline-none"
                                            >
                                                {TIMEZONES.map((zone) => (
                                                    <option
                                                        key={zone}
                                                        value={zone}
                                                    >
                                                        {zone}
                                                    </option>
                                                ))}
                                            </select>
                                            <InputError
                                                message={errors.timezone}
                                            />
                                        </div>
                                    </div>

                                    <div className="grid gap-2">
                                        <Label htmlFor="media">Media</Label>
                                        <Input
                                            id="media"
                                            type="file"
                                            accept="image/*,video/*,application/pdf,.mp4,.mov,.webm"
                                            multiple
                                            disabled={uploading}
                                            onChange={(event) =>
                                                void addMedia(
                                                    Array.from(
                                                        event.target.files ??
                                                            [],
                                                    ),
                                                )
                                            }
                                        />
                                        {uploading && (
                                            <p className="text-muted-foreground flex items-center gap-2 text-sm">
                                                <Spinner /> Uploading&hellip;
                                            </p>
                                        )}
                                        {uploadError && (
                                            <InputError message={uploadError} />
                                        )}
                                        {media.map((item, index) => (
                                            <div
                                                key={item.url}
                                                className="text-muted-foreground text-sm"
                                            >
                                                <input
                                                    type="hidden"
                                                    name={`media[${index}][url]`}
                                                    value={item.url}
                                                />
                                                <input
                                                    type="hidden"
                                                    name={`media[${index}][type]`}
                                                    value={item.type}
                                                />
                                                {item.name}
                                            </div>
                                        ))}
                                        <InputError message={errors.media} />
                                    </div>

                                    <div className="flex items-center gap-3">
                                        <Button
                                            type="submit"
                                            disabled={processing || uploading}
                                            data-test="create-post"
                                        >
                                            {processing && <Spinner />}
                                            Send post
                                        </Button>
                                        {recentlySuccessful && (
                                            <p className="text-sm text-green-600">
                                                Sent.
                                            </p>
                                        )}
                                    </div>
                                </CardContent>
                            </Card>
                        </>
                    )}
                </Form>

                <Card>
                    <CardHeader>
                        <CardTitle>Post history</CardTitle>
                        <CardDescription>
                            Every post composed from this console.
                        </CardDescription>
                    </CardHeader>
                    <CardContent>
                        {posts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No posts sent yet.
                            </p>
                        ) : (
                            <div className="grid gap-4">
                                {posts.map((post) => (
                                    <div
                                        key={post.id}
                                        className="rounded-md border p-4"
                                    >
                                        <div className="flex flex-wrap items-center gap-2">
                                            <span
                                                className={`rounded-full px-2 py-0.5 text-xs ${statusBadge(post)}`}
                                            >
                                                {post.status}
                                            </span>
                                            <span className="text-muted-foreground text-xs">
                                                {post.created_by} ·{' '}
                                                {post.created_at
                                                    ? new Date(
                                                          post.created_at,
                                                      ).toLocaleString()
                                                    : '—'}
                                            </span>
                                            {post.scheduled_at && (
                                                <span className="text-muted-foreground text-xs">
                                                    scheduled for{' '}
                                                    {new Date(
                                                        post.scheduled_at,
                                                    ).toLocaleString()}{' '}
                                                    ({post.timezone})
                                                </span>
                                            )}
                                            {post.zernio_post_id &&
                                                post.status !== 'published' &&
                                                post.status !== 'scheduled' && (
                                                    <Form
                                                        {...refresh.form(
                                                            post.id,
                                                        )}
                                                        className="ml-auto"
                                                    >
                                                        {({ processing }) => (
                                                            <Button
                                                                type="submit"
                                                                variant="outline"
                                                                size="sm"
                                                                disabled={
                                                                    processing
                                                                }
                                                            >
                                                                {(processing ||
                                                                    post.status ===
                                                                        'pending') && (
                                                                    <Spinner />
                                                                )}
                                                                Refresh status
                                                            </Button>
                                                        )}
                                                    </Form>
                                                )}
                                        </div>
                                        <p className="mt-2 text-sm whitespace-pre-wrap">
                                            {post.content}
                                        </p>
                                        <div className="mt-2 flex flex-wrap gap-2">
                                            {post.accounts.map((account) => (
                                                <a
                                                    key={account.id}
                                                    href={
                                                        account.platform_post_url ??
                                                        undefined
                                                    }
                                                    target="_blank"
                                                    rel="noreferrer"
                                                    className="text-muted-foreground bg-muted rounded-full px-2 py-0.5 text-xs"
                                                >
                                                    {account.platform}:{' '}
                                                    {account.name} (
                                                    {account.status})
                                                </a>
                                            ))}
                                        </div>
                                        {post.error && (
                                            <p className="text-destructive mt-2 text-xs">
                                                {post.error}
                                            </p>
                                        )}
                                    </div>
                                ))}
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
