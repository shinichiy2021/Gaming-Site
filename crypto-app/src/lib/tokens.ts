import { type Address, type PublicClient, formatUnits } from 'viem';
import type { AssetSeed } from '@/lib/buildPortfolio';
import type { PriceMap } from '@/lib/prices';

export const erc20BalanceOfAbi = [
  {
    type: 'function',
    name: 'balanceOf',
    stateMutability: 'view',
    inputs: [{ name: 'account', type: 'address' }],
    outputs: [{ name: '', type: 'uint256' }],
  },
] as const;

export type TrackedToken = {
  symbol: string;
  name: string;
  address: Address;
  decimals: number;
  color: string;
  coingeckoId: string;
};

/** Ethereum mainnet ERC-20s. */
export const TRACKED_TOKENS: readonly TrackedToken[] = [
  {
    symbol: 'USDT',
    name: 'Tether',
    address: '0xdAC17F958D2ee523a2206206994597C13D831ec7',
    decimals: 6,
    color: '#26a17b',
    coingeckoId: 'tether',
  },
  {
    symbol: 'USDC',
    name: 'USD Coin',
    address: '0xA0b86991c6218b36c1d19D4a2e9Eb0cE3606eB48',
    decimals: 6,
    color: '#2775ca',
    coingeckoId: 'usd-coin',
  },
  {
    symbol: 'DAI',
    name: 'Dai',
    address: '0x6B175474E89094C44Da98b954EedeAC495271d0F',
    decimals: 18,
    color: '#f5ac37',
    coingeckoId: 'dai',
  },
  {
    symbol: 'WBTC',
    name: 'Wrapped Bitcoin',
    address: '0x2260FAC5E5542a773Aa44fBCfeDf7C193bc2C599',
    decimals: 8,
    color: '#f7931a',
    coingeckoId: 'bitcoin',
  },
  {
    symbol: 'LINK',
    name: 'Chainlink',
    address: '0x514910771AF9Ca656af840dff83E8264EcF986CA',
    decimals: 18,
    color: '#2a5ada',
    coingeckoId: 'chainlink',
  },
] as const;

/** Arbitrum One ERC-20s. */
export const ARBITRUM_TOKENS: readonly TrackedToken[] = [
  {
    symbol: 'USDT.ARB',
    name: 'Tether (Arbitrum)',
    address: '0xFd086bC7CD5C481DCC9C85ebE478A1C0b69FCbb9',
    decimals: 6,
    color: '#26a17b',
    coingeckoId: 'tether',
  },
  {
    symbol: 'USDC.ARB',
    name: 'USD Coin (Arbitrum)',
    address: '0xaf88d065e77c8cC2239327C5EDb3A432268e5831',
    decimals: 6,
    color: '#2775ca',
    coingeckoId: 'usd-coin',
  },
  {
    symbol: 'DAI.ARB',
    name: 'Dai (Arbitrum)',
    address: '0xDA10009cBd5D07dd0CeCc66161FC93D7c9000da1',
    decimals: 18,
    color: '#f5ac37',
    coingeckoId: 'dai',
  },
  {
    symbol: 'WBTC.ARB',
    name: 'Wrapped Bitcoin (Arbitrum)',
    address: '0x2f2a2543B76A4166549F7aaB2e75Bef0aefC5B0f',
    decimals: 8,
    color: '#f7931a',
    coingeckoId: 'bitcoin',
  },
  {
    symbol: 'LINK.ARB',
    name: 'Chainlink (Arbitrum)',
    address: '0xf97f4df75117a78c1A5a0DBb814Af92458539FB4',
    decimals: 18,
    color: '#2a5ada',
    coingeckoId: 'chainlink',
  },
] as const;

export const ETH_META = {
  symbol: 'ETH',
  name: 'Ethereum',
  color: '#627eea',
  coingeckoId: 'ethereum',
} as const;

export const ETH_ARB_META = {
  symbol: 'ETH.ARB',
  name: 'Ethereum (Arbitrum)',
  color: '#12aaff',
  coingeckoId: 'ethereum',
} as const;

export async function readEvmChainSeeds(
  client: PublicClient,
  address: Address,
  prices: PriceMap,
  native: { symbol: string; name: string; color: string; coingeckoId: string },
  tokens: readonly TrackedToken[]
): Promise<AssetSeed[]> {
  const [ethWei, ...tokenBalances] = await Promise.all([
    client.getBalance({ address }),
    ...tokens.map((token) =>
      client.readContract({
        address: token.address,
        abi: erc20BalanceOfAbi,
        functionName: 'balanceOf',
        args: [address],
      })
    ),
  ]);

  const ethPrice = prices[native.coingeckoId];
  return [
    {
      symbol: native.symbol,
      name: native.name,
      amount: Number(formatUnits(ethWei, 18)),
      price_usd: ethPrice?.usd ?? 0,
      change_24h: ethPrice?.change_24h ?? 0,
      color: native.color,
    },
    ...tokens.map((token, i) => {
      const raw = tokenBalances[i] as bigint;
      const quote = prices[token.coingeckoId];
      return {
        symbol: token.symbol,
        name: token.name,
        amount: Number(formatUnits(raw, token.decimals)),
        price_usd: quote?.usd ?? 0,
        change_24h: quote?.change_24h ?? 0,
        color: token.color,
      };
    }),
  ];
}
