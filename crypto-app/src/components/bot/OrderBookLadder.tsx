'use client';

export type BookLevel = { price: number; quantity: number };

export type BookPayload = {
  market: string;
  mid: number | null;
  bestBid: number | null;
  bestAsk: number | null;
  spreadBps: number | null;
  bids: BookLevel[];
  asks: BookLevel[];
  updatedAt: string;
};

type Props = {
  book: BookPayload | null;
  loading?: boolean;
  /** Paper maker quotes overlaid on the ladder */
  quoteBid?: number | null;
  quoteAsk?: number | null;
  error?: string | null;
};

export default function OrderBookLadder({
  book,
  loading,
  quoteBid,
  quoteAsk,
  error,
}: Props) {
  if (error) {
    return (
      <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5">
        <h2 className="mb-2 font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
          Phoenix SOL/USDC L2
        </h2>
        <p className="text-sm text-[var(--color-down)]">{error}</p>
        <p className="mt-2 text-xs text-[var(--color-muted)]">
          RPC制限のときは SOLANA_RPC_URL を設定してください。
        </p>
      </div>
    );
  }

  if (!book || loading) {
    return (
      <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5">
        <div className="mb-4 h-4 w-40 animate-pulse rounded bg-white/10" />
        <div className="space-y-2">
          {Array.from({ length: 8 }).map((_, i) => (
            <div key={i} className="h-5 animate-pulse rounded bg-white/5" />
          ))}
        </div>
      </div>
    );
  }

  const maxQty = Math.max(
    0.0001,
    ...book.bids.map((l) => l.quantity),
    ...book.asks.map((l) => l.quantity),
  );

  const asks = [...book.asks].slice(0, 8).reverse();
  const bids = book.bids.slice(0, 8);

  return (
    <div className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-5 backdrop-blur-md">
      <div className="mb-3 flex flex-wrap items-end justify-between gap-2">
        <div>
          <p className="mb-1 font-[family-name:var(--font-display)] text-[0.68rem] tracking-[0.14em] text-[var(--color-up)] uppercase">
            Phoenix · Live L2
          </p>
          <h2 className="font-[family-name:var(--font-display)] text-lg font-bold">SOL / USDC</h2>
        </div>
        <div className="text-right">
          <p className="font-[family-name:var(--font-display)] text-2xl font-black tabular-nums">
            {book.mid != null ? book.mid.toFixed(3) : '—'}
          </p>
          <p className="text-xs text-[var(--color-muted)]">
            spread {book.spreadBps != null ? `${book.spreadBps.toFixed(1)} bps` : '—'}
          </p>
        </div>
      </div>

      <div className="mb-3 grid grid-cols-[1fr_auto_1fr] gap-2 text-[0.65rem] tracking-wider text-[var(--color-muted)] uppercase">
        <span className="text-right">Size</span>
        <span className="text-center">Price</span>
        <span>Size</span>
      </div>

      <div className="space-y-1">
        {asks.map((level) => {
          const w = (level.quantity / maxQty) * 100;
          const isQuote =
            quoteAsk != null && Math.abs(level.price - quoteAsk) / quoteAsk < 0.0008;
          return (
            <div
              key={`a-${level.price}`}
              className={`relative grid grid-cols-[1fr_auto_1fr] items-center gap-2 rounded px-1 py-0.5 text-sm ${
                isQuote ? 'ring-1 ring-[var(--color-down)]/50' : ''
              }`}
            >
              <div className="relative h-5 overflow-hidden rounded">
                <div
                  className="absolute inset-y-0 right-0 bg-[rgba(255,107,107,0.22)] transition-all duration-500"
                  style={{ width: `${w}%` }}
                />
              </div>
              <span className="min-w-[4.5rem] text-center font-[family-name:var(--font-display)] tabular-nums text-[var(--color-down)]">
                {level.price.toFixed(3)}
              </span>
              <span className="tabular-nums text-[var(--color-muted)]">{level.quantity.toFixed(2)}</span>
            </div>
          );
        })}

        <div className="my-2 grid grid-cols-[1fr_auto_1fr] items-center gap-2 border-y border-white/10 py-2">
          <div className="text-right text-xs text-[var(--color-muted)]">
            {quoteBid != null ? `paper bid ${quoteBid.toFixed(3)}` : ''}
          </div>
          <div className="rounded-full bg-[rgba(61,214,140,0.15)] px-3 py-1 text-center font-[family-name:var(--font-display)] text-sm font-bold tabular-nums">
            {book.mid?.toFixed(3)}
          </div>
          <div className="text-xs text-[var(--color-muted)]">
            {quoteAsk != null ? `paper ask ${quoteAsk.toFixed(3)}` : ''}
          </div>
        </div>

        {bids.map((level) => {
          const w = (level.quantity / maxQty) * 100;
          const isQuote =
            quoteBid != null && Math.abs(level.price - quoteBid) / quoteBid < 0.0008;
          return (
            <div
              key={`b-${level.price}`}
              className={`relative grid grid-cols-[1fr_auto_1fr] items-center gap-2 rounded px-1 py-0.5 text-sm ${
                isQuote ? 'ring-1 ring-[var(--color-up)]/50' : ''
              }`}
            >
              <span className="text-right tabular-nums text-[var(--color-muted)]">{level.quantity.toFixed(2)}</span>
              <span className="min-w-[4.5rem] text-center font-[family-name:var(--font-display)] tabular-nums text-[var(--color-up)]">
                {level.price.toFixed(3)}
              </span>
              <div className="relative h-5 overflow-hidden rounded">
                <div
                  className="absolute inset-y-0 left-0 bg-[rgba(61,214,140,0.22)] transition-all duration-500"
                  style={{ width: `${w}%` }}
                />
              </div>
            </div>
          );
        })}
      </div>
    </div>
  );
}
