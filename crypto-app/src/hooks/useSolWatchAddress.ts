'use client';

import { useCallback, useEffect, useState } from 'react';
import { isSolanaAddress } from '@/lib/solana';

const STORAGE_KEY = 'gh-crypto-sol';

export function useSolWatchAddress() {
  const [address, setAddressState] = useState<string>('');
  const [draft, setDraft] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(STORAGE_KEY) || '';
      if (saved && isSolanaAddress(saved)) {
        setAddressState(saved);
        setDraft(saved);
      }
    } catch {
      /* ignore */
    }
    setHydrated(true);
  }, []);

  const save = useCallback((value: string) => {
    const next = value.trim();
    if (!next) {
      setAddressState('');
      setDraft('');
      setError(null);
      window.localStorage.removeItem(STORAGE_KEY);
      return;
    }
    if (!isSolanaAddress(next)) {
      setError('Solana アドレスを入力してください');
      return;
    }
    setAddressState(next);
    setDraft(next);
    setError(null);
    window.localStorage.setItem(STORAGE_KEY, next);
  }, []);

  const clear = useCallback(() => {
    setAddressState('');
    setDraft('');
    setError(null);
    window.localStorage.removeItem(STORAGE_KEY);
  }, []);

  return {
    address: hydrated ? address : '',
    draft,
    setDraft,
    error,
    save,
    clear,
    hydrated,
  };
}
