import type { Client, MarketState, UiLadder } from "@ellipsis-labs/phoenix-sdk";
import { PublicKey } from "@solana/web3.js";

export type BookSnapshot = {
  bestBid: number | null;
  bestAsk: number | null;
  mid: number | null;
  spreadBps: number | null;
  ladder: UiLadder;
};

/**
 * Refresh market account data, then read L2 UiLadder (human units).
 * Bids/asks prices are quote per base (USDC per SOL).
 */
export async function fetchUiLadder(
  phoenix: Client,
  marketAddress: string,
  levels = 10,
): Promise<{ marketState: MarketState; snapshot: BookSnapshot }> {
  await phoenix.refreshMarket(new PublicKey(marketAddress));

  const marketState = phoenix.marketStates.get(marketAddress);
  if (!marketState) {
    throw new Error(`Market state missing after refresh: ${marketAddress}`);
  }

  const ladder = phoenix.getUiLadder(marketAddress, levels);
  const bestBid = ladder.bids[0]?.price ?? null;
  const bestAsk = ladder.asks[0]?.price ?? null;

  let mid: number | null = null;
  let spreadBps: number | null = null;
  if (bestBid !== null && bestAsk !== null && bestBid > 0 && bestAsk > 0) {
    mid = (bestBid + bestAsk) / 2;
    spreadBps = ((bestAsk - bestBid) / mid) * 10_000;
  }

  return {
    marketState,
    snapshot: { bestBid, bestAsk, mid, spreadBps, ladder },
  };
}

export function printBookTop(snapshot: BookSnapshot, depth = 5): void {
  const { ladder, mid, spreadBps } = snapshot;
  console.log(
    `[book] mid=${mid?.toFixed(4) ?? "n/a"} spreadBps=${spreadBps?.toFixed(1) ?? "n/a"}`,
  );
  console.log("  asks (nearest first):");
  for (const level of ladder.asks.slice(0, depth).reverse()) {
    console.log(`    ${level.price.toFixed(4)}  x ${level.quantity.toFixed(4)}`);
  }
  console.log("  ----");
  console.log("  bids:");
  for (const level of ladder.bids.slice(0, depth)) {
    console.log(`    ${level.price.toFixed(4)}  x ${level.quantity.toFixed(4)}`);
  }
}

export function hasTraderSeat(marketState: MarketState, trader: PublicKey): boolean {
  return marketState.data.traderPubkeyToTraderIndex.has(trader.toBase58());
}

export function getMarketMints(marketState: MarketState): {
  baseMint: PublicKey;
  quoteMint: PublicKey;
  baseDecimals: number;
  quoteDecimals: number;
} {
  return {
    baseMint: marketState.data.header.baseParams.mintKey,
    quoteMint: marketState.data.header.quoteParams.mintKey,
    baseDecimals: marketState.data.header.baseParams.decimals,
    quoteDecimals: marketState.data.header.quoteParams.decimals,
  };
}
