import { NextRequest, NextResponse } from 'next/server';
import { isNativeSegwitAddress } from '@/lib/bitcoin';

export const dynamic = 'force-dynamic';

type EsploraAddress = {
  chain_stats?: { funded_txo_sum?: number; spent_txo_sum?: number };
  mempool_stats?: { funded_txo_sum?: number; spent_txo_sum?: number };
};

const ESPLORA_HOSTS = [
  'https://mempool.space/api',
  'https://blockstream.info/api',
] as const;

function satsFromEsplora(data: EsploraAddress): number {
  const chainFunded = Number(data.chain_stats?.funded_txo_sum) || 0;
  const chainSpent = Number(data.chain_stats?.spent_txo_sum) || 0;
  const memFunded = Number(data.mempool_stats?.funded_txo_sum) || 0;
  const memSpent = Number(data.mempool_stats?.spent_txo_sum) || 0;
  return Math.max(0, chainFunded - chainSpent + memFunded - memSpent);
}

async function fetchEsploraBalance(address: string): Promise<number> {
  let lastStatus = 0;

  for (const base of ESPLORA_HOSTS) {
    try {
      const upstream = await fetch(`${base}/address/${encodeURIComponent(address)}`, {
        headers: { Accept: 'application/json' },
        cache: 'no-store',
      });
      lastStatus = upstream.status;
      if (!upstream.ok) continue;
      const data = (await upstream.json()) as EsploraAddress;
      return satsFromEsplora(data);
    } catch {
      /* try next host */
    }
  }

  throw new Error(lastStatus ? `esplora error (${lastStatus})` : 'BTC 残高の取得に失敗しました');
}

export async function GET(req: NextRequest) {
  const address = (req.nextUrl.searchParams.get('address') || '').trim();
  if (!isNativeSegwitAddress(address)) {
    return NextResponse.json(
      { error: 'Native SegWit アドレス（bc1q…）のみ対応しています' },
      { status: 400 }
    );
  }

  try {
    const sats = await fetchEsploraBalance(address);
    return NextResponse.json({
      address,
      sats,
      btc: sats / 1e8,
    });
  } catch (err) {
    const message = err instanceof Error ? err.message : 'BTC 残高の取得に失敗しました';
    return NextResponse.json({ error: message }, { status: 502 });
  }
}
