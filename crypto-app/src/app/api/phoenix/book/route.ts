import { NextResponse } from 'next/server';
import { Connection, PublicKey } from '@solana/web3.js';
import * as Phoenix from '@ellipsis-labs/phoenix-sdk';

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

/** Mainnet Phoenix SOL/USDC */
const MARKET = '4DoNfFBfF7UokCC2FQzriy7yHK6DY6NVdYpuekQ5pRgg';

type Cache = {
  at: number;
  payload: unknown;
};

declare global {
  var __phoenixBookCache: Cache | undefined;
}

export async function GET() {
  try {
    const cached = globalThis.__phoenixBookCache;
    if (cached && Date.now() - cached.at < 2500) {
      return NextResponse.json(cached.payload);
    }

    const rpc =
      process.env.SOLANA_RPC_URL ||
      process.env.RPC_URL ||
      'https://api.mainnet-beta.solana.com';

    const connection = new Connection(rpc, { commitment: 'confirmed' });
    const phoenix = await Phoenix.Client.create(connection);
    const marketPk = new PublicKey(MARKET);
    await phoenix.refreshMarket(marketPk);

    const marketState = phoenix.marketStates.get(MARKET);
    if (!marketState) {
      return NextResponse.json({ error: 'Market not found' }, { status: 404 });
    }

    const ladder = phoenix.getUiLadder(MARKET, 12);
    const bestBid = ladder.bids[0]?.price ?? null;
    const bestAsk = ladder.asks[0]?.price ?? null;
    const mid =
      bestBid !== null && bestAsk !== null ? (bestBid + bestAsk) / 2 : null;
    const spreadBps =
      mid && bestBid !== null && bestAsk !== null
        ? ((bestAsk - bestBid) / mid) * 10_000
        : null;

    const payload = {
      market: MARKET,
      mid,
      bestBid,
      bestAsk,
      spreadBps,
      bids: ladder.bids.slice(0, 12),
      asks: ladder.asks.slice(0, 12),
      updatedAt: new Date().toISOString(),
    };

    globalThis.__phoenixBookCache = { at: Date.now(), payload };
    return NextResponse.json(payload);
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Failed to load book';
    return NextResponse.json({ error: message }, { status: 502 });
  }
}
