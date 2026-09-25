import type { BotContext } from "./config.js";
import { fetchUiLadder, hasTraderSeat, printBookTop } from "./market.js";
import {
  cancelAllOrders,
  checkBalances,
  computeMakerQuotes,
  ensureSeatOrExit,
  placeMakerQuotes,
} from "./order.js";

function sleep(ms: number): Promise<void> {
  return new Promise((resolve) => setTimeout(resolve, ms));
}

/**
 * Exponential backoff for RPC / send failures (caps at 30s).
 */
async function backoff(attempt: number, baseMs: number): Promise<void> {
  const ms = Math.min(30_000, baseMs * 2 ** Math.min(attempt, 4));
  console.warn(`[backoff] sleeping ${ms}ms (attempt=${attempt})`);
  await sleep(ms);
}

export async function runBot(ctx: BotContext): Promise<void> {
  console.log("[bot] Phoenix SOL/USDC maker scaffold");
  console.log(`[bot] market=${ctx.marketAddress}`);
  console.log(`[bot] trader=${ctx.wallet.publicKey.toBase58()}`);
  console.log(
    `[bot] spread=${ctx.cfg.gridSpreadBps}bps size=${ctx.cfg.orderAmountSol} SOL interval=${ctx.cfg.loopIntervalMs}ms`,
  );

  // Initial seat check / optional setup
  await ctx.phoenix.refreshMarket(ctx.marketState.address);
  const fresh = ctx.phoenix.marketStates.get(ctx.marketAddress) ?? ctx.marketState;
  await ensureSeatOrExit(
    ctx.connection,
    fresh,
    ctx.wallet,
    ctx.cfg.autoClaimSeat,
    ctx.cfg,
  );

  // Re-verify after optional claim
  await ctx.phoenix.refreshMarket(ctx.marketState.address);
  const after = ctx.phoenix.marketStates.get(ctx.marketAddress);
  if (!after || !hasTraderSeat(after, ctx.wallet.publicKey)) {
    console.error("[seat] Still missing after setup. Aborting.");
    process.exit(1);
  }

  let failures = 0;

  // eslint-disable-next-line no-constant-condition
  while (true) {
    try {
      const { marketState, snapshot } = await fetchUiLadder(
        ctx.phoenix,
        ctx.marketAddress,
        10,
      );
      printBookTop(snapshot, 3);

      if (snapshot.mid === null) {
        console.warn("[bot] Empty book / no mid — skipping quote cycle");
        failures = 0;
        await sleep(ctx.cfg.loopIntervalMs);
        continue;
      }

      const quotes = computeMakerQuotes(snapshot.mid, ctx.cfg.gridSpreadBps);
      console.log(
        `[quote] mid=${quotes.mid.toFixed(4)} bid=${quotes.bidPrice.toFixed(4)} ask=${quotes.askPrice.toFixed(4)}`,
      );

      const bal = await checkBalances(
        ctx.connection,
        ctx.wallet,
        marketState,
        ctx.cfg.orderAmountSol,
        quotes.bidPrice,
      );
      console.log(
        `[bal] SOL=${bal.solUi.toFixed(4)} USDC=${bal.usdcUi.toFixed(2)} bidOk=${bal.okForBid} askOk=${bal.okForAsk}`,
      );

      if (!bal.okForBid && !bal.okForAsk) {
        console.error("[bal] Insufficient SOL and USDC for either side — pausing cycle");
        await sleep(ctx.cfg.loopIntervalMs);
        continue;
      }

      // Cancel resting quotes first, then replace (simple MM pattern).
      try {
        await cancelAllOrders({
          phoenix: ctx.phoenix,
          marketAddress: ctx.marketAddress,
          wallet: ctx.wallet,
          connection: ctx.connection,
          cfg: ctx.cfg,
        });
      } catch (err) {
        console.warn("[bot] cancel-all failed (may have no orders):", err);
      }

      // Only place sides we can fund. For scaffold simplicity we require both;
      // tighten later to place single-sided quotes.
      if (!bal.okForBid || !bal.okForAsk) {
        console.warn(
          "[bot] Skipping place — need both SOL (ask) and USDC (bid) for two-sided quotes",
        );
      } else {
        await placeMakerQuotes(
          {
            phoenix: ctx.phoenix,
            marketAddress: ctx.marketAddress,
            wallet: ctx.wallet,
            connection: ctx.connection,
            cfg: ctx.cfg,
          },
          quotes,
        );
      }

      failures = 0;
      await sleep(ctx.cfg.loopIntervalMs);
    } catch (err) {
      failures += 1;
      console.error("[bot] cycle error:", err);
      await backoff(failures, ctx.cfg.loopIntervalMs);
    }
  }
}
