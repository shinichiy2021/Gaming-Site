import type { PriceMap } from '@/lib/prices';

export const PAPER_STARTING_CASH = 1000;
export const PAPER_FEE_BPS = 10; // 0.10%
export const PAPER_STORAGE_KEY = 'gaming-hub-paper-bot-v2';

export type PaperSymbol = 'BTC' | 'ETH' | 'SOL' | 'LINK' | 'RENDER' | 'SUI';

export const PAPER_UNIVERSE: {
  symbol: PaperSymbol;
  name: string;
  coingeckoId: string;
  color: string;
}[] = [
  { symbol: 'BTC', name: 'Bitcoin', coingeckoId: 'bitcoin', color: '#f7931a' },
  { symbol: 'ETH', name: 'Ethereum', coingeckoId: 'ethereum', color: '#627eea' },
  { symbol: 'SOL', name: 'Solana', coingeckoId: 'solana', color: '#14f195' },
  { symbol: 'LINK', name: 'Chainlink', coingeckoId: 'chainlink', color: '#2a5ada' },
  { symbol: 'RENDER', name: 'Render', coingeckoId: 'render-token', color: '#6c3ce9' },
  { symbol: 'SUI', name: 'Sui', coingeckoId: 'sui', color: '#4da2ff' },
];

export type PaperPosition = {
  symbol: PaperSymbol;
  amount: number;
  avgCostUsd: number;
};

export type PaperTrade = {
  id: string;
  at: string;
  side: 'buy' | 'sell';
  symbol: PaperSymbol;
  amount: number;
  priceUsd: number;
  notionalUsd: number;
  feeUsd: number;
  reason: string;
};

export type EquityPoint = { t: string; equityUsd: number };

export type PaperBotState = {
  version: 2;
  startingCash: number;
  cashUsd: number;
  positions: PaperPosition[];
  trades: PaperTrade[];
  lastPrices: Partial<Record<PaperSymbol, number>>;
  lastTickAt: string | null;
  createdAt: string;
  tickCount: number;
  equityHistory: EquityPoint[];
  /** Paper maker quotes around SOL mid (bps), for book overlay */
  gridSpreadBps: number;
};

export type PaperSnapshot = {
  equityUsd: number;
  positionsValueUsd: number;
  pnlUsd: number;
  pnlPct: number;
  holdings: {
    symbol: PaperSymbol;
    name: string;
    color: string;
    amount: number;
    priceUsd: number;
    valueUsd: number;
    avgCostUsd: number;
    pnlUsd: number;
    pnlPct: number;
    weight: number;
    change24h: number;
  }[];
};

function feeOn(notional: number) {
  return (notional * PAPER_FEE_BPS) / 10_000;
}

export function createPaperBotState(startingCash = PAPER_STARTING_CASH): PaperBotState {
  const now = new Date().toISOString();
  return {
    version: 2,
    startingCash,
    cashUsd: startingCash,
    positions: [],
    trades: [],
    lastPrices: {},
    lastTickAt: null,
    createdAt: now,
    tickCount: 0,
    equityHistory: [{ t: now, equityUsd: startingCash }],
    gridSpreadBps: 20,
  };
}

export function loadPaperBotState(): PaperBotState {
  if (typeof window === 'undefined') return createPaperBotState();
  try {
    const raw =
      localStorage.getItem(PAPER_STORAGE_KEY) ||
      localStorage.getItem('gaming-hub-paper-bot-v1');
    if (!raw) return createPaperBotState();
    const parsed = JSON.parse(raw) as Partial<PaperBotState> & { version?: number };
    if (typeof parsed.cashUsd !== 'number') return createPaperBotState();
    const base = createPaperBotState(parsed.startingCash ?? PAPER_STARTING_CASH);
    return {
      ...base,
      ...parsed,
      version: 2,
      equityHistory: parsed.equityHistory?.length
        ? parsed.equityHistory
        : [{ t: parsed.createdAt ?? base.createdAt, equityUsd: parsed.cashUsd }],
      gridSpreadBps: parsed.gridSpreadBps ?? 20,
      positions: parsed.positions ?? [],
      trades: parsed.trades ?? [],
      lastPrices: parsed.lastPrices ?? {},
    };
  } catch {
    return createPaperBotState();
  }
}

export function savePaperBotState(state: PaperBotState) {
  if (typeof window === 'undefined') return;
  localStorage.setItem(PAPER_STORAGE_KEY, JSON.stringify(state));
}

export function priceFor(symbol: PaperSymbol, prices: PriceMap): number {
  const meta = PAPER_UNIVERSE.find((u) => u.symbol === symbol);
  if (!meta) return 0;
  return prices[meta.coingeckoId]?.usd || 0;
}

export function change24hFor(symbol: PaperSymbol, prices: PriceMap): number {
  const meta = PAPER_UNIVERSE.find((u) => u.symbol === symbol);
  if (!meta) return 0;
  return prices[meta.coingeckoId]?.change_24h || 0;
}

export function buildSnapshot(state: PaperBotState, prices: PriceMap): PaperSnapshot {
  const holdings = state.positions
    .map((p) => {
      const meta = PAPER_UNIVERSE.find((u) => u.symbol === p.symbol)!;
      const priceUsd = priceFor(p.symbol, prices);
      const valueUsd = p.amount * priceUsd;
      const cost = p.avgCostUsd * p.amount;
      const pnlUsd = valueUsd - cost;
      const pnlPct = cost > 0 ? (pnlUsd / cost) * 100 : 0;
      return {
        symbol: p.symbol,
        name: meta.name,
        color: meta.color,
        amount: p.amount,
        priceUsd,
        valueUsd,
        avgCostUsd: p.avgCostUsd,
        pnlUsd,
        pnlPct,
        weight: 0,
        change24h: change24hFor(p.symbol, prices),
      };
    })
    .filter((h) => h.valueUsd > 0.01)
    .sort((a, b) => b.valueUsd - a.valueUsd);

  const positionsValueUsd = holdings.reduce((s, h) => s + h.valueUsd, 0);
  const equityUsd = state.cashUsd + positionsValueUsd;
  for (const h of holdings) {
    h.weight = equityUsd > 0 ? Math.round((h.valueUsd / equityUsd) * 1000) / 10 : 0;
  }

  const pnlUsd = equityUsd - state.startingCash;
  const pnlPct = state.startingCash > 0 ? (pnlUsd / state.startingCash) * 100 : 0;

  return { equityUsd, positionsValueUsd, pnlUsd, pnlPct, holdings };
}

function upsertPosition(state: PaperBotState, symbol: PaperSymbol, amountDelta: number, price: number) {
  const idx = state.positions.findIndex((p) => p.symbol === symbol);
  if (idx < 0) {
    if (amountDelta <= 0) return;
    state.positions.push({ symbol, amount: amountDelta, avgCostUsd: price });
    return;
  }
  const pos = state.positions[idx];
  if (amountDelta > 0) {
    const newAmount = pos.amount + amountDelta;
    pos.avgCostUsd = (pos.avgCostUsd * pos.amount + price * amountDelta) / newAmount;
    pos.amount = newAmount;
  } else {
    pos.amount += amountDelta;
    if (pos.amount <= 1e-12) {
      state.positions.splice(idx, 1);
    }
  }
}

function executeBuy(
  state: PaperBotState,
  symbol: PaperSymbol,
  spendUsd: number,
  price: number,
  reason: string,
): PaperTrade | null {
  if (spendUsd < 5 || price <= 0 || state.cashUsd < 5) return null;
  const capped = Math.min(spendUsd, state.cashUsd * 0.98);
  if (capped < 5) return null;
  const fee = feeOn(capped);
  const net = capped - fee;
  const amount = net / price;
  state.cashUsd -= capped;
  upsertPosition(state, symbol, amount, price);
  const trade: PaperTrade = {
    id: `${Date.now()}-${symbol}-buy`,
    at: new Date().toISOString(),
    side: 'buy',
    symbol,
    amount,
    priceUsd: price,
    notionalUsd: capped,
    feeUsd: fee,
    reason,
  };
  state.trades.unshift(trade);
  return trade;
}

function executeSell(
  state: PaperBotState,
  symbol: PaperSymbol,
  fraction: number,
  price: number,
  reason: string,
): PaperTrade | null {
  const pos = state.positions.find((p) => p.symbol === symbol);
  if (!pos || price <= 0) return null;
  const amount = pos.amount * Math.min(1, Math.max(0, fraction));
  if (amount * price < 5) return null;
  const notional = amount * price;
  const fee = feeOn(notional);
  state.cashUsd += notional - fee;
  upsertPosition(state, symbol, -amount, price);
  const trade: PaperTrade = {
    id: `${Date.now()}-${symbol}-sell`,
    at: new Date().toISOString(),
    side: 'sell',
    symbol,
    amount,
    priceUsd: price,
    notionalUsd: notional,
    feeUsd: fee,
    reason,
  };
  state.trades.unshift(trade);
  return trade;
}

/**
 * Rule-based paper day-trade tick (momentum + dip-buy + stop / take-profit).
 * Not financial advice — simulation only.
 */
export function runPaperTick(state: PaperBotState, prices: PriceMap): {
  state: PaperBotState;
  trades: PaperTrade[];
  notes: string[];
} {
  const next: PaperBotState = {
    ...state,
    positions: state.positions.map((p) => ({ ...p })),
    trades: [...state.trades],
    lastPrices: { ...state.lastPrices },
    equityHistory: [...(state.equityHistory ?? [])],
    gridSpreadBps: state.gridSpreadBps ?? 20,
  };
  const newTrades: PaperTrade[] = [];
  const notes: string[] = [];
  const snap = buildSnapshot(next, prices);

  for (const asset of PAPER_UNIVERSE) {
    const price = priceFor(asset.symbol, prices);
    if (price <= 0) continue;
    const ch24 = change24hFor(asset.symbol, prices);
    const prev = next.lastPrices[asset.symbol];
    const tickMove = prev && prev > 0 ? ((price - prev) / prev) * 100 : 0;
    const holding = next.positions.find((p) => p.symbol === asset.symbol);
    const holdingValue = holding ? holding.amount * price : 0;
    const holdingPct = snap.equityUsd > 0 ? (holdingValue / snap.equityUsd) * 100 : 0;
    const unrealizedPct =
      holding && holding.avgCostUsd > 0 ? ((price - holding.avgCostUsd) / holding.avgCostUsd) * 100 : 0;

    // Stop-loss
    if (holding && unrealizedPct <= -6) {
      const t = executeSell(next, asset.symbol, 1, price, `損切り: 含み ${unrealizedPct.toFixed(1)}%`);
      if (t) {
        newTrades.push(t);
        notes.push(`${asset.symbol} を損切り（含み ${unrealizedPct.toFixed(1)}%）`);
      }
      next.lastPrices[asset.symbol] = price;
      continue;
    }

    // Take profit on strength
    if (holding && (unrealizedPct >= 8 || ch24 >= 5) && holdingPct >= 8) {
      const t = executeSell(
        next,
        asset.symbol,
        0.35,
        price,
        `利確: 含み ${unrealizedPct.toFixed(1)}% / 24h ${ch24.toFixed(1)}%`,
      );
      if (t) {
        newTrades.push(t);
        notes.push(`${asset.symbol} の 35% を利確`);
      }
    }

    // Dip buy (mean reversion lite)
    const wantBuy =
      (ch24 <= -3 || tickMove <= -1.2) &&
      next.cashUsd >= 40 &&
      holdingPct < 35;

    if (wantBuy) {
      const budget = Math.min(next.cashUsd * 0.18, snap.equityUsd * 0.12, 180);
      const t = executeBuy(
        next,
        asset.symbol,
        budget,
        price,
        `押し目買い: 24h ${ch24.toFixed(1)}%` + (tickMove ? ` / tick ${tickMove.toFixed(2)}%` : ''),
      );
      if (t) {
        newTrades.push(t);
        notes.push(`${asset.symbol} を約 $${t.notionalUsd.toFixed(0)} 買い`);
      }
    }

    // Momentum add (small)
    if (holding && ch24 >= 2 && ch24 < 5 && tickMove > 0.4 && next.cashUsd >= 30 && holdingPct < 40) {
      const budget = Math.min(next.cashUsd * 0.08, 60);
      const t = executeBuy(next, asset.symbol, budget, price, `順張り追加: 24h ${ch24.toFixed(1)}%`);
      if (t) {
        newTrades.push(t);
        notes.push(`${asset.symbol} に順張り追加 $${t.notionalUsd.toFixed(0)}`);
      }
    }

    next.lastPrices[asset.symbol] = price;
  }

  // Keep some cash: if cash < 15% after buys, skip further (already capped per trade)
  const after = buildSnapshot(next, prices);
  if (after.equityUsd > 0 && next.cashUsd / after.equityUsd < 0.12) {
    notes.push('現金比率が低いため、追加買いは抑制中（最低〜12%現金を維持）');
  }

  if (!newTrades.length) {
    notes.push('今回のティックでは売買条件にヒットせず（様子見）');
  }

  next.tickCount += 1;
  next.lastTickAt = new Date().toISOString();
  next.trades = next.trades.slice(0, 80);
  next.equityHistory = [
    ...next.equityHistory,
    { t: next.lastTickAt, equityUsd: after.equityUsd },
  ].slice(-120);

  return { state: next, trades: newTrades, notes };
}
