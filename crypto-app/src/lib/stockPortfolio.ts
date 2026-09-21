export type StockMarket = 'domestic' | 'foreign';

export type StockAccountKind =
  | 'tokutei'
  | 'nisa_growth'
  | 'nisa_tsumitate'
  | 'foreign_tokutei'
  | 'foreign_nisa'
  | 'foreign_old_nisa'
  | 'cash_usd';

export type StockHolding = {
  id: string;
  code: string;
  name: string;
  market: StockMarket;
  exchange: string | null;
  account: StockAccountKind;
  accountLabel: string;
  buyDate: string | null;
  quantity: number;
  /** JPY for domestic, USD for foreign */
  costPrice: number;
  /** JPY for domestic, USD for foreign */
  currentPrice: number;
  dayChange: number;
  dayChangePct: number;
  /** Primary PnL in display currency of the holding (JPY domestic / USD foreign) */
  pnl: number;
  pnlPct: number;
  /** Always JPY for portfolio totals */
  value: number;
  valueUsd: number | null;
  pnlJpy: number;
  weight: number;
  color: string;
  isCash?: boolean;
};

export type StockSectionSummary = {
  account: StockAccountKind;
  accountLabel: string;
  market: StockMarket;
  value: number;
  valueUsd: number | null;
  pnl: number;
  pnlJpy: number;
  pnlPct: number;
  dayChange: number;
  dayChangePct: number;
  weight: number;
  color: string;
  holdingsCount: number;
};

export type MarketSummary = {
  market: StockMarket | 'cash';
  label: string;
  value: number;
  valueUsd: number | null;
  pnlJpy: number;
  weight: number;
  color: string;
};

export type StockPortfolio = {
  holdings: StockHolding[];
  sections: StockSectionSummary[];
  markets: MarketSummary[];
  totalValue: number;
  totalPnl: number;
  totalPnlPct: number;
  totalValueUsd: number;
  totalPnlUsd: number;
  dayChange: number;
  dayChangePct: number;
  usdJpy: number | null;
  fxAsOf: string | null;
  updatedAt: string;
  source: 'seed' | 'upload';
};

export const ACCOUNT_META: Record<
  StockAccountKind,
  { label: string; short: string; color: string; market: StockMarket | 'cash' }
> = {
  tokutei: { label: '国内・特定', short: '国内特定', color: '#3dd68c', market: 'domestic' },
  nisa_growth: { label: '国内・NISA成長', short: '国内NISA', color: '#4da2ff', market: 'domestic' },
  nisa_tsumitate: { label: '国内・つみたて', short: 'つみたて', color: '#f7c948', market: 'domestic' },
  foreign_tokutei: { label: '海外・特定', short: '海外特定', color: '#fb7185', market: 'foreign' },
  foreign_nisa: { label: '海外・NISA', short: '海外NISA', color: '#a78bfa', market: 'foreign' },
  foreign_old_nisa: { label: '海外・旧NISA', short: '旧NISA', color: '#f7931a', market: 'foreign' },
  cash_usd: { label: '米ドル預り金', short: 'USD現金', color: '#94a3b8', market: 'cash' },
};

export const MARKET_META = {
  domestic: { label: '国内', color: '#3dd68c' },
  foreign: { label: '海外株式', color: '#fb7185' },
  cash: { label: 'USD預り', color: '#94a3b8' },
} as const;

export const HOLDING_COLORS = [
  '#3dd68c',
  '#4da2ff',
  '#f7c948',
  '#f7931a',
  '#a78bfa',
  '#fb7185',
  '#2dd4bf',
  '#94a3b8',
  '#60a5fa',
  '#c084fc',
  '#34d399',
  '#f472b6',
  '#38bdf8',
  '#fbbf24',
];
