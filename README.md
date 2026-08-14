# Gaming Hub - WordPress Gaming Site

A modern gaming website built with WordPress and a custom dark theme featuring neon accents.

## Features

- **Custom Gaming Theme** — Dark design with cyan/purple neon accents
- **Game Reviews** — Rating system with platform and genre metadata
- **News Section** — Latest gaming industry news
- **Guides** — Walkthroughs and strategy content
- **Responsive Design** — Mobile-friendly layout
- **Docker Setup** — One-command local development environment

## Quick Start

### Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) installed and running

### Launch

```bash
# Start WordPress
docker compose up -d

# Or use the setup script
chmod +x scripts/setup.sh
./scripts/setup.sh
```

Open **http://localhost:8080** in your browser.

### WordPress Initial Setup

1. Select language and fill in site title (e.g. "Gaming Hub")
2. Create admin username and password
3. Complete installation

### Activate Theme

1. Go to **Appearance → Themes**
2. Activate **Gaming Hub**

### Configure Homepage

1. Go to **Settings → Reading**
2. Select **A static page** for homepage display
3. Save changes

### Create Categories

Create these categories under **Posts → Categories**:

| Category | Slug     | Description              |
|----------|----------|--------------------------|
| Reviews  | reviews  | Game reviews and ratings |
| News     | news     | Gaming industry news     |
| Guides   | guides   | Walkthroughs and tips    |

### Add Game Metadata (Optional)

When editing a post, add custom fields:

| Field Name       | Example Value        |
|------------------|----------------------|
| `_game_platform` | PS5 / PC             |
| `_game_genre`    | Action RPG           |
| `_game_rating`   | 4.5                  |

### Pokémon GO 最新情報

テーマ有効化後、**Pokémon GO** ページが自動作成されます。

- トップページに最新4件を表示
- 専用ページ `/pokemon-go/` で15件表示（パーマリンク設定後）
- Pokémon GO Hub の RSS から30分ごとに自動更新
- 公式サイト（日本語）へのリンク付き

**設定 → パーマリンク** で「投稿名」を選び保存すると、`/pokemon-go/` のURLでアクセスできます。

### EcoFlow デバイスステータス（アプリ風ダッシュボード）

`/tag/ecoflow/` でバッテリー残量・ソーラー発電量・入出力電力などを表示できます。

1. [EcoFlow Developer Platform](https://developer.ecoflow.com/us/) で Access Key / Secret Key を取得
2. デバイスのシリアル番号 (SN) を確認（EcoFlow アプリ → デバイス設定）
3. `.env` に設定:

```env
ECOFLOW_ACCESS_KEY=your_access_key
ECOFLOW_SECRET_KEY=your_secret_key
ECOFLOW_DEVICE_SN=your_delta_pro_3_serial
ECOFLOW_DEVICE_SN_2=your_delta_3_1500_serial
ECOFLOW_API_REGION=us
```

Delta 3 1500 を Delta Pro 3 の AC 100V 出力に接続している場合、`ECOFLOW_DEVICE_SN_2` を設定すると横一列の電力フロー図で Pro → 1500 → ホーム と表示されます。

4. `docker compose up -d` で再起動

または **外観 → カスタマイズ → EcoFlow API** から設定できます。

### Tesla Model 3（Powerwall フロー連携）

`/powerwall/` の電力フロー図で Model 3 の充電状態・SOC・充電電力を Tesla Fleet API から取得できます。

1. [Tesla Developer](https://developer.tesla.com/) でアプリを作成し Client ID / Secret を取得
2. Redirect URI に以下を登録:
   `http://localhost:8080/wp-json/gaming-hub/v1/tesla/oauth/callback`
3. `.env` に設定:

```env
TESLA_CLIENT_ID=your_client_id
TESLA_CLIENT_SECRET=your_client_secret
TESLA_VEHICLE_VIN=your_model3_vin
# 日本は通常 NA リージョン（自動検出。失敗時のみ手動設定）:
# TESLA_FLEET_API_BASE_URL=https://fleet-api.prd.na.vn.cloud.tesla.com
# 初回 OAuth 後に自動保存。手動設定も可:
# TESLA_REFRESH_TOKEN=...
```

4. `docker compose up -d` で再起動
5. **外観 → カスタマイズ → Tesla API (Model 3)** で VIN を入力
6. Powerwall ページの **「Tesla で認証」** からアカウント連携

ソーラー / Powerwall は引き続きデモ（2kW パネル想定）。Model 3 のみ実データです。

## URLs

| Service    | URL                      |
|------------|--------------------------|
| WordPress  | http://localhost:8080    |
| phpMyAdmin | http://localhost:8081    |

## Project Structure

```
my-gaming-site/
├── docker-compose.yml          # Docker services config
├── .env                        # Environment variables
├── wp-content/
│   └── themes/
│       └── gaming-hub/         # Custom gaming theme
│           ├── style.css
│           ├── functions.php
│           ├── front-page.php  # Homepage template
│           ├── single.php      # Single post template
│           ├── archive.php     # Category/archive template
│           └── assets/
│               ├── css/main.css
│               └── js/main.js
└── scripts/
    └── setup.sh                # Setup helper script
```

## Customization

Hero section text can be customized via **Appearance → Customize → Hero Section**.

## Cursor での開発

このリポジトリには Cursor / VS Code 向けの設定が含まれています。

| ファイル | 内容 |
|----------|------|
| `.vscode/tasks.json` | Docker 起動、EcoFlow React watch/build など |
| `.vscode/settings.json` | PHP/JS インデント、Intelephense など |
| `.vscode/extensions.json` | 推奨拡張機能 |
| `.cursor/rules/` | AI 向けプロジェクト規約 |
| `AGENTS.md` | エージェント向けクイックリファレンス |

### おすすめの流れ

1. Cursor で **Extensions** から推奨拡張機能をインストール
2. **Terminal → Run Task → Dev: Start all** で Docker + React watch を起動
3. http://localhost:8080 を開いて確認
4. `src/ecoflow/` を編集すると watch が自動ビルド（本番前は `npm run build:ecoflow`）

### よく使うタスク

- **Docker: Start** — WordPress 起動
- **Theme: EcoFlow React (watch)** — Canvas アニメーションのホットリロード
- **Theme: EcoFlow React (build)** — 本番ビルド（デフォルト Build タスク `Cmd+Shift+B`）

## Production Deploy (https://shinichiy-gaming-hub.com)

本番 AWS EC2（nginx + Docker）へデプロイします。

### 自動デプロイ（GitHub Actions）

`master` ブランチへ push すると [Deploy to AWS](.github/workflows/deploy-aws.yml) が EC2 へ rsync + `docker compose up -d` します。手動実行は **Actions → Deploy to AWS → Run workflow** でも可能です。

**初回セットアップ（ローカル）**

```bash
brew install gh          # 未インストール時
gh auth login            # GitHub にログイン
./scripts/setup-github-actions.sh
```

スクリプトは Secrets / Variables / `production` 環境を登録します。SSH 鍵はリポジトリ直下の `private-key.pem` を使います（別パスなら `DEPLOY_SSH_KEY_FILE=~/.ssh/your_key ./scripts/setup-github-actions.sh`）。

**GitHub リポジトリ設定（手動でも可: Settings → Secrets and variables → Actions）**

| 種類 | 名前 | 値 |
|------|------|-----|
| Secret | `DEPLOY_HOST` | `shinichiy-gaming-hub.com` |
| Secret | `DEPLOY_USER` | `ubuntu`（SSH ユーザー） |
| Secret | `DEPLOY_SSH_KEY` | EC2 用 SSH **秘密鍵**の全文 |
| Variable | `DEPLOY_PATH` | `/opt/gaming-hub`（任意） |

`production` 環境を作成すると、デプロイ前に GitHub の承認ゲートを挟めます（Settings → Environments → production）。

EC2 の `~/.ssh/authorized_keys` に、上記秘密鍵と対になる**公開鍵**が登録されている必要があります。

### 手動デプロイ（ローカル）

```bash
DEPLOY_USER=ubuntu ./scripts/deploy.sh
```

### 初回のみ（サーバー上）

```bash
# ローカルからファイルをサーバーへ（SSH ユーザー名を指定）
DEPLOY_USER=ubuntu ./scripts/deploy.sh

# サーバーに SSH して初期セットアップ（Docker / nginx / Let's Encrypt）
ssh ubuntu@shinichiy-gaming-hub.com
sudo CERTBOT_EMAIL=you@example.com bash /opt/gaming-hub/scripts/server-bootstrap.sh
```

`.env` を編集（パスワード・API キー）:

```bash
sudo nano /opt/gaming-hub/.env
cd /opt/gaming-hub && docker compose -f docker-compose.prod.yml up -d
```

### 2回目以降

- **自動**: `master` へ merge / push
- **手動**: `DEPLOY_USER=ubuntu ./scripts/deploy.sh`

### WordPress 初回

1. https://shinichiy-gaming-hub.com/ でインストール
2. **設定 → 一般** — URL が `https://shinichiy-gaming-hub.com` であることを確認
3. **外観 → テーマ** — Gaming Hub を有効化
4. Tesla Redirect URI: `https://shinichiy-gaming-hub.com/wp-json/gaming-hub/v1/tesla/oauth/callback`

### Tesla 公開鍵

```bash
# サーバー上
cp public-key.pem /opt/gaming-hub/tesla/public-key.pem
curl -sI https://shinichiy-gaming-hub.com/.well-known/appspecific/com.tesla.3p.public-key.pem
```

## Commands

```bash
docker compose up -d       # Start
docker compose down        # Stop
docker compose down -v     # Stop and remove data
docker compose logs -f     # View logs

# EcoFlow React (theme directory)
cd wp-content/themes/gaming-hub
npm install              # First time
npm run dev:ecoflow      # Watch mode
npm run build:ecoflow    # Production build
```

## License

GPL v2 or later (WordPress theme standard)
