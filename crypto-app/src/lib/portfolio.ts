export type Holding = {
  symbol: string;
  name: string;
  amount: number;
  price_usd: number;
  change_24h: number;
  color: string;
  value_usd: number;
  value_jpy: number;
  weight: number;
};

export type Portfolio = {
  currency: 'JPY' | 'USD';
  usd_jpy: number;
  total_usd: number;
  total_jpy: number;
  change_24h_usd: number;
  change_24h_jpy: number;
  change_24h_pct: number;
  updated_at: string;
  holdings: Holding[];
};

export function emptyPortfolio(): Portfolio {
  return {
    currency: 'JPY',
    usd_jpy: 148.5,
    total_usd: 0,
    total_jpy: 0,
    change_24h_usd: 0,
    change_24h_jpy: 0,
    change_24h_pct: 0,
    updated_at: new Date().toISOString(),
    holdings: [],
  };
}
