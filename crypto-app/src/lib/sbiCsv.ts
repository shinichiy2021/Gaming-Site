import {
  ACCOUNT_META,
  HOLDING_COLORS,
  type StockAccountKind,
  type StockHolding,
  type StockPortfolio,
  type StockSectionSummary,
} from '@/lib/stockPortfolio';

function parseCsvLine(line: string): string[] {
  const result: string[] = [];
  let current = '';
  let inQuotes = false;

  for (let i = 0; i < line.length; i += 1) {
    const ch = line[i];
    if (ch === '"') {
      if (inQuotes && line[i + 1] === '"') {
        current += '"';
        i += 1;
      } else {
        inQuotes = !inQuotes;
      }
      continue;
    }
    if (ch === ',' && !inQuotes) {
      result.push(current.trim());
      current = '';
      continue;
    }
    if (ch !== '\r') {
      current += ch;
    }
  }
  result.push(current.trim());
  while (result.length > 0 && result[result.length - 1] === '') {
    result.pop();
  }
  return result;
}

function parseNumber(raw: string | undefined): number {
  if (!raw) return 0;
  const cleaned = raw.replace(/[",\s]/g, '').replace(/^\+/, '');
  if (!cleaned || cleaned === '----/--/--') return 0;
  const n = Number(cleaned);
  return Number.isFinite(n) ? n : 0;
}

function parseBuyDate(raw: string | undefined): string | null {
  if (!raw || raw === '----/--/--' || raw === '----') return null;
  return raw;
}

function detectAccount(title: string): StockAccountKind | null {
  if (title.includes('つみたて')) return 'nisa_tsumitate';
  if (title.includes('成長')) return 'nisa_growth';
  if (title.includes('特定')) return 'tokutei';
  return null;
}

function isSectionTitle(cells: string[]): boolean {
  if (cells.length !== 1) return false;
  const t = cells[0];
  return (
    (t.includes('株式') || t.includes('投資信託')) &&
    !t.includes('合計') &&
    !t.includes('総合計')
  );
}

function isSectionTotalTitle(cells: string[]): boolean {
  if (cells.length !== 1) return false;
  const t = cells[0];
  return t.includes('合計') && !t.includes('総合計');
}

function isGrandTotalTitle(cells: string[]): boolean {
  return cells.length === 1 && cells[0] === '総合計';
}

function isHoldingsHeader(cells: string[]): boolean {
  return cells[0] === '銘柄（コード）' || cells[0] === 'ファンド名';
}

function isTotalsHeader(cells: string[]): boolean {
  return cells[0] === '評価額' && cells.includes('含み損益');
}

function splitCodeName(raw: string): { code: string; name: string } {
  const m = raw.match(/^(\d{4})\s+(.+)$/);
  if (m) return { code: m[1], name: m[2] };
  return { code: '', name: raw };
}

type ParseState =
  | { kind: 'idle' }
  | { kind: 'section'; account: StockAccountKind }
  | { kind: 'holdings'; account: StockAccountKind }
  | { kind: 'section_total_header'; account: StockAccountKind }
  | { kind: 'grand_total_header' };

export function parseSbiPortfolioCsv(
  text: string,
  source: 'seed' | 'upload' = 'upload',
): StockPortfolio {
  const lines = text
    .replace(/^\uFEFF/, '')
    .split(/\n/)
    .map((l) => l.trim())
    .filter((l) => l.length > 0);

  const holdingsRaw: Omit<StockHolding, 'weight' | 'color' | 'id'>[] = [];
  const sectionTotals = new Map<
    StockAccountKind,
    { value: number; pnl: number; pnlPct: number; dayChange: number; dayChangePct: number }
  >();

  let grand = {
    value: 0,
    pnl: 0,
    pnlPct: 0,
    dayChange: 0,
    dayChangePct: 0,
  };

  let state: ParseState = { kind: 'idle' };

  for (const line of lines) {
    const cells = parseCsvLine(line);
    if (cells.length === 0) continue;

    if (isSectionTitle(cells)) {
      const account = detectAccount(cells[0]);
      if (account) state = { kind: 'section', account };
      continue;
    }

    if (isGrandTotalTitle(cells)) {
      state = { kind: 'grand_total_header' };
      continue;
    }

    if (isSectionTotalTitle(cells)) {
      const account = detectAccount(cells[0]);
      if (account) state = { kind: 'section_total_header', account };
      continue;
    }

    if (state.kind === 'section' && isHoldingsHeader(cells)) {
      state = { kind: 'holdings', account: state.account };
      continue;
    }

    if (
      (state.kind === 'section_total_header' || state.kind === 'grand_total_header') &&
      isTotalsHeader(cells)
    ) {
      continue;
    }

    if (state.kind === 'holdings') {
      if (cells.length < 10) continue;
      const label = cells[0];
      if (!label || label.includes('合計') || label === '評価額') continue;

      const { code, name } = splitCodeName(label);
      const meta = ACCOUNT_META[state.account];
      holdingsRaw.push({
        code,
        name,
        market: 'domestic',
        exchange: 'TSE',
        account: state.account,
        accountLabel: meta.label,
        buyDate: parseBuyDate(cells[1]),
        quantity: parseNumber(cells[2]),
        costPrice: parseNumber(cells[3]),
        currentPrice: parseNumber(cells[4]),
        dayChange: parseNumber(cells[5]),
        dayChangePct: parseNumber(cells[6]),
        pnl: parseNumber(cells[7]),
        pnlPct: parseNumber(cells[8]),
        value: parseNumber(cells[9]),
        valueUsd: null,
        pnlJpy: parseNumber(cells[7]),
      });
      continue;
    }

    if (state.kind === 'section_total_header' && cells.length >= 5 && !isTotalsHeader(cells)) {
      sectionTotals.set(state.account, {
        value: parseNumber(cells[0]),
        pnl: parseNumber(cells[1]),
        pnlPct: parseNumber(cells[2]),
        dayChange: parseNumber(cells[3]),
        dayChangePct: parseNumber(cells[4]),
      });
      state = { kind: 'idle' };
      continue;
    }

    if (state.kind === 'grand_total_header' && cells.length >= 5 && !isTotalsHeader(cells)) {
      grand = {
        value: parseNumber(cells[0]),
        pnl: parseNumber(cells[1]),
        pnlPct: parseNumber(cells[2]),
        dayChange: parseNumber(cells[3]),
        dayChangePct: parseNumber(cells[4]),
      };
      state = { kind: 'idle' };
    }
  }

  const totalValue =
    grand.value ||
    holdingsRaw.reduce((sum, h) => sum + h.value, 0) ||
    [...sectionTotals.values()].reduce((sum, s) => sum + s.value, 0);

  const holdings: StockHolding[] = holdingsRaw
    .slice()
    .sort((a, b) => b.value - a.value)
    .map((h, i) => ({
      ...h,
      id: `${h.account}-${h.code || h.name}-${i}`,
      weight: totalValue > 0 ? Math.round((h.value / totalValue) * 1000) / 10 : 0,
      color: HOLDING_COLORS[i % HOLDING_COLORS.length],
    }));

  const accountOrder: StockAccountKind[] = ['tokutei', 'nisa_growth', 'nisa_tsumitate'];
  const sections: StockSectionSummary[] = accountOrder
    .map((account): StockSectionSummary | null => {
      const meta = ACCOUNT_META[account];
      const group = holdings.filter((h) => h.account === account);
      if (!group.length && !sectionTotals.has(account)) return null;
      const totals = sectionTotals.get(account);
      const value = totals?.value ?? group.reduce((s, h) => s + h.value, 0);
      const pnl = totals?.pnl ?? group.reduce((s, h) => s + h.pnl, 0);
      const dayChange = totals?.dayChange ?? 0;
      const dayChangePct = totals?.dayChangePct ?? 0;
      const pnlPct =
        totals?.pnlPct ??
        (value - pnl > 0 ? Math.round((pnl / (value - pnl)) * 10000) / 100 : 0);
      return {
        account,
        accountLabel: meta.label,
        market: 'domestic',
        value,
        valueUsd: null,
        pnl,
        pnlJpy: pnl,
        pnlPct,
        dayChange,
        dayChangePct,
        weight: totalValue > 0 ? Math.round((value / totalValue) * 1000) / 10 : 0,
        color: meta.color,
        holdingsCount: group.length,
      };
    })
    .filter((s): s is StockSectionSummary => s !== null);

  const totalPnl = grand.pnl || holdings.reduce((s, h) => s + h.pnl, 0);
  const totalPnlPct =
    grand.pnlPct ||
    (totalValue - totalPnl > 0
      ? Math.round((totalPnl / (totalValue - totalPnl)) * 10000) / 100
      : 0);

  return {
    holdings,
    sections,
    markets: [
      {
        market: 'domestic',
        label: '国内',
        value: totalValue,
        valueUsd: null,
        pnlJpy: totalPnl,
        weight: 100,
        color: '#3dd68c',
      },
    ],
    totalValue,
    totalPnl,
    totalPnlPct,
    totalValueUsd: 0,
    totalPnlUsd: 0,
    dayChange: grand.dayChange,
    dayChangePct: grand.dayChangePct,
    usdJpy: null,
    fxAsOf: null,
    updatedAt: new Date().toISOString(),
    source,
  };
}

export async function decodeCsvFile(file: File): Promise<string> {
  const buffer = await file.arrayBuffer();
  const utf8 = new TextDecoder('utf-8', { fatal: false }).decode(buffer);
  if (!utf8.includes('\uFFFD') && (utf8.includes('ポートフォリオ') || utf8.includes('評価額'))) {
    return utf8;
  }
  try {
    return new TextDecoder('shift_jis').decode(buffer);
  } catch {
    try {
      return new TextDecoder('windows-31j').decode(buffer);
    } catch {
      return utf8;
    }
  }
}
