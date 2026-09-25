'use client';

import { useQuery } from '@tanstack/react-query';
import { useWallet } from '@/components/WalletProvider';
import { useBtcWatchAddress } from '@/hooks/useBtcWatchAddress';
import { useSolWatchAddress } from '@/hooks/useSolWatchAddress';
import { useSuiWatchAddress } from '@/hooks/useSuiWatchAddress';
import { BTC_META, fetchNativeSegwitBalance } from '@/lib/bitcoin';
import { buildPortfolio, type AssetSeed } from '@/lib/buildPortfolio';
import { arbitrumClient, publicClient } from '@/lib/ethereum';
import { fetchUsdPrices, usdJpyFromPrices, type PriceMap } from '@/lib/prices';
import { emptyPortfolio, type Portfolio } from '@/lib/portfolio';
import { SOL_META, SOL_TRACKED_TOKENS, fetchSolanaBalance } from '@/lib/solana';
import { SUI_META, SUI_TRACKED_COINS, fetchSuiBalance } from '@/lib/sui';
import {
  ARBITRUM_TOKENS,
  ETH_ARB_META,
  ETH_META,
  TRACKED_TOKENS,
  readEvmChainSeeds,
} from '@/lib/tokens';

function assetSeed(
  symbol: string,
  name: string,
  amount: number,
  prices: PriceMap,
  color: string,
  coingeckoId: string
): AssetSeed {
  const quote = prices[coingeckoId];
  return {
    symbol,
    name,
    amount,
    price_usd: quote?.usd ?? 0,
    change_24h: quote?.change_24h ?? 0,
    color,
  };
}

export function useWalletPortfolio() {
  const { address, chainId, connecting } = useWallet();
  const btcWatch = useBtcWatchAddress();
  const solWatch = useSolWatchAddress();
  const suiWatch = useSuiWatchAddress();

  const watchesReady = btcWatch.hydrated && solWatch.hydrated && suiWatch.hydrated;
  const hasMm = Boolean(address);
  const hasBtc = Boolean(btcWatch.address);
  const hasSol = Boolean(solWatch.address);
  const hasSui = Boolean(suiWatch.address);
  const live = hasMm || hasBtc || hasSol || hasSui;

  const query = useQuery({
    queryKey: ['live-portfolio', address, btcWatch.address, solWatch.address, suiWatch.address],
    enabled: watchesReady && live,
    refetchInterval: 60_000,
    queryFn: async (): Promise<Portfolio> => {
      const prices = await fetchUsdPrices([
        ETH_META.coingeckoId,
        BTC_META.coingeckoId,
        SOL_META.coingeckoId,
        SUI_META.coingeckoId,
        'chainlink',
        'render-token',
        ...TRACKED_TOKENS.map((t) => t.coingeckoId),
        ...ARBITRUM_TOKENS.map((t) => t.coingeckoId),
        ...SOL_TRACKED_TOKENS.map((t) => t.coingeckoId),
        ...SUI_TRACKED_COINS.map((t) => t.coingeckoId),
      ]);
      const usdJpy = usdJpyFromPrices(prices);
      const seeds: AssetSeed[] = [];

      const seedErrors: string[] = [];

      if (address) {
        try {
          const [mainnet, arb] = await Promise.all([
            readEvmChainSeeds(publicClient, address, prices, ETH_META, TRACKED_TOKENS),
            readEvmChainSeeds(arbitrumClient, address, prices, ETH_ARB_META, ARBITRUM_TOKENS),
          ]);
          seeds.push(...mainnet, ...arb);
        } catch (err) {
          console.warn('[portfolio] EVM balance failed', err);
          seedErrors.push('EVM');
        }
      }

      if (btcWatch.address) {
        try {
          const bal = await fetchNativeSegwitBalance(btcWatch.address);
          seeds.push(
            assetSeed(BTC_META.symbol, BTC_META.name, bal.btc, prices, BTC_META.color, BTC_META.coingeckoId)
          );
        } catch (err) {
          console.warn('[portfolio] BTC balance failed', err);
          seedErrors.push('BTC');
        }
      }

      if (solWatch.address) {
        try {
          const bal = await fetchSolanaBalance(solWatch.address);
          seeds.push(
            assetSeed(SOL_META.symbol, SOL_META.name, bal.sol, prices, SOL_META.color, SOL_META.coingeckoId)
          );
          for (const token of SOL_TRACKED_TOKENS) {
            seeds.push(
              assetSeed(
                token.symbol,
                token.name,
                bal.tokens[token.key] || 0,
                prices,
                token.color,
                token.coingeckoId
              )
            );
          }
        } catch (err) {
          console.warn('[portfolio] SOL balance failed', err);
          seedErrors.push('SOL');
        }
      }

      if (suiWatch.address) {
        try {
          const bal = await fetchSuiBalance(suiWatch.address);
          seeds.push(
            assetSeed(SUI_META.symbol, SUI_META.name, bal.sui, prices, SUI_META.color, SUI_META.coingeckoId)
          );
          for (const coin of SUI_TRACKED_COINS) {
            seeds.push(
              assetSeed(
                coin.symbol,
                coin.name,
                bal.tokens[coin.key] || 0,
                prices,
                coin.color,
                coin.coingeckoId
              )
            );
          }
        } catch (err) {
          console.warn('[portfolio] SUI balance failed', err);
          seedErrors.push('SUI');
        }
      }

      if (!seeds.some((s) => s.amount > 0) && seedErrors.length) {
        throw new Error(`${seedErrors.join(' / ')} 残高の取得に失敗しました`);
      }

      return buildPortfolio(seeds, usdJpy);
    },
  });

  const initialLoading =
    !watchesReady || connecting || (live && query.isLoading && !query.data);
  const refreshing = live && Boolean(query.data) && query.isFetching && !query.isLoading;
  const portfolio = query.data ?? emptyPortfolio();

  const parts: string[] = [];
  if (hasMm) parts.push('EVM');
  if (hasBtc) parts.push('BTC');
  if (hasSol) parts.push('SOL');
  if (hasSui) parts.push('SUI');
  const sourceLabel = !live ? 'idle' : parts.join('+') || 'live';

  return {
    portfolio,
    address,
    isConnected: hasMm,
    hasBtc,
    hasSol,
    hasSui,
    hasSources: live,
    sourceLabel,
    loading: initialLoading,
    refreshing,
    chainId,
    error: query.error instanceof Error ? query.error.message : null,
    btcWatch,
    solWatch,
    suiWatch,
    refetch: () => {
      void query.refetch();
    },
  };
}

/** @deprecated use useWalletPortfolio */
export const useDemoOrWalletPortfolio = useWalletPortfolio;
