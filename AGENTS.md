# Agent Guide — Gaming Hub

WordPress + Docker local dev. Custom theme: `wp-content/themes/gaming-hub/`.

| Service | URL |
|---------|-----|
| WordPress | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |
| Production | https://shinichiy-gaming-hub.com |

Dashboards live on tag pages: `/tag/ecoflow/`, `/tag/tesla/`, `/tag/pokemon-go/`.

## Key paths

| Area | Path (under `wp-content/themes/gaming-hub/`) |
|------|------|
| Feature PHP | `inc/` |
| Templates | `template-parts/` |
| Styles | `assets/css/main.css` |
| React flows | `src/ecoflow/`, `src/powerwall/`, `src/tesla/` |
| Built JS | `assets/js/*-flow.js` (generated — do not edit) |

## Dev commands

```bash
docker compose up -d
cd wp-content/themes/gaming-hub && npm install      # first time only
cd wp-content/themes/gaming-hub && npm run dev:ecoflow   # watch
cd wp-content/themes/gaming-hub && npm run build:flows   # build all three flows
```

In Cursor: **Terminal → Run Task**.

## Before finishing front-end changes

1. Bump `GAMING_HUB_VERSION` in `functions.php` if CSS/JS changed
2. Run the matching `npm run build:*` if React sources changed
3. Hard-reload the browser (`Cmd+Shift+R`)

## Conventions

- Feature pattern: `inc/*.php` + `template-parts/` + conditional enqueue in the feature file
- EcoFlow Delta Pro 3 quota keys: `powInSumW`, `powOutSumW`, `bmsChgDsgState` (0=待機, 1=放電, 2=充電)
- Only commit when the user explicitly asks

## Secrets

Never commit `.env`, `*.pem`, or `wp-content/ecoflow-cache/bridge-status.json`. API keys reach the WordPress container as env vars via `docker-compose.yml`, or via the Customizer.
