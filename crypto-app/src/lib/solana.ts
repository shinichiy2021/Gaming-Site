export const SOL_META = {
  symbol: 'SOL',
  name: 'Solana (native)',
  color: '#14f195',
  coingeckoId: 'solana',
} as const;

export type SolTrackedToken = {
  key: 'usdc' | 'render';
  symbol: string;
  name: string;
  mint: string;
  decimals: number;
  color: string;
  coingeckoId: string;
};

/** SPL tokens tracked for Solana watch addresses. */
export const SOL_TRACKED_TOKENS: readonly SolTrackedToken[] = [
  {
    key: 'usdc',
    symbol: 'USDC.SOL',
    name: 'USD Coin (Solana)',
    mint: 'EPjFWdd5AufqSSqeM2qN1xzybapC8G4wEGGkZwyTDt1v',
    decimals: 6,
    color: '#2775ca',
    coingeckoId: 'usd-coin',
  },
  {
    key: 'render',
    symbol: 'RENDER.SOL',
    name: 'Render (Solana)',
    mint: 'rndrizKT3MK1iimdxRdWabcF7Zg7AR5T4nud4EkHBof',
    decimals: 8,
    color: '#6c3ce9',
    coingeckoId: 'render-token',
  },
] as const;

/** @deprecated use SOL_TRACKED_TOKENS */
export const SOL_USDC_MINT = SOL_TRACKED_TOKENS[0].mint;

export const SOL_USDC_META = SOL_TRACKED_TOKENS[0];

/** Base58 Solana address (no 0/O/I/l). */
export function isSolanaAddress(value: string): boolean {
  const addr = value.trim();
  if (addr.length < 32 || addr.length > 44) return false;
  return /^[1-9A-HJ-NP-Za-km-z]+$/.test(addr);
}

export type SolanaBalance = {
  address: string;
  sol: number;
  tokens: Record<string, number>;
};

export async function fetchSolanaBalance(address: string): Promise<SolanaBalance> {
  const addr = address.trim();
  if (!isSolanaAddress(addr)) {
    throw new Error('Solana アドレスを入力してください');
  }

  const res = await fetch(`/api/sol/balance?address=${encodeURIComponent(addr)}`);
  const json = (await res.json()) as {
    sol?: number;
    tokens?: Record<string, number>;
    usdc?: number;
    render?: number;
    error?: string;
  };
  if (!res.ok) {
    throw new Error(json.error || 'Solana 残高の取得に失敗しました');
  }

  const tokens = { ...(json.tokens || {}) };
  if (json.usdc !== undefined) tokens.usdc = Number(json.usdc) || 0;
  if (json.render !== undefined) tokens.render = Number(json.render) || 0;

  return {
    address: addr,
    sol: Number(json.sol) || 0,
    tokens,
  };
}
