export const SUI_META = {
  symbol: 'SUI',
  name: 'Sui (native)',
  color: '#4da2ff',
  coingeckoId: 'sui',
  coinType: '0x2::sui::SUI',
  decimals: 9,
} as const;

export type SuiTrackedCoin = {
  key: 'usdc';
  symbol: string;
  name: string;
  /** Primary + legacy / bridged variants to sum. */
  coinTypes: readonly string[];
  decimals: number;
  color: string;
  coingeckoId: string;
};

/** Circle native USDC on Sui (CoinGecko / Circle). */
export const SUI_USDC_NATIVE =
  '0xdba34672e30cb065b1f93e3ab55318768fd6fef66c15942c9f7cb846e2f900e7::usdc::USDC';

/** Legacy Wormhole USDC on Sui (still held by some wallets). */
export const SUI_USDC_WORMHOLE =
  '0x5d4b302506645c37ff133b98c4b14a5bdfbf6e4f78dbfd870e785d8da1c76d15::coin::COIN';

/** Stablecoins / extras tracked on Sui. */
export const SUI_TRACKED_COINS: readonly SuiTrackedCoin[] = [
  {
    key: 'usdc',
    symbol: 'USDC.SUI',
    name: 'USD Coin (Sui)',
    coinTypes: [SUI_USDC_NATIVE, SUI_USDC_WORMHOLE],
    decimals: 6,
    color: '#2775ca',
    coingeckoId: 'usd-coin',
  },
] as const;

/**
 * Sui address: 0x + 1–64 hex chars. Normalize to 0x + 64 hex.
 */
export function normalizeSuiAddress(value: string): string | null {
  const raw = value.trim().toLowerCase();
  if (!/^0x[0-9a-f]{1,64}$/.test(raw)) return null;
  const hex = raw.slice(2).padStart(64, '0');
  return `0x${hex}`;
}

export function isSuiAddress(value: string): boolean {
  return normalizeSuiAddress(value) !== null;
}

export type SuiBalance = {
  address: string;
  sui: number;
  tokens: Record<string, number>;
};

export async function fetchSuiBalance(address: string): Promise<SuiBalance> {
  const normalized = normalizeSuiAddress(address);
  if (!normalized) {
    throw new Error('Sui アドレス（0x…）を入力してください');
  }

  const res = await fetch(`/api/sui/balance?address=${encodeURIComponent(normalized)}`);
  const json = (await res.json()) as {
    sui?: number;
    tokens?: Record<string, number>;
    error?: string;
  };
  if (!res.ok) {
    throw new Error(json.error || 'Sui 残高の取得に失敗しました');
  }

  return {
    address: normalized,
    sui: Number(json.sui) || 0,
    tokens: json.tokens || {},
  };
}
