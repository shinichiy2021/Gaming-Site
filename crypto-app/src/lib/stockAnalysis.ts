import type { StockHolding, StockPortfolio } from '@/lib/stockPortfolio';

export type StockIdeaItem = {
  id: string;
  priority: 'now' | 'watch' | 'setup';
  title: string;
  detail: string;
  trigger: string;
};

export type StockBucket = {
  key: 'domestic' | 'foreign' | 'cash';
  label: string;
  weight: number;
  value: number;
};

export type StockPortfolioInsight = {
  hasHoldings: boolean;
  assetCount: number;
  top: StockHolding | null;
  top3Weight: number;
  concentration: 'dispersed' | 'moderate' | 'concentrated';
  buckets: StockBucket[];
  bestDay: StockHolding | null;
  worstDay: StockHolding | null;
  analysis: string[];
  ideas: StockIdeaItem[];
  generatedAt: string;
  marketTone: 'risk-on' | 'mixed' | 'risk-off' | 'flat';
};

function fmtYen(n: number) {
  return new Intl.NumberFormat('ja-JP', {
    style: 'currency',
    currency: 'JPY',
    maximumFractionDigits: Math.abs(n) >= 100 ? 0 : 2,
  }).format(n);
}

function fmtUsd(n: number) {
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: 2,
  }).format(n);
}

function fmtPct(n: number) {
  const sign = n > 0 ? '+' : '';
  return `${sign}${n.toFixed(2)}%`;
}

function fmtSignedYen(n: number) {
  return `${n > 0 ? '+' : ''}${fmtYen(n)}`;
}

function roundYen(n: number) {
  if (n >= 100_000) return Math.round(n / 10_000) * 10_000;
  if (n >= 10_000) return Math.round(n / 1000) * 1000;
  if (n >= 1000) return Math.round(n / 100) * 100;
  return Math.round(n);
}

function labelOf(h: StockHolding) {
  return h.code ? `${h.code} ${h.name}` : h.name;
}

function dayImpactYen(h: StockHolding): number {
  if (h.market !== 'domestic' || h.isCash) return 0;
  // Approximate: dayChange is price change; impact ≈ qty * dayChange
  if (h.dayChange !== 0 && h.quantity > 0) {
    return h.quantity * h.dayChange;
  }
  if (h.dayChangePct === 0) return 0;
  const denom = 1 + h.dayChangePct / 100;
  if (denom === 0) return 0;
  return h.value - h.value / denom;
}

function concentrationOf(top3: number): StockPortfolioInsight['concentration'] {
  if (top3 >= 45) return 'concentrated';
  if (top3 >= 30) return 'moderate';
  return 'dispersed';
}

function marketToneFrom(p: StockPortfolio, equity: StockHolding[]): StockPortfolioInsight['marketTone'] {
  const pct = p.dayChangePct;
  const withDay = equity.filter((h) => h.market === 'domestic');
  const up = withDay.filter((h) => h.dayChangePct >= 1).length;
  const down = withDay.filter((h) => h.dayChangePct <= -1).length;
  if (pct >= 1 || (up >= 4 && down <= 1)) return 'risk-on';
  if (pct <= -1 || (down >= 4 && up <= 1)) return 'risk-off';
  if (Math.abs(pct) < 0.3 && up <= 1 && down <= 1) return 'flat';
  return 'mixed';
}

export function analyzeStockPortfolio(portfolio: StockPortfolio): StockPortfolioInsight {
  const equity = portfolio.holdings.filter((h) => !h.isCash && h.value > 0);
  const generatedAt = portfolio.updatedAt || new Date().toISOString();

  if (!equity.length) {
    return {
      hasHoldings: false,
      assetCount: 0,
      top: null,
      top3Weight: 0,
      concentration: 'dispersed',
      buckets: [],
      bestDay: null,
      worstDay: null,
      analysis: ['表示できる株式がありません。SBIの国内CSVと海外データを取り込んでください。'],
      ideas: [
        {
          id: 'upload-csv',
          priority: 'setup',
          title: '国内ポートフォリオCSVを取り込む',
          detail: 'SBIの「ポートフォリオ一覧」CSVをアップロードすると、前日比つきで具体案を出せます。',
          trigger: 'データなし',
        },
      ],
      generatedAt,
      marketTone: 'flat',
    };
  }

  const sorted = [...equity].sort((a, b) => b.weight - a.weight);
  const top = sorted[0] ?? null;
  const top3Weight = Math.round(sorted.slice(0, 3).reduce((s, h) => s + h.weight, 0) * 10) / 10;
  const concentration = concentrationOf(top3Weight);

  const buckets: StockBucket[] = portfolio.markets.map((m) => ({
    key: m.market,
    label: m.label,
    weight: m.weight,
    value: m.value,
  }));

  const domestic = equity.filter((h) => h.market === 'domestic');
  const foreign = equity.filter((h) => h.market === 'foreign');
  const byDay = [...domestic].sort((a, b) => b.dayChangePct - a.dayChangePct);
  const bestDay = byDay[0] ?? null;
  const worstDay = byDay.length ? byDay[byDay.length - 1] : null;
  const byImpact = [...domestic]
    .map((h) => ({ h, impact: dayImpactYen(h) }))
    .sort((a, b) => a.impact - b.impact);
  const drag = byImpact[0];
  const lift = byImpact[byImpact.length - 1];

  const byPnlPct = [...equity].sort((a, b) => b.pnlPct - a.pnlPct);
  const bestPnl = byPnlPct[0];
  const worstPnl = byPnlPct[byPnlPct.length - 1];

  const domesticW = portfolio.markets.find((m) => m.market === 'domestic')?.weight ?? 0;
  const foreignW = portfolio.markets.find((m) => m.market === 'foreign')?.weight ?? 0;
  const cashW = portfolio.markets.find((m) => m.market === 'cash')?.weight ?? 0;
  const cashValue = portfolio.markets.find((m) => m.market === 'cash')?.value ?? 0;
  const cashUsd = portfolio.markets.find((m) => m.market === 'cash')?.valueUsd ?? 0;

  const tone = marketToneFrom(portfolio, equity);
  const analysis: string[] = [];

  if (top) {
    analysis.push(
      `最大保有は ${labelOf(top)}（${top.weight}%・${fmtYen(top.value)}）。` +
        `上位3銘柄で ${top3Weight}%、集中度は${
          concentration === 'concentrated' ? '高め' : concentration === 'moderate' ? '中程度' : '分散寄り'
        }。`,
    );
  }

  analysis.push(
    `市場別は 国内 ${domesticW}% / 海外 ${foreignW}%` +
      (cashW > 0 ? ` / USD預り ${cashW}%` : '') +
      `。含み損益 ${fmtSignedYen(portfolio.totalPnl)}（${fmtPct(portfolio.totalPnlPct)}）。`,
  );

  if (domestic.length) {
    analysis.push(
      `国内前日比 ${fmtSignedYen(portfolio.dayChange)}（${fmtPct(portfolio.dayChangePct)}）、トーンは ${tone}` +
        (bestDay && worstDay
          ? `。強い ${labelOf(bestDay)} ${fmtPct(bestDay.dayChangePct)} / 弱い ${labelOf(worstDay)} ${fmtPct(worstDay.dayChangePct)}。`
          : '。'),
    );
  }

  if (foreign.length) {
    analysis.push(
      `海外株式 ${foreign.length}銘柄・${fmtYen(foreign.reduce((s, h) => s + h.value, 0))}` +
        (portfolio.totalValueUsd
          ? `（約 ${fmtUsd(portfolio.totalValueUsd - cashUsd)} + 現金 ${fmtUsd(cashUsd)}）`
          : '') +
        `。参考レート ${portfolio.usdJpy ?? '—'} 円/USD。`,
    );
  }

  const ideas = buildIdeas({
    portfolio,
    equity,
    top,
    bestDay,
    worstDay,
    drag,
    lift,
    bestPnl,
    worstPnl,
    domesticW,
    foreignW,
    cashValue,
    cashUsd,
    tone,
  });

  return {
    hasHoldings: true,
    assetCount: equity.length,
    top,
    top3Weight,
    concentration,
    buckets,
    bestDay,
    worstDay,
    analysis: analysis.slice(0, 4),
    ideas,
    generatedAt,
    marketTone: tone,
  };
}

function buildIdeas(args: {
  portfolio: StockPortfolio;
  equity: StockHolding[];
  top: StockHolding | null;
  bestDay: StockHolding | null;
  worstDay: StockHolding | null;
  drag?: { h: StockHolding; impact: number };
  lift?: { h: StockHolding; impact: number };
  bestPnl?: StockHolding;
  worstPnl?: StockHolding;
  domesticW: number;
  foreignW: number;
  cashValue: number;
  cashUsd: number;
  tone: StockPortfolioInsight['marketTone'];
}): StockIdeaItem[] {
  const {
    portfolio,
    equity,
    top,
    bestDay,
    worstDay,
    drag,
    lift,
    bestPnl,
    worstPnl,
    domesticW,
    foreignW,
    cashValue,
    cashUsd,
    tone,
  } = args;
  const ideas: StockIdeaItem[] = [];
  const total = portfolio.totalValue;

  // Day-move ideas (domestic)
  if (worstDay && worstDay.dayChangePct <= -2) {
    const buy = roundYen(Math.min(total * 0.02, Math.max(30_000, Math.abs(dayImpactYen(worstDay)) * 1.2)));
    ideas.push({
      id: `dip-${worstDay.id}-${worstDay.dayChangePct.toFixed(2)}`,
      priority: cashValue + 50_000 < buy && portfolio.totalPnl < 0 ? 'watch' : 'now',
      title: `${labelOf(worstDay)} の押し目を検討`,
      detail:
        `現在値 ${fmtYen(worstDay.currentPrice)}（前日比 ${fmtPct(worstDay.dayChangePct)}、概算影響 ${fmtSignedYen(dayImpactYen(worstDay))}）。` +
        `追加の目安は約 ${fmtYen(buy)}（全体の ${((buy / total) * 100).toFixed(1)}%）。` +
        `特定口座なら税計算が単純、NISA枠が残っていれば成長投資枠での購入を優先。`,
      trigger: `前日比 ${fmtPct(worstDay.dayChangePct)}`,
    });
  }

  if (bestDay && bestDay.dayChangePct >= 2 && bestDay.weight >= 5) {
    const trim = roundYen(Math.min(bestDay.value * 0.12, total * 0.03));
    ideas.push({
      id: `trim-day-${bestDay.id}-${bestDay.dayChangePct.toFixed(2)}`,
      priority: 'now',
      title: `${labelOf(bestDay)} の一部利確`,
      detail:
        `${labelOf(bestDay)} は前日比 ${fmtPct(bestDay.dayChangePct)}（比率 ${bestDay.weight}%・評価 ${fmtYen(bestDay.value)}）。` +
        `約 ${fmtYen(trim)} 分を利確し、現金 or つみたて枠（オルカン等）へ退避する案。` +
        `利確後の比率目安 ${(bestDay.weight * 0.88).toFixed(1)}%。`,
      trigger: `前日比 ${fmtPct(bestDay.dayChangePct)} · 比率 ${bestDay.weight}%`,
    });
  }

  if (drag && lift && drag.h.id !== lift.h.id && Math.abs(drag.impact) >= 5000) {
    ideas.push({
      id: `impact-${drag.h.id}-${lift.h.id}`,
      priority: tone === 'risk-off' ? 'now' : 'watch',
      title: `前日寄与の入れ替え意識（${drag.h.code || drag.h.name}）`,
      detail:
        `押し下げ最大は ${labelOf(drag.h)}（概算 ${fmtSignedYen(drag.impact)}）。` +
        `押し上げ最大は ${labelOf(lift.h)}（概算 ${fmtSignedYen(lift.impact)}）。` +
        `全体前日比 ${fmtSignedYen(portfolio.dayChange)} なので、弱い銘柄のナンピンは控え、強い側の一部を防衛資金へ。`,
      trigger: `寄与差 ${fmtYen(Math.abs(lift.impact - drag.impact))}`,
    });
  }

  // Unrealized PnL ideas
  if (bestPnl && bestPnl.pnlPct >= 25 && bestPnl.weight >= 4) {
    const trim = roundYen(Math.min(bestPnl.value * 0.2, bestPnl.pnlJpy * 0.35));
    ideas.push({
      id: `pnl-trim-${bestPnl.id}`,
      priority: 'watch',
      title: `${labelOf(bestPnl)} の含み益を一部確定`,
      detail:
        `含み益 ${fmtSignedYen(bestPnl.pnlJpy)}（${fmtPct(bestPnl.pnlPct)}）、評価 ${fmtYen(bestPnl.value)}。` +
        `利益の一部として約 ${fmtYen(trim)} を利確し、取得単価を下げつつ現金比率を上げる。` +
        (bestPnl.account.startsWith('foreign') || bestPnl.account.includes('nisa')
          ? 'NISA口座なら非課税のままリバランス可能な点も考慮。'
          : '特定口座なら税引き後の手取りも試算する。'),
      trigger: `含み益 ${fmtPct(bestPnl.pnlPct)}`,
    });
  }

  if (worstPnl && worstPnl.pnlPct <= -20) {
    ideas.push({
      id: `pnl-cut-${worstPnl.id}`,
      priority: tone === 'risk-off' ? 'now' : 'watch',
      title: `${labelOf(worstPnl)} の損切り/保有ルールを決める`,
      detail:
        `含み損 ${fmtSignedYen(worstPnl.pnlJpy)}（${fmtPct(worstPnl.pnlPct)}）、評価 ${fmtYen(worstPnl.value)}。` +
        `「あと ${fmtPct(-5)} で半分売却」などルールを先に書き、感情での追撃買いを避ける。` +
        `追加購入するなら枠は評価額の 10%（約 ${fmtYen(roundYen(worstPnl.value * 0.1))}）までに制限。`,
      trigger: `含み損 ${fmtPct(worstPnl.pnlPct)}`,
    });
  }

  // Concentration
  if (top && top.weight >= 12) {
    const excess = roundYen(top.value * ((top.weight - 10) / top.weight));
    ideas.push({
      id: `conc-${top.id}`,
      priority: top.weight >= 15 ? 'now' : 'watch',
      title: `${labelOf(top)} 集中を 10% 前後へ`,
      detail:
        `現在 ${top.weight}%（${fmtYen(top.value)}）。10% 目安まで落とすなら約 ${fmtYen(excess)} 分。` +
        `受け皿はオルカン（つみたて）や現金、比率の低い海外コア（例: 分散ETF）へ。`,
      trigger: `単一銘柄 ${top.weight}%`,
    });
  }

  // Market balance domestic / foreign
  if (foreignW >= 45) {
    ideas.push({
      id: 'fx-heavy',
      priority: 'watch',
      title: '為替リスクを意識した防衛',
      detail:
        `海外比率 ${foreignW}%（円換算）。USD/JPY 参考 ${portfolio.usdJpy ?? '—'}。` +
        `円高に振れた局面では海外の追加を止め、国内 or USD現金の温存を優先。` +
        (cashUsd > 0
          ? `いまの米ドル預り ${fmtUsd(cashUsd)}（${fmtYen(cashValue)}）は、押し目まで待機でOK。`
          : '米ドル預りが薄いので、海外買いの前に現金USDを少し残す。'),
      trigger: `海外 ${foreignW}%`,
    });
  } else if (domesticW >= 75 && foreignW < 20) {
    const add = roundYen(total * 0.03);
    ideas.push({
      id: 'add-foreign',
      priority: 'watch',
      title: '海外コアを少し厚くする',
      detail:
        `国内 ${domesticW}% と偏り気味。全体の約 3%（${fmtYen(add)}）を ` +
        `海外の低コストETFや既存の強いADR（含み益側）へ、分割で振る案。`,
      trigger: `国内 ${domesticW}%`,
    });
  }

  if (cashUsd >= 100 && tone !== 'risk-off') {
    const deploy = Math.min(cashUsd * 0.3, 80);
    ideas.push({
      id: 'deploy-usd-cash',
      priority: 'watch',
      title: '米ドル預りの一部を投資へ',
      detail:
        `米ドル預り ${fmtUsd(cashUsd)}（${fmtYen(cashValue)}）。` +
        `うち約 ${fmtUsd(deploy)} を、前日で弱い海外銘柄 or コアETFへ2回に分けて投入する案。` +
        `残りは為替のブレに備えてキープ。`,
      trigger: `USD現金 ${fmtUsd(cashUsd)}`,
    });
  }

  // Tone playbook
  if (tone === 'risk-off') {
    ideas.push({
      id: 'tone-off',
      priority: 'now',
      title: '国内が弱い日の守り',
      detail:
        `国内前日比 ${fmtSignedYen(portfolio.dayChange)}（${fmtPct(portfolio.dayChangePct)}）。` +
        `新規の個別株買いは見送り、つみたて（オルカン等）の定期だけ継続。` +
        `下落寄与の大きい銘柄へのナンピンは、あらかじめ決めた枠以外は禁止。`,
      trigger: 'トーン risk-off',
    });
  } else if (tone === 'risk-on') {
    ideas.push({
      id: 'tone-on',
      priority: 'watch',
      title: '国内が強い日の追撃上限',
      detail:
        `国内前日比 ${fmtPct(portfolio.dayChangePct)}。勢い銘柄への追加は ` +
        `1銘柄あたり評価額の 5%（目安 ${fmtYen(roundYen(total * 0.01))}〜）までに制限し、FOMO買いを防ぐ。`,
      trigger: 'トーン risk-on',
    });
  }

  // NISA / account mix
  const nisaValue = equity
    .filter((h) => h.account.includes('nisa') || h.account === 'nisa_growth' || h.account === 'nisa_tsumitate' || h.account === 'foreign_nisa' || h.account === 'foreign_old_nisa')
    .reduce((s, h) => s + h.value, 0);
  const nisaW = total > 0 ? Math.round((nisaValue / total) * 1000) / 10 : 0;
  if (nisaW < 40) {
    ideas.push({
      id: 'prefer-nisa',
      priority: 'setup',
      title: '新規買いは NISA 枠を優先',
      detail:
        `NISA系の比率は約 ${nisaW}%。新規の個別・投信は成長/つみたて枠を先に使い、` +
        `特定口座は「枠が埋まった後」か「短期トレード用」に限定する運用ルールがわかりやすい。`,
      trigger: `NISA比率 ${nisaW}%`,
    });
  }

  ideas.push({
    id: 'refresh-csv',
    priority: 'setup',
    title: 'SBI CSV を更新して値動き案を刷新',
    detail:
      `いまのアイデアは取り込み済みデータの前日比・含み損益から算出。` +
      `最新の「ポートフォリオ一覧」CSVを再取り込み（または再取得）すると、金額・％が更新されます。` +
      `銘柄数 ${equity.length}・合計 ${fmtYen(total)}。`,
    trigger: `更新 ${new Date(portfolio.updatedAt).toLocaleString('ja-JP', { month: 'short', day: 'numeric', hour: '2-digit', minute: '2-digit' })}`,
  });

  const rank = { now: 0, watch: 1, setup: 2 } as const;
  const seen = new Set<string>();
  return ideas
    .filter((idea) => {
      if (seen.has(idea.id)) return false;
      seen.add(idea.id);
      return true;
    })
    .sort((a, b) => rank[a.priority] - rank[b.priority])
    .slice(0, 6);
}
