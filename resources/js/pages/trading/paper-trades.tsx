import { Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dateTime, inr, signed } from '@/lib/format';
import type { PaperTrade } from '@/types/trading';

type PaperTradesPageProps = {
    trades: PaperTrade[];
};

export default function PaperTrades({ trades }: PaperTradesPageProps) {
    const realized = trades.filter((t) => t.status === 'closed');
    const netPnl = realized.reduce((sum, t) => sum + t.pnl_net, 0);

    return (
        <>
            <Head title="Paper trades" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Paper trades</h1>
                    <p className="text-sm text-muted-foreground">
                        {realized.length} closed · net P&L {signed(netPnl)}
                    </p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm text-muted-foreground">Ledger</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {trades.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">No paper trades yet — run a paper session first.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="py-2 pr-4 font-medium">Symbol</th>
                                            <th className="py-2 pr-4 font-medium">Fill</th>
                                            <th className="py-2 pr-4 font-medium">Qty</th>
                                            <th className="py-2 pr-4 font-medium">SL</th>
                                            <th className="py-2 pr-4 font-medium">Target</th>
                                            <th className="py-2 pr-4 font-medium">Net P&L</th>
                                            <th className="py-2 pr-4 font-medium">Status</th>
                                            <th className="py-2 pr-4 font-medium">Exit reason</th>
                                            <th className="py-2 pr-4 font-medium">Executed</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {trades.map((t) => (
                                            <tr key={t.id} className="border-b last:border-0">
                                                <td className="py-2 pr-4 font-medium">{t.symbol}</td>
                                                <td className="py-2 pr-4">{inr(t.fill_price)}</td>
                                                <td className="py-2 pr-4">{t.quantity}</td>
                                                <td className="py-2 pr-4">{inr(t.stop_loss)}</td>
                                                <td className="py-2 pr-4">{inr(t.target)}</td>
                                                <td className={`py-2 pr-4 ${t.pnl_net >= 0 ? 'text-emerald-600' : 'text-red-600'}`}>{signed(t.pnl_net)}</td>
                                                <td className="py-2 pr-4">
                                                    <Badge variant={t.status === 'closed' ? 'outline' : 'default'}>{t.status}</Badge>
                                                </td>
                                                <td className="py-2 pr-4">
                                                    {t.exit_reason ? <Badge variant="outline">{t.exit_reason}</Badge> : '—'}
                                                </td>
                                                <td className="py-2 pr-4">{dateTime(t.executed_at)}</td>
                                            </tr>
                                        ))}
                                    </tbody>
                                </table>
                            </div>
                        )}
                    </CardContent>
                </Card>
            </div>
        </>
    );
}

PaperTrades.layout = {
    breadcrumbs: [
        {
            title: 'Paper trades',
            href: '/trading/paper-trades',
        },
    ],
};