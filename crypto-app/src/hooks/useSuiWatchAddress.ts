'use client';

import { useCallback, useEffect, useState } from 'react';
import { isSuiAddress, normalizeSuiAddress } from '@/lib/sui';

const STORAGE_KEY = 'gh-crypto-sui';

export function useSuiWatchAddress() {
  const [address, setAddressState] = useState<string>('');
  const [draft, setDraft] = useState('');
  const [error, setError] = useState<string | null>(null);
  const [hydrated, setHydrated] = useState(false);

  useEffect(() => {
    try {
      const saved = window.localStorage.getItem(STORAGE_KEY) || '';
      const normalized = normalizeSuiAddress(saved);
      if (normalized) {
        setAddressState(normalized);
        setDraft(normalized);
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
    const normalized = normalizeSuiAddress(next);
    if (!normalized || !isSuiAddress(next)) {
      setError('Sui アドレス（0x…）を入力してください');
      return;
    }
    setAddressState(normalized);
    setDraft(normalized);
    setError(null);
    window.localStorage.setItem(STORAGE_KEY, normalized);
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
