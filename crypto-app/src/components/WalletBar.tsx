'use client';

import { useWallet } from '@/components/WalletProvider';

function shortAddress(addr: string) {
  return `${addr.slice(0, 6)}…${addr.slice(-4)}`;
}

function chainLabel(chainId?: number) {
  if (chainId === 1) return 'Ethereum';
  if (chainId === 42161) return 'Arbitrum';
  if (chainId === undefined) return '';
  return `chain ${chainId}`;
}

export default function WalletBar({
  loading,
  onRefresh,
}: {
  loading?: boolean;
  onRefresh?: () => void;
}) {
  const { address, chainId, connecting, connect, disconnect, error } = useWallet();
  const isConnected = Boolean(address);

  return (
    <div className="flex flex-wrap items-center justify-end gap-2">
      {isConnected && address ? (
        <>
          {chainId !== undefined ? (
            <span className="rounded-full border border-[var(--color-line)] bg-black/35 px-3 py-1.5 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-muted)]">
              {chainLabel(chainId)}
            </span>
          ) : null}
          <span className="rounded-full border border-[var(--color-line)] bg-black/35 px-3 py-1.5 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-muted)] tabular-nums">
            {shortAddress(address)}
          </span>
          <button
            type="button"
            onClick={() => onRefresh?.()}
            disabled={loading}
            className="rounded-full border border-[var(--color-line)] bg-black/35 px-3.5 py-1.5 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-ink)] transition hover:bg-[rgba(61,214,140,0.12)] disabled:opacity-50"
          >
            {loading ? '読込中…' : '再取得'}
          </button>
          <button
            type="button"
            onClick={disconnect}
            className="rounded-full border border-white/15 px-3.5 py-1.5 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-muted)] transition hover:text-[var(--color-ink)]"
          >
            切断
          </button>
        </>
      ) : (
        <button
          type="button"
          onClick={() => void connect()}
          disabled={connecting}
          className="rounded-full border border-[var(--color-line)] bg-[rgba(61,214,140,0.18)] px-3.5 py-1.5 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-ink)] transition hover:bg-[rgba(61,214,140,0.28)] disabled:opacity-50"
        >
          {connecting ? '接続中…' : 'MetaMask 接続'}
        </button>
      )}

      {error ? <p className="w-full text-right text-xs text-[var(--color-down)]">{error}</p> : null}
    </div>
  );
}
