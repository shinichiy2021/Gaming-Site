'use client';

import {
  createContext,
  useCallback,
  useContext,
  useEffect,
  useMemo,
  useState,
  type ReactNode,
} from 'react';
import { type Address } from 'viem';
import { getEthereum, isAddress } from '@/lib/ethereum';

type WalletContextValue = {
  address?: Address;
  chainId?: number;
  connecting: boolean;
  connect: () => Promise<void>;
  disconnect: () => void;
  switchToMainnet: () => Promise<void>;
  error: string | null;
};

const WalletContext = createContext<WalletContextValue | null>(null);

const STORAGE_KEY = 'gh-crypto-mm-connected';

export function WalletProvider({ children }: { children: ReactNode }) {
  const [address, setAddress] = useState<Address | undefined>();
  const [chainId, setChainId] = useState<number | undefined>();
  const [connecting, setConnecting] = useState(false);
  const [error, setError] = useState<string | null>(null);

  const refreshChain = useCallback(async () => {
    const eth = getEthereum();
    if (!eth) return;
    const raw = (await eth.request({ method: 'eth_chainId' })) as string;
    setChainId(Number.parseInt(raw, 16));
  }, []);

  const connect = useCallback(async () => {
    const eth = getEthereum();
    if (!eth) {
      setError('MetaMask が見つかりません。拡張機能を入れてください。');
      return;
    }
    setConnecting(true);
    setError(null);
    try {
      const accounts = (await eth.request({ method: 'eth_requestAccounts' })) as string[];
      const next = accounts[0];
      if (!next || !isAddress(next)) {
        setError('アカウントを取得できませんでした。');
        setAddress(undefined);
        return;
      }
      setAddress(next);
      window.localStorage.setItem(STORAGE_KEY, '1');
      await refreshChain();
    } catch (err) {
      const message = err instanceof Error ? err.message : '接続に失敗しました。';
      setError(message);
    } finally {
      setConnecting(false);
    }
  }, [refreshChain]);

  const disconnect = useCallback(() => {
    setAddress(undefined);
    setChainId(undefined);
    setError(null);
    window.localStorage.removeItem(STORAGE_KEY);
  }, []);

  const switchToMainnet = useCallback(async () => {
    const eth = getEthereum();
    if (!eth) return;
    try {
      await eth.request({
        method: 'wallet_switchEthereumChain',
        params: [{ chainId: '0x1' }],
      });
      await refreshChain();
    } catch (err) {
      const message = err instanceof Error ? err.message : 'ネットワーク切替に失敗しました。';
      setError(message);
    }
  }, [refreshChain]);

  useEffect(() => {
    const eth = getEthereum();
    if (!eth) return;

    const onAccounts = (accounts: unknown) => {
      const list = Array.isArray(accounts) ? (accounts as string[]) : [];
      const next = list[0];
      if (next && isAddress(next)) {
        setAddress(next);
      } else {
        disconnect();
      }
    };
    const onChain = (id: unknown) => {
      if (typeof id === 'string') {
        setChainId(Number.parseInt(id, 16));
      }
    };

    eth.on?.('accountsChanged', onAccounts);
    eth.on?.('chainChanged', onChain);

    if (window.localStorage.getItem(STORAGE_KEY) === '1') {
      void (async () => {
        try {
          const accounts = (await eth.request({ method: 'eth_accounts' })) as string[];
          const next = accounts[0];
          if (next && isAddress(next)) {
            setAddress(next);
            await refreshChain();
          } else {
            window.localStorage.removeItem(STORAGE_KEY);
          }
        } catch {
          window.localStorage.removeItem(STORAGE_KEY);
        }
      })();
    }

    return () => {
      eth.removeListener?.('accountsChanged', onAccounts);
      eth.removeListener?.('chainChanged', onChain);
    };
  }, [disconnect, refreshChain]);

  const value = useMemo(
    () => ({
      address,
      chainId,
      connecting,
      connect,
      disconnect,
      switchToMainnet,
      error,
    }),
    [address, chainId, connect, connecting, disconnect, error, switchToMainnet]
  );

  return <WalletContext.Provider value={value}>{children}</WalletContext.Provider>;
}

export function useWallet() {
  const ctx = useContext(WalletContext);
  if (!ctx) {
    throw new Error('useWallet must be used within WalletProvider');
  }
  return ctx;
}
