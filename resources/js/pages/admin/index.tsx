import { Head } from '@inertiajs/react';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';

type UserTotals = {
    accounts: number;
    realized_pnl_net: number;
    unrealized_pnl_net: number;
    orders_count: number;
    signals_count: number;
    open_positions: number;
};

type AdminUser = {
    id: string;
    name: string;
    username: string;
    email: string;
    is_admin: boolean;
    accounts: unknown[];
    totals: UserTotals;
};

type Props = {
    users: AdminUser[];
    summary: {
        users: number;
        total_realized_pnl_net: number;
        total_unrealized_pnl_net: number;
        total_orders: number;
        total_signals: number;
        total_open_positions: number;
    };
};

function inr(value: number): string {
    return new Intl.NumberFormat('en-IN', { style: 'currency', currency: 'INR', maximumFractionDigits: 0 }).format(value ?? 0);
}

export default function AdminIndex({ users, summary }: Props) {
    return (
        <>
            <Head title="Admin console" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Admin console</h1>
                    <p className="text-sm text-muted-foreground">Company overview · profit/loss per user</p>
                </div>

                <div className="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
                    <SummaryCard label="Users" value={String(summary.users)} />
                    <SummaryCard label="Net realized P&L" value={inr(summary.total_realized_pnl_net)} />
                    <SummaryCard label="Net unrealized P&L" value={inr(summary.total_unrealized_pnl_net)} />
                    <SummaryCard label="Orders" value={String(summary.total_orders)} />
                    <SummaryCard label="Signals" value={String(summary.total_signals)} />
                    <SummaryCard label="Open positions" value={String(summary.total_open_positions)} />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Users</CardTitle>
                        <CardDescription>Per-account realized/unrealized profit and trading activity</CardDescription>
                    </CardHeader>
                    <CardContent className="overflow-x-auto">
                        <table className="w-full text-sm">
                            <thead>
                                <tr className="border-b text-left text-muted-foreground">
                                    <th className="pb-2 pr-4 font-medium">User</th>
                                    <th className="pb-2 pr-4 font-medium">Accounts</th>
                                    <th className="pb-2 pr-4 font-medium">Realized P&L</th>
                                    <th className="pb-2 pr-4 font-medium">Unrealized P&L</th>
                                    <th className="pb-2 pr-4 font-medium">Orders</th>
                                    <th className="pb-2 pr-4 font-medium">Signals</th>
                                    <th className="pb-2 font-medium">Open positions</th>
                                </tr>
                            </thead>
                            <tbody>
                                {users.map((user) => (
                                    <tr key={user.id} className="border-b last:border-0">
                                        <td className="py-3 pr-4">
                                            <p className="font-medium">{user.name}</p>
                                            <p className="text-xs text-muted-foreground">{user.email}</p>
                                        </td>
                                        <td className="py-3 pr-4">{user.totals.accounts}</td>
                                        <td className="py-3 pr-4 tabular-nums">{inr(user.totals.realized_pnl_net)}</td>
                                        <td className="py-3 pr-4 tabular-nums">{inr(user.totals.unrealized_pnl_net)}</td>
                                        <td className="py-3 pr-4">{user.totals.orders_count}</td>
                                        <td className="py-3 pr-4">{user.totals.signals_count}</td>
                                        <td className="py-3">{user.totals.open_positions}</td>
                                    </tr>
                                ))}
                            </tbody>
                        </table>
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function SummaryCard({ label, value }: { label: string; value: string }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">{label}</CardTitle>
            </CardHeader>
            <CardContent>
                <p className="text-2xl font-semibold tabular-nums">{value}</p>
            </CardContent>
        </Card>
    );
}