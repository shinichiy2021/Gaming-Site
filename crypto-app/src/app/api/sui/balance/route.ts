import { NextRequest, NextResponse } from 'next/server';
import { SUI_META, SUI_TRACKED_COINS, SUI_USDC_NATIVE, SUI_USDC_WORMHOLE, normalizeSuiAddress } from '@/lib/sui';

export const dynamic = 'force-dynamic';

const RPC = process.env.SUI_RPC_URL || 'https://1rpc.io/sui';

type CoinBalance = {
  coinType?: string;
  totalBalance?: string;
};

async function rpc<T>(method: string, params: unknown[]): Promise<T> {
  const res = await fetch(RPC, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }),
    next: { revalidate: 30 },
  });
  if (!res.ok) {
    throw new Error(`Sui RPC HTTP ${res.status}`);
  }
  const json = (await res.json()) as { result?: T; error?: { message?: string } };
  if (json.error) {
    throw new Error(json.error.message || 'Sui RPC error');
  }
  return json.result as T;
}

function amountFromBalance(raw: string | undefined, decimals: number): number {
  if (!raw) return 0;
  try {
    const value = BigInt(raw);
    const base = BigInt(10) ** BigInt(decimals);
    const whole = value / base;
    const frac = value % base;
    return Number(whole) + Number(frac) / Number(base);
  } catch {
    return 0;
  }
}

function normalizeCoinType(coinType: string): string {
  return coinType.trim().toLowerCase();
}

/** Sum balances for a tracked coin across known types + needle matches. */
function sumTrackedCoin(
  byType: Map<string, string>,
  coin: (typeof SUI_TRACKED_COINS)[number]
): number {
  const seen = new Set<string>();
  let total = 0;

  const add = (coinType: string | undefined) => {
    if (!coinType) return;
    const key = normalizeCoinType(coinType);
    if (seen.has(key)) return;
    const raw = byType.get(key) ?? byType.get(coinType);
    if (!raw) return;
    seen.add(key);
    total += amountFromBalance(raw, coin.decimals);
  };

  for (const type of coin.coinTypes) {
    add(type);
    // Also try lowercase map key form
    add(normalizeCoinType(type));
  }

  // Catch renamed / alternate packages that still expose ::usdc::USDC
  if (coin.key === 'usdc') {
    for (const [type] of byType) {
      const lower = normalizeCoinType(type);
      if (lower.endsWith('::usdc::usdc')) {
        add(type);
      }
      // Wormhole USDC only (avoid matching unrelated ::coin::COIN)
      if (lower === normalizeCoinType(SUI_USDC_WORMHOLE)) {
        add(type);
      }
    }
  }

  return total;
}

export async function GET(req: NextRequest) {
  const address = normalizeSuiAddress(req.nextUrl.searchParams.get('address') || '');
  if (!address) {
    return NextResponse.json({ error: 'Sui アドレスが不正です' }, { status: 400 });
  }

  try {
    const [allBalances, ...direct] = await Promise.all([
      rpc<CoinBalance[]>('suix_getAllBalances', [address]),
      // Explicit reads in case getAllBalances omits a coin
      rpc<CoinBalance>('suix_getBalance', [address, SUI_USDC_NATIVE]).catch(() => null),
      rpc<CoinBalance>('suix_getBalance', [address, SUI_USDC_WORMHOLE]).catch(() => null),
    ]);

    const byType = new Map<string, string>();
    for (const row of allBalances || []) {
      if (row.coinType && row.totalBalance) {
        byType.set(row.coinType, row.totalBalance);
        byType.set(normalizeCoinType(row.coinType), row.totalBalance);
      }
    }
    for (const row of direct) {
      if (row?.coinType && row.totalBalance) {
        byType.set(row.coinType, row.totalBalance);
        byType.set(normalizeCoinType(row.coinType), row.totalBalance);
      }
    }

    const sui = amountFromBalance(
      byType.get(SUI_META.coinType) ?? byType.get(normalizeCoinType(SUI_META.coinType)),
      SUI_META.decimals
    );

    const tokens: Record<string, number> = {};
    for (const coin of SUI_TRACKED_COINS) {
      tokens[coin.key] = sumTrackedCoin(byType, coin);
    }

    return NextResponse.json({
      address,
      sui,
      tokens,
      usdc: tokens.usdc ?? 0,
      usdcTypes: [SUI_USDC_NATIVE, SUI_USDC_WORMHOLE],
    });
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Sui 残高の取得に失敗しました';
    return NextResponse.json({ error: message }, { status: 502 });
  }
}
