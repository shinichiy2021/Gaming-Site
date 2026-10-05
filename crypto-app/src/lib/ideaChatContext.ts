import type { PortfolioInsight } from '@/lib/cryptoAnalysis';
import type { Portfolio } from '@/lib/portfolio';

const priorityLabel = { now: '今すぐ', watch: '様子見', setup: 'セットアップ' } as const;

function pct(n: number | null) {
  if (n === null) return '—';
  return `${n > 0 ? '+' : ''}${n.toFixed(2)}%`;
}

function usd(n: number) {
  return `$${n.toLocaleString('en-US', { maximumFractionDigits: n >= 100 ? 0 : 2 })}`;
}

/** Plain-text snapshot of the crypto dashboard for the Gemini system prompt. */
export function buildIdeaChatContext(portfolio: Portfolio, insight: PortfolioInsight): string {
  const lines: string[] = [];
  lines.push('## ポートフォリオ情報');
  lines.push(`更新: ${portfolio.updated_at}`);
  lines.push(`合計: ${usd(portfolio.total_usd)}（約 ${portfolio.total_jpy.toLocaleString('ja-JP')} 円、USD/JPY ${portfolio.usd_jpy.toFixed(2)}）`);
  lines.push(`24h: ${pct(portfolio.change_24h_pct)}（${usd(portfolio.change_24h_usd)}）`);
  lines.push(
    portfolio.has_7d
      ? `直近7日: ${pct(portfolio.change_7d_pct)}（${usd(portfolio.change_7d_usd)}）`
      : '直近7日: 取得できず（アイデアは24h基準）',
  );
  lines.push('');
  lines.push('保有（銘柄 / 数量 / 価格 / 評価額 / 比率 / 24h / 7日）:');
  for (const h of portfolio.holdings.filter((x) => x.value_usd > 0)) {
    lines.push(
      `- ${h.symbol}: ${h.amount} / ${usd(h.price_usd)} / ${usd(h.value_usd)} / ${h.weight}% / ${pct(h.change_24h)} / ${pct(h.change_7d)}`,
    );
  }

  lines.push('');
  lines.push('## 現状の分析');
  for (const a of insight.analysis) lines.push(`- ${a}`);

  lines.push('');
  lines.push('## 今後のアイデア（ダッシュボードの自動生成）');
  insight.ideas.forEach((idea, i) => {
    lines.push(`${i + 1}. [${priorityLabel[idea.priority]}] ${idea.title}`);
    lines.push(`   ${idea.detail}`);
    lines.push(`   根拠: ${idea.trigger}`);
  });

  return lines.join('\n');
}
