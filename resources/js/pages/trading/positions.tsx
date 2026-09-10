import { Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dateTime, inr, signed } from '@/lib/format';
import type { Position } from '@/types/trading';

type PositionsPageProps = {
    positions: Position[];
};

export default function Positions({ positions }: PositionsPageProps) {
    const open = positions.filter((p) => p.status === 'open');
    const closed = positions.filter((p) => p.status !== 'open');

    return (
        <>
            <Head title="Positions" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Positions</h1>
                    <p className="text-sm text-muted-foreground">
                        {open.length} open · {closed.length} closed
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm text-muted-foreground">Open positions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PositionTable positions={open} empty="No open positions" />
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm text-muted-foreground">Closed positions</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <PositionTable positions={closed} closed empty="No closed positions yet" showColumn="net_pnl" />
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

function PositionTable({
    positions,
    closed = false,
    empty,
    showColumn = 'unrealized',
}: {
    positions: Position[];
    closed?: boolean;
    empty: string;
    showColumn?: 'net_pnl' | 'unrealized';
}) {
    if (positions.length === 0) {
        return <p className="py-6 text-center text-sm text-muted-foreground">{empty}</p>;
    }

    const pnlKey = showColumn === 'net_pnl' ? 'net_pnl' : 'unrealized_pnl';

    return (
        <div className="overflow-x-auto">
            <table className="w-full text-sm">
                <thead>
                    <tr className="border-b text-left text-muted-foreground">
                        <th className="py-2 pr-4 font-medium">Symbol</th>
                        <th className="py-2 pr-4 font-medium">Qty</th>
                        <th className="py-2 pr-4 font-medium">Avg</th>
                        <th className="py-2 pr-4 font-medium">Stop</th>
                        <th className="py-2 pr-4 font-medium">Target 3</th>
                        <th className="py-2 pr-4 font-medium">P&L</th>
                        <th className="py-2 pr-4 font-medium">Opened</th>
                        {closed ? <th className="py-2 pr-4 font-medium">Close reason</th> : null}
                    </tr>
                </thead>
                <tbody>
                    {positions.map((p) => (
                        <tr key={p.id} className="border-b last:border-0">
                            <td className="py-2 pr-4 font-medium">{p.symbol ?? '—'}</td>
                            <td className="py-2 pr-4">{p.quantity}</td>
                            <td className="py-2 pr-4">{inr(p.avg_entry_price)}</td>
                            <td className="py-2 pr-4">{inr(p.stop_loss)}</td>
                            <td className="py-2 pr-4">{inr(p.target3)}</td>
                            <td className={`py-2 pr-4 ${p[pnlKey] >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{signed(p[pnlKey])}</td>
                            <td className="py-2 pr-4">{dateTime(p.opened_at)}</td>
                            {closed ? (
                                <td className="py-2 pr-4">
                                    {p.close_reason ? <Badge variant="outline">{p.close_reason}</Badge> : '—'}
                                </td>
                            ) : null}
                        </tr>
                    ))}
                </tbody>
            </table>
        </div>
    );
}

Positions.layout = {
    breadcrumbs: [
        {
            title: 'Positions',
            href: '/trading/positions',
        },
    ],
};