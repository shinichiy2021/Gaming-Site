'use client';

import Link from 'next/link';
import { useCallback, useEffect, useMemo, useState } from 'react';
import { useWalletPortfolio } from '@/hooks/useWalletPortfolio';
import { fetchMergedStockPortfolio, readSavedSbiCsv } from '@/lib/loadStockPortfolio';
import {
  buildRiskSnapshot,
  loadRiskHistory,
  recordRiskSnapshot,
  type RiskSnapshot,
} from '@/lib/riskAssets';
import type { StockPortfolio } from '@/lib/stockPortfolio';

function fmtUsd(n: number) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: n >= 1000 ? 0 : 2,
  }).format(n);
}

function fmtYen(n: number) {
  return new Intl.NumberFormat('ja-JP', {
    style: 'currency',
    currency: 'JPY',
    maximumFractionDigits: 0,
  }).format(n);
}

function fmtPct(n: number) {
  return `${n.toFixed(1)}%`;
}

function formatDay(iso: string) {
  try {
    return new Date(iso).toLocaleString('ja-JP', {
      timeZone: 'Asia/Tokyo',
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
    });
  } catch {
    return iso.slice(0, 16);
  }
}

function RatioBar({ cryptoPct, stockPct }: { cryptoPct: number; stockPct: number }) {
  return (
    <div className="mt-3 h-3 overflow-hidden rounded-full bg-white/10" aria-hidden>
      <div className="flex h-full w-full">
        <span
          className="h-full bg-[rgba(61,214,140,0.75)] transition-[width] duration-500"
          style={{ width: `${Math.min(100, cryptoPct)}%` }}
        />
        <span
          className="h-full bg-[rgba(251,113,133,0.65)] transition-[width] duration-500"
          style={{ width: `${Math.min(100, stockPct)}%` }}
        />
      </div>
    </div>
  );
}

function HistorySpark({ history }: { history: RiskSnapshot[] }) {
  if (history.length < 2) {
    return (
      <p className="text-sm text-[var(--color-muted)]">記録が2件以上になると推移が表示されます。</p>
    );
  }

  const w = 320;
  const h = 72;
  const pad = 6;
  const values = history.map((s) => s.cryptoPct);
  const min = Math.min(...values) - 1;
  const max = Math.max(...values) + 1;
  const span = Math.max(0.5, max - min);
  const pts = history
    .map((s, i) => {
      const x = pad + (i / (history.length - 1)) * (w - pad * 2);
      const y = pad + (1 - (s.cryptoPct - min) / span) * (h - pad * 2);
      return `${x},${y}`;
    })
    .join(' ');

  const first = history[0].cryptoPct;
  const last = history[history.length - 1].cryptoPct;
  const delta = last - first;

  return (
    <div>
      <div className="mb-2 flex items-baseline justify-between gap-2">
        <p className="text-xs text-[var(--color-muted)]">クリプト％ 推移（直近）</p>
        <p
          className={`font-[family-name:var(--font-display)] text-sm tabular-nums ${
            delta > 0 ? 'text-[var(--color-up)]' : delta < 0 ? 'text-[var(--color-down)]' : 'text-[var(--color-muted)]'
          }`}
        >
          {delta > 0 ? '+' : ''}
          {delta.toFixed(1)}pt
        </p>
      </div>
      <svg viewBox={`0 0 ${w} ${h}`} className="w-full max-w-md" role="img" aria-label="クリプト比率の推移">
        <polyline
          fill="none"
          stroke="rgba(61,214,140,0.85)"
          strokeWidth="2.2"
          strokeLinejoin="round"
          strokeLinecap="round"
          points={pts}
        />
      </svg>
    </div>
  );
}

export default function AllAssetsDashboard() {
  const {
    portfolio: crypto,
    hasSources: hasCrypto,
    loading: cryptoLoading,
    refreshing: cryptoRefreshing,
    error: cryptoError,
    refetch: refetchCrypto,
  } = useWalletPortfolio();

  const [stock, setStock] = useState<StockPortfolio | null>(null);
  const [stockLoading, setStockLoading] = useState(true);
  const [stockError, setStockError] = useState<string | null>(null);
  const [history, setHistory] = useState<RiskSnapshot[]>([]);
  const [currency, setCurrency] = useState<'JPY' | 'USD'>('USD');
  const [note, setNote] = useState<string | null>(null);

  const loadStock = useCallback(async () => {
    setStockLoading(true);
    setStockError(null);
    try {
      const next = await fetchMergedStockPortfolio(readSavedSbiCsv());
      if (!next.holdings.length) throw new Error('empty');
      setStock(next);
    } catch {
      setStock(null);
      setStockError('株式データの読み込みに失敗しました（/stock で CSV を確認）');
    } finally {
      setStockLoading(false);
    }
  }, []);

  useEffect(() => {
    setHistory(loadRiskHistory());
    void loadStock();
  }, [loadStock]);

  const snap = useMemo(() => {
    if (!hasCrypto || !crypto.total_usd || !stock) return null;
    return buildRiskSnapshot(crypto, stock);
  }, [crypto, hasCrypto, stock]);

  // Auto-record once both sides are ready (replaces same-day entry)
  useEffect(() => {
    if (!snap) return;
    setHistory(recordRiskSnapshot(snap));
  }, [snap]);

  const busy = cryptoLoading || cryptoRefreshing || stockLoading;

  async function refreshAll() {
    setNote(null);
    await Promise.all([refetchCrypto(), loadStock()]);
    setNote('最新残高で更新しました');
  }

  function manualRecord() {
    if (!snap) return;
    const next = recordRiskSnapshot({ ...snap, t: new Date().toISOString() });
    setHistory(next);
    setNote(`記録しました · クリプト ${fmtPct(snap.cryptoPct)}`);
  }

  const money = (usd: number, jpy: number) => (currency === 'USD' ? fmtUsd(usd) : fmtYen(jpy));

  return (
    <div className="mx-auto flex min-h-screen max-w-5xl flex-col gap-8 px-5 py-10 sm:px-8">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="mb-1.5 font-[family-name:var(--font-display)] text-xs tracking-[0.16em] text-[var(--color-up)] uppercase">
            All assets
          </p>
          <h1 className="font-[family-name:var(--font-display)] text-[clamp(1.5rem,3.5vw,2.2rem)] font-bold leading-tight">
            全体資産
          </h1>
          <div className="mt-2 flex flex-wrap items-center gap-3">
            <Link
              href="/"
              className="text-[0.7rem] tracking-wider text-[var(--color-muted)] underline-offset-2 hover:text-[var(--color-ink)] hover:underline"
            >
              Crypto →
            </Link>
            <Link
              href="/stock"
              className="text-[0.7rem] tracking-wider text-[var(--color-muted)] underline-offset-2 hover:text-[var(--color-ink)] hover:underline"
            >
              Stock →
            </Link>
            <Link
              href="/bot"
              className="text-[0.7rem] tracking-wider text-[var(--color-muted)] underline-offset-2 hover:text-[var(--color-ink)] hover:underline"
            >
              Paper Bot →
            </Link>
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <div className="inline-flex rounded-full border border-[var(--color-line)] bg-black/35 p-0.5" role="group">
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
          <button
            type="button"
            onClick={() => void refreshAll()}
            disabled={busy}
            className="rounded-full border border-[var(--color-line)] bg-black/35 px-3.5 py-2 font-[family-name:var(--font-display)] text-xs tracking-wider transition hover:bg-[rgba(61,214,140,0.12)] disabled:opacity-50"
          >
            {busy ? '更新中…' : '再取得'}
          </button>
          <button
            type="button"
            onClick={manualRecord}
            disabled={!snap}
            className="rounded-full border border-[var(--color-line)] bg-[rgba(61,214,140,0.18)] px-3.5 py-2 font-[family-name:var(--font-display)] text-xs tracking-wider transition hover:bg-[rgba(61,214,140,0.28)] disabled:opacity-50"
          >
            比率を記録
          </button>
        </div>
      </header>

      <p className="max-w-2xl text-[0.95rem] text-[var(--color-muted)]">
        クリプトと株式の評価額を合算し、リスク資産に占めるクリプト％を記録します。月次の増減判断用メモです。
      </p>

      {note ? <p className="text-sm text-[var(--color-up)]">{note}</p> : null}
      {cryptoError ? <p className="text-sm text-[var(--color-down)]">{cryptoError}</p> : null}
      {stockError ? <p className="text-sm text-[var(--color-down)]">{stockError}</p> : null}

      {!hasCrypto && !cryptoLoading ? (
        <p className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 text-sm text-[var(--color-muted)]">
          クリプト側が未接続です。{' '}
          <Link href="/" className="text-[var(--color-up)] underline-offset-2 hover:underline">
            Crypto
          </Link>{' '}
          でウォレットまたはアドレスを登録してください。
        </p>
      ) : null}

      {snap ? (
        <>
          <section className="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
            {[
              { label: 'リスク資産合計', value: money(snap.riskUsd, snap.riskJpy), sub: 'Crypto + Stock' },
              {
                label: 'クリプト',
                value: money(snap.cryptoUsd, snap.cryptoJpy),
                sub: fmtPct(snap.cryptoPct),
                accent: 'text-[var(--color-up)]',
              },
              {
                label: '株式',
                value: money(snap.stockUsd, snap.stockJpy),
                sub: fmtPct(snap.stockPct),
                accent: 'text-[#fb7185]',
              },
              {
                label: 'クリプト％',
                value: fmtPct(snap.cryptoPct),
                sub: `対リスク資産 · FX ${snap.usdJpy}`,
              },
            ].map((card) => (
              <div
                key={card.label}
                className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md"
              >
                <p className="mb-2 text-[0.7rem] tracking-wider text-[var(--color-muted)] uppercase">{card.label}</p>
                <p
                  className={`font-[family-name:var(--font-display)] text-[clamp(1.3rem,2.8vw,1.75rem)] font-bold tabular-nums ${card.accent ?? ''}`}
                >
                  {card.value}
                </p>
                <p className="mt-1 text-xs text-[var(--color-muted)]">{card.sub}</p>
              </div>
            ))}
          </section>

          <section className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
            <div className="mb-1 flex flex-wrap items-end justify-between gap-2">
              <h2 className="font-[family-name:var(--font-display)] text-lg font-bold">配分</h2>
              <p className="text-xs text-[var(--color-muted)]">
                <span className="mr-3 inline-flex items-center gap-1.5">
                  <span className="inline-block h-2 w-2 rounded-full bg-[rgba(61,214,140,0.85)]" />
                  Crypto {fmtPct(snap.cryptoPct)}
                </span>
                <span className="inline-flex items-center gap-1.5">
                  <span className="inline-block h-2 w-2 rounded-full bg-[rgba(251,113,133,0.75)]" />
                  Stock {fmtPct(snap.stockPct)}
                </span>
              </p>
            </div>
            <RatioBar cryptoPct={snap.cryptoPct} stockPct={snap.stockPct} />
          </section>

          <section className="grid gap-4 lg:grid-cols-2">
            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
              <h2 className="mb-3 font-[family-name:var(--font-display)] text-lg font-bold">記録履歴</h2>
              <HistorySpark history={history} />
              {history.length ? (
                <ul className="mt-4 max-h-56 space-y-2 overflow-y-auto text-sm">
                  {[...history].reverse().slice(0, 12).map((h) => (
                    <li
                      key={h.t}
                      className="grid grid-cols-[7rem_1fr_auto] gap-2 border-b border-white/5 py-2 text-[var(--color-muted)]"
                    >
                      <span className="tabular-nums">{formatDay(h.t)}</span>
                      <span className="tabular-nums text-[var(--color-ink)]">
                        Crypto {fmtPct(h.cryptoPct)}
                      </span>
                      <span className="tabular-nums">{fmtUsd(h.riskUsd)}</span>
                    </li>
                  ))}
                </ul>
              ) : null}
            </div>

            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
              <h2 className="mb-3 font-[family-name:var(--font-display)] text-lg font-bold">メモ</h2>
              <ul className="flex flex-col gap-2.5 text-[0.92rem] leading-relaxed text-[var(--color-ink)]/90">
                <li>
                  いまのクリプト合計 {fmtUsd(snap.cryptoUsd)}。株式評価額 {fmtUsd(snap.stockUsd)} と足すとリスク資産{' '}
                  {fmtUsd(snap.riskUsd)}。
                </li>
                <li>
                  リスク資産に占めるクリプトは <strong className="text-[var(--color-up)]">{fmtPct(snap.cryptoPct)}</strong>
                  。この％の月次変化を履歴で追えます。
                </li>
                <li className="text-[var(--color-muted)]">
                  株式は /stock で取り込んだ CSV・海外 JSON を使用。クリプトは接続ウォレット／監視アドレスの合計です。
                </li>
              </ul>
            </div>
          </section>
        </>
      ) : (
        <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-8 text-center text-[var(--color-muted)]">
          {busy ? '読み込み中…' : 'クリプトと株式の両方を用意すると合算が表示されます。'}
        </div>
      )}
    </div>
  );
}
