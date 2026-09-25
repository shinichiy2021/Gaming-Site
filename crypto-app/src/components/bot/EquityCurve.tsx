'use client';

import type { EquityPoint } from '@/lib/paperBot';

type Props = {
  history: EquityPoint[];
  startingCash: number;
};

export default function EquityCurve({ history, startingCash }: Props) {
  const points = history.length ? history : [{ t: new Date().toISOString(), equityUsd: startingCash }];
  const values = points.map((p) => p.equityUsd);
  const min = Math.min(...values, startingCash) * 0.995;
  const max = Math.max(...values, startingCash) * 1.005;
  const span = Math.max(max - min, 1);
  const w = 640;
  const h = 180;
  const pad = 12;

  const coords = points.map((p, i) => {
    const x = pad + (i / Math.max(points.length - 1, 1)) * (w - pad * 2);
    const y = pad + (1 - (p.equityUsd - min) / span) * (h - pad * 2);
    return { x, y, ...p };
  });

  const line = coords.map((c, i) => `${i === 0 ? 'M' : 'L'} ${c.x.toFixed(1)} ${c.y.toFixed(1)}`).join(' ');
  const area =
    coords.length > 0
      ? `${line} L ${coords[coords.length - 1].x.toFixed(1)} ${h - pad} L ${coords[0].x.toFixed(1)} ${h - pad} Z`
      : '';

  const last = values[values.length - 1] ?? startingCash;
  const up = last >= startingCash;
  const baselineY = pad + (1 - (startingCash - min) / span) * (h - pad * 2);

  return (
    <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
      <div className="mb-3 flex items-end justify-between gap-2">
        <div>
          <p className="mb-1 font-[family-name:var(--font-display)] text-[0.68rem] tracking-[0.14em] text-[var(--color-up)] uppercase">
            Equity
          </p>
          <h2 className="font-[family-name:var(--font-display)] text-lg font-bold">評価額カーブ</h2>
        </div>
        <p className={`font-[family-name:var(--font-display)] text-sm tabular-nums ${up ? 'text-[var(--color-up)]' : 'text-[var(--color-down)]'}`}>
          {points.length} pts
        </p>
      </div>
      <svg viewBox={`0 0 ${w} ${h}`} className="h-44 w-full" role="img" aria-label="Equity curve">
        <defs>
          <linearGradient id="eqFill" x1="0" y1="0" x2="0" y2="1">
            <stop offset="0%" stopColor={up ? '#3dd68c' : '#ff6b6b'} stopOpacity="0.35" />
            <stop offset="100%" stopColor={up ? '#3dd68c' : '#ff6b6b'} stopOpacity="0" />
          </linearGradient>
        </defs>
        <line
          x1={pad}
          x2={w - pad}
          y1={baselineY}
          y2={baselineY}
          stroke="rgba(255,255,255,0.12)"
          strokeDasharray="4 4"
        />
        {area ? <path d={area} fill="url(#eqFill)" /> : null}
        <path
          d={line}
          fill="none"
          stroke={up ? '#3dd68c' : '#ff6b6b'}
          strokeWidth="2.5"
          strokeLinejoin="round"
          strokeLinecap="round"
        />
        {coords.length ? (
          <circle
            cx={coords[coords.length - 1].x}
            cy={coords[coords.length - 1].y}
            r="4"
            fill={up ? '#3dd68c' : '#ff6b6b'}
          >
            <animate attributeName="r" values="3;5;3" dur="2s" repeatCount="indefinite" />
          </circle>
        ) : null}
      </svg>
    </div>
  );
}
