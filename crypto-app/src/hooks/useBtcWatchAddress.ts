'use client';

import { useCallback, useEffect, useState } from 'react';
import { isNativeSegwitAddress } from '@/lib/bitcoin';

const STORAGE_KEY = 'gh-crypto-btc-ns';

export function useBtcWatchAddress() {
  const [address, setAddressState] = useState<string>('');
  const [draft, setDraft] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(STORAGE_KEY) || '';
      if (saved && isNativeSegwitAddress(saved)) {
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
    if (!isNativeSegwitAddress(next)) {
      setError('Native SegWit（bc1q…）のみ登録できます');
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
