/** Bitcoin Native SegWit (P2WPKH) watch-only helpers. */

export const BTC_META = {
  symbol: 'BTC',
  name: 'Bitcoin (Native SegWit)',
  color: '#f7931a',
  coingeckoId: 'bitcoin',
} as const;

const BECH32_CHARSET = 'qpzry9x8gf2tvdw0s3jn54khce6mua7l';

/**
 * Mainnet Native SegWit (bc1q…). Rejects Taproot (bc1p) and legacy.
 */
export function isNativeSegwitAddress(value: string): boolean {
  const addr = value.trim().toLowerCase();
  if (!addr.startsWith('bc1q')) return false;
  if (addr.length < 42 || addr.length > 62) return false;
  const body = addr.slice(4);
  for (const ch of body) {
    if (!BECH32_CHARSET.includes(ch)) return false;
  }
  return true;
}

export type BtcAddressBalance = {
  address: string;
  sats: number;
  btc: number;
};

/**
 * Confirmed balance via mempool.space Esplora API (proxied by /api/btc/balance).
 */
export async function fetchNativeSegwitBalance(address: string): Promise<BtcAddressBalance> {
  const addr = address.trim();
  if (!isNativeSegwitAddress(addr)) {
    throw new Error('Native SegWit アドレス（bc1q…）を入力してください');
  }

  const res = await fetch(`/api/btc/balance?address=${encodeURIComponent(addr)}`);
  const json = (await res.json()) as { sats?: number; error?: string };
  if (!res.ok) {
    throw new Error(json.error || 'BTC 残高の取得に失敗しました');
  }

  const sats = Number(json.sats) || 0;
  return {
    address: addr,
    sats,
    btc: sats / 1e8,
  };
}
