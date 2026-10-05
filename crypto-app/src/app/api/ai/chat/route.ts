import { NextResponse, type NextRequest } from 'next/server';

export const runtime = 'nodejs';
export const dynamic = 'force-dynamic';

type ChatTurn = { role: 'user' | 'model'; text: string };

const MAX_TURNS = 20;
const MAX_TEXT = 4000;
const MAX_CONTEXT = 12000;
const FALLBACK_MODELS = ['gemini-3.8-flash', 'gemini-3.5-flash', 'gemini-3.1-flash-lite'];

const SYSTEM_PROMPT = [
  'あなたは個人の暗号資産ポートフォリオについて相談に乗るアシスタントです。',
  '下の「ポートフォリオ情報」と「今後のアイデア」は、ダッシュボードがルールに基づいて自動生成したものです。',
  'ユーザーの質問に、これらの数字を根拠として日本語で具体的に答えてください。',
  'アイデアの理由・リスク・実行するなら分け方やタイミングなどを、保有比率や直近7日の値動きと結びつけて説明してください。',
  '情報にない価格やニュースを事実のように断定しないでください。知らないことは知らないと答えてください。',
  '投資判断はユーザー自身が行うものであり、助言は参考情報であることを、必要な場面で簡潔に添えてください。',
  '回答は簡潔に。見出しは使わず、必要なら短い箇条書きにしてください。',
].join('\n');

function sanitizeTurns(raw: unknown): ChatTurn[] | null {
  if (!Array.isArray(raw) || raw.length === 0) return null;
  const turns: ChatTurn[] = [];
  for (const item of raw.slice(-MAX_TURNS)) {
    const role = (item as ChatTurn)?.role;
    const text = (item as ChatTurn)?.text;
    if ((role !== 'user' && role !== 'model') || typeof text !== 'string' || !text.trim()) return null;
    turns.push({ role, text: text.slice(0, MAX_TEXT) });
  }
  return turns[turns.length - 1].role === 'user' ? turns : null;
}

export async function POST(req: NextRequest) {
  const apiKey = process.env.GEMINI_API_KEY;
  if (!apiKey) {
    return NextResponse.json({ error: 'GEMINI_API_KEY が設定されていません' }, { status: 503 });
  }

  let body: { messages?: unknown; context?: unknown };
  try {
    body = await req.json();
  } catch {
    return NextResponse.json({ error: 'リクエストが不正です' }, { status: 400 });
  }

  const turns = sanitizeTurns(body.messages);
  if (!turns) {
    return NextResponse.json({ error: 'メッセージが不正です' }, { status: 400 });
  }
  const context = typeof body.context === 'string' ? body.context.slice(0, MAX_CONTEXT) : '';

  const primary = process.env.GEMINI_MODEL || 'gemini-3.8-flash';
  const models = [primary, ...FALLBACK_MODELS.filter((m) => m !== primary)];
  const payload = JSON.stringify({
    system_instruction: { parts: [{ text: `${SYSTEM_PROMPT}\n\n${context}` }] },
    contents: turns.map((t) => ({ role: t.role, parts: [{ text: t.text }] })),
    generationConfig: { temperature: 0.4, maxOutputTokens: 1500 },
  });

  let lastError = 'Gemini に接続できませんでした';
  for (const model of models) {
    const url = `https://generativelanguage.googleapis.com/v1beta/models/${encodeURIComponent(model)}:generateContent`;
    try {
      const upstream = await fetch(url, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'x-goog-api-key': apiKey },
        body: payload,
        cache: 'no-store',
      });

      const data = (await upstream.json().catch(() => null)) as {
        candidates?: { content?: { parts?: { text?: string }[] } }[];
        error?: { message?: string };
      } | null;

      if (!upstream.ok) {
        lastError = data?.error?.message || `Gemini error (${upstream.status})`;
        // Only overload / quota problems are worth retrying on another model.
        if (upstream.status === 429 || upstream.status === 503 || /high demand|overloaded/i.test(lastError)) continue;
        return NextResponse.json({ error: lastError }, { status: 502 });
      }

      const text = (data?.candidates?.[0]?.content?.parts ?? [])
        .map((p) => p.text ?? '')
        .join('')
        .trim();
      if (!text) {
        return NextResponse.json({ error: '回答を生成できませんでした' }, { status: 502 });
      }
      return NextResponse.json({ text, model });
    } catch {
      lastError = 'Gemini に接続できませんでした';
    }
  }
  return NextResponse.json({ error: lastError }, { status: 502 });
}
