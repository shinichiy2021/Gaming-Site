export type PriceQuote = {
  usd: number;
  jpy: number;
  change_24h: number;
  /** null when CoinGecko markets data was unavailable */
  change_7d: number | null;
};

export type PriceMap = Record<string, PriceQuote>;

const FALLBACK: PriceMap = {
  ethereum: { usd: 3420, jpy: 508000, change_24h: 0, change_7d: null },
  tether: { usd: 1, jpy: 148.5, change_24h: 0, change_7d: null },
  'usd-coin': { usd: 1, jpy: 148.5, change_24h: 0, change_7d: null },
  dai: { usd: 1, jpy: 148.5, change_24h: 0, change_7d: null },
  bitcoin: { usd: 97500, jpy: 14_480_000, change_24h: 0, change_7d: null },
  solana: { usd: 178, jpy: 26400, change_24h: 0, change_7d: null },
  chainlink: { usd: 18, jpy: 2670, change_24h: 0, change_7d: null },
  'render-token': { usd: 7, jpy: 1040, change_24h: 0, change_7d: null },
  sui: { usd: 3.5, jpy: 520, change_24h: 0, change_7d: null },
};

async function fetch7dChanges(ids: string[]): Promise<Record<string, number>> {
  const url =
    `https://api.coingecko.com/api/v3/coins/markets` +
    `?vs_currency=usd&ids=${encodeURIComponent(ids.join(','))}` +
    `&price_change_percentage=7d&per_page=250`;
  try {
    const res = await fetch(url);
    if (!res.ok) return {};
    const rows = (await res.json()) as { id: string; price_change_percentage_7d_in_currency?: number | null }[];
    const out: Record<string, number> = {};
    for (const row of rows) {
      const v = row.price_change_percentage_7d_in_currency;
      if (typeof v === 'number' && Number.isFinite(v)) out[row.id] = v;
    }
    return out;
  } catch {
    return {};
  }
}

/**
 * Spot + 24h / 7d change from CoinGecko (no API key for basic usage).
 */
export async function fetchUsdPrices(ids: string[]): Promise<PriceMap> {
  const unique = [...new Set(ids)];
  const url =
    `https://api.coingecko.com/api/v3/simple/price` +
    `?ids=${encodeURIComponent(unique.join(','))}` +
    `&vs_currencies=usd,jpy&include_24hr_change=true`;

  try {
    const [res, weekly] = await Promise.all([fetch(url), fetch7dChanges(unique)]);
    if (!res.ok) {
      return FALLBACK;
    }
    const json = (await res.json()) as Record<
      string,
      { usd?: number; jpy?: number; usd_24h_change?: number }
    >;

    const out: PriceMap = { ...FALLBACK };
    for (const id of unique) {
      const row = json[id];
      if (!row) continue;
      out[id] = {
        usd: Number(row.usd) || FALLBACK[id]?.usd || 0,
        jpy: Number(row.jpy) || FALLBACK[id]?.jpy || 0,
        change_24h: Number(row.usd_24h_change) || 0,
        change_7d: id in weekly ? weekly[id] : null,
      };
    }
    return out;
  } catch {
    return FALLBACK;
  }
}

export function usdJpyFromPrices(prices: PriceMap): number {
  const eth = prices.ethereum;
  if (eth?.usd && eth?.jpy) {
    return eth.jpy / eth.usd;
  }
  const btc = prices.bitcoin;
  if (btc?.usd && btc?.jpy) {
    return btc.jpy / btc.usd;
  }
  return 148.5;
}
