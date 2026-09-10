import { Head, useForm } from '@inertiajs/react';
import { useMemo, useState } from 'react';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Select, SelectContent, SelectItem, SelectTrigger, SelectValue } from '@/components/ui/select';
import type { ConfigRow } from '@/types/trading';

type ConfigPageProps = {
    rows: ConfigRow[];
};

export default function Config({ rows }: ConfigPageProps) {
    const [localRows, setLocalRows] = useState(rows);

    const groups = useMemo(() => {
        const out: Record<string, ConfigRow[]> = {};
        for (const row of rows) {
            (out[row.group] ??= []).push(row);
        }
        return out;
    }, [rows]);

    return (
        <>
            <Head title="Trading config" />

            <div className="flex flex-1 flex-col gap-6 p-4">
                <div>
                    <h1 className="text-xl font-semibold">Trading configuration</h1>
                    <p className="text-sm text-muted-foreground">Strategy parameters stored in trading_configs · every change is audited</p>
                </div>

                {Object.entries(groups).map(([group, groupRows]) => (
                    <Card key={group}>
                        <CardHeader>
                            <CardTitle className="capitalize">{group}</CardTitle>
                        </CardHeader>
                        <CardContent className="grid gap-4 md:grid-cols-2">
                            {groupRows.map((row) => (
                                <ConfigRowEditor
                                    key={row.key}
                                    row={row}
                                    onSaved={(key, value) => {
                                        setLocalRows((prev) => prev.map((r) => (r.key === key ? { ...r, value } : r)));
                                    }}
                                />
                            ))}
                        </CardContent>
                    </Card>
                ))}
            </div>
        </>
    );
}

function parseStored(row: ConfigRow, raw: string): ConfigRow['value'] {
    if (row.type === 'boolean') {
        return raw === 'true' || raw === '1';
    }
    if (row.type === 'integer') {
        return Number.parseInt(raw, 10) || 0;
    }
    return Number(raw) || 0;
}

function ConfigRowEditor({ row, onSaved }: { row: ConfigRow; onSaved: (key: string, value: ConfigRow['value']) => void }) {
    const editable = row.is_editable && ['float', 'integer', 'boolean'].includes(row.type);
    const savedDisplay = String(row.value ?? '');
    const isBoolean = row.type === 'boolean';

    const { data, setData, patch, processing, errors } = useForm<{ key: string; value: string }>({
        key: row.key,
        value: isBoolean ? (row.value === true ? 'true' : 'false') : savedDisplay,
    });

    if (!editable) {
        return (
            <div className="grid gap-1 rounded-lg border p-3">
                <Label className="text-xs text-muted-foreground">{row.key}</Label>
                <p className="text-sm font-medium">{savedDisplay}</p>
                <p className="text-xs text-muted-foreground">{row.label}</p>
            </div>
        );
    }

    return (
        <div className="grid gap-2 rounded-lg border p-3">
            <div className="flex items-start justify-between gap-2">
                <div>
                    <Label className="text-xs text-muted-foreground">{row.key}</Label>
                    <p className="text-sm">{row.label}</p>
                </div>
                <span className="text-xs font-medium text-muted-foreground">{row.type}</span>
            </div>

            {isBoolean ? (
                <Select
                    value={data.value}
                    onValueChange={(value) => {
                        setData('value', value);
                    }}
                >
                    <SelectTrigger className="w-full">
                        <SelectValue placeholder="Select" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="true">true</SelectItem>
                        <SelectItem value="false">false</SelectItem>
                    </SelectContent>
                </Select>
            ) : (
                <Input
                    value={data.value}
                    onChange={(event) => setData('value', event.target.value)}
                    type="number"
                    step={row.type === 'integer' ? '1' : 'any'}
                />
            )}

            <Button
                onClick={() => {
                    patch('/trading/config', {
                        preserveScroll: true,
                        onSuccess: () => onSaved(row.key, parseStored(row, data.value)),
                    });
                }}
                disabled={processing}
            >
                Save
            </Button>

            {errors.value ? <p className="text-sm text-red-600">{errors.value}</p> : null}
        </div>
    );
}

Config.layout = {
    breadcrumbs: [
        {
            title: 'Trading config',
            href: '/trading/config',
        },
    ],
};