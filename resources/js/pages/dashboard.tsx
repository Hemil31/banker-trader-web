import { Head, router } from '@inertiajs/react';
import { useState } from 'react';
import type { ReactNode } from 'react';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dateTime, inr } from '@/lib/format';
import type { Position, Overview } from '@/types/trading';

type DashboardProps = {
    overview: Overview;
};

function StatCard({ label, value, sub }: { label: string; value: string; sub?: string }) {
    return (
        <Card>
            <CardHeader>
                <CardTitle className="text-sm font-medium text-muted-foreground">{label}</CardTitle>
            </CardHeader>
            <CardContent>
                <div className="text-2xl font-semibold">{value}</div>
                {sub ? <p className="mt-1 text-sm text-muted-foreground">{sub}</p> : null}
            </CardContent>
        </Card>
    );
}

export default function Dashboard({ overview }: DashboardProps) {
    const [running, setRunning] = useState(false);
    const { account, portfolio, open_positions: openPositions, recent_signals: recentSignals, recent_paper_trades: recentPaperTrades } = overview;

    const runSession = () => {
        setRunning(true);
        router.post(
            '/trading/run',
            {},
            {
                preserveScroll: true,
                onFinish: () => {
                    setRunning(false);
                    router.reload({ only: ['overview'] });
                },
            },
        );
    };

    return (
        <>
            <Head title="Dashboard" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div className="flex items-center justify-between">
                    <div>
                        <h1 className="text-xl font-semibold">{account.name}</h1>
                        <p className="text-sm text-muted-foreground">
                            Paper account · {account.mode} · {openPositions.length} open positions
                        </p>
                    </div>
                    <Button onClick={runSession} disabled={running}>
                        {running ? 'Running…' : 'Run paper session'}
                    </Button>
                </div>

                <div className="grid gap-4 md:grid-cols-4">
                    <StatCard label="Net equity" value={inr(portfolio.net_equity)} sub={`Started with ${inr(account.starting_capital)}`} />
                    <StatCard label="Available cash" value={inr(account.available_cash)} />
                    <StatCard label="Invested" value={inr(portfolio.invested)} sub={`${portfolio.open_positions_count} open positions`} />
                    <StatCard label="Unrealized P&L" value={inr(portfolio.unrealized)} sub={`Realized ${inr(portfolio.realized)} · bars ${overview.market_bars}`} />
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle>Open positions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <TradingTable
                            head={['Symbol', 'Qty', 'Avg', 'Stop', 'Target 3', 'Unrealized', 'Opened']}
                            rows={openPositions}
                            render={(row: Position) => [
                                row.symbol ?? '—',
                                row.quantity,
                                inr(row.avg_entry_price),
                                inr(row.stop_loss),
                                inr(row.target3),
                                <span key="pnl" className={row.unrealized_pnl >= 0 ? 'text-emerald-600' : 'text-red-600'}>{inr(row.unrealized_pnl)}</span>,
                                dateTime(row.opened_at),
                            ]}
                            empty="No open positions"
                        />
                    </CardContent>
                </Card>

                <div className="grid gap-4 lg:grid-cols-2">
                    <Card>
                        <CardHeader>
                            <CardTitle>Recent signals</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <TradingTable
                                head={['Symbol', 'Score', 'Price', 'Status', 'Date']}
                                rows={recentSignals}
                                render={(row) => [
                                    row.symbol ?? '—',
                                    row.score.toFixed(1),
                                    inr(row.price),
                                    <SignalStatusBadge key="s" status={row.status} />,
                                    dateTime(row.signal_date),
                                ]}
                                empty="No signals yet"
                            />
                        </CardContent>
                    </Card>

                    <Card>
                        <CardHeader>
                            <CardTitle>Recent paper trades</CardTitle>
                        </CardHeader>
                        <CardContent>
                            <TradingTable
                                head={['Symbol', 'Fill', 'Qty', 'Net P&L', 'Status']}
                                rows={recentPaperTrades}
                                render={(row) => [
                                    row.symbol,
                                    inr(row.fill_price),
                                    row.quantity,
                                    <span key="pnl" className={row.pnl_net >= 0 ? 'text-emerald-600' : 'text-red-600'}>{inr(row.pnl_net)}</span>,
                                    <Badge key="b" variant={row.status === 'closed' ? 'outline' : 'default'}>{row.status}</Badge>,
                                ]}
                                empty="No paper trades yet"
                            />
                        </CardContent>
                    </Card>
                </div>
            </div>
        </>
    );
}

function SignalStatusBadge({ status }: { status: string }) {
    if (status === 'filled' || status === 'executed') {
        return <Badge>entered</Badge>;
    }
    if (status === 'rejected') {
        return <Badge variant="destructive">rejected</Badge>;
    }
    return <Badge variant="outline">{status}</Badge>;
}

type TradingTableProps<T> = {
    head: string[];
    rows: T[];
    render: (row: T) => ReactNode[];
    empty: string;
};

function TradingTable<T>({ head, rows, render, empty }: TradingTableProps<T>) {
    if (rows.length === 0) {
        return <p className="py-6 text-center text-sm text-muted-foreground">{empty}</p>;
    }

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-muted-foreground">
                        {head.map((h) => (
                            <th key={h} className="py-2 pr-4 font-medium">
                                {h}
                            </th>
                        ))}
                    </tr>
                </thead>
                <tbody>
                    {rows.map((row, i) => (
                        <tr key={i} className="border-b last:border-0">
                            {render(row).map((cell, j) => (
                                <td key={j} className="py-2 pr-4">
                                    {cell}
                                </td>
                            ))}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

Dashboard.layout = {
    breadcrumbs: [
        {
            title: 'Dashboard',
            href: '/dashboard',
        },
    ],
};