# Crypto portfolio (Next.js + Tailwind)

Standalone app for `/tag/crypto/` (WordPress redirects here; not linked in nav).

```bash
# Dev
cd crypto-app && npm run dev -- -p 3002

# Or Docker
docker compose up -d --build crypto
```

- Local: http://localhost:3002
- WP entry: http://localhost:8080/tag/crypto/ → redirects
- Env: `CRYPTO_APP_URL` (default `http://localhost:3002`)

## MetaMask (Ethereum + Arbitrum)

Connect once; balances are read from **both** Ethereum mainnet and Arbitrum One for that address:

- ETH / ETH.ARB
- USDT, USDC, DAI, WBTC, LINK (and `.ARB` counterparts)
- Prices via CoinGecko

## Bitcoin Native SegWit

Register a watch-only `bc1q…` address. Balance via mempool.space (`/api/btc/balance`).

## Solana

Register a watch-only Solana address. Reads SOL + USDC + RENDER (`/api/sol/balance`). Optional `SOLANA_RPC_URL` env.

## Sui

Register a watch-only Sui address (`0x…`). Reads SUI + USDC (`/api/sui/balance`). Default RPC `https://1rpc.io/sui` (override with `SUI_RPC_URL`).

Disconnected / no watch addresses → empty idle state (no sample data). Loading shows a skeleton instead of placeholder balances.
