import type { Holding, Portfolio } from '@/lib/portfolio';

const STABLES = new Set(['USDT', 'USDC', 'DAI', 'USDT.ARB', 'USDC.ARB', 'USDC.SOL', 'USDC.SUI']);
const MAJORS = new Set(['BTC', 'ETH', 'ETH.ARB', 'WBTC']);

export type PortfolioBucket = {
  key: 'majors' | 'alts' | 'stables';
  label: string;
  weight: number;
  valueUsd: number;
};

export type IdeaItem = {
  id: string;
  priority: 'now' | 'watch' | 'setup';
  title: string;
  detail: string;
  trigger: string;
};

export type PortfolioInsight = {
  hasHoldings: boolean;
  assetCount: number;
  top: Holding | null;
  top3Weight: number;
  concentration: 'dispersed' | 'moderate' | 'concentrated';
  buckets: PortfolioBucket[];
  bestMover: Holding | null;
  worstMover: Holding | null;
  analysis: string[];
  ideas: IdeaItem[];
  generatedAt: string;
  marketTone: 'risk-on' | 'mixed' | 'risk-off' | 'flat';
};

type Opts = {
  hasSources: boolean;
  hasMm: boolean;
  hasBtc: boolean;
  hasSol: boolean;
  hasSui: boolean;
};

function classify(symbol: string): PortfolioBucket['key'] {
  const base = symbol.replace(/\.(ARB|SOL|SUI)$/, '');
  if (STABLES.has(symbol) || STABLES.has(base)) return 'stables';
  if (MAJORS.has(symbol) || MAJORS.has(base)) return 'majors';
  return 'alts';
}

function isStable(symbol: string) {
  return classify(symbol) === 'stables';
}

function concentrationOf(top3: number): PortfolioInsight['concentration'] {
  if (top3 >= 80) return 'concentrated';
  if (top3 >= 55) return 'moderate';
  return 'dispersed';
}

function fmtUsd(n: number, digits = 0) {
  const abs = Math.abs(n);
  const d = abs >= 1000 ? 0 : abs >= 10 ? 1 : 2;
  return new Intl.NumberFormat('en-US', {
    style: 'currency',
    currency: 'USD',
    maximumFractionDigits: digits || d,
    minimumFractionDigits: 0,
  }).format(n);
}

function fmtPct(n: number) {
  const sign = n > 0 ? '+' : '';
  return `${sign}${n.toFixed(2)}%`;
}

function fmtPrice(n: number) {
  if (n >= 1000) return fmtUsd(n, 0);
  if (n >= 1) return fmtUsd(n, 2);
  return `$${n.toPrecision(3)}`;
}

function roundUsd(n: number) {
  if (n >= 500) return Math.round(n / 50) * 50;
  if (n >= 100) return Math.round(n / 10) * 10;
  if (n >= 20) return Math.round(n / 5) * 5;
  return Math.round(n);
}

function contributionUsd(h: Holding) {
  const denom = 1 + h.change_24h / 100;
  if (denom === 0) return 0;
  const prev = h.value_usd / denom;
  return h.value_usd - prev;
}

function marketToneFrom(portfolio: Portfolio, holdings: Holding[]): PortfolioInsight['marketTone'] {
  const pct = portfolio.change_24h_pct;
  const up = holdings.filter((h) => !isStable(h.symbol) && h.change_24h >= 1).length;
  const down = holdings.filter((h) => !isStable(h.symbol) && h.change_24h <= -1).length;
  if (pct >= 1.5 || (up >= 3 && down === 0)) return 'risk-on';
  if (pct <= -1.5 || (down >= 3 && up === 0)) return 'risk-off';
  if (Math.abs(pct) < 0.4 && up <= 1 && down <= 1) return 'flat';
  return 'mixed';
}

function setupIdeas(opts: Opts): IdeaItem[] {
  const ideas: IdeaItem[] = [];
  if (!opts.hasMm) {
    ideas.push({
      id: 'setup-mm',
      priority: 'setup',
      title: 'MetaMask を接続',
      detail: 'Ethereum / Arbitrum の ETH・USDC・LINK など ERC-20 残高を合算表示できます。',
      trigger: '未接続',
    });
  }
  if (!opts.hasBtc) {
    ideas.push({
      id: 'setup-btc',
      priority: 'setup',
      title: 'BTC（bc1q…）を登録',
      detail: 'Native SegWit アドレスをウォッチに追加し、マクロ資産としての BTC 比率を可視化する。',
      trigger: 'BTC未登録',
    });
  }
  if (!opts.hasSol) {
    ideas.push({
      id: 'setup-sol',
      priority: 'setup',
      title: 'Solana アドレスを追加',
      detail: 'SOL / USDC.SOL / RENDER.SOL を取り込み、L1 分散を数字で確認する。',
      trigger: 'SOL未登録',
    });
  }
  if (!opts.hasSui) {
    ideas.push({
      id: 'setup-sui',
      priority: 'setup',
      title: 'Sui アドレスを追加',
      detail: 'SUI + USDC.SUI を合算し、新興 L1 の比率を 5〜10% 以内に抑える目安を作る。',
      trigger: 'SUI未登録',
    });
  }
  return ideas;
}

function buildConcreteIdeas(
  portfolio: Portfolio,
  holdings: Holding[],
  buckets: PortfolioBucket[],
  opts: Opts,
): IdeaItem[] {
  const ideas: IdeaItem[] = [];
  const total = portfolio.total_usd;
  const stableBucket = buckets.find((b) => b.key === 'stables');
  const majorBucket = buckets.find((b) => b.key === 'majors');
  const altBucket = buckets.find((b) => b.key === 'alts');
  const stableUsd = stableBucket?.valueUsd ?? 0;
  const majorW = majorBucket?.weight ?? 0;
  const altW = altBucket?.weight ?? 0;
  const stableW = stableBucket?.weight ?? 0;

  const risky = holdings.filter((h) => !isStable(h.symbol));
  const byWeight = [...holdings].sort((a, b) => b.weight - a.weight);
  const byMove = [...risky].sort((a, b) => b.change_24h - a.change_24h);
  const byImpact = [...risky]
    .map((h) => ({ h, impact: contributionUsd(h) }))
    .sort((a, b) => a.impact - b.impact);

  const top = byWeight[0];
  const best = byMove[0];
  const worst = byMove[byMove.length - 1];
  const drag = byImpact[0];
  const lift = byImpact[byImpact.length - 1];
  const tone = marketToneFrom(portfolio, holdings);

  // 1) Price-move driven (highest priority)
  if (worst && worst.change_24h <= -3) {
    const buy = roundUsd(Math.min(stableUsd * 0.25, total * 0.03, Math.max(25, Math.abs(contributionUsd(worst)) * 1.5)));
    if (stableUsd >= 25 && buy >= 20) {
      ideas.push({
        id: `dip-${worst.symbol}-${worst.change_24h.toFixed(2)}`,
        priority: 'now',
        title: `${worst.symbol} の押し目を拾う`,
        detail:
          `現在値 ${fmtPrice(worst.price_usd)}（24h ${fmtPct(worst.change_24h)}、寄与 ${fmtUsd(contributionUsd(worst))}）。` +
          `ステーブル ${fmtUsd(stableUsd)} のうち約 ${fmtUsd(buy)}（全体の ${(
            (buy / total) *
            100
          ).toFixed(1)}%）を ${worst.symbol} に振る案。残り余力は ${fmtUsd(stableUsd - buy)}。`,
        trigger: `24h ${fmtPct(worst.change_24h)}`,
      });
    } else {
      ideas.push({
        id: `watch-dip-${worst.symbol}`,
        priority: 'watch',
        title: `${worst.symbol} 下落を監視（買い余力不足）`,
        detail:
          `${worst.symbol} は ${fmtPct(worst.change_24h)}（${fmtPrice(worst.price_usd)}）。` +
          `ステーブルが ${fmtUsd(stableUsd)}（${stableW}%）しかないため、追加購入より先に USDC を ${(
            total * 0.1
          ).toFixed(0)} USD相当まで厚くする。`,
        trigger: `24h ${fmtPct(worst.change_24h)}`,
      });
    }
  }

  if (best && best.change_24h >= 4 && best.weight >= 8) {
    const trim = roundUsd(Math.min(best.value_usd * 0.15, total * 0.04));
    const targetW = Math.max(8, Math.round(best.weight * 0.85 * 10) / 10);
    ideas.push({
      id: `trim-${best.symbol}-${best.change_24h.toFixed(2)}`,
      priority: 'now',
      title: `${best.symbol} の一部利確`,
      detail:
        `${best.symbol} は ${fmtPrice(best.price_usd)}（24h ${fmtPct(best.change_24h)}、比率 ${best.weight}%）。` +
        `約 ${fmtUsd(trim)} 分を USDC 化し、比率をおおむね ${targetW}% 付近へ。利確後のステーブル目安 ${(
          stableUsd + trim
        ).toFixed(0)} USD。`,
      trigger: `24h ${fmtPct(best.change_24h)} · 比率 ${best.weight}%`,
    });
  }

  if (drag && lift && drag.h.symbol !== lift.h.symbol && Math.abs(drag.impact) >= 15) {
    ideas.push({
      id: `rotate-${drag.h.symbol}-${lift.h.symbol}`,
      priority: tone === 'risk-off' ? 'now' : 'watch',
      title: `寄与の入れ替え（${drag.h.symbol} → 守備）`,
      detail:
        `押し下げ最大は ${drag.h.symbol}（寄与 ${fmtUsd(drag.impact)}、${fmtPct(drag.h.change_24h)}）。` +
        `押し上げ最大は ${lift.h.symbol}（寄与 ${fmtUsd(lift.impact)}）。` +
        `全体 ${fmtPct(portfolio.change_24h_pct)}（${fmtUsd(portfolio.change_24h_usd)}）なので、` +
        `${drag.h.symbol} を追加せず、まずは ${lift.h.symbol} の含み益の一部をステーブルに退避する。`,
      trigger: `寄与差 ${fmtUsd(lift.impact - drag.impact)}`,
    });
  }

  // 2) Structure / rebalance with numbers
  if (top && top.weight >= 40) {
    const excess = roundUsd(top.value_usd * ((top.weight - 35) / top.weight));
    ideas.push({
      id: `conc-${top.symbol}`,
      priority: 'now',
      title: `${top.symbol} 集中を 35% 以下へ`,
      detail:
        `現在 ${top.weight}%（${fmtUsd(top.value_usd)} @ ${fmtPrice(top.price_usd)}）。` +
        `35% 目安まで落とすなら約 ${fmtUsd(excess)} 分の売却/交換。` +
        `受け皿は USDC、または比率の低いメジャー（BTC/ETH）へ。`,
      trigger: `単一銘柄 ${top.weight}%`,
    });
  }

  if (stableW < 8) {
    const need = roundUsd(total * 0.12 - stableUsd);
    ideas.push({
      id: 'build-stable',
      priority: 'now',
      title: 'ステーブルを 12% まで厚くする',
      detail:
        `いま ${fmtUsd(stableUsd)}（${stableW}%）。目標 12% まであと約 ${fmtUsd(Math.max(need, 0))}。` +
        `24hで伸びた銘柄から順に利確し、急落時の弾薬にする。`,
      trigger: `ステーブル ${stableW}%`,
    });
  } else if (stableW > 35 && tone !== 'risk-off') {
    const deploy = roundUsd(Math.min(stableUsd * 0.2, total * 0.05));
    const btc = holdings.find((h) => h.symbol === 'BTC' || h.symbol === 'WBTC');
    const eth = holdings.find((h) => h.symbol === 'ETH' || h.symbol === 'ETH.ARB');
    const target = btc && eth ? (btc.weight <= eth.weight ? 'BTC' : 'ETH') : btc ? 'BTC' : eth ? 'ETH' : 'BTC';
    ideas.push({
      id: 'deploy-stable',
      priority: 'watch',
      title: `余剰ステーブルを ${target} へ週次投入`,
      detail:
        `ステーブル ${stableW}%（${fmtUsd(stableUsd)}）と高め。` +
        `今週の枠として約 ${fmtUsd(deploy)} を ${target} に分割買い（例: 2〜3回に分ける）。` +
        `全体が ${fmtPct(portfolio.change_24h_pct)} の局面なので、一括より分割が無難。`,
      trigger: `ステーブル ${stableW}% · 相場 ${tone}`,
    });
  }

  if (altW >= 55 && majorW < 35) {
    const shift = roundUsd(total * ((altW - 45) / 100));
    ideas.push({
      id: 'alt-to-major',
      priority: 'watch',
      title: 'アルト → BTC/ETH へコア寄せ',
      detail:
        `アルト ${altW}% / メジャー ${majorW}%。コア（BTC+ETH）45% を目指すなら、` +
        `アルト側から約 ${fmtUsd(shift)} 分を BTC か ETH にローテーション。` +
        `24hで弱いアルトから順に減らす。`,
      trigger: `アルト ${altW}%`,
    });
  }

  // 3) Market tone playbook
  if (tone === 'risk-off') {
    ideas.push({
      id: 'tone-off',
      priority: 'now',
      title: 'リスクオフ局面の守り',
      detail:
        `全体 ${fmtPct(portfolio.change_24h_pct)}（${fmtUsd(portfolio.change_24h_usd)}）。` +
        `新規のアルト買いは止め、下落寄与の大きい銘柄のナンピンは見送り。` +
        `ステーブル比率 ${stableW}% を維持し、回復は BTC/ETH から。`,
      trigger: '相場トーン risk-off',
    });
  } else if (tone === 'risk-on' && stableW >= 10) {
    const probe = roundUsd(Math.min(stableUsd * 0.1, total * 0.02));
    ideas.push({
      id: 'tone-on',
      priority: 'watch',
      title: 'リスクオンの少額フォロー',
      detail:
        `全体 ${fmtPct(portfolio.change_24h_pct)}。勢いのある銘柄への追加は ` +
        `ステーブルの約 ${fmtUsd(probe)}（全体 ${(probe / total) * 100 > 0 ? ((probe / total) * 100).toFixed(1) : '0'}%）までに限定し、追撃買いの上限を先に決める。`,
      trigger: '相場トーン risk-on',
    });
  }

  // 4) Coverage gaps (setup)
  ideas.push(...setupIdeas(opts));

  // Always keep one cross-asset idea
  ideas.push({
    id: 'cross-stock',
    priority: 'watch',
    title: '株式との合算比率を記録',
    detail:
      `いまのクリプト合計 ${fmtUsd(total)}。株式ダッシュボードの評価額と足し、` +
      `「リスク資産に占めるクリプト％」をメモすると、今月の増減判断がしやすい。`,
    trigger: `合計 ${fmtUsd(total)}`,
  });

  // Prefer now > watch > setup, keep unique ids, limit
  const rank = { now: 0, watch: 1, setup: 2 };
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

export function analyzePortfolio(portfolio: Portfolio, opts: Opts): PortfolioInsight {
  const holdings = portfolio.holdings.filter((h) => h.value_usd > 0);
  const generatedAt = portfolio.updated_at || new Date().toISOString();

  if (!opts.hasSources) {
    return {
      hasHoldings: false,
      assetCount: 0,
      top: null,
      top3Weight: 0,
      concentration: 'dispersed',
      buckets: [],
      bestMover: null,
      worstMover: null,
      analysis: [
        'ウォレット未接続のため、価格連動の具体案はまだ出せません。',
        '接続・アドレス登録後、「再取得」のたびに 24h 値動きからアイデアを更新します。',
      ],
      ideas: (
        [
          ...setupIdeas(opts),
          {
            id: 'setup-refresh',
            priority: 'setup',
            title: '接続後は再取得で値動き案を更新',
            detail: '残高と CoinGecko 価格を取り直し、下落銘柄への買い余力や利確額をその時点の数字で出し直します。',
            trigger: '運用メモ',
          },
        ] as IdeaItem[]
      ).slice(0, 5),
      generatedAt,
      marketTone: 'flat',
    };
  }

  if (!holdings.length) {
    return {
      hasHoldings: false,
      assetCount: 0,
      top: null,
      top3Weight: 0,
      concentration: 'dispersed',
      buckets: [],
      bestMover: null,
      worstMover: null,
      analysis: ['接続済みですが残高 0 です。別チェーンや未対応トークンの可能性があります。'],
      ideas: (
        [
          {
            id: 'zero-check',
            priority: 'now',
            title: '残高の置き場所を確認',
            detail: 'Arbitrum USDC、Solana RENDER、Sui USDC など、トラッキング対象チェーンに資産がないか確認する。',
            trigger: '残高 0',
          },
          ...setupIdeas(opts),
        ] as IdeaItem[]
      ).slice(0, 5),
      generatedAt,
      marketTone: 'flat',
    };
  }

  const sorted = [...holdings].sort((a, b) => b.weight - a.weight);
  const top = sorted[0] ?? null;
  const top3Weight = sorted.slice(0, 3).reduce((s, h) => s + h.weight, 0);
  const concentration = concentrationOf(top3Weight);

  const bucketMap: Record<PortfolioBucket['key'], number> = { majors: 0, alts: 0, stables: 0 };
  for (const h of holdings) bucketMap[classify(h.symbol)] += h.value_usd;
  const total = portfolio.total_usd || holdings.reduce((s, h) => s + h.value_usd, 0);
  const buckets: PortfolioBucket[] = (
    [
      ['majors', 'BTC / ETH 系'],
      ['alts', 'アルト'],
      ['stables', 'ステーブル'],
    ] as const
  )
    .map(([key, label]) => ({
      key,
      label,
      valueUsd: bucketMap[key],
      weight: total > 0 ? Math.round((bucketMap[key] / total) * 1000) / 10 : 0,
    }))
    .filter((b) => b.valueUsd > 0);

  const risky = holdings.filter((h) => !isStable(h.symbol));
  const movers = [...risky].sort((a, b) => b.change_24h - a.change_24h);
  const bestMover = movers[0] ?? null;
  const worstMover = movers[movers.length - 1] ?? null;
  const tone = marketToneFrom(portfolio, holdings);

  const analysis: string[] = [];
  if (top) {
    analysis.push(
      `最大保有は ${top.symbol} ${top.weight}%（${fmtUsd(top.value_usd)} @ ${fmtPrice(top.price_usd)}）。` +
        `上位3銘柄 ${top3Weight.toFixed(1)}%・集中度は${
          concentration === 'concentrated' ? '高め' : concentration === 'moderate' ? '中程度' : '分散寄り'
        }。`,
    );
  }

  const majorW = buckets.find((b) => b.key === 'majors')?.weight ?? 0;
  const stableW = buckets.find((b) => b.key === 'stables')?.weight ?? 0;
  const altW = buckets.find((b) => b.key === 'alts')?.weight ?? 0;
  analysis.push(
    `内訳 メジャー ${majorW}% / アルト ${altW}% / ステーブル ${stableW}%` +
      `（ステーブル ${fmtUsd(buckets.find((b) => b.key === 'stables')?.valueUsd ?? 0)}）。`,
  );

  analysis.push(
    `24h 全体 ${fmtPct(portfolio.change_24h_pct)}（${fmtUsd(portfolio.change_24h_usd)}）、トーンは ${tone}` +
      (bestMover && worstMover
        ? `。強い ${bestMover.symbol} ${fmtPct(bestMover.change_24h)} / 弱い ${worstMover.symbol} ${fmtPct(worstMover.change_24h)}。`
        : '。'),
  );

  return {
    hasHoldings: true,
    assetCount: holdings.length,
    top,
    top3Weight: Math.round(top3Weight * 10) / 10,
    concentration,
    buckets,
    bestMover,
    worstMover,
    analysis: analysis.slice(0, 4),
    ideas: buildConcreteIdeas(portfolio, holdings, buckets, opts),
    generatedAt,
    marketTone: tone,
  };
}
