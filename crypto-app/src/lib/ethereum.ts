import { type Address, createPublicClient, http } from 'viem';
import { arbitrum, mainnet } from 'viem/chains';

export const publicClient = createPublicClient({
  chain: mainnet,
  transport: http(),
});

export const arbitrumClient = createPublicClient({
  chain: arbitrum,
  transport: http(),
});

export type EthereumProvider = {
  request: (args: { method: string; params?: unknown[] }) => Promise<unknown>;
  on?: (event: string, handler: (...args: unknown[]) => void) => void;
  removeListener?: (event: string, handler: (...args: unknown[]) => void) => void;
  isMetaMask?: boolean;
};

export function getEthereum(): EthereumProvider | undefined {
  if (typeof window === 'undefined') return undefined;
  const eth = (window as Window & { ethereum?: EthereumProvider }).ethereum;
  return eth;
}

export function isAddress(value: string): value is Address {
  return /^0x[a-fA-F0-9]{40}$/.test(value);
}
