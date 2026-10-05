'use client';

import { useEffect, useRef, useState } from 'react';
import { withBase } from '@/lib/basePath';

type Turn = { role: 'user' | 'model'; text: string };

const SUGGESTIONS = [
  'いちばん優先すべきアイデアはどれ？理由も教えて',
  '各アイデアのリスクを教えて',
  '今週実行するなら、どの順番・何回に分けるのがいい？',
  '何もしない場合に気をつけることは？',
];

type Props = {
  context: string;
  disabled?: boolean;
};

export default function IdeaChat({ context, disabled }: Props) {
  const [turns, setTurns] = useState<Turn[]>([]);
  const [input, setInput] = useState('');
  const [sending, setSending] = useState(false);
  const [error, setError] = useState<string | null>(null);
  const listRef = useRef<HTMLDivElement>(null);

  useEffect(() => {
    listRef.current?.scrollTo({ top: listRef.current.scrollHeight, behavior: 'smooth' });
  }, [turns, sending]);

  async function send(text: string) {
    const question = text.trim();
    if (!question || sending || disabled) return;
    const next: Turn[] = [...turns, { role: 'user', text: question }];
    setTurns(next);
    setInput('');
    setSending(true);
    setError(null);
    try {
      const res = await fetch(withBase('/api/ai/chat'), {
        method: 'POST',
        headers: { 'Content-Type': 'application/json' },
        body: JSON.stringify({ messages: next, context }),
      });
      const data = (await res.json().catch(() => ({}))) as { text?: string; error?: string };
      if (!res.ok || !data.text) throw new Error(data.error || `エラー (${res.status})`);
      setTurns([...next, { role: 'model', text: data.text }]);
    } catch (err) {
      setTurns(turns);
      setInput(question);
      setError(err instanceof Error ? err.message : '送信に失敗しました');
    } finally {
      setSending(false);
    }
  }

  return (
    <section
      className="rounded-2xl border border-[var(--color-line)] bg-[var(--color-panel)] p-4 backdrop-blur-md sm:p-5"
      aria-labelledby="idea-chat-title"
    >
      <div className="mb-3 flex flex-wrap items-start justify-between gap-2">
        <div>
          <p className="mb-1 font-[family-name:var(--font-display)] text-[0.68rem] tracking-[0.14em] text-[var(--color-up)] uppercase">
            Ask Gemini
          </p>
          <h2 id="idea-chat-title" className="font-[family-name:var(--font-display)] text-lg font-bold text-[var(--color-ink)]">
            アイデアについて質問
          </h2>
        </div>
        {turns.length ? (
          <button
            type="button"
            onClick={() => {
              setTurns([]);
              setError(null);
            }}
            disabled={sending}
            className="rounded-full border border-white/15 px-3 py-1.5 text-[0.7rem] text-[var(--color-muted)] transition hover:text-[var(--color-ink)] disabled:opacity-50"
          >
            会話をリセット
          </button>
        ) : null}
      </div>

      <p className="mb-4 text-xs text-[var(--color-muted)]">
        いまの保有・分析・アイデア全体を Gemini に渡して回答します（保有額も送信されます）。回答は参考情報です。
      </p>

      {turns.length ? (
        <div ref={listRef} className="mb-4 flex max-h-[60vh] flex-col gap-3 overflow-y-auto overscroll-contain pr-1 sm:max-h-[28rem]">
          {turns.map((t, i) => (
            <div
              key={i}
              className={`max-w-[92%] rounded-xl px-3.5 py-2.5 text-[0.9rem] leading-relaxed break-words whitespace-pre-wrap ${
                t.role === 'user'
                  ? 'self-end bg-[rgba(61,214,140,0.16)] text-[var(--color-ink)]'
                  : 'self-start border border-white/10 bg-black/30 text-[var(--color-ink)]/90'
              }`}
            >
              {t.text}
            </div>
          ))}
          {sending ? (
            <div className="self-start rounded-xl border border-white/10 bg-black/30 px-3.5 py-2.5 text-sm text-[var(--color-muted)]">
              考え中…
            </div>
          ) : null}
        </div>
      ) : (
        <div className="mb-4 flex flex-wrap gap-2">
          {SUGGESTIONS.map((s) => (
            <button
              key={s}
              type="button"
              onClick={() => void send(s)}
              disabled={sending || disabled}
              className="rounded-full border border-[var(--color-line)] bg-black/25 px-3 py-1.5 text-left text-[0.78rem] text-[var(--color-ink)]/90 transition hover:bg-[rgba(61,214,140,0.12)] disabled:opacity-50"
            >
              {s}
            </button>
          ))}
        </div>
      )}

      {error ? <p className="mb-3 text-sm text-[var(--color-down)]">{error}</p> : null}

      <form
        onSubmit={(e) => {
          e.preventDefault();
          void send(input);
        }}
        className="flex flex-col gap-2 sm:flex-row"
      >
        <textarea
          value={input}
          onChange={(e) => setInput(e.target.value)}
          onKeyDown={(e) => {
            if (e.key === 'Enter' && !e.shiftKey && !e.nativeEvent.isComposing) {
              e.preventDefault();
              void send(input);
            }
          }}
          rows={2}
          maxLength={2000}
          placeholder={disabled ? '残高を読み込むと質問できます' : 'アイデアについて質問（Enter で送信）'}
          disabled={sending || disabled}
          enterKeyHint="send"
          className="min-h-[2.75rem] min-w-0 flex-1 resize-y rounded-xl border border-[var(--color-line)] bg-black/35 px-3 py-2 text-base sm:text-sm text-[var(--color-ink)] placeholder:text-[var(--color-muted)] focus:border-[var(--color-up)] focus:outline-none disabled:opacity-50"
        />
        <button
          type="submit"
          disabled={sending || disabled || !input.trim()}
          className="self-stretch rounded-full border border-[var(--color-line)] bg-[rgba(61,214,140,0.18)] px-4 py-2.5 sm:self-end sm:py-2 font-[family-name:var(--font-display)] text-xs tracking-wider text-[var(--color-ink)] transition hover:bg-[rgba(61,214,140,0.28)] disabled:opacity-50"
        >
          {sending ? '送信中…' : '送信'}
        </button>
      </form>
    </section>
  );
}
