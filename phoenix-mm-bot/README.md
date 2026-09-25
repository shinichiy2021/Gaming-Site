# Phoenix SOL/USDC Maker Bot (scaffold)

TypeScript (ESM) scaffold that:

1. Monitors Phoenix L2 (`UiLadder`) for market `4DoNfFBfF7UokCC2FQzriy7yHK6DY6NVdYpuekQ5pRgg`
2. Quotes Post-Only bid/ask at `GRID_SPREAD_BPS` from mid
3. Cancels resting orders each cycle, then replaces quotes
4. Attaches ComputeBudget priority fee + CU limit on every tx

**This places real on-chain orders when configured.** Use a dedicated test wallet.

## Setup

```bash
cd phoenix-mm-bot
cp .env.example .env
# fill RPC_URL, WS_URL, WALLET_PRIVATE_KEY
npm install
npm run dev
```

## Seat

Phoenix makers need a Seat (+ base/quote ATAs). On first run without a seat the bot exits with guidance.

Set `AUTO_CLAIM_SEAT=true` once to run `getMakerSetupInstructionsForMarket`.

## Safety

- Balance checks for SOL (ask + fees) and USDC (bid) before placing
- Loop interval + exponential backoff on errors
- Never commit `.env`
