import type { Holding, Portfolio } from '@/lib/portfolio';

export type AssetSeed = {
  symbol: string;
  name: string;
  amount: number;
  price_usd: number;
  change_24h: number;
  color: string;
};

export function buildPortfolio(seeds: AssetSeed[], usdJpy: number): Portfolio {
  let totalUsd = 0;
  let prevUsd = 0;

  const holdings: Holding[] = seeds
    .filter((row) => row.amount > 0)
    .map((row) => {
      const valueUsd = row.amount * row.price_usd;
      const denom = 1 + row.change_24h / 100;
      const prev = denom !== 0 ? valueUsd / denom : valueUsd;
      totalUsd += valueUsd;
      prevUsd += prev;
      return {
        ...row,
        value_usd: Math.round(valueUsd * 100) / 100,
        value_jpy: Math.round(valueUsd * usdJpy),
        weight: 0,
      };
    })
    .sort((a, b) => b.value_usd - a.value_usd);

  for (const row of holdings) {
    row.weight = totalUsd > 0 ? Math.round((row.value_usd / totalUsd) * 1000) / 10 : 0;
  }

  const changeUsd = totalUsd - prevUsd;
  const changePct = prevUsd > 0 ? (changeUsd / prevUsd) * 100 : 0;

  return {
    currency: 'JPY',
    usd_jpy: usdJpy,
    total_usd: Math.round(totalUsd * 100) / 100,
    total_jpy: Math.round(totalUsd * usdJpy),
    change_24h_usd: Math.round(changeUsd * 100) / 100,
    change_24h_jpy: Math.round(changeUsd * usdJpy),
    change_24h_pct: Math.round(changePct * 100) / 100,
    updated_at: new Date().toISOString(),
    holdings,
  };
}
