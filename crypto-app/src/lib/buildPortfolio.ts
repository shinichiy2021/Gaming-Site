import type { Holding, Portfolio } from '@/lib/portfolio';

export type AssetSeed = {
  symbol: string;
  name: string;
  amount: number;
  price_usd: number;
  change_24h: number;
  change_7d: number | null;
  color: string;
};

function previousValue(valueUsd: number, changePct: number) {
  const denom = 1 + changePct / 100;
  return denom !== 0 ? valueUsd / denom : valueUsd;
}

export function buildPortfolio(seeds: AssetSeed[], usdJpy: number): Portfolio {
  let totalUsd = 0;
  let prevUsd = 0;
  let prev7dUsd = 0;
  let has7d = true;

  const holdings: Holding[] = seeds
    .filter((row) => row.amount > 0)
    .map((row) => {
      const valueUsd = row.amount * row.price_usd;
      totalUsd += valueUsd;
      prevUsd += previousValue(valueUsd, row.change_24h);
      if (row.change_7d === null) {
        if (valueUsd > 0) has7d = false;
      } else {
        prev7dUsd += previousValue(valueUsd, row.change_7d);
      }
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
  const change7dUsd = has7d ? totalUsd - prev7dUsd : 0;
  const change7dPct = has7d && prev7dUsd > 0 ? (change7dUsd / prev7dUsd) * 100 : 0;

  return {
    currency: 'JPY',
    usd_jpy: usdJpy,
    total_usd: Math.round(totalUsd * 100) / 100,
    total_jpy: Math.round(totalUsd * usdJpy),
    change_24h_usd: Math.round(changeUsd * 100) / 100,
    change_24h_jpy: Math.round(changeUsd * usdJpy),
    change_24h_pct: Math.round(changePct * 100) / 100,
    has_7d: has7d && holdings.length > 0,
    change_7d_usd: Math.round(change7dUsd * 100) / 100,
    change_7d_pct: Math.round(change7dPct * 100) / 100,
    updated_at: new Date().toISOString(),
    holdings,
  };
}
