import type { Portfolio } from '@/lib/portfolio';
import type { StockPortfolio } from '@/lib/stockPortfolio';

export const RISK_HISTORY_KEY = 'gaming-hub-risk-ratio-v1';

export type RiskSnapshot = {
  t: string;
  cryptoUsd: number;
  stockUsd: number;
  riskUsd: number;
  cryptoPct: number;
  stockPct: number;
  cryptoJpy: number;
  stockJpy: number;
  riskJpy: number;
  usdJpy: number;
};

function round2(n: number) {
  return Math.round(n * 100) / 100;
}

function round1(n: number) {
  return Math.round(n * 10) / 10;
}

export function resolveUsdJpy(crypto: Portfolio | null, stock: StockPortfolio | null): number {
  if (stock?.usdJpy && stock.usdJpy > 0) return stock.usdJpy;
  if (crypto?.usd_jpy && crypto.usd_jpy > 0) return crypto.usd_jpy;
  return 150;
}

/** Convert stock totalValue (JPY) to USD. */
export function stockValueUsd(stock: StockPortfolio, usdJpy: number): number {
  if (usdJpy > 0) return stock.totalValue / usdJpy;
  return stock.totalValueUsd;
}

export function buildRiskSnapshot(
  crypto: Portfolio,
  stock: StockPortfolio,
  at = new Date().toISOString(),
): RiskSnapshot | null {
  const usdJpy = resolveUsdJpy(crypto, stock);
  const cryptoUsd = crypto.total_usd;
  const stockUsd = stockValueUsd(stock, usdJpy);
  const riskUsd = cryptoUsd + stockUsd;
  if (riskUsd <= 0) return null;

  const cryptoJpy = crypto.total_jpy > 0 ? crypto.total_jpy : cryptoUsd * usdJpy;
  const stockJpy = stock.totalValue;
  const riskJpy = cryptoJpy + stockJpy;

  return {
    t: at,
    cryptoUsd: round2(cryptoUsd),
    stockUsd: round2(stockUsd),
    riskUsd: round2(riskUsd),
    cryptoPct: round1((cryptoUsd / riskUsd) * 100),
    stockPct: round1((stockUsd / riskUsd) * 100),
    cryptoJpy: Math.round(cryptoJpy),
    stockJpy: Math.round(stockJpy),
    riskJpy: Math.round(riskJpy),
    usdJpy: round2(usdJpy),
  };
}

function dayKeyJst(iso: string): string {
  try {
    return new Intl.DateTimeFormat('en-CA', {
      timeZone: 'Asia/Tokyo',
      year: 'numeric',
      month: '2-digit',
      day: '2-digit',
    }).format(new Date(iso));
  } catch {
    return iso.slice(0, 10);
  }
}

export function loadRiskHistory(): RiskSnapshot[] {
  if (typeof window === 'undefined') return [];
  try {
    const raw = localStorage.getItem(RISK_HISTORY_KEY);
    if (!raw) return [];
    const parsed = JSON.parse(raw) as RiskSnapshot[];
    return Array.isArray(parsed) ? parsed : [];
  } catch {
    return [];
  }
}

export function saveRiskHistory(history: RiskSnapshot[]) {
  if (typeof window === 'undefined') return;
  localStorage.setItem(RISK_HISTORY_KEY, JSON.stringify(history.slice(-90)));
}

/** Append or replace today's snapshot (JST). Keeps last 90 entries. */
export function recordRiskSnapshot(snap: RiskSnapshot): RiskSnapshot[] {
  const prev = loadRiskHistory();
  const today = dayKeyJst(snap.t);
  const last = prev[prev.length - 1];
  const next =
    last && dayKeyJst(last.t) === today
      ? [...prev.slice(0, -1), snap]
      : [...prev, snap];
  const capped = next.slice(-90);
  saveRiskHistory(capped);
  return capped;
}
