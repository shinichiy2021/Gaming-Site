import { withBase } from '@/lib/basePath';
import { mergePortfolios, type ForeignPortfolioRaw } from '@/lib/mergeStockPortfolio';
import { parseSbiPortfolioCsv } from '@/lib/sbiCsv';
import type { StockPortfolio } from '@/lib/stockPortfolio';

export const SBI_CSV_STORAGE_KEY = 'gaming-hub-sbi-csv-v1';

async function fetchForeign(): Promise<ForeignPortfolioRaw> {
  const res = await fetch(withBase('/data/sbi-foreign.json'), { cache: 'no-store' });
  if (!res.ok) throw new Error('foreign missing');
  return (await res.json()) as ForeignPortfolioRaw;
}

async function fetchDomesticSeed(): Promise<string> {
  const res = await fetch(withBase('/data/sbi-portfolio.csv'), { cache: 'no-store' });
  if (!res.ok) throw new Error('domestic missing');
  return res.text();
}

/**
 * Load domestic CSV (upload or seed) + foreign JSON, same as StockDashboard.
 */
export async function fetchMergedStockPortfolio(csvText?: string | null): Promise<StockPortfolio> {
  const hasUpload = Boolean(csvText);
  const [domesticText, foreign] = await Promise.all([
    hasUpload ? Promise.resolve(csvText as string) : fetchDomesticSeed(),
    fetchForeign(),
  ]);
  const source = hasUpload ? 'upload' : 'seed';
  const domestic = parseSbiPortfolioCsv(domesticText, source);
  return mergePortfolios(domestic, foreign, source);
}

export function readSavedSbiCsv(): string | null {
  if (typeof window === 'undefined') return null;
  return localStorage.getItem(SBI_CSV_STORAGE_KEY);
}
