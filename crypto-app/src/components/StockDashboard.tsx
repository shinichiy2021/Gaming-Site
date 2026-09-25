'use client';

import { useCallback, useEffect, useId, useRef, useState } from 'react';
import Link from 'next/link';
import StockPortfolioInsight from '@/components/StockPortfolioInsight';
import { mergePortfolios, type ForeignPortfolioRaw } from '@/lib/mergeStockPortfolio';
import { decodeCsvFile, parseSbiPortfolioCsv } from '@/lib/sbiCsv';
import {
  ACCOUNT_META,
  MARKET_META,
  type StockAccountKind,
  type StockHolding,
  type StockMarket,
  type StockPortfolio,
} from '@/lib/stockPortfolio';

const STORAGE_KEY = 'gaming-hub-sbi-csv-v1';

type MarketFilter = 'all' | StockMarket | 'cash';
type AccountFilter = 'all' | StockAccountKind;

const labels = {
  title: '株式ポートフォリオ',
  brand: 'SBI ポートフォリオ',
  subtitle: '国内CSV + 海外株式（USD）をまとめて表示します',
  total: '評価額合計',
  pnl: '含み損益（円）',
  day: '国内 前日比',
  byMarket: '市場別配分',
  byAccount: '口座別',
  holdings: '保有銘柄',
  upload: '国内CSVを取り込み',
  reupload: '別の国内CSV',
  reset: '初期データに戻す',
  loading: '読み込み中…',
  error: 'データを読み込めませんでした',
  empty: '表示できる銘柄がありません',
  code: '銘柄',
  qty: '数量',
  price: '現在値',
  value: '評価額',
  pnlCol: '損益',
  dayCol: '前日比',
  weight: '比率',
  account: '口座',
  fx: '参考レート',
  sourceSeed: 'シードデータ',
  sourceUpload: '国内CSV取込済',
  filterAll: 'すべて',
  filterDomestic: '国内',
  filterForeign: '海外',
} as const;

function formatYen(value: number) {
  return new Intl.NumberFormat('ja-JP', {
    style: 'currency',
    currency: 'JPY',
    maximumFractionDigits: value >= 100 ? 0 : 2,
  }).format(value);
}

function formatUsd(value: number) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 2,
  }).format(value);
}

function formatPct(pct: number) {
  const sign = pct > 0 ? '+' : '';
  return `${sign}${pct.toFixed(2)}%`;
}

function formatSignedYen(value: number) {
  const sign = value > 0 ? '+' : '';
  return `${sign}${formatYen(value)}`;
}

function formatSignedUsd(value: number) {
  const sign = value > 0 ? '+' : '';
  return `${sign}${formatUsd(value)}`;
}

function toneClass(value: number) {
  if (value > 0) return 'text-[var(--color-up)]';
  if (value < 0) return 'text-[var(--color-down)]';
  return 'text-[var(--color-muted)]';
}

function AllocationRing({
  items,
}: {
  items: { key: string; weight: number; color: string }[];
}) {
  const size = 180;
  const stroke = 22;
  const r = (size - stroke) / 2;
  const c = 2 * Math.PI * r;
  let offset = 0;
  const segments = items.map((item) => {
    const len = (item.weight / 100) * c;
    const seg = { ...item, len, offset };
    offset += len;
    return seg;
  });

  return (
    <svg width={size} height={size} viewBox={`0 0 ${size} ${size}`} aria-hidden className="shrink-0">
      <circle cx={size / 2} cy={size / 2} r={r} fill="none" stroke="rgba(255,255,255,0.06)" strokeWidth={stroke} />
      {segments.map((seg) => (
        <circle
          key={seg.key}
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

function HoldingRow({ h }: { h: StockHolding }) {
  const foreign = h.market === 'foreign';
  return (
    <tr className="transition hover:bg-[rgba(61,214,140,0.05)]">
      <td className="border-b border-white/5 px-2.5 py-3.5 whitespace-nowrap">
        <div className="flex items-center gap-3">
          <span
            className="grid h-8 w-8 shrink-0 place-items-center rounded-full font-[family-name:var(--font-display)] text-[0.65rem] font-bold text-[#0a0f0d]"
            style={{ background: h.color }}
          >
            {h.code ? h.code.slice(0, 2) : h.name.slice(0, 1)}
          </span>
          <span>
            <strong className="block font-[family-name:var(--font-display)] text-sm leading-tight">
              {h.code ? `${h.code} ${h.name}` : h.name}
            </strong>
            <small className="text-xs text-[var(--color-muted)]">
              {ACCOUNT_META[h.account].short}
              {h.exchange ? ` · ${h.exchange}` : ''}
              {h.buyDate ? ` · ${h.buyDate}` : ''}
            </small>
          </span>
        </div>
      </td>
      <td className="border-b border-white/5 px-2.5 py-3.5 tabular-nums whitespace-nowrap max-md:hidden">
        {h.isCash
          ? formatUsd(h.quantity)
          : h.quantity.toLocaleString('ja-JP')}
      </td>
      <td className="border-b border-white/5 px-2.5 py-3.5 tabular-nums whitespace-nowrap max-md:hidden">
        {foreign ? formatUsd(h.currentPrice) : formatYen(h.currentPrice)}
      </td>
      <td className="border-b border-white/5 px-2.5 py-3.5 tabular-nums whitespace-nowrap">
        <div>{formatYen(h.value)}</div>
        {h.valueUsd != null ? (
          <div className="text-xs text-[var(--color-muted)]">{formatUsd(h.valueUsd)}</div>
        ) : null}
      </td>
      <td className={`border-b border-white/5 px-2.5 py-3.5 tabular-nums whitespace-nowrap ${toneClass(h.pnlJpy)}`}>
        {h.isCash ? (
          <span className="text-[var(--color-muted)]">—</span>
        ) : (
          <>
            <div>{formatSignedYen(h.pnlJpy)}</div>
            {foreign ? (
              <div className="text-xs opacity-80">
                {formatSignedUsd(h.pnl)} · {formatPct(h.pnlPct)}
              </div>
            ) : (
              <div className="text-xs opacity-80">{formatPct(h.pnlPct)}</div>
            )}
          </>
        )}
      </td>
      <td className={`border-b border-white/5 px-2.5 py-3.5 tabular-nums whitespace-nowrap max-sm:hidden ${toneClass(h.dayChangePct)}`}>
        {foreign || h.isCash ? (
          <span className="text-[var(--color-muted)]">—</span>
        ) : (
          formatPct(h.dayChangePct)
        )}
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
}

export default function StockDashboard() {
  const inputId = useId();
  const fileRef = useRef<HTMLInputElement>(null);
  const [portfolio, setPortfolio] = useState<StockPortfolio | null>(null);
  const [loading, setLoading] = useState(true);
  const [refreshing, setRefreshing] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const [marketFilter, setMarketFilter] = useState<MarketFilter>('all');
  const [accountFilter, setAccountFilter] = useState<AccountFilter>('all');

  const loadForeign = useCallback(async (): Promise<ForeignPortfolioRaw> => {
    const res = await fetch('/data/sbi-foreign.json', { cache: 'no-store' });
    if (!res.ok) throw new Error('foreign missing');
    return (await res.json()) as ForeignPortfolioRaw;
  }, []);

  const loadCombined = useCallback(
    async (source: 'seed' | 'upload', csvText?: string) => {
      const [domesticText, foreign] = await Promise.all([
        csvText
          ? Promise.resolve(csvText)
          : fetch('/data/sbi-portfolio.csv', { cache: 'no-store' }).then(async (r) => {
              if (!r.ok) throw new Error('domestic missing');
              return r.text();
            }),
        loadForeign(),
      ]);
      const domestic = parseSbiPortfolioCsv(domesticText, source);
      return mergePortfolios(domestic, foreign, source);
    },
    [loadForeign],
  );

  useEffect(() => {
    let cancelled = false;
    (async () => {
      setLoading(true);
      setError(null);
      try {
        const saved = typeof window !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;
        const next = await loadCombined(saved ? 'upload' : 'seed', saved ?? undefined);
        if (!cancelled) {
          if (!next.holdings.length) throw new Error('empty');
          setPortfolio(next);
        }
      } catch {
        if (!cancelled) {
          setPortfolio(null);
          setError(labels.error);
        }
      } finally {
        if (!cancelled) setLoading(false);
      }
    })();
    return () => {
      cancelled = true;
    };
  }, [loadCombined]);

  async function onFile(file: File | null) {
    if (!file) return;
    setLoading(true);
    setError(null);
    try {
      const text = await decodeCsvFile(file);
      const next = await loadCombined('upload', text);
      if (!next.holdings.length) throw new Error('empty');
      localStorage.setItem(STORAGE_KEY, text);
      setPortfolio(next);
      setMarketFilter('all');
      setAccountFilter('all');
    } catch {
      setError(labels.error);
    } finally {
      setLoading(false);
      if (fileRef.current) fileRef.current.value = '';
    }
  }

  async function resetToSeed() {
    localStorage.removeItem(STORAGE_KEY);
    setLoading(true);
    setError(null);
    try {
      const next = await loadCombined('seed');
      setPortfolio(next);
      setMarketFilter('all');
      setAccountFilter('all');
    } catch {
      setError(labels.error);
    } finally {
      setLoading(false);
    }
  }

  async function refreshIdeas() {
    setRefreshing(true);
    setError(null);
    try {
      const saved = typeof window !== 'undefined' ? localStorage.getItem(STORAGE_KEY) : null;
      const next = await loadCombined(saved ? 'upload' : 'seed', saved ?? undefined);
      if (!next.holdings.length) throw new Error('empty');
      // Force a fresh timestamp so analysis/ideas regenerate visibly.
      setPortfolio({ ...next, updatedAt: new Date().toISOString() });
    } catch {
      setError(labels.error);
    } finally {
      setRefreshing(false);
    }
  }

  const visible = portfolio
    ? portfolio.holdings.filter((h) => {
        if (marketFilter === 'cash') return h.isCash;
        if (marketFilter === 'domestic') return h.market === 'domestic';
        if (marketFilter === 'foreign') return h.market === 'foreign' && !h.isCash;
        if (accountFilter !== 'all') return h.account === accountFilter;
        return true;
      })
    : [];

  const sectionsVisible = portfolio
    ? portfolio.sections.filter((s) => {
        if (marketFilter === 'cash') return s.account === 'cash_usd';
        if (marketFilter === 'domestic') return s.market === 'domestic';
        if (marketFilter === 'foreign') return s.market === 'foreign' && s.account !== 'cash_usd';
        return true;
      })
    : [];

  const fxLabel = portfolio?.usdJpy
    ? `1 USD = ${portfolio.usdJpy.toFixed(2)} 円`
    : null;

  return (
    <div className="mx-auto flex max-w-[1080px] flex-col gap-5 px-4 py-8 sm:px-6 lg:py-10">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="mb-1.5 font-[family-name:var(--font-display)] text-xs tracking-[0.16em] text-[var(--color-up)] uppercase">
            {labels.title}
          </p>
          <h1 className="font-[family-name:var(--font-display)] text-[clamp(1.4rem,3vw,2rem)] font-bold leading-tight text-[var(--color-ink)]">
            {labels.brand}
          </h1>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            {portfolio ? (
              <span className="inline-block rounded-full border border-white/15 px-2.5 py-0.5 text-[0.7rem] tracking-wider text-[var(--color-muted)] uppercase">
                {portfolio.source === 'upload' ? labels.sourceUpload : labels.sourceSeed}
              </span>
            ) : null}
            <Link
              href="/"
              className="text-[0.7rem] tracking-wider text-[var(--color-muted)] underline-offset-2 hover:text-[var(--color-ink)] hover:underline"
            >
              Crypto →
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
          <input
            ref={fileRef}
            id={inputId}
            type="file"
            accept=".csv,text/csv"
            className="sr-only"
            onChange={(e) => void onFile(e.target.files?.[0] ?? null)}
          />
          <label
            htmlFor={inputId}
            className="cursor-pointer rounded-full border border-[var(--color-line)] bg-[rgba(61,214,140,0.18)] px-3.5 py-2 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-ink)] transition hover:bg-[rgba(61,214,140,0.28)]"
          >
            {portfolio ? labels.reupload : labels.upload}
          </label>
          {portfolio?.source === 'upload' ? (
            <button
              type="button"
              onClick={() => void resetToSeed()}
              className="rounded-full border border-white/15 px-3.5 py-2 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-muted)] transition hover:text-[var(--color-ink)]"
            >
              {labels.reset}
            </button>
          ) : null}
        </div>
      </header>

      <p className="max-w-xl text-[0.95rem] text-[var(--color-muted)]">{labels.subtitle}</p>

      {portfolio ? (
        <StockPortfolioInsight
          portfolio={portfolio}
          loading={loading}
          refreshing={refreshing}
          onRefresh={() => void refreshIdeas()}
        />
      ) : null}

      {error ? <p className="text-sm text-[var(--color-down)]">{error}</p> : null}

      {loading && !portfolio ? (
        <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-8 text-[var(--color-muted)]">
          {labels.loading}
        </div>
      ) : null}

      {portfolio ? (
        <>
          <div className="flex flex-wrap gap-2">
            {(
              [
                ['all', labels.filterAll],
                ['domestic', labels.filterDomestic],
                ['foreign', labels.filterForeign],
              ] as const
            ).map(([key, label]) => (
              <button
                key={key}
                type="button"
                onClick={() => {
                  setMarketFilter(key);
                  setAccountFilter('all');
                }}
                className={`rounded-full border px-3.5 py-1.5 font-[family-name:var(--font-display)] text-xs tracking-wider transition ${
                  marketFilter === key
                    ? 'border-[var(--color-line)] bg-[rgba(61,214,140,0.18)] text-[var(--color-ink)]'
                    : 'border-white/15 text-[var(--color-muted)] hover:text-[var(--color-ink)]'
                }`}
              >
                {label}
              </button>
            ))}
          </div>

          <div className={`grid gap-4 lg:grid-cols-4 ${loading ? 'opacity-70' : ''}`}>
            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md lg:col-span-1">
              <p className="mb-2 font-[family-name:var(--font-display)] text-xs tracking-[0.12em] text-[var(--color-muted)] uppercase">
                {labels.total}
              </p>
              <strong className="block font-[family-name:var(--font-display)] text-[clamp(1.6rem,3.5vw,2.3rem)] font-black tracking-tight">
                {formatYen(portfolio.totalValue)}
              </strong>
              {portfolio.totalValueUsd > 0 ? (
                <p className="mt-1 text-sm text-[var(--color-muted)]">
                  海外分 {formatUsd(portfolio.totalValueUsd)}
                </p>
              ) : null}
            </div>
            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
              <p className="mb-2 font-[family-name:var(--font-display)] text-xs tracking-[0.12em] text-[var(--color-muted)] uppercase">
                {labels.pnl}
              </p>
              <div className={`font-[family-name:var(--font-display)] text-[clamp(1.2rem,2.8vw,1.7rem)] font-bold ${toneClass(portfolio.totalPnl)}`}>
                <div>{formatSignedYen(portfolio.totalPnl)}</div>
                <div className="mt-1 text-base font-semibold">{formatPct(portfolio.totalPnlPct)}</div>
              </div>
              {portfolio.totalPnlUsd !== 0 ? (
                <p className={`mt-1 text-xs ${toneClass(portfolio.totalPnlUsd)}`}>
                  海外 {formatSignedUsd(portfolio.totalPnlUsd)}
                </p>
              ) : null}
            </div>
            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
              <p className="mb-2 font-[family-name:var(--font-display)] text-xs tracking-[0.12em] text-[var(--color-muted)] uppercase">
                {labels.day}
              </p>
              <div className={`font-[family-name:var(--font-display)] text-[clamp(1.2rem,2.8vw,1.7rem)] font-bold ${toneClass(portfolio.dayChange)}`}>
                <div>{formatSignedYen(portfolio.dayChange)}</div>
                <div className="mt-1 text-base font-semibold">{formatPct(portfolio.dayChangePct)}</div>
              </div>
            </div>
            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
              <p className="mb-2 font-[family-name:var(--font-display)] text-xs tracking-[0.12em] text-[var(--color-muted)] uppercase">
                {labels.fx}
              </p>
              <strong className="block font-[family-name:var(--font-display)] text-[clamp(1.2rem,2.8vw,1.5rem)] font-bold">
                {fxLabel ?? '—'}
              </strong>
              {portfolio.fxAsOf ? (
                <p className="mt-2 text-xs text-[var(--color-muted)]">
                  {new Date(portfolio.fxAsOf).toLocaleString('ja-JP', {
                    month: 'short',
                    day: 'numeric',
                    hour: '2-digit',
                    minute: '2-digit',
                  })}
                </p>
              ) : null}
            </div>
          </div>

          <div className="grid gap-4 lg:grid-cols-[1fr_1.2fr]">
            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
              <h2 className="mb-4 font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
                {labels.byMarket}
              </h2>
              <div className="flex flex-wrap items-center justify-center gap-5 sm:justify-start">
                <AllocationRing
                  items={portfolio.markets.map((m) => ({
                    key: m.market,
                    weight: m.weight,
                    color: m.color,
                  }))}
                />
                <ul className="flex min-w-[10rem] flex-1 flex-col gap-3">
                  {portfolio.markets.map((m) => (
                    <li key={m.market}>
                      <button
                        type="button"
                        onClick={() => {
                          setMarketFilter((prev) => (prev === m.market ? 'all' : m.market));
                          setAccountFilter('all');
                        }}
                        className={`grid w-full grid-cols-[0.7rem_1fr_auto] items-center gap-2 rounded-lg px-1 py-1 text-left text-sm transition hover:bg-white/5 ${
                          marketFilter === m.market ? 'bg-white/5' : ''
                        }`}
                      >
                        <span className="h-2.5 w-2.5 rounded-sm" style={{ background: m.color }} />
                        <span>
                          <span className="block font-[family-name:var(--font-display)] text-xs">{m.label}</span>
                          <span className="text-xs text-[var(--color-muted)]">
                            {formatYen(m.value)}
                            {m.valueUsd != null ? ` · ${formatUsd(m.valueUsd)}` : ''}
                          </span>
                        </span>
                        <span className="tabular-nums text-[var(--color-muted)]">{m.weight}%</span>
                      </button>
                      {m.pnlJpy !== 0 ? (
                        <div className={`mt-0.5 pl-5 text-xs tabular-nums ${toneClass(m.pnlJpy)}`}>
                          {formatSignedYen(m.pnlJpy)}
                        </div>
                      ) : null}
                    </li>
                  ))}
                </ul>
              </div>
            </div>

            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
              <h2 className="mb-3 font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
                {labels.byAccount}
              </h2>
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-sm">
                  <thead>
                    <tr>
                      {[labels.account, labels.value, labels.pnlCol].map((label) => (
                        <th
                          key={label}
                          className="border-b border-white/10 px-2 pb-3 text-left font-[family-name:var(--font-display)] text-[0.68rem] tracking-wider text-[var(--color-muted)] uppercase"
                        >
                          {label}
                        </th>
                      ))}
                    </tr>
                  </thead>
                  <tbody>
                    {sectionsVisible.map((s) => (
                      <tr
                        key={s.account}
                        className="cursor-pointer transition hover:bg-white/5"
                        onClick={() => {
                          setAccountFilter((prev) => (prev === s.account ? 'all' : s.account));
                          setMarketFilter('all');
                        }}
                      >
                        <td className="border-b border-white/5 px-2 py-3 whitespace-nowrap">
                          <span className="mr-2 inline-block h-2 w-2 rounded-sm" style={{ background: s.color }} />
                          {s.accountLabel}
                          <span className="ml-1 text-xs text-[var(--color-muted)]">
                            (
                            {s.account === 'cash_usd'
                              ? MARKET_META.cash.label
                              : s.market === 'domestic'
                                ? MARKET_META.domestic.label
                                : MARKET_META.foreign.label}
                            )
                          </span>
                        </td>
                        <td className="border-b border-white/5 px-2 py-3 tabular-nums">
                          <div>{formatYen(s.value)}</div>
                          {s.valueUsd != null ? (
                            <div className="text-xs text-[var(--color-muted)]">{formatUsd(s.valueUsd)}</div>
                          ) : null}
                        </td>
                        <td className={`border-b border-white/5 px-2 py-3 tabular-nums ${toneClass(s.pnlJpy)}`}>
                          {s.account === 'cash_usd' ? (
                            '—'
                          ) : (
                            <>
                              {formatSignedYen(s.pnlJpy)}
                              <span className="ml-1 text-xs opacity-80">{formatPct(s.pnlPct)}</span>
                            </>
                          )}
                        </td>
                      </tr>
                    ))}
                  </tbody>
                </table>
              </div>
            </div>
          </div>

          <section className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md" aria-labelledby="stock-holdings-title">
            <div className="mb-4 flex flex-wrap items-center justify-between gap-2">
              <h2 id="stock-holdings-title" className="font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
                {labels.holdings}
                {accountFilter !== 'all' ? ` · ${ACCOUNT_META[accountFilter].short}` : ''}
                {marketFilter !== 'all' && accountFilter === 'all'
                  ? ` · ${marketFilter === 'cash' ? MARKET_META.cash.label : MARKET_META[marketFilter].label}`
                  : ''}
              </h2>
              {(marketFilter !== 'all' || accountFilter !== 'all') && (
                <button
                  type="button"
                  onClick={() => {
                    setMarketFilter('all');
                    setAccountFilter('all');
                  }}
                  className="text-xs text-[var(--color-muted)] underline-offset-2 hover:underline"
                >
                  フィルタ解除
                </button>
              )}
            </div>
            {visible.length ? (
              <div className="overflow-x-auto">
                <table className="w-full border-collapse text-[0.92rem]">
                  <thead>
                    <tr>
                      {[labels.code, labels.qty, labels.price, labels.value, labels.pnlCol, labels.dayCol, labels.weight].map(
                        (label) => (
                          <th
                            key={label}
                            className="border-b border-white/10 px-2.5 pb-3 text-left font-[family-name:var(--font-display)] text-[0.68rem] tracking-wider whitespace-nowrap text-[var(--color-muted)] uppercase"
                          >
                            {label}
                          </th>
                        ),
                      )}
                    </tr>
                  </thead>
                  <tbody>
                    {visible.map((h) => (
                      <HoldingRow key={h.id} h={h} />
                    ))}
                  </tbody>
                </table>
              </div>
            ) : (
              <p className="text-sm text-[var(--color-muted)]">{labels.empty}</p>
            )}
          </section>
        </>
      ) : null}
    </div>
  );
}
