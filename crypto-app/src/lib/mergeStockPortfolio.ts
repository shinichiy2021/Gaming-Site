import {
  ACCOUNT_META,
  HOLDING_COLORS,
  MARKET_META,
  type MarketSummary,
  type StockAccountKind,
  type StockHolding,
  type StockPortfolio,
  type StockSectionSummary,
} from '@/lib/stockPortfolio';

type ForeignHoldingRaw = {
  name: string;
  code: string;
  exchange: string;
  priceUsd: number;
  priceJpy: number;
  quantity: number;
  costUsd: number;
  costJpy: number;
  costAmountUsd: number;
  costAmountJpy: number;
  valueUsd: number;
  valueJpy: number;
  pnlUsd: number;
  pnlJpy: number;
};

type ForeignSectionRaw = {
  account: StockAccountKind;
  valueUsd: number;
  valueJpy: number;
  pnlUsd: number;
  pnlUsdPct: number;
  pnlJpy: number;
  pnlJpyPct: number;
  holdings: ForeignHoldingRaw[];
};

export type ForeignPortfolioRaw = {
  usdJpy: number;
  fxAsOf: string;
  sections: ForeignSectionRaw[];
  cash: {
    currency: string;
    quantityUsd: number;
    valueJpy: number;
  };
};

function pctFromCost(pnl: number, cost: number): number {
  if (!cost) return 0;
  return Math.round((pnl / cost) * 10000) / 100;
}

function withWeights(holdings: StockHolding[], totalValue: number): StockHolding[] {
  return holdings
    .slice()
    .sort((a, b) => b.value - a.value)
    .map((h, i) => ({
      ...h,
      weight: totalValue > 0 ? Math.round((h.value / totalValue) * 1000) / 10 : 0,
      color: h.color || HOLDING_COLORS[i % HOLDING_COLORS.length],
    }));
}

export function parseForeignPortfolio(raw: ForeignPortfolioRaw): {
  holdings: StockHolding[];
  sections: StockSectionSummary[];
  usdJpy: number;
  fxAsOf: string;
  totalValue: number;
  totalPnlJpy: number;
  totalValueUsd: number;
  totalPnlUsd: number;
} {
  const holdings: StockHolding[] = [];
  const sections: StockSectionSummary[] = [];

  for (const section of raw.sections) {
    const meta = ACCOUNT_META[section.account];
    for (const [i, h] of section.holdings.entries()) {
      const cost = h.costAmountUsd || h.valueUsd - h.pnlUsd;
      holdings.push({
        id: `foreign-${section.account}-${h.code}-${i}`,
        code: h.code,
        name: h.name,
        market: 'foreign',
        exchange: h.exchange,
        account: section.account,
        accountLabel: meta.label,
        buyDate: null,
        quantity: h.quantity,
        costPrice: h.costUsd,
        currentPrice: h.priceUsd,
        dayChange: 0,
        dayChangePct: 0,
        pnl: h.pnlUsd,
        pnlPct: pctFromCost(h.pnlUsd, cost),
        value: h.valueJpy,
        valueUsd: h.valueUsd,
        pnlJpy: h.pnlJpy,
        weight: 0,
        color: HOLDING_COLORS[(holdings.length + i) % HOLDING_COLORS.length],
      });
    }

    sections.push({
      account: section.account,
      accountLabel: meta.label,
      market: 'foreign',
      value: section.valueJpy,
      valueUsd: section.valueUsd,
      pnl: section.pnlUsd,
      pnlJpy: section.pnlJpy,
      pnlPct: section.pnlJpyPct,
      dayChange: 0,
      dayChangePct: 0,
      weight: 0,
      color: meta.color,
      holdingsCount: section.holdings.length,
    });
  }

  if (raw.cash?.quantityUsd) {
    const meta = ACCOUNT_META.cash_usd;
    holdings.push({
      id: 'foreign-cash-usd',
      code: 'USD',
      name: '米ドル預り金',
      market: 'foreign',
      exchange: null,
      account: 'cash_usd',
      accountLabel: meta.label,
      buyDate: null,
      quantity: raw.cash.quantityUsd,
      costPrice: 1,
      currentPrice: 1,
      dayChange: 0,
      dayChangePct: 0,
      pnl: 0,
      pnlPct: 0,
      value: raw.cash.valueJpy,
      valueUsd: raw.cash.quantityUsd,
      pnlJpy: 0,
      weight: 0,
      color: meta.color,
      isCash: true,
    });
    sections.push({
      account: 'cash_usd',
      accountLabel: meta.label,
      market: 'foreign',
      value: raw.cash.valueJpy,
      valueUsd: raw.cash.quantityUsd,
      pnl: 0,
      pnlJpy: 0,
      pnlPct: 0,
      dayChange: 0,
      dayChangePct: 0,
      weight: 0,
      color: meta.color,
      holdingsCount: 1,
    });
  }

  const totalValue = holdings.reduce((s, h) => s + h.value, 0);
  const totalPnlJpy = sections.reduce((s, sec) => s + sec.pnlJpy, 0);
  const totalValueUsd = holdings.reduce((s, h) => s + (h.valueUsd ?? 0), 0);
  const totalPnlUsd = sections.reduce((s, sec) => s + (sec.account === 'cash_usd' ? 0 : sec.pnl), 0);

  return {
    holdings: withWeights(holdings, totalValue),
    sections,
    usdJpy: raw.usdJpy,
    fxAsOf: raw.fxAsOf,
    totalValue,
    totalPnlJpy,
    totalValueUsd,
    totalPnlUsd,
  };
}

export function normalizeDomesticPortfolio(domestic: StockPortfolio): StockPortfolio {
  const holdings: StockHolding[] = domestic.holdings.map((h) => ({
    ...h,
    market: 'domestic' as const,
    exchange: h.exchange ?? 'TSE',
    valueUsd: null,
    pnlJpy: h.pnlJpy ?? h.pnl,
  }));

  const sections: StockSectionSummary[] = domestic.sections.map((s) => ({
    ...s,
    market: 'domestic' as const,
    valueUsd: null,
    pnlJpy: s.pnlJpy ?? s.pnl,
  }));

  return {
    ...domestic,
    holdings,
    sections,
    markets: [
      {
        market: 'domestic',
        label: MARKET_META.domestic.label,
        value: domestic.totalValue,
        valueUsd: null,
        pnlJpy: domestic.totalPnl,
        weight: 100,
        color: MARKET_META.domestic.color,
      },
    ],
    totalValueUsd: 0,
    totalPnlUsd: 0,
    usdJpy: null,
    fxAsOf: null,
  };
}

export function mergePortfolios(
  domestic: StockPortfolio,
  foreignRaw: ForeignPortfolioRaw,
  source: 'seed' | 'upload',
): StockPortfolio {
  const domesticNorm = normalizeDomesticPortfolio(domestic);
  const foreign = parseForeignPortfolio(foreignRaw);

  const totalValue = domesticNorm.totalValue + foreign.totalValue;
  const totalPnl = domesticNorm.totalPnl + foreign.totalPnlJpy;
  const costBasis = totalValue - totalPnl;
  const totalPnlPct = costBasis > 0 ? Math.round((totalPnl / costBasis) * 10000) / 100 : 0;

  const holdings = withWeights([...domesticNorm.holdings, ...foreign.holdings], totalValue);

  const sections: StockSectionSummary[] = [...domesticNorm.sections, ...foreign.sections].map((s) => ({
    ...s,
    weight: totalValue > 0 ? Math.round((s.value / totalValue) * 1000) / 10 : 0,
  }));

  const domesticValue = domesticNorm.totalValue;
  const foreignEquityValue = foreign.sections
    .filter((s) => s.account !== 'cash_usd')
    .reduce((sum, s) => sum + s.value, 0);
  const cashValue = foreign.sections.find((s) => s.account === 'cash_usd')?.value ?? 0;
  const foreignPnl = foreign.sections
    .filter((s) => s.account !== 'cash_usd')
    .reduce((sum, s) => sum + s.pnlJpy, 0);
  const cashUsd = foreign.sections.find((s) => s.account === 'cash_usd')?.valueUsd ?? 0;

  const markets: MarketSummary[] = [
    {
      market: 'domestic',
      label: MARKET_META.domestic.label,
      value: domesticValue,
      valueUsd: null,
      pnlJpy: domesticNorm.totalPnl,
      weight: totalValue > 0 ? Math.round((domesticValue / totalValue) * 1000) / 10 : 0,
      color: MARKET_META.domestic.color,
    },
    {
      market: 'foreign',
      label: MARKET_META.foreign.label,
      value: foreignEquityValue,
      valueUsd: foreign.totalValueUsd - cashUsd,
      pnlJpy: foreignPnl,
      weight: totalValue > 0 ? Math.round((foreignEquityValue / totalValue) * 1000) / 10 : 0,
      color: MARKET_META.foreign.color,
    },
  ];

  if (cashValue > 0) {
    markets.push({
      market: 'cash',
      label: MARKET_META.cash.label,
      value: cashValue,
      valueUsd: cashUsd,
      pnlJpy: 0,
      weight: totalValue > 0 ? Math.round((cashValue / totalValue) * 1000) / 10 : 0,
      color: MARKET_META.cash.color,
    });
  }

  return {
    holdings,
    sections,
    markets,
    totalValue,
    totalPnl,
    totalPnlPct,
    totalValueUsd: foreign.totalValueUsd,
    totalPnlUsd: foreign.totalPnlUsd,
    dayChange: domesticNorm.dayChange,
    dayChangePct: domesticNorm.dayChangePct,
    usdJpy: foreign.usdJpy,
    fxAsOf: foreign.fxAsOf,
    updatedAt: new Date().toISOString(),
    source,
  };
}
