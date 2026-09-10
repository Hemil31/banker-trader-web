export type Account = {
    id: number;
    name: string;
    mode: string;
    starting_capital: number;
    available_cash: number;
    invested_amount: number;
    started_at: string | null;
};

export type PortfolioSummary = {
    invested: number;
    unrealized: number;
    realized: number;
    gross_equity: number;
    net_equity: number;
    open_positions_count: number;
};

export type Position = {
    id: number;
    symbol: string | null;
    status: string;
    quantity: number;
    partial_booked_qty: number;
    avg_entry_price: number;
    entry_value: number;
    stop_loss: number;
    target1: number;
    target2: number;
    target3: number;
    current_stop: number;
    unrealized_pnl: number;
    unrealized_pnl_net: number;
    realized_pnl: number;
    realized_pnl_net: number;
    net_pnl: number;
    close_reason: string | null;
    exit_price: number;
    opened_at: string | null;
    closed_at: string | null;
};

export type TradingSignal = {
    id: number;
    symbol: string | null;
    signal_date: string | null;
    price: number;
    score: number;
    proposed_sl: number;
    proposed_target1: number;
    proposed_target3: number;
    risk_reward_ratio: number;
    entry_reasons: unknown;
    status: string;
};

export type PaperTrade = {
    id: number;
    position_id: number | null;
    symbol: string;
    direction: string;
    signal_price: number;
    fill_price: number;
    quantity: number;
    stop_loss: number;
    target: number;
    pnl: number;
    pnl_net: number;
    status: string;
    entry_reason: string | null;
    exit_reason: string | null;
    executed_at: string | null;
    exited_at: string | null;
};

export type ConfigRow = {
    key: string;
    group: string;
    type: string;
    value: number | string | boolean | unknown[];
    label: string;
    is_editable: boolean;
};

export type Overview = {
    account: Account;
    portfolio: PortfolioSummary;
    open_positions: Position[];
    recent_signals: TradingSignal[];
    recent_paper_trades: PaperTrade[];
    market_bars: number;
    config_count: number;
};