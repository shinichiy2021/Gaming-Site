'use client';

export default function WatchAddressBar({
  title,
  hint,
  placeholder,
  accentClassName,
  address,
  draft,
  setDraft,
  error,
  onSave,
  onClear,
}: {
  title: string;
  hint: string;
  placeholder: string;
  accentClassName: string;
  address: string;
  draft: string;
  setDraft: (v: string) => void;
  error: string | null;
  onSave: (v: string) => void;
  onClear: () => void;
}) {
  const registered = Boolean(address);

  return (
    <div className="w-full rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-4 backdrop-blur-md">
      <div className="mb-2 flex flex-wrap items-center justify-between gap-2">
        <h2 className="font-[family-name:var(--font-display)] text-sm tracking-[0.1em] text-[var(--color-muted)] uppercase">
          {title}
        </h2>
        {registered ? (
          <span className="rounded-full border border-[var(--color-line)] bg-[rgba(61,214,140,0.12)] px-2.5 py-0.5 font-[family-name:var(--font-display)] text-[0.65rem] tracking-wider text-[var(--color-up)] uppercase">
            登録済み
          </span>
        ) : null}
      </div>
      <p className="mb-3 text-xs text-[var(--color-muted)]">{hint}</p>

      {registered ? (
        <div className="flex flex-col gap-2 sm:flex-row sm:items-center">
          <code className="min-w-0 flex-1 break-all rounded-xl border border-white/10 bg-black/40 px-3 py-2 font-mono text-sm text-[var(--color-ink)]">
            {address}
          </code>
          <button
            type="button"
            onClick={onClear}
            className="rounded-full border border-white/15 px-3.5 py-2 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-muted)] transition hover:text-[var(--color-ink)]"
          >
            削除
          </button>
        </div>
      ) : (
        <div className="flex flex-col gap-2 sm:flex-row">
          <input
            type="text"
            value={draft}
            onChange={(e) => setDraft(e.target.value)}
            placeholder={placeholder}
            spellCheck={false}
            autoComplete="off"
            className="min-w-0 flex-1 rounded-xl border border-white/10 bg-black/40 px-3 py-2 font-mono text-sm text-[var(--color-ink)] outline-none placeholder:text-[var(--color-muted)]/60 focus:border-[var(--color-line)]"
          />
          <button
            type="button"
            onClick={() => onSave(draft)}
            className={`rounded-full border border-[var(--color-line)] px-3.5 py-2 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-ink)] transition ${accentClassName}`}
          >
            登録
          </button>
        </div>
      )}

      {error ? <p className="mt-2 text-xs text-[var(--color-down)]">{error}</p> : null}
    </div>
  );
}
