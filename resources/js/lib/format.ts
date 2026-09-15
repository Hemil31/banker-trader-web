const inrFormatter = new Intl.NumberFormat('en-IN', {
    style: 'currency',
    currency: 'INR',
    maximumFractionDigits: 0,
});

const dateTimeFormatter = new Intl.DateTimeFormat('en-IN', {
    dateStyle: 'medium',
    timeStyle: 'short',
});

const dateFormatter = new Intl.DateTimeFormat('en-IN', {
    dateStyle: 'medium',
});

export function inr(value: number | string | null | undefined): string {
    const n = typeof value === 'number' ? value : Number(value ?? 0);
    return inrFormatter.format(n);
}

export function dateTime(value?: string | null): string {
    return value ? dateTimeFormatter.format(new Date(value)) : '—';
}

export function dateOnly(value?: string | null): string {
    return value ? dateFormatter.format(new Date(value)) : '—';
}

export function signed(value: number | null | undefined): string {
    const n = Number(value ?? 0);
    const formatted = `${n >= 0 ? '+' : ''}${inr(n)}`;
    return formatted;
}
