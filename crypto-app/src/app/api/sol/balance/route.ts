import { NextRequest, NextResponse } from 'next/server';
import { SOL_TRACKED_TOKENS, isSolanaAddress } from '@/lib/solana';

export const dynamic = 'force-dynamic';

const RPC = process.env.SOLANA_RPC_URL || 'https://api.mainnet-beta.solana.com';

type TokenAccountResult = {
  value: Array<{
    account: {
      data: {
        parsed?: {
          info?: {
            mint?: string;
            tokenAmount?: { uiAmount?: number | null; amount?: string; decimals?: number };
          };
        };
      };
    };
  }>;
};

async function rpc<T>(method: string, params: unknown[]): Promise<T> {
  const res = await fetch(RPC, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ jsonrpc: '2.0', id: 1, method, params }),
    next: { revalidate: 30 },
  });
  if (!res.ok) {
    throw new Error(`Solana RPC HTTP ${res.status}`);
  }
  const json = (await res.json()) as { result?: T; error?: { message?: string } };
  if (json.error) {
    throw new Error(json.error.message || 'Solana RPC error');
  }
  return json.result as T;
}

/** getBalance may return a bare number or `{ value: number }`. */
function parseLamports(result: unknown): number {
  if (typeof result === 'number' && Number.isFinite(result)) {
    return result;
  }
  if (result && typeof result === 'object' && 'value' in result) {
    const value = (result as { value: unknown }).value;
    if (typeof value === 'number' && Number.isFinite(value)) {
      return value;
    }
  }
  return 0;
}

function sumMintBalance(result: TokenAccountResult | undefined, mint: string, fallbackDecimals: number): number {
  let total = 0;
  for (const row of result?.value || []) {
    const info = row.account?.data?.parsed?.info;
    if (info?.mint !== mint) continue;
    const ui = info.tokenAmount?.uiAmount;
    if (typeof ui === 'number' && Number.isFinite(ui)) {
      total += ui;
    } else if (info.tokenAmount?.amount) {
      const decimals = info.tokenAmount.decimals ?? fallbackDecimals;
      total += Number(info.tokenAmount.amount) / 10 ** decimals;
    }
  }
  return total;
}

export async function GET(req: NextRequest) {
  const address = (req.nextUrl.searchParams.get('address') || '').trim();
  if (!isSolanaAddress(address)) {
    return NextResponse.json({ error: 'Solana アドレスが不正です' }, { status: 400 });
  }

  try {
    const [balanceRaw, ...tokenResults] = await Promise.all([
      rpc<unknown>('getBalance', [address, { commitment: 'confirmed' }]),
      ...SOL_TRACKED_TOKENS.map((token) =>
        rpc<TokenAccountResult>('getTokenAccountsByOwner', [
          address,
          { mint: token.mint },
          { encoding: 'jsonParsed', commitment: 'confirmed' },
        ])
      ),
    ]);

    const lamports = parseLamports(balanceRaw);
    const tokens: Record<string, number> = {};
    SOL_TRACKED_TOKENS.forEach((token, i) => {
      tokens[token.key] = sumMintBalance(tokenResults[i], token.mint, token.decimals);
    });

    return NextResponse.json({
      address,
      lamports,
      sol: lamports / 1e9,
      tokens,
      usdc: tokens.usdc ?? 0,
      render: tokens.render ?? 0,
    });
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Solana 残高の取得に失敗しました';
    return NextResponse.json({ error: message }, { status: 502 });
  }
}
