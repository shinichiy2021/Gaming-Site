'use client';

import { useMemo } from 'react';
import { analyzePortfolio, type IdeaItem } from '@/lib/cryptoAnalysis';
import type { Portfolio } from '@/lib/portfolio';

type Props = {
  portfolio: Portfolio;
  hasSources: boolean;
  hasMm: boolean;
  hasBtc: boolean;
  hasSol: boolean;
  hasSui: boolean;
  loading: boolean;
  refreshing?: boolean;
  onRefresh?: () => void;
};

const concentrationLabel = {
  dispersed: '分散寄り',
  moderate: '中程度',
  concentrated: '集中',
} as const;

const toneLabel = {
  'risk-on': 'リスクオン',
  mixed: 'まちまち',
  'risk-off': 'リスクオフ',
  flat: 'ほぼ横ばい',
} as const;

const priorityLabel: Record<IdeaItem['priority'], string> = {
  now: '今すぐ',
  watch: '様子見',
  setup: 'セットアップ',
};

function formatUpdatedAt(iso: string) {
  try {
    return new Date(iso).toLocaleString('ja-JP', {
      month: 'short',
      day: 'numeric',
      hour: '2-digit',
      minute: '2-digit',
      second: '2-digit',
    });
  } catch {
    return '';
  }
}

export default function PortfolioInsight({
  portfolio,
  hasSources,
  hasMm,
  hasBtc,
  hasSol,
  hasSui,
  loading,
  refreshing,
  onRefresh,
}: Props) {
  const insight = useMemo(
    () =>
      analyzePortfolio(portfolio, {
        hasSources,
        hasMm,
        hasBtc,
        hasSol,
        hasSui,
      }),
    [portfolio, hasSources, hasMm, hasBtc, hasSol, hasSui],
  );

  const busy = loading || Boolean(refreshing);

  return (
    <section
      className={`grid gap-4 lg:grid-cols-2 ${busy ? 'opacity-80' : ''}`}
      aria-labelledby="crypto-insight-title"
    >
      <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
        <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="mb-1 font-[family-name:var(--font-display)] text-[0.68rem] tracking-[0.14em] text-[var(--color-up)] uppercase">
              Analysis
            </p>
            <h2
              id="crypto-insight-title"
              className="font-[family-name:var(--font-display)] text-lg font-bold text-[var(--color-ink)]"
            >
              現状の分析
            </h2>
          </div>
          {insight.hasHoldings ? (
            <span className="rounded-full border border-white/15 px-2.5 py-1 text-[0.7rem] text-[var(--color-muted)]">
              {toneLabel[insight.marketTone]}
            </span>
          ) : null}
        </div>

        {insight.hasHoldings ? (
          <div className="mb-4 flex flex-wrap gap-2">
            <span className="rounded-full border border-white/15 px-2.5 py-1 text-[0.7rem] text-[var(--color-muted)]">
              {insight.assetCount} 資産
            </span>
            <span className="rounded-full border border-white/15 px-2.5 py-1 text-[0.7rem] text-[var(--color-muted)]">
              集中度 {concentrationLabel[insight.concentration]}
            </span>
            {insight.top ? (
              <span className="rounded-full border border-white/15 px-2.5 py-1 text-[0.7rem] text-[var(--color-muted)]">
                Top {insight.top.symbol} {insight.top.weight}%
              </span>
            ) : null}
            <span className="rounded-full border border-white/15 px-2.5 py-1 text-[0.7rem] text-[var(--color-muted)]">
              上位3銘柄 {insight.top3Weight}%
            </span>
          </div>
        ) : null}

        {insight.buckets.length > 0 ? (
          <ul className="mb-4 flex flex-col gap-2">
            {insight.buckets.map((b) => (
              <li key={b.key} className="grid grid-cols-[5.5rem_1fr_auto] items-center gap-2 text-sm">
                <span className="text-[var(--color-muted)]">{b.label}</span>
                <span className="block h-1.5 overflow-hidden rounded-full bg-white/10" aria-hidden>
                  <span
                    className="block h-full rounded-full bg-[var(--color-up)]"
                    style={{ width: `${Math.min(100, b.weight)}%`, opacity: 0.55 + b.weight / 200 }}
                  />
                </span>
                <span className="tabular-nums text-[var(--color-muted)]">{b.weight}%</span>
              </li>
            ))}
          </ul>
        ) : null}

        <ul className="flex flex-col gap-2.5">
          {insight.analysis.map((line) => (
            <li key={line} className="text-[0.92rem] leading-relaxed text-[var(--color-ink)]/90">
              {line}
            </li>
          ))}
        </ul>
      </div>

      <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
        <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
          <div>
            <p className="mb-1 font-[family-name:var(--font-display)] text-[0.68rem] tracking-[0.14em] text-[var(--color-up)] uppercase">
              Ideas
            </p>
            <h2 className="font-[family-name:var(--font-display)] text-lg font-bold text-[var(--color-ink)]">
              今後のアイデア
            </h2>
          </div>
          {hasSources && onRefresh ? (
            <button
              type="button"
              onClick={onRefresh}
              disabled={busy}
              className="rounded-full border border-[var(--color-line)] bg-black/35 px-3 py-1.5 font-[family-name:var(--font-display)] text-[0.7rem] tracking-wider text-[var(--color-ink)] transition hover:bg-[rgba(61,214,140,0.12)] disabled:opacity-50"
            >
              {busy ? '更新中…' : '値動きを再取得'}
            </button>
          ) : null}
        </div>

        <p className="mb-4 text-xs text-[var(--color-muted)]">
          24hの価格・寄与額から自動生成。再取得のたびに金額・％を更新します
          {insight.generatedAt ? ` · ${formatUpdatedAt(insight.generatedAt)}` : ''}
        </p>

        <ol className="flex flex-col gap-4">
          {insight.ideas.map((idea, i) => (
            <li key={idea.id} className="grid grid-cols-[1.4rem_1fr] gap-2">
              <span className="font-[family-name:var(--font-display)] text-sm text-[var(--color-up)]">
                {String(i + 1).padStart(2, '0')}
              </span>
              <div>
                <div className="mb-1 flex flex-wrap items-baseline gap-x-2 gap-y-1">
                  <strong className="font-[family-name:var(--font-display)] text-sm text-[var(--color-ink)]">
                    {idea.title}
                  </strong>
                  <span
                    className={`rounded-full px-2 py-0.5 text-[0.65rem] tracking-wider ${
                      idea.priority === 'now'
                        ? 'bg-[rgba(61,214,140,0.18)] text-[var(--color-up)]'
                        : idea.priority === 'watch'
                          ? 'bg-white/5 text-[var(--color-muted)]'
                          : 'border border-white/15 text-[var(--color-muted)]'
                    }`}
                  >
                    {priorityLabel[idea.priority]}
                  </span>
                </div>
                <p className="text-[0.9rem] leading-relaxed text-[var(--color-ink)]/90">{idea.detail}</p>
                <p className="mt-1 text-[0.7rem] tabular-nums text-[var(--color-muted)]">根拠: {idea.trigger}</p>
              </div>
            </li>
          ))}
        </ol>
      </div>
    </section>
  );
}
