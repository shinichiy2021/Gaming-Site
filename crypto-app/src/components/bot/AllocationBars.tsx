'use client';

type Holding = {
  symbol: string;
  color: string;
  weight: number;
  valueUsd: number;
};

type Props = {
  holdings: Holding[];
  cashUsd: number;
  equityUsd: number;
};

export default function AllocationBars({ holdings, cashUsd, equityUsd }: Props) {
  const cashW = equityUsd > 0 ? (cashUsd / equityUsd) * 100 : 0;
  const rows = [
    ...holdings.map((h) => ({
      key: h.symbol,
      label: h.symbol,
      weight: h.weight,
      color: h.color,
      value: h.valueUsd,
    })),
    {
      key: 'CASH',
      label: 'CASH',
      weight: Math.round(cashW * 10) / 10,
      color: '#94a3b8',
      value: cashUsd,
    },
  ].sort((a, b) => b.weight - a.weight);

  return (
    <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
      <p className="mb-1 font-[family-name:var(--font-display)] text-[0.68rem] tracking-[0.14em] text-[var(--color-up)] uppercase">
        Allocation
      </p>
      <h2 className="mb-4 font-[family-name:var(--font-display)] text-lg font-bold">配分</h2>
      <ul className="flex flex-col gap-3">
        {rows.map((row) => (
          <li key={row.key}>
            <div className="mb-1 flex justify-between text-sm">
              <span className="flex items-center gap-2">
                <span className="h-2.5 w-2.5 rounded-sm" style={{ background: row.color }} />
                {row.label}
              </span>
              <span className="tabular-nums text-[var(--color-muted)]">{row.weight}%</span>
            </div>
            <div className="h-2 overflow-hidden rounded-full bg-white/10">
              <div
                className="h-full rounded-full transition-all duration-700"
                style={{ width: `${Math.min(100, row.weight)}%`, background: row.color }}
              />
            </div>
          </li>
        ))}
      </ul>
    </div>
  );
}
