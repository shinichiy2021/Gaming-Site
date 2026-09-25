import * as Phoenix from "@ellipsis-labs/phoenix-sdk";
import {
  ComputeBudgetProgram,
  Transaction,
  sendAndConfirmTransaction,
  type Connection,
  type Keypair,
  type TransactionInstruction,
} from "@solana/web3.js";
import {
  TOKEN_PROGRAM_ID,
  getAssociatedTokenAddressSync,
  getAccount,
} from "@solana/spl-token";
import type { BotConfig } from "./config.js";
import { getMarketMints } from "./market.js";

export type QuotePrices = {
  bidPrice: number;
  askPrice: number;
  mid: number;
};

export function computeMakerQuotes(
  mid: number,
  gridSpreadBps: number,
): QuotePrices {
  const edge = mid * (gridSpreadBps / 10_000);
  return {
    mid,
    bidPrice: mid - edge,
    askPrice: mid + edge,
  };
}

export type BalanceCheck = {
  solLamports: number;
  solUi: number;
  usdcRaw: bigint;
  usdcUi: number;
  okForBid: boolean;
  okForAsk: boolean;
};

/**
 * Ensure native SOL (fees + ask inventory) and USDC ATA (bid inventory) cover the quote.
 */
export async function checkBalances(
  connection: Connection,
  wallet: Keypair,
  marketState: Phoenix.MarketState,
  orderAmountSol: number,
  bidPrice: number,
): Promise<BalanceCheck> {
  const { quoteMint, quoteDecimals } = getMarketMints(marketState);
  const solLamports = await connection.getBalance(wallet.publicKey, "confirmed");
  const solUi = solLamports / 1e9;

  const ata = getAssociatedTokenAddressSync(
    quoteMint,
    wallet.publicKey,
    false,
    TOKEN_PROGRAM_ID,
  );

  let usdcRaw = 0n;
  try {
    const acct = await getAccount(connection, ata, "confirmed", TOKEN_PROGRAM_ID);
    usdcRaw = acct.amount;
  } catch {
    usdcRaw = 0n;
  }

  const usdcUi = Number(usdcRaw) / 10 ** quoteDecimals;
  const usdcNeeded = orderAmountSol * bidPrice * 1.01; // small buffer
  const solNeededForAsk = orderAmountSol + 0.01; // size + fee buffer

  return {
    solLamports,
    solUi,
    usdcRaw,
    usdcUi,
    okForBid: usdcUi >= usdcNeeded,
    okForAsk: solUi >= solNeededForAsk,
  };
}

function withComputeBudget(
  ixs: TransactionInstruction[],
  cfg: BotConfig,
): TransactionInstruction[] {
  return [
    ComputeBudgetProgram.setComputeUnitPrice({
      microLamports: cfg.priorityFeeMicroLamports,
    }),
    ComputeBudgetProgram.setComputeUnitLimit({
      units: cfg.computeUnitLimit,
    }),
    ...ixs,
  ];
}

export async function sendIxTransaction(
  connection: Connection,
  wallet: Keypair,
  ixs: TransactionInstruction[],
  cfg: BotConfig,
  label: string,
): Promise<string> {
  const tx = new Transaction().add(...withComputeBudget(ixs, cfg));
  const sig = await sendAndConfirmTransaction(connection, tx, [wallet], {
    commitment: cfg.commitment,
    skipPreflight: false,
  });
  console.log(`[tx] ${label}: ${sig}`);
  return sig;
}

export async function cancelAllOrders(
  ctx: {
    phoenix: Phoenix.Client;
    marketAddress: string;
    wallet: Keypair;
    connection: Connection;
    cfg: BotConfig;
  },
): Promise<void> {
  const ix = ctx.phoenix.createCancelAllOrdersInstruction(
    ctx.marketAddress,
    ctx.wallet.publicKey,
  );
  await sendIxTransaction(
    ctx.connection,
    ctx.wallet,
    [ix],
    ctx.cfg,
    "cancel-all",
  );
}

/**
 * Place Post-Only (maker) bid + ask around mid using Phoenix templates.
 */
export async function placeMakerQuotes(
  ctx: {
    phoenix: Phoenix.Client;
    marketAddress: string;
    wallet: Keypair;
    connection: Connection;
    cfg: BotConfig;
  },
  quotes: QuotePrices,
): Promise<void> {
  const nowSec = Math.floor(Date.now() / 1000);
  const expire = nowSec + ctx.cfg.orderLifetimeSeconds;
  const size = ctx.cfg.orderAmountSol;

  const bidTemplate: Phoenix.PostOnlyOrderTemplate = {
    side: Phoenix.Side.Bid,
    priceAsFloat: quotes.bidPrice,
    sizeInBaseUnits: size,
    clientOrderId: nowSec,
    rejectPostOnly: true,
    useOnlyDepositedFunds: false,
    lastValidUnixTimestampInSeconds: expire,
  };

  const askTemplate: Phoenix.PostOnlyOrderTemplate = {
    side: Phoenix.Side.Ask,
    priceAsFloat: quotes.askPrice,
    sizeInBaseUnits: size,
    clientOrderId: nowSec + 1,
    rejectPostOnly: true,
    useOnlyDepositedFunds: false,
    lastValidUnixTimestampInSeconds: expire,
  };

  const bidIx = ctx.phoenix.getPostOnlyOrderInstructionfromTemplate(
    ctx.marketAddress,
    ctx.wallet.publicKey,
    bidTemplate,
  );
  const askIx = ctx.phoenix.getPostOnlyOrderInstructionfromTemplate(
    ctx.marketAddress,
    ctx.wallet.publicKey,
    askTemplate,
  );

  await sendIxTransaction(
    ctx.connection,
    ctx.wallet,
    [bidIx, askIx],
    ctx.cfg,
    `quote bid=${quotes.bidPrice.toFixed(4)} ask=${quotes.askPrice.toFixed(4)}`,
  );
}

export async function ensureSeatOrExit(
  connection: Connection,
  marketState: Phoenix.MarketState,
  wallet: Keypair,
  autoClaim: boolean,
  cfg: BotConfig,
): Promise<void> {
  const trader = wallet.publicKey.toBase58();
  const hasSeat = marketState.data.traderPubkeyToTraderIndex.has(trader);

  if (hasSeat) {
    console.log(`[seat] OK — trader index present for ${trader}`);
    return;
  }

  const setupIxs = await Phoenix.getMakerSetupInstructionsForMarket(
    connection,
    marketState,
    wallet.publicKey,
  );

  if (!autoClaim) {
    console.error(
      "[seat] Phoenix maker Seat (and/or base/quote ATAs) not ready for this wallet.",
    );
    console.error(
      "[seat] Claim a seat before quoting. Set AUTO_CLAIM_SEAT=true once to run setup, or claim via Phoenix CLI / SDK.",
    );
    console.error(
      `[seat] Pending setup instructions: ${setupIxs.length} (ATA create / claim seat).`,
    );
    process.exit(1);
  }

  if (setupIxs.length === 0) {
    console.warn(
      "[seat] AUTO_CLAIM_SEAT=true but no setup ixs returned — refresh market and retry.",
    );
    return;
  }

  console.log(`[seat] Claiming seat / creating ATAs (${setupIxs.length} ixs)...`);
  await sendIxTransaction(
    connection,
    wallet,
    setupIxs,
    cfg,
    "claim-seat-setup",
  );
}
