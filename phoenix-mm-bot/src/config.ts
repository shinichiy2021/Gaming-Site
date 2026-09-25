import "dotenv/config";
import {
  Connection,
  Keypair,
  PublicKey,
  type Commitment,
} from "@solana/web3.js";
import bs58 from "bs58";
import * as Phoenix from "@ellipsis-labs/phoenix-sdk";

/** Mainnet Phoenix SOL/USDC market (as specified). */
export const SOL_USDC_MARKET = new PublicKey(
  "4DoNfFBfF7UokCC2FQzriy7yHK6DY6NVdYpuekQ5pRgg",
);

function requireEnv(name: string): string {
  const value = process.env[name]?.trim();
  if (!value) {
    throw new Error(`Missing required env: ${name}`);
  }
  return value;
}

function numEnv(name: string, fallback?: number): number {
  const raw = process.env[name]?.trim();
  if (raw === undefined || raw === "") {
    if (fallback !== undefined) return fallback;
    throw new Error(`Missing required env: ${name}`);
  }
  const n = Number(raw);
  if (!Number.isFinite(n)) {
    throw new Error(`Invalid number for ${name}: ${raw}`);
  }
  return n;
}

function boolEnv(name: string, fallback = false): boolean {
  const raw = process.env[name]?.trim()?.toLowerCase();
  if (raw === undefined || raw === "") return fallback;
  return raw === "1" || raw === "true" || raw === "yes";
}

export type BotConfig = {
  rpcUrl: string;
  wsUrl: string;
  gridSpreadBps: number;
  orderAmountSol: number;
  priorityFeeMicroLamports: number;
  computeUnitLimit: number;
  loopIntervalMs: number;
  autoClaimSeat: boolean;
  orderLifetimeSeconds: number;
  commitment: Commitment;
};

export function loadConfig(): BotConfig {
  return {
    rpcUrl: requireEnv("RPC_URL"),
    wsUrl: requireEnv("WS_URL"),
    gridSpreadBps: numEnv("GRID_SPREAD_BPS", 20),
    orderAmountSol: numEnv("ORDER_AMOUNT_SOL", 0.1),
    priorityFeeMicroLamports: numEnv("PRIORITY_FEE_MICROLAMPORTS", 50_000),
    computeUnitLimit: numEnv("COMPUTE_UNIT_LIMIT", 250_000),
    loopIntervalMs: numEnv("LOOP_INTERVAL_MS", 2_000),
    autoClaimSeat: boolEnv("AUTO_CLAIM_SEAT", false),
    orderLifetimeSeconds: numEnv("ORDER_LIFETIME_SECONDS", 30),
    commitment: "confirmed",
  };
}

export function loadWallet(): Keypair {
  const secret = requireEnv("WALLET_PRIVATE_KEY");
  try {
    // Prefer Base58 (Phantom export / common hot-wallet format).
    return Keypair.fromSecretKey(bs58.decode(secret));
  } catch {
    // Also accept JSON byte-array form: [1,2,3,...]
    const parsed = JSON.parse(secret) as number[];
    return Keypair.fromSecretKey(Uint8Array.from(parsed));
  }
}

export function createConnection(cfg: BotConfig): Connection {
  return new Connection(cfg.rpcUrl, {
    commitment: cfg.commitment,
    wsEndpoint: cfg.wsUrl,
  });
}

export type BotContext = {
  cfg: BotConfig;
  connection: Connection;
  wallet: Keypair;
  phoenix: Phoenix.Client;
  marketAddress: string;
  marketState: Phoenix.MarketState;
};

export async function createBotContext(): Promise<BotContext> {
  const cfg = loadConfig();
  const connection = createConnection(cfg);
  const wallet = loadWallet();
  const phoenix = await Phoenix.Client.create(connection);
  const marketAddress = SOL_USDC_MARKET.toBase58();
  const marketState = phoenix.marketStates.get(marketAddress);

  if (!marketState) {
    throw new Error(
      `Phoenix market not loaded: ${marketAddress}. Check RPC / market id.`,
    );
  }

  return {
    cfg,
    connection,
    wallet,
    phoenix,
    marketAddress,
    marketState,
  };
}
