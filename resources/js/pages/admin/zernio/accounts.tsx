import { Form, Head } from '@inertiajs/react';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Spinner } from '@/components/ui/spinner';
import { sync } from '@/routes/admin/zernio/accounts';

type AccountProps = {
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

type Props = {
    accounts: AccountProps[];
};

export default function ZernioAccounts({ accounts }: Props) {
    return (
        <>
            <Head title="Zernio accounts" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex flex-wrap items-start justify-between gap-4">
                    <div>
                        <h1 className="text-xl font-semibold">
                            Zernio accounts
                        </h1>
                        <p className="text-muted-foreground text-sm">
                            Social accounts connected at zernio.com. Sync to
                            pick up new connections.
                        </p>
                    </div>
                    <Form {...sync.form()} className="flex items-end gap-3">
                        {({ processing }) => (
                            <Button
                                type="submit"
                                disabled={processing}
                                data-test="sync-accounts"
                            >
                                {processing && <Spinner />}
                                Refresh accounts
                            </Button>
                        )}
                    </Form>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Connected accounts</CardTitle>
                        <CardDescription>
                            {accounts.length} account
                            {accounts.length === 1 ? '' : 's'} mirrored from
                            Zernio
                        </CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        {accounts.length === 0 ? (
                            <p className="text-muted-foreground text-sm">
                                No accounts yet. Connect accounts at zernio.com,
                                then hit &ldquo;Refresh accounts&rdquo;.
                            </p>
                        ) : (
                            <table className="w-full text-sm">
                                <thead>
                                    <tr className="text-muted-foreground border-b text-left">
                                        <th className="pr-4 pb-2 font-medium">
                                            Account
                                        </th>
                                        <th className="pr-4 pb-2 font-medium">
                                            Platform
                                        </th>
                                        <th className="pr-4 pb-2 font-medium">
                                            Status
                                        </th>
                                        <th className="pb-2 font-medium">
                                            Synced
                                        </th>
                                    </tr>
                                </thead>
                                <tbody>
                                    {accounts.map((account) => (
                                        <tr
                                            key={account.id}
                                            className="border-b last:border-0"
                                        >
                                            <td className="py-3 pr-4">
                                                <p className="font-medium">
                                                    {account.name}
                                                </p>
                                                {account.username && (
                                                    <p className="text-muted-foreground text-xs">
                                                        @{account.username}
                                                    </p>
                                                )}
                                            </td>
                                            <td className="py-3 pr-4 uppercase">
                                                {account.platform}
                                            </td>
                                            <td className="py-3 pr-4">
                                                {!account.is_active && (
                                                    <span className="bg-muted text-muted-foreground rounded-full px-2 py-0.5 text-xs">
                                                        inactive
                                                    </span>
                                                )}
                                                {account.is_active &&
                                                    account.needs_reconnection && (
                                                        <span className="bg-destructive/10 text-destructive rounded-full px-2 py-0.5 text-xs">
                                                            needs reconnection
                                                        </span>
                                                    )}
                                                {account.is_active &&
                                                    !account.needs_reconnection && (
                                                        <span className="rounded-full bg-green-500/10 px-2 py-0.5 text-xs text-green-600">
                                                            active
                                                        </span>
                                                    )}
                                            </td>
                                            <td className="text-muted-foreground py-3">
                                                {account.synced_at
                                                    ? new Date(
                                                          account.synced_at,
                                                      ).toLocaleString()
                                                    : '—'}
                                            </td>
                                        </tr>
                                    ))}
                                </tbody>
                            </table>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}
