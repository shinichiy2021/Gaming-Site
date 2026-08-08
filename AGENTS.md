# Agent Guide — Gaming Hub

## Project

WordPress + Docker local dev. Custom theme: `wp-content/themes/gaming-hub/`.

| Service   | URL |
|-----------|-----|
| WordPress | http://localhost:8080 |
| phpMyAdmin | http://localhost:8081 |
| EcoFlow   | http://localhost:8080/tag/ecoflow/ |

## Start developing

```bash
docker compose up -d
cd wp-content/themes/gaming-hub && npm install   # first time only
cd wp-content/themes/gaming-hub && npm run dev:ecoflow
```

In Cursor: **Terminal → Run Task → Dev: Start all**

## Key paths

| Area | Path |
|------|------|
| Theme PHP | `wp-content/themes/gaming-hub/inc/` |
| Templates | `wp-content/themes/gaming-hub/template-parts/` |
| Styles | `wp-content/themes/gaming-hub/assets/css/main.css` |
| EcoFlow React | `wp-content/themes/gaming-hub/src/ecoflow/` |
| Built JS | `wp-content/themes/gaming-hub/assets/js/ecoflow-flow.js` |

## Before finishing front-end changes

1. Bump `GAMING_HUB_VERSION` in `functions.php` if CSS/JS changed
2. Run `npm run build:ecoflow` if React sources changed
3. Hard-reload browser (`Cmd+Shift+R`)

## Secrets

- `.env` — EcoFlow API keys (never commit)
- Docker passes env to WordPress container via `docker-compose.yml`
