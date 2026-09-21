import { NextRequest, NextResponse } from 'next/server';
import { isNativeSegwitAddress } from '@/lib/bitcoin';

export const dynamic = 'force-dynamic';

type EsploraAddress = {
  chain_stats?: { funded_txo_sum?: number; spent_txo_sum?: number };
  mempool_stats?: { funded_txo_sum?: number; spent_txo_sum?: number };
};

export async function GET(req: NextRequest) {
  const address = (req.nextUrl.searchParams.get('address') || '').trim();
  if (!isNativeSegwitAddress(address)) {
    return NextResponse.json(
      { error: 'Native SegWit アドレス（bc1q…）のみ対応しています' },
      { status: 400 }
    );
  }

  try {
    const upstream = await fetch(`https://mempool.space/api/address/${encodeURIComponent(address)}`, {
      headers: { Accept: 'application/json' },
      next: { revalidate: 30 },
    });

    if (!upstream.ok) {
      return NextResponse.json(
        { error: `mempool.space error (${upstream.status})` },
        { status: 502 }
      );
    }

    const data = (await upstream.json()) as EsploraAddress;
    const chainFunded = Number(data.chain_stats?.funded_txo_sum) || 0;
    const chainSpent = Number(data.chain_stats?.spent_txo_sum) || 0;
    const sats = Math.max(0, chainFunded - chainSpent);

    return NextResponse.json({
      address,
      sats,
      btc: sats / 1e8,
    });
  } catch {
    return NextResponse.json({ error: 'BTC 残高の取得に失敗しました' }, { status: 502 });
  }
}
