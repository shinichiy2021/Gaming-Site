'use client';

import Link from 'next/link';
import { useCallback, useEffect, useMemo, useRef, useState } from 'react';
import AllocationBars from '@/components/bot/AllocationBars';
import EquityCurve from '@/components/bot/EquityCurve';
import OrderBookLadder, { type BookPayload } from '@/components/bot/OrderBookLadder';
import { fetchUsdPrices, type PriceMap } from '@/lib/prices';
import {
  PAPER_STARTING_CASH,
  PAPER_UNIVERSE,
  buildSnapshot,
  createPaperBotState,
  loadPaperBotState,
  runPaperTick,
  savePaperBotState,
  type PaperBotState,
} from '@/lib/paperBot';

function fmtUsd(n: number, digits = 2) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: digits,
  }).format(n);
}

function fmtPct(n: number) {
  const sign = n > 0 ? '+' : '';
  return `${sign}${n.toFixed(2)}%`;
}

function tone(n: number) {
  if (n > 0) return 'text-[var(--color-up)]';
  if (n < 0) return 'text-[var(--color-down)]';
  return 'text-[var(--color-muted)]';
}

function formatTime(iso: string | null) {
  if (!iso) return '—';
  try {
    return new Date(iso).toLocaleString('ja-JP', {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  } catch {
    return iso;
  }
}

const AUTO_PREF_KEY = 'gaming-hub-paper-bot-auto-v1';
const INTERVAL_OPTIONS = [
  { sec: 15, label: '15秒' },
  { sec: 30, label: '30秒' },
  { sec: 60, label: '60秒' },
] as const;

type AutoPrefs = { on: boolean; intervalSec: number };

function loadAutoPrefs(): AutoPrefs {
  if (typeof window === 'undefined') return { on: false, intervalSec: 15 };
  try {
    const raw = localStorage.getItem(AUTO_PREF_KEY);
    if (!raw) return { on: false, intervalSec: 15 };
    const parsed = JSON.parse(raw) as Partial<AutoPrefs>;
    const intervalSec = INTERVAL_OPTIONS.some((o) => o.sec === parsed.intervalSec)
      ? (parsed.intervalSec as number)
      : 15;
    return { on: Boolean(parsed.on), intervalSec };
  } catch {
    return { on: false, intervalSec: 15 };
  }
}

export default function PaperBotDashboard() {
  const [state, setState] = useState<PaperBotState | null>(null);
  const [prices, setPrices] = useState<PriceMap | null>(null);
  const [book, setBook] = useState<BookPayload | null>(null);
  const [bookError, setBookError] = useState<string | null>(null);
  const [bookLoading, setBookLoading] = useState(true);
  const [loading, setLoading] = useState(true);
  const [running, setRunning] = useState(false);
  const [auto, setAuto] = useState(false);
  const [intervalSec, setIntervalSec] = useState(15);
  const [countdown, setCountdown] = useState(0);
  const [notes, setNotes] = useState<string[]>([]);
  const [lastTradeCount, setLastTradeCount] = useState(0);
  const [error, setError] = useState<string | null>(null);
  const [hydrated, setHydrated] = useState(false);
  const stateRef = useRef<PaperBotState | null>(null);
  const runningRef = useRef(false);
  const nextTickAtRef = useRef<number>(0);

  const ids = useMemo(() => PAPER_UNIVERSE.map((u) => u.coingeckoId), []);

  const refreshPrices = useCallback(async () => {
    const next = await fetchUsdPrices(ids);
    setPrices(next);
    return next;
  }, [ids]);

  const refreshBook = useCallback(async () => {
    try {
      const res = await fetch('/api/phoenix/book', { cache: 'no-store' });
      const json = (await res.json()) as BookPayload & { error?: string };
      if (!res.ok) {
        setBookError(json.error || '板の取得に失敗');
        return;
      }
      setBookError(null);
      setBook(json);
    } catch {
      setBookError('板の取得に失敗');
    } finally {
      setBookLoading(false);
    }
  }, []);

  useEffect(() => {
    stateRef.current = state;
  }, [state]);

  useEffect(() => {
    const prefs = loadAutoPrefs();
    setAuto(prefs.on);
    setIntervalSec(prefs.intervalSec);
    setHydrated(true);

    const bot = loadPaperBotState();
    setState(bot);
    (async () => {
      setLoading(true);
      setError(null);
      try {
        await Promise.all([refreshPrices(), refreshBook()]);
      } catch {
        setError('初期データの取得に失敗しました');
      } finally {
        setLoading(false);
      }
    })();
  }, [refreshPrices, refreshBook]);

  useEffect(() => {
    if (!hydrated) return;
    localStorage.setItem(AUTO_PREF_KEY, JSON.stringify({ on: auto, intervalSec }));
  }, [auto, intervalSec, hydrated]);

  useEffect(() => {
    if (!state) return;
    savePaperBotState(state);
  }, [state]);

  useEffect(() => {
    const id = window.setInterval(() => {
      void refreshBook();
    }, 4000);
    return () => window.clearInterval(id);
  }, [refreshBook]);

  const snapshot = useMemo(() => {
    if (!state || !prices) return null;
    return buildSnapshot(state, prices);
  }, [state, prices]);

  const paperQuotes = useMemo(() => {
    if (!book?.mid || !state) return { bid: null as number | null, ask: null as number | null };
    const edge = book.mid * ((state.gridSpreadBps ?? 20) / 10_000);
    return { bid: book.mid - edge, ask: book.mid + edge };
  }, [book, state]);

  const runTick = useCallback(async () => {
    const current = stateRef.current;
    if (!current || runningRef.current) return;
    runningRef.current = true;
    setRunning(true);
    setError(null);
    try {
      const [px] = await Promise.all([refreshPrices(), refreshBook()]);
      const result = runPaperTick(current, px);
      setState(result.state);
      setNotes(result.notes);
      setLastTradeCount(result.trades.length);
      nextTickAtRef.current = Date.now() + intervalSec * 1000;
    } catch {
      setError('ティック実行に失敗しました');
    } finally {
      runningRef.current = false;
      setRunning(false);
    }
  }, [refreshPrices, refreshBook, intervalSec]);

  function resetBot() {
    const fresh = createPaperBotState(PAPER_STARTING_CASH);
    setState(fresh);
    setNotes([`ペーパー資金 $${PAPER_STARTING_CASH} でリセットしました（実弾取引なし）`]);
    setLastTradeCount(0);
  }

  function toggleAuto() {
    setAuto((prev) => {
      const next = !prev;
      if (next) {
        nextTickAtRef.current = Date.now();
        void runTick();
      }
      return next;
    });
  }

  // Auto loop + countdown
  useEffect(() => {
    if (!auto || !hydrated) {
      setCountdown(0);
      return;
    }

    if (!nextTickAtRef.current) {
      nextTickAtRef.current = Date.now() + intervalSec * 1000;
    }

    const id = window.setInterval(() => {
      const remainMs = nextTickAtRef.current - Date.now();
      setCountdown(Math.max(0, Math.ceil(remainMs / 1000)));
      if (remainMs <= 0 && !runningRef.current) {
        nextTickAtRef.current = Date.now() + intervalSec * 1000;
        void runTick();
      }
    }, 250);

    return () => window.clearInterval(id);
  }, [auto, hydrated, intervalSec, runTick]);

  const busy = loading || running;
  const cashWeight =
    snapshot && snapshot.equityUsd > 0 ? (state!.cashUsd / snapshot.equityUsd) * 100 : 100;

  return (
    <div className="mx-auto flex max-w-[1180px] flex-col gap-5 px-4 py-8 sm:px-6 lg:py-10">
      <header className="flex flex-wrap items-start justify-between gap-4">
        <div>
          <p className="mb-1.5 font-[family-name:var(--font-display)] text-xs tracking-[0.16em] text-[var(--color-up)] uppercase">
            Paper Bot · Phoenix L2
          </p>
          <h1 className="font-[family-name:var(--font-display)] text-[clamp(1.4rem,3vw,2rem)] font-bold leading-tight text-[var(--color-ink)]">
            仮想 $1,000 ボット
          </h1>
          <div className="mt-2 flex flex-wrap items-center gap-2">
            <span className="inline-block rounded-full border border-[rgba(247,147,26,0.35)] bg-[rgba(247,147,26,0.12)] px-2.5 py-0.5 text-[0.7rem] tracking-wider text-[#f7c948] uppercase">
              Simulation only
            </span>
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
          </div>
        </div>

        <div className="flex flex-wrap items-center gap-2">
          <button
            type="button"
            onClick={() => void runTick()}
            disabled={busy || !state}
            className="rounded-full border border-[var(--color-line)] bg-[rgba(61,214,140,0.18)] px-3.5 py-2 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-ink)] transition hover:bg-[rgba(61,214,140,0.28)] disabled:opacity-50"
          >
            {running ? '実行中…' : '今すぐ1回'}
          </button>
          <button
            type="button"
            onClick={toggleAuto}
            className={`rounded-full border px-3.5 py-2 font-[family-name:var(--font-display)] text-xs tracking-wider transition ${
              auto
                ? 'border-[var(--color-line)] bg-[rgba(61,214,140,0.22)] text-[var(--color-ink)] shadow-[0_0_20px_rgba(61,214,140,0.25)]'
                : 'border-white/15 text-[var(--color-muted)]'
            }`}
          >
            {auto ? `● 自動ON` : '○ 自動OFF'}
          </button>
          <div className="inline-flex rounded-full border border-white/15 p-0.5" role="group" aria-label="自動間隔">
            {INTERVAL_OPTIONS.map((opt) => (
              <button
                key={opt.sec}
                type="button"
                onClick={() => {
                  setIntervalSec(opt.sec);
                  if (auto) nextTickAtRef.current = Date.now() + opt.sec * 1000;
                }}
                className={`rounded-full px-2.5 py-1.5 font-[family-name:var(--font-display)] text-[0.65rem] tracking-wider transition ${
                  intervalSec === opt.sec
                    ? 'bg-white/10 text-[var(--color-ink)]'
                    : 'text-[var(--color-muted)]'
                }`}
              >
                {opt.label}
              </button>
            ))}
          </div>
          <button
            type="button"
            onClick={resetBot}
            className="rounded-full border border-white/15 px-3.5 py-2 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-muted)] transition hover:text-[var(--color-ink)]"
          >
            $1000にリセット
          </button>
        </div>
      </header>

      <p className="max-w-2xl text-[0.95rem] text-[var(--color-muted)]">
        ペーパー自動のみ。仮想資金で売買し、板・損益・約定をこの画面で確認できます（実弾なし）。
      </p>

      {auto ? (
        <div className="flex flex-wrap items-center gap-4 rounded-2xl border border-[var(--color-line)] bg-[rgba(61,214,140,0.1)] px-5 py-4">
          <span className="relative flex h-3 w-3">
            <span className="absolute inline-flex h-full w-full animate-ping rounded-full bg-[var(--color-up)] opacity-60" />
            <span className="relative inline-flex h-3 w-3 rounded-full bg-[var(--color-up)]" />
          </span>
          <div className="min-w-0 flex-1">
            <p className="font-[family-name:var(--font-display)] text-sm font-bold text-[var(--color-ink)]">
              ペーパー自動稼働中
            </p>
            <p className="text-xs text-[var(--color-muted)]">
              {running
                ? 'ティック実行中… 価格取得 → 判断 → 仮想約定'
                : `次のティックまで ${countdown}s · 間隔 ${intervalSec}s · 最終 ${formatTime(state?.lastTickAt ?? null)}`}
            </p>
          </div>
          <div className="text-right">
            <p className="font-[family-name:var(--font-display)] text-2xl font-black tabular-nums text-[var(--color-up)]">
              {countdown}
              <span className="ml-1 text-sm font-semibold text-[var(--color-muted)]">s</span>
            </p>
            {lastTradeCount > 0 ? (
              <p className="text-xs text-[var(--color-up)]">直前 +{lastTradeCount} 約定</p>
            ) : (
              <p className="text-xs text-[var(--color-muted)]">直前 様子見</p>
            )}
          </div>
        </div>
      ) : null}

      {error ? <p className="text-sm text-[var(--color-down)]">{error}</p> : null}

      {snapshot && state ? (
        <>
          {/* Hero metrics */}
          <div className={`grid gap-4 sm:grid-cols-2 lg:grid-cols-4 ${busy ? 'opacity-80' : ''}`}>
            {[
              {
                label: '評価額',
                value: fmtUsd(snapshot.equityUsd),
                sub: `現金 ${cashWeight.toFixed(0)}%`,
              },
              {
                label: '損益',
                value: `${snapshot.pnlUsd >= 0 ? '+' : ''}${fmtUsd(snapshot.pnlUsd)}`,
                sub: fmtPct(snapshot.pnlPct),
                className: tone(snapshot.pnlUsd),
              },
              {
                label: 'ティック',
                value: String(state.tickCount),
                sub: formatTime(state.lastTickAt),
              },
              {
                label: 'Paper spread',
                value: `${state.gridSpreadBps} bps`,
                sub: paperQuotes.bid
                  ? `${paperQuotes.bid.toFixed(2)} / ${paperQuotes.ask?.toFixed(2)}`
                  : 'mid待ち',
              },
            ].map((card) => (
              <div
                key={card.label}
                className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md"
              >
                <p className="mb-2 text-xs tracking-[0.12em] text-[var(--color-muted)] uppercase">{card.label}</p>
                <strong
                  className={`block font-[family-name:var(--font-display)] text-[clamp(1.35rem,2.8vw,1.9rem)] font-black tabular-nums ${card.className ?? ''}`}
                >
                  {card.value}
                </strong>
                <p className={`mt-1 text-xs tabular-nums ${card.className ?? 'text-[var(--color-muted)]'}`}>{card.sub}</p>
              </div>
            ))}
          </div>

          {/* Spread control */}
          <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5">
            <div className="mb-3 flex flex-wrap items-center justify-between gap-2">
              <h2 className="font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
                Maker spread（板オーバーレイ）
              </h2>
              <span className="font-[family-name:var(--font-display)] tabular-nums text-[var(--color-ink)]">
                {state.gridSpreadBps} bps = {(state.gridSpreadBps / 100).toFixed(2)}%
              </span>
            </div>
            <input
              type="range"
              min={5}
              max={80}
              step={1}
              value={state.gridSpreadBps}
              onChange={(e) =>
                setState((s) => (s ? { ...s, gridSpreadBps: Number(e.target.value) } : s))
              }
              className="w-full accent-[var(--color-up)]"
              aria-label="Grid spread bps"
            />
            <div className="mt-3 h-3 overflow-hidden rounded-full bg-white/10">
              <div className="relative h-full">
                <div
                  className="absolute inset-y-0 left-1/2 -translate-x-1/2 rounded-full bg-[rgba(61,214,140,0.45)] transition-all"
                  style={{ width: `${Math.min(90, state.gridSpreadBps)}%` }}
                />
                <div className="absolute inset-y-0 left-1/2 w-0.5 -translate-x-1/2 bg-[var(--color-ink)]" />
              </div>
            </div>
            <p className="mt-2 text-xs text-[var(--color-muted)]">中央が mid、帯が paper bid/ask の距離イメージ</p>
          </div>

          {/* Main graphic row */}
          <div className="grid gap-4 lg:grid-cols-[1.05fr_0.95fr]">
            <div className="flex flex-col gap-4">
              <EquityCurve history={state.equityHistory ?? []} startingCash={state.startingCash} />
              <AllocationBars
                holdings={snapshot.holdings}
                cashUsd={state.cashUsd}
                equityUsd={snapshot.equityUsd}
              />
            </div>
            <OrderBookLadder
              book={book}
              loading={bookLoading && !book}
              error={bookError}
              quoteBid={paperQuotes.bid}
              quoteAsk={paperQuotes.ask}
            />
          </div>

          {notes.length ? (
            <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5">
              <h2 className="mb-3 font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
                直近の判断
              </h2>
              <ul className="flex flex-col gap-2">
                {notes.map((n) => (
                  <li key={n} className="text-[0.92rem] text-[var(--color-ink)]/90">
                    {n}
                  </li>
                ))}
              </ul>
            </div>
          ) : null}

          <div className="grid gap-4 lg:grid-cols-2">
            <section className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5">
              <h2 className="mb-4 font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
                保有ポジション
              </h2>
              {snapshot.holdings.length ? (
                <div className="overflow-x-auto">
                  <table className="w-full border-collapse text-sm">
                    <thead>
                      <tr>
                        {['銘柄', '評価', '損益', '比率'].map((h) => (
                          <th
                            key={h}
                            className="border-b border-white/10 px-2 pb-2 text-left text-[0.68rem] tracking-wider text-[var(--color-muted)] uppercase"
                          >
                            {h}
                          </th>
                        ))}
                      </tr>
                    </thead>
                    <tbody>
                      {snapshot.holdings.map((h) => (
                        <tr key={h.symbol}>
                          <td className="border-b border-white/5 px-2 py-3 whitespace-nowrap">
                            <span className="mr-2 inline-block h-2 w-2 rounded-sm" style={{ background: h.color }} />
                            {h.symbol}
                            <span className="ml-1 text-xs text-[var(--color-muted)]">{fmtPct(h.change24h)}</span>
                          </td>
                          <td className="border-b border-white/5 px-2 py-3 tabular-nums">{fmtUsd(h.valueUsd)}</td>
                          <td className={`border-b border-white/5 px-2 py-3 tabular-nums ${tone(h.pnlUsd)}`}>
                            {fmtPct(h.pnlPct)}
                          </td>
                          <td className="border-b border-white/5 px-2 py-3">
                            <div className="flex min-w-[4rem] flex-col gap-1">
                              <span className="tabular-nums">{h.weight}%</span>
                              <span className="block h-1 overflow-hidden rounded-full bg-white/10">
                                <span
                                  className="block h-full rounded-full"
                                  style={{ width: `${Math.min(100, h.weight)}%`, background: h.color }}
                                />
                              </span>
                            </div>
                          </td>
                        </tr>
                      ))}
                    </tbody>
                  </table>
                </div>
              ) : (
                <p className="text-sm text-[var(--color-muted)]">ポジションなし。ティックを実行してください。</p>
              )}
            </section>

            <section className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5">
              <h2 className="mb-4 font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
                約定テープ
              </h2>
              {state.trades.length ? (
                <ul className="flex max-h-[22rem] flex-col gap-2 overflow-y-auto pr-1">
                  {state.trades.slice(0, 24).map((t) => (
                    <li
                      key={t.id}
                      className="grid grid-cols-[auto_1fr_auto] items-center gap-3 rounded-lg border border-white/5 bg-black/20 px-3 py-2 text-sm"
                    >
                      <span
                        className={`rounded px-2 py-0.5 font-[family-name:var(--font-display)] text-[0.65rem] tracking-wider ${
                          t.side === 'buy'
                            ? 'bg-[rgba(61,214,140,0.18)] text-[var(--color-up)]'
                            : 'bg-[rgba(255,107,107,0.18)] text-[var(--color-down)]'
                        }`}
                      >
                        {t.side === 'buy' ? 'BUY' : 'SELL'}
                      </span>
                      <span>
                        <strong className="font-[family-name:var(--font-display)]">{t.symbol}</strong>
                        <span className="ml-2 tabular-nums text-[var(--color-muted)]">{fmtUsd(t.notionalUsd)}</span>
                        <p className="mt-0.5 text-xs text-[var(--color-muted)]">{t.reason}</p>
                      </span>
                      <span className="text-xs text-[var(--color-muted)]">{formatTime(t.at)}</span>
                    </li>
                  ))}
                </ul>
              ) : (
                <p className="text-sm text-[var(--color-muted)]">まだ約定がありません。</p>
              )}
            </section>
          </div>

          <p className="text-xs text-[var(--color-muted)]">
            ペーパー対象: {PAPER_UNIVERSE.map((u) => u.symbol).join(' · ')}。Phoenix 板は監視のみ。投資助言ではありません。
          </p>
        </>
      ) : (
        <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-8 text-[var(--color-muted)]">
          {loading ? '読み込み中…' : 'データを準備できませんでした'}
        </div>
      )}
    </div>
  );
}
