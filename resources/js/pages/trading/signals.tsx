import { Head } from '@inertiajs/react';
import { Badge } from '@/components/ui/badge';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { dateOnly, inr } from '@/lib/format';
import type { TradingSignal } from '@/types/trading';

type SignalsPageProps = {
    signals: TradingSignal[];
};

export default function Signals({ signals }: SignalsPageProps) {
    return (
        <>
            <Head title="Signals" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Trading signals</h1>
                    <p className="text-sm text-muted-foreground">Latest {signals.length} signals from the scanner</p>
                </div>

                <Card>
                    <CardHeader>
                        <CardTitle className="text-sm text-muted-foreground">Signal log</CardTitle>
                    </CardHeader>
                    <CardContent>
                        {signals.length === 0 ? (
                            <p className="py-6 text-center text-sm text-muted-foreground">No signals yet — run a paper session first.</p>
                        ) : (
                            <div className="overflow-x-auto">
                                <table className="w-full text-sm">
                                    <thead>
                                        <tr className="border-b text-left text-muted-foreground">
                                            <th className="py-2 pr-4 font-medium">Symbol</th>
                                            <th className="py-2 pr-4 font-medium">Date</th>
                                            <th className="py-2 pr-4 font-medium">Price</th>
                                            <th className="py-2 pr-4 font-medium">Score</th>
                                            <th className="py-2 pr-4 font-medium">SL</th>
                                            <th className="py-2 pr-4 font-medium">T1</th>
                                            <th className="py-2 pr-4 font-medium">T3</th>
                                            <th className="py-2 pr-4 font-medium">R:R</th>
                                            <th className="py-2 pr-4 font-medium">Status</th>
                                        </tr>
                                    </thead>
                                    <tbody>
                                        {signals.map((signal) => (
                                            <tr key={signal.id} className="border-b last:border-0">
                                                <td className="py-2 pr-4 font-medium">{signal.symbol ?? '—'}</td>
                                                <td className="py-2 pr-4">{dateOnly(signal.signal_date)}</td>
                                                <td className="py-2 pr-4">{inr(signal.price)}</td>
                                                <td className="py-2 pr-4">
                                                    <span className={signal.score >= 60 ? 'text-emerald-600' : 'text-muted-foreground'}>
                                                        {signal.score.toFixed(1)}
                                                    </span>
                                                </td>
                                                <td className="py-2 pr-4">{inr(signal.proposed_sl)}</td>
                                                <td className="py-2 pr-4">{inr(signal.proposed_target1)}</td>
                                                <td className="py-2 pr-4">{inr(signal.proposed_target3)}</td>
                                                <td className="py-2 pr-4">{signal.risk_reward_ratio.toFixed(2)}</td>
                                                <td className="py-2 pr-4">
                                                    <SignalStatus status={signal.status} />
                                                </td>
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

function SignalStatus({ status }: { status: string }) {
    if (status === 'filled' || status === 'executed') {
        return <Badge>entered</Badge>;
    }
    if (status === 'rejected') {
        return <Badge variant="destructive">rejected</Badge>;
    }
    return <Badge variant="outline">{status}</Badge>;
}

Signals.layout = {
    breadcrumbs: [
        {
            title: 'Signals',
            href: '/trading/signals',
        },
    ],
};