'use client';

import { useMemo, useState } from 'react';
import Link from 'next/link';
import WalletBar from '@/components/WalletBar';
import WatchAddressBar from '@/components/WatchAddressBar';
import PortfolioInsight from '@/components/PortfolioInsight';
import { useWalletPortfolio } from '@/hooks/useWalletPortfolio';
import type { Holding, Portfolio } from '@/lib/portfolio';

const labels = {
  title: 'ポートフォリオ',
  subtitleIdle: 'MetaMask を接続するか、BTC / Solana / Sui アドレスを登録してください',
  subtitleLive: '実残高（Ethereum · Arbitrum · BTC · Solana · Sui）',
  subtitleLoading: '残高を読み込み中です…',
  total: '合計残高',
  change24h: '24時間の変動',
  allocation: '配分',
  holdings: '保有資産',
  asset: '銘柄',
  amount: '数量',
  value: '評価額',
  weight: '比率',
  loading: '読込中',
  refreshing: '更新中',
  idle: '未接続',
  updated: '更新',
  empty: '残高のある資産がありません',
  idleHint: 'ウォレット接続またはアドレス登録後に残高が表示されます',
} as const;

function sourceBadge(sourceLabel: string, loading: boolean, refreshing: boolean) {
  if (loading) return labels.loading;
  if (refreshing) return labels.refreshing;
  if (sourceLabel === 'idle') return labels.idle;
  return sourceLabel;
}

function formatMoney(value: number, currency: 'JPY' | 'USD') {
  if (currency === 'USD') {
    return new Intl.NumberFormat('en-US', {
      style: 'currency',
      currency: 'USD',
      maximumFractionDigits: value >= 1000 ? 0 : 2,
    }).format(value);
  }
  return new Intl.NumberFormat('ja-JP', {
    style: 'currency',
    currency: 'JPY',
    maximumFractionDigits: 0,
  }).format(value);
}

function formatAmount(amount: number, symbol: string) {
  const digits = amount >= 100 ? 2 : amount >= 1 ? 4 : 6;
  return `${amount.toLocaleString(undefined, { maximumFractionDigits: digits })} ${symbol}`;
}

function formatPct(pct: number) {
  const sign = pct > 0 ? '+' : '';
  return `${sign}${pct.toFixed(2)}%`;
}

function AllocationRing({ holdings }: { holdings: Holding[] }) {
  const size = 180;
  const stroke = 22;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  let offset = 0;

  const segments = holdings.map((h) => {
    const len = (h.weight / 100) * c;
    const seg = { ...h, len, offset };
    offset += len;
    return seg;
  });

  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden className="shrink-0 drop-shadow-[0_0_12px_rgba(61,214,140,0.15)]">
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgba(255,255,255,0.06)" strokeWidth={stroke} />
      {segments.map((seg) => (
        <circle
          key={seg.symbol}
          cx={size / 2}
          cy={size / 2}
          r={r}
          fill="none"
          stroke={seg.color}
          strokeWidth={stroke}
          strokeDasharray={`${seg.len} ${c - seg.len}`}
          strokeDashoffset={-seg.offset}
          transform={`rotate(-90 ${size / 2} ${size / 2})`}
        />
      ))}
    </svg>
  );
}

function LoadingPanel() {
  return (
    <div
      className="grid gap-4 lg:grid-cols-[1.2fr_1fr]"
      role="status"
      aria-live="polite"
      aria-busy="true"
    >
      <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
        <p className="mb-4 font-[family-name:var(--font-display)] text-sm tracking-[0.12em] text-[var(--color-up)] uppercase">
          {labels.subtitleLoading}
        </p>
        <div className="mb-4 h-12 w-2/3 animate-pulse rounded-lg bg-white/10" />
        <div className="mb-2 h-5 w-1/3 animate-pulse rounded bg-white/10" />
        <div className="h-4 w-1/4 animate-pulse rounded bg-white/5" />
      </div>
      <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
        <div className="mb-4 h-4 w-20 animate-pulse rounded bg-white/10" />
        <div className="flex items-center gap-5">
          <div className="h-[180px] w-[180px] animate-pulse rounded-full bg-white/10" />
          <div className="flex flex-1 flex-col gap-2">
            <div className="h-4 w-24 animate-pulse rounded bg-white/10" />
            <div className="h-4 w-20 animate-pulse rounded bg-white/10" />
            <div className="h-4 w-28 animate-pulse rounded bg-white/10" />
          </div>
        </div>
      </div>
      <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md lg:col-span-2">
        <div className="mb-4 h-4 w-24 animate-pulse rounded bg-white/10" />
        <div className="space-y-3">
          <div className="h-10 animate-pulse rounded bg-white/5" />
          <div className="h-10 animate-pulse rounded bg-white/5" />
          <div className="h-10 animate-pulse rounded bg-white/5" />
        </div>
      </div>
    </div>
  );
}

function IdlePanel() {
  return (
    <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-8 text-center backdrop-blur-md">
      <p className="font-[family-name:var(--font-display)] text-sm tracking-[0.12em] text-[var(--color-muted)] uppercase">
        {labels.idle}
      </p>
      <p className="mt-3 text-[0.95rem] text-[var(--color-muted)]">{labels.idleHint}</p>
    </div>
  );
}

function PortfolioPanels({
  portfolio,
  currency,
  refreshing,
}: {
  portfolio: Portfolio;
  currency: 'JPY' | 'USD';
  refreshing: boolean;
}) {
  const holdings = portfolio.holdings;
  const total = currency === 'USD' ? portfolio.total_usd : portfolio.total_jpy;
  const change = currency === 'USD' ? portfolio.change_24h_usd : portfolio.change_24h_jpy;
  const up = portfolio.change_24h_pct >= 0;

  const updatedLabel = useMemo(() => {
    try {
      return new Date(portfolio.updated_at).toLocaleString('ja-JP', {
        month: 'short',
        day: 'numeric',
        hour: '2-digit',
        minute: '2-digit',
      });
    } catch {
      return '';
    }
  }, [portfolio.updated_at]);

  return (
    <div className={`grid gap-4 lg:grid-cols-[1.2fr_1fr] ${refreshing ? 'opacity-80' : ''}`}>
      <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
        <strong className="mb-3 block font-[family-name:var(--font-display)] text-[clamp(2rem,5vw,2.85rem)] font-black tracking-tight">
          {formatMoney(total, currency)}
        </strong>
        <div className={`flex flex-wrap items-baseline gap-x-3 gap-y-1 font-[family-name:var(--font-display)] ${up ? 'text-[var(--color-up)]' : 'text-[var(--color-down)]'}`}>
          <span>{formatMoney(change, currency)}</span>
          <span>{formatPct(portfolio.change_24h_pct)}</span>
          <small className="w-full font-[family-name:var(--font-body)] text-sm text-[var(--color-muted)]">{labels.change24h}</small>
        </div>
        {updatedLabel ? (
          <p className="mt-4 text-sm text-[var(--color-muted)]">
            {labels.updated}: {updatedLabel}
            {refreshing ? ` · ${labels.refreshing}` : ''}
          </p>
        ) : null}
      </div>

      <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
        <h2 className="mb-4 font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
          {labels.allocation}
        </h2>
        {holdings.length ? (
          <div className="flex flex-wrap items-center justify-center gap-5 sm:justify-start">
            <AllocationRing holdings={holdings} />
            <ul className="flex min-w-[7.5rem] flex-col gap-2">
              {holdings.map((h) => (
                <li key={h.symbol} className="grid grid-cols-[0.7rem_1fr_auto] items-center gap-2 text-sm">
                  <span className="h-2.5 w-2.5 rounded-sm" style={{ background: h.color }} />
                  <span className="font-[family-name:var(--font-display)] text-xs">{h.symbol}</span>
                  <span className="tabular-nums text-[var(--color-muted)]">{h.weight}%</span>
                </li>
              ))}
            </ul>
          </div>
        ) : (
          <p className="text-sm text-[var(--color-muted)]">{labels.empty}</p>
        )}
      </div>

      <section className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md lg:col-span-2" aria-labelledby="holdings-title">
        <h2 id="holdings-title" className="mb-4 font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
          {labels.holdings}
        </h2>
        {holdings.length ? (
          <div className="overflow-x-auto">
            <table className="w-full border-collapse text-[0.92rem]">
              <thead>
                <tr>
                  {[labels.asset, labels.amount, labels.value, labels.change24h, labels.weight].map((label) => (
                    <th
                      key={label}
                      className="border-b border-white/10 px-2.5 pb-3 text-left font-[family-name:var(--font-display)] text-[0.68rem] tracking-wider whitespace-nowrap text-[var(--color-muted)] uppercase"
                    >
                      {label}
                    </th>
                  ))}
                </tr>
              </thead>
              <tbody>
                {holdings.map((h) => {
                  const value = currency === 'USD' ? h.value_usd : h.value_jpy;
                  const ch = h.change_24h;
                  return (
                    <tr key={h.symbol} className="transition hover:bg-[rgba(61,214,140,0.05)]">
                      <td className="border-b border-white/5 px-2.5 py-3.5 whitespace-nowrap">
                        <div className="flex items-center gap-3">
                          <span
                            className="grid h-8 w-8 shrink-0 place-items-center rounded-full font-[family-name:var(--font-display)] text-sm font-bold text-[#0a0f0d]"
                            style={{ background: h.color }}
                          >
                            {h.symbol.slice(0, 1)}
                          </span>
                          <span>
                            <strong className="block font-[family-name:var(--font-display)] text-sm leading-tight">{h.symbol}</strong>
                            <small className="text-xs text-[var(--color-muted)]">{h.name}</small>
                          </span>
                        </div>
                      </td>
                      <td className="border-b border-white/5 px-2.5 py-3.5 tabular-nums whitespace-nowrap max-md:hidden">
                        {formatAmount(h.amount, h.symbol)}
                      </td>
                      <td className="border-b border-white/5 px-2.5 py-3.5 tabular-nums whitespace-nowrap">
                        {formatMoney(value, currency)}
                      </td>
                      <td className={`border-b border-white/5 px-2.5 py-3.5 tabular-nums whitespace-nowrap ${ch >= 0 ? 'text-[var(--color-up)]' : 'text-[var(--color-down)]'}`}>
                        {formatPct(ch)}
                      </td>
                      <td className="border-b border-white/5 px-2.5 py-3.5 whitespace-nowrap">
                        <div className="flex min-w-[4.5rem] flex-col gap-1.5">
                          <span className="tabular-nums">{h.weight}%</span>
                          <span className="block h-1 overflow-hidden rounded-full bg-white/10" aria-hidden>
                            <span className="block h-full rounded-full" style={{ width: `${Math.min(100, h.weight)}%`, background: h.color }} />
                          </span>
                        </div>
                      </td>
                    </tr>
                  );
                })}
              </tbody>
            </table>
          </div>
        ) : (
          <p className="text-sm text-[var(--color-muted)]">{labels.empty}</p>
        )}
      </section>
    </div>
  );
}

export default function CryptoDashboard() {
  const [currency, setCurrency] = useState<'JPY' | 'USD'>('JPY');
  const {
    portfolio,
    hasSources,
    isConnected,
    hasBtc,
    hasSol,
    hasSui,
    sourceLabel,
    loading,
    refreshing,
    error,
    refetch,
    btcWatch,
    solWatch,
    suiWatch,
  } = useWalletPortfolio();

  const subtitle = loading
    ? labels.subtitleLoading
    : hasSources
      ? labels.subtitleLive
      : labels.subtitleIdle;

  return (
    <div className="mx-auto flex max-w-[1080px] flex-col gap-5 px-4 py-8 sm:px-6 lg:py-10">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="mb-1.5 font-[family-name:var(--font-display)] text-xs tracking-[0.16em] text-[var(--color-up)] uppercase">
            {labels.title}
          </p>
          <h1 className="font-[family-name:var(--font-display)] text-[clamp(1.4rem,3vw,2rem)] font-bold leading-tight text-[var(--color-ink)]">
            {labels.total}
          </h1>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <span className="inline-block rounded-full border border-white/15 px-2.5 py-0.5 text-[0.7rem] tracking-wider text-[var(--color-muted)] uppercase">
              {sourceBadge(sourceLabel, loading, refreshing)}
            </span>
            <Link
              href="/stock"
              className="text-[0.7rem] tracking-wider text-[var(--color-muted)] underline-offset-2 hover:text-[var(--color-ink)] hover:underline"
            >
              国内株式 →
            </Link>
          </div>
        </div>
        <div className="flex flex-col items-end gap-3">
          <WalletBar loading={loading || refreshing} onRefresh={refetch} />
          <div className="inline-flex rounded-full border border-[var(--color-line)] bg-black/35 p-0.5" role="group" aria-label="Currency">
            {(['JPY', 'USD'] as const).map((code) => (
              <button
                key={code}
                type="button"
                onClick={() => setCurrency(code)}
                className={`rounded-full px-3.5 py-1.5 font-[family-name:var(--font-display)] text-xs tracking-wider transition ${
                  currency === code
                    ? 'bg-[rgba(61,214,140,0.18)] text-[var(--color-ink)]'
                    : 'text-[var(--color-muted)]'
                }`}
              >
                {code}
              </button>
            ))}
          </div>
        </div>
      </header>

      <PortfolioInsight
        portfolio={portfolio}
        hasSources={hasSources}
        hasMm={isConnected}
        hasBtc={hasBtc}
        hasSol={hasSol}
        hasSui={hasSui}
        loading={loading}
        refreshing={refreshing}
        onRefresh={refetch}
      />

      <p className="max-w-xl text-[0.95rem] text-[var(--color-muted)]">{subtitle}</p>

      <div className="grid gap-3 lg:grid-cols-2">
        <WatchAddressBar
          title="Bitcoin · Native SegWit"
          hint="ウォッチ用の bc1q… アドレス（秘密鍵不要）"
          placeholder="bc1q…"
          accentClassName="bg-[rgba(247,147,26,0.18)] hover:bg-[rgba(247,147,26,0.28)]"
          address={btcWatch.address}
          draft={btcWatch.draft}
          setDraft={btcWatch.setDraft}
          error={btcWatch.error}
          onSave={btcWatch.save}
          onClear={btcWatch.clear}
        />
        <WatchAddressBar
          title="Solana"
          hint="ウォッチ用の Solana アドレス（SOL + USDC + RENDER）"
          placeholder="Solana address"
          accentClassName="bg-[rgba(20,241,149,0.18)] hover:bg-[rgba(20,241,149,0.28)]"
          address={solWatch.address}
          draft={solWatch.draft}
          setDraft={solWatch.setDraft}
          error={solWatch.error}
          onSave={solWatch.save}
          onClear={solWatch.clear}
        />
        <WatchAddressBar
          title="Sui"
          hint="ウォッチ用の Sui アドレス（SUI + USDC）"
          placeholder="0x…"
          accentClassName="bg-[rgba(77,162,255,0.18)] hover:bg-[rgba(77,162,255,0.28)]"
          address={suiWatch.address}
          draft={suiWatch.draft}
          setDraft={suiWatch.setDraft}
          error={suiWatch.error}
          onSave={suiWatch.save}
          onClear={suiWatch.clear}
        />
      </div>

      {error ? <p className="text-sm text-[var(--color-down)]">{error}</p> : null}

      {loading ? <LoadingPanel /> : null}
      {!loading && !hasSources ? <IdlePanel /> : null}
      {!loading && hasSources ? (
        <PortfolioPanels portfolio={portfolio} currency={currency} refreshing={refreshing} />
      ) : null}
    </div>
  );
}
