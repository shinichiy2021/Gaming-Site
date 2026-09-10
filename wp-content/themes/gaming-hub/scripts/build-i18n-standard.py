#!/usr/bin/env python3
"""Migrate gaming-hub to English msgids + WordPress ja.po / ja.mo + i18n-ja.php for JS."""
from __future__ import annotations

import argparse
import re
import subprocess
import textwrap
from datetime import datetime, timezone
from pathlib import Path

ROOT = Path(__file__).resolve().parents[1]
I18N_EN = ROOT / "inc" / "i18n-en.php"
LANG_DIR = ROOT / "languages"
PO_FILE = LANG_DIR / "gaming-hub-ja.po"
MO_FILE = LANG_DIR / "ja.mo"
JS_MAP = ROOT / "inc" / "i18n-ja.php"
DB_EN_MAP = ROOT / "inc" / "i18n-db-en.php"

TRANS_FUNC = r"(?:__|_e|esc_html__|esc_attr__|esc_html_e|esc_attr_e)"
TRANS_RE = re.compile(
    TRANS_FUNC + r"\(\s*'((?:\\'|[^'])*)'\s*,\s*'gaming-hub'\s*\)",
    re.MULTILINE,
)

# Japanese strings added after i18n-en.php was last synced.
JA_SUPPLEMENT: dict[str, str] = {
    "%1$s年%2$s月": "%1$s-%2$s",
    "200V 普通充電": "200V AC charging",
    "A8・Amazon・メーカー公式アフィのURLを貼ると、EcoFlow / Tesla の「うちの実測構成」と公式ボタンに反映されます。空欄は公式直リンクのままです。": "Paste A8, Amazon, or official affiliate URLs to update EcoFlow / Tesla kit blocks. Empty fields keep the default official link.",
    "Affiliate / 実測キット": "Affiliate / measured kit",
    "DELTA 3 1500 Amazonアフィ URL（任意）": "DELTA 3 1500 Amazon affiliate URL (optional)",
    "DELTA 3 1500 URL（公式 or アフィ）": "DELTA 3 1500 URL (official or affiliate)",
    "DELTA Pro 3 Amazonアフィ URL（任意）": "DELTA Pro 3 Amazon affiliate URL (optional)",
    "DELTA Pro 3 URL（公式 or アフィ）": "DELTA Pro 3 URL (official or affiliate)",
    "EcoFlow A8バナー（300×250）を表示": "Show EcoFlow A8 banners (300×250)",
    "EcoFlow 公式トップ URL": "EcoFlow official top URL",
    "EcoFlow 公式ブログ URL": "EcoFlow official blog URL",
    'EcoFlow「うちの実測構成」を表示': "Show EcoFlow measured kit block",
    "GPS 誤差を考慮し 300〜500m 推奨。デフォルト 400m。": "Allow 300–500 m for GPS error. Default 400 m.",
    "Model 3 URL（公式 or A8）": "Model 3 URL (official or A8)",
    "Model 3 関連 Amazonアフィ URL（任意）": "Model 3 Amazon affiliate URL (optional)",
    "Pokémon GO 公式": "Pokémon GO official",
    "Pokémon GO 公式ニュース": "Pokémon GO official news",
    "Tesla Fleet API 実データ": "Live Tesla Fleet API data",
    "Tesla 充電ページ URL": "Tesla charging page URL",
    "Tesla 公式トップ URL": "Tesla official top URL",
    'Tesla「うちの実測構成」を表示': "Show Tesla measured kit block",
    "e Vitara × ニチコン V2H 系統図": "e Vitara × Nichicon V2H system diagram",
    "vehicle_location スコープが必要です。": "The vehicle_location scope is required.",
    "ソーラーパネル URL": "Solar panel URL",
    "今日 電気代": "Today's electricity cost",
    "充電関連 Amazonアフィ URL（任意）": "Charging Amazon affiliate URL (optional)",
    "参加が多すぎます。少し待ってください": "Too many joins. Please wait a moment.",
    "土": "Sat",
    "日": "Sun",
    "月": "Mon",
    "木": "Thu",
    "水": "Wed",
    "火": "Tue",
    "自宅 半径（m）": "Home radius (m)",
    "自宅 経度（AI PLAN ジオフェンス）": "Home longitude (AI PLAN geofence)",
    "自宅 緯度（AI PLAN ジオフェンス）": "Home latitude (AI PLAN geofence)",
    "車はスリープ中です。起こしてからもう一度押してください。": "The car is asleep. Wake it, then try again.",
    "金": "Fri",
    "黄棒: Pro 残量W · 橙棒: 1500 残量W · 棒の高さ: 合算容量に対する割合 · 橙の帯: 発電見込み Pro 800W + 1500 500W · 青緑線: LOOOP 請求単価": "Yellow: Pro watts · Orange: 1500 watts · Bar height: share of combined capacity · Orange band: forecast solar Pro 800W + 1500 500W · Teal line: LOOOP billed rate",
    "黄棒: Pro 残量W · 橙棒: 1500 残量W · 棒の高さ: 合算容量に対する割合 · 金の帯: グリッド充電（計画）· 橙の帯: 発電見込み Pro 800W + 1500 500W · 朱橙線: AC出力見込み · 青緑線: 請求単価": "Yellow: Pro watts · Orange: 1500 watts · Bar height: share of combined capacity · Gold band: planned grid charge · Orange band: forecast solar Pro 800W + 1500 500W · Orange line: forecast AC load · Teal line: billed rate",
}

# English msgids that were already in PHP without a Japanese source string.
EN_ONLY_JA: dict[str, str] = {
    "%": "%",
    "%s out of 5 stars": "%s / 5 つ星",
    "AI PLAN": "AIプラン",
    "API Region": "API リージョン",
    "Access Key": "アクセスキー",
    "Add widgets here.": "ウィジェットをここに追加",
    "All rights reserved.": "All rights reserved.",
    "App Login Email (Delta 3)": "アプリログインメール (Delta 3)",
    "App Login Password (Delta 3)": "アプリログインパスワード (Delta 3)",
    "BUY": "買電",
    "Client ID": "Client ID",
    "Client Secret": "Client Secret",
    "Community Day": "Community Day",
    "DC 12V": "DC 12V",
    "DRIVING LOG": "DRIVING LOG",
    "Delta 3 1500": "DELTA 3 1500",
    "Delta Pro 3": "DELTA Pro 3",
    "Device Serial Number (Delta Pro 3)": "シリアル番号 (DELTA Pro 3)",
    "Device Serial Number 2 (Delta 3 1500)": "シリアル番号 2 (DELTA 3 1500)",
    "EcoFlow": "EcoFlow",
    "EcoFlow API": "EcoFlow API",
    "EcoFlow API error.": "EcoFlow API エラー",
    "EcoFlow Developer Platform": "EcoFlow Developer Platform",
    "EcoFlow Device Status": "EcoFlow 機器ステータス",
    "EcoFlow portable power and solar energy content": "EcoFlow ポータブル電源・ソーラー記事",
    "Every 10 minutes": "10分ごと",
    "Every 5 minutes": "5分ごと",
    "Est. end of day %s": "一日の終わり 見込み %s",
    "Example: MR51ZJ1APH6S0189": "例: MR51ZJ1APH6S0189",
    "Includes planned home charging and today’s expected driving. It can fall below the current SOC.": "自宅の計画充電と、今日の走行見込みを反映した一日の終わりの残量です。走行が多いと、いまの残量より下がることがあります。",
    "Extra Battery 1kW": "Extra Battery 1kW",
    "Footer": "フッター",
    "Footer Menu": "フッターメニュー",
    "Footer widget area.": "フッターウィジェットエリア",
    "GRID": "GRID",
    "Game Info": "ゲーム情報",
    "Genre": "ジャンル",
    "Hero CTA Text": "ヒーロー CTA テキスト",
    "Hero CTA URL": "ヒーロー CTA URL",
    "Hero Section": "ヒーローセクション",
    "Hero Subtitle": "ヒーロー サブタイトル",
    "Hero Title": "ヒーロー タイトル",
    "INPUT": "INPUT",
    "Invalid EcoFlow API response.": "EcoFlow API の応答が不正です",
    "Invalid Tesla Fleet API response.": "Tesla Fleet API の応答が不正です",
    "Invalid Tesla OAuth state.": "Tesla OAuth state が不正です",
    "It seems we can't find what you're looking for.": "お探しのページが見つかりません",
    "KM": "KM",
    "LANCERS": "LANCERS",
    "LOOOP-style electricity price forecast for Chubu area": "中部エリアの LOOOP 風電気料金予測",
    "LOW": "LOW",
    "Low Volt": "Low Volt",
    "Model 3": "Model 3",
    "Model 3 SOC": "Model 3 SOC",
    "Model 3 VIN": "Model 3 VIN",
    "NET": "NET",
    "NOW": "現在",
    "Next": "次へ",
    "No posts found in this archive.": "このアーカイブに記事はありません",
    "No posts found with this tag.": "このタグの記事はありません",
    "Nothing Found": "見つかりませんでした",
    "Open-Meteo response invalid.": "Open-Meteo の応答が不正です",
    "PV": "発電",
    "Platform": "プラットフォーム",
    "Pokémon GO": "Pokémon GO",
    "Pokémon GO Latest News": "Pokémon GO 最新ニュース",
    "Pokémon GO YouTuber Videos": "Pokémon GO YouTuber 動画",
    "Powerwall 3": "Powerwall 3",
    "Powerwall Energy Flow": "Powerwall 電力フロー",
    "Powerwall SOC": "Powerwall SOC",
    "Powerwall Specifications": "Powerwall 仕様",
    "Powerwall URL": "Powerwall URL",
    "Previous": "前へ",
    "Primary Menu": "メインメニュー",
    "Primary Navigation": "メインナビゲーション",
    "Quick Links": "クイックリンク",
    "RATE MAP": "RATE MAP",
    "ROOM": "ROOM",
    "Rating": "評価",
    "Redirect URI: /wp-json/gaming-hub/v1/tesla/oauth/callback": "Redirect URI: /wp-json/gaming-hub/v1/tesla/oauth/callback",
    "Refresh Token": "Refresh Token",
    "SUMMARY": "SUMMARY",
    "Secret Key": "シークレットキー",
    "Sidebar": "サイドバー",
    "Supercharger": "Supercharger",
    "SwitchBot API (UPS Plug)": "SwitchBot API (UPS プラグ)",
    "SwitchBot Plug": "SwitchBot プラグ",
    "Tesla": "Tesla",
    "Tesla API": "Tesla API",
    "Tesla API (Model 3)": "Tesla API (Model 3)",
    "Tesla API is not configured.": "Tesla API が未設定です",
    "Tesla Client ID / Secret are not configured.": "Tesla Client ID / Secret が未設定です",
    "Tesla Fleet API base URL is not configured.": "Tesla Fleet API base URL が未設定です",
    "Tesla Fleet API region could not be detected. Set TESLA_FLEET_API_BASE_URL in .env.": "Tesla Fleet API リージョンを検出できません。.env に TESLA_FLEET_API_BASE_URL を設定してください",
    "Tesla Fleet API region mismatch. Retrying with correct region.": "Tesla Fleet API リージョン不一致。正しいリージョンで再試行します",
    "Tesla Fleet API request failed.": "Tesla Fleet API リクエストに失敗しました",
    "Tesla Powerwall 3": "Tesla Powerwall 3",
    "Tesla Powerwall Latest News": "Powerwall 最新ニュース",
    "Tesla VIN is not configured.": "Tesla VIN が未設定です",
    "Tesla access token is not set.": "Tesla アクセストークンが未設定です",
    "Tesla charging history request failed.": "Tesla 充電履歴の取得に失敗しました",
    "Tesla partner app is not registered for this Fleet API region.": "この Fleet API リージョンに Tesla パートナーアプリが未登録です",
    "Tesla partner domain is empty.": "Tesla パートナードメインが空です",
    "Tesla partner domain is not configured.": "Tesla パートナードメインが未設定です",
    "Tesla public key is not reachable at %s (HTTP %d).": "Tesla 公開鍵に %s から到達できません (HTTP %d)",
    "Tesla token request failed.": "Tesla トークン取得に失敗しました",
    "Tesla vehicle is asleep.": "Tesla 車両はスリープ中です",
    "Toggle menu": "メニューを開く",
    "Token": "トークン",
    "UPS": "UPS",
    "UPS Plug device ID (optional)": "UPS プラグ デバイス ID（任意）",
    "Unknown Tesla charge command.": "不明な Tesla 充電コマンドです",
    "WINDOW": "時間帯",
    "Language": "言語",
}

JS_MSGID_REPLACEMENTS: dict[str, str] = {
    "未取得": "n/a",
    "待機": "Standby",
    "最終値": "last",
    " 円": " yen",
}


def unescape_php(s: str) -> str:
    return s.replace("\\'", "'")


def escape_php(s: str) -> str:
    return s.replace("'", "\\'")


def parse_i18n_en(path: Path) -> dict[str, str]:
    content = path.read_text(encoding="utf-8")
    pairs: dict[str, str] = {}
    for match in re.finditer(r"'((?:\\'|[^'])*)'\s*=>\s*'((?:\\'|[^'])*)'\s*,?", content):
        ja = unescape_php(match.group(1))
        en = unescape_php(match.group(2))
        pairs[ja] = en
    pairs.update(JA_SUPPLEMENT)
    return pairs


def collect_php_msgids(root: Path) -> set[str]:
    msgids: set[str] = set()
    for path in root.rglob("*.php"):
        if "node_modules" in path.parts or "vendor" in path.parts:
            continue
        text = path.read_text(encoding="utf-8", errors="ignore")
        for match in TRANS_RE.finditer(text):
            msgids.add(unescape_php(match.group(1)))
    return msgids


def migrate_php_files(root: Path, ja_to_en: dict[str, str]) -> list[str]:
    warnings: list[str] = []
    skip = {"i18n-en.php", "i18n-ja.php"}
    for path in sorted(root.rglob("*.php")):
        if path.name in skip or "node_modules" in path.parts or "vendor" in path.parts:
            continue
        original = path.read_text(encoding="utf-8")
        changed = False

        def repl(match: re.Match[str]) -> str:
            nonlocal changed
            source = unescape_php(match.group(1))
            if source in ja_to_en:
                changed = True
                func = match.group(0).split("(", 1)[0]
                return f"{func}('{escape_php(ja_to_en[source])}', 'gaming-hub')"
            if re.search(r"[ぁ-んァ-ヶ一-龥]", source):
                warnings.append(f"{path}: untranslated Japanese msgid: {source[:80]}")
            return match.group(0)

        updated = TRANS_RE.sub(repl, original)
        if changed:
            path.write_text(updated, encoding="utf-8")
    return warnings


def migrate_js_sources(root: Path) -> None:
    targets = list((ROOT / "src").rglob("*.js")) + list((ROOT / "src").rglob("*.jsx"))
    for path in targets:
        text = path.read_text(encoding="utf-8")
        new_text = text
        for ja, en in JS_MSGID_REPLACEMENTS.items():
            new_text = new_text.replace(f"gamingHubT( '{ja}' )", f"gamingHubT( '{en}' )")
            new_text = new_text.replace(f"gamingHubT('{ja}')", f"gamingHubT('{en}')")
            new_text = new_text.replace(f"? gamingHubT( '{ja}' )", f"? gamingHubT( '{en}' )")
            new_text = new_text.replace(f": '{ja}'", f": '{en}'")
        if new_text != text:
            path.write_text(new_text, encoding="utf-8")


def po_escape(s: str) -> str:
    return s.replace("\\", "\\\\").replace('"', '\\"').replace("\n", "\\n")


def build_en_to_ja(ja_to_en: dict[str, str], msgids: set[str]) -> dict[str, str]:
    en_to_ja: dict[str, str] = {}
    for ja, en in ja_to_en.items():
        if en not in en_to_ja:
            en_to_ja[en] = ja
    en_to_ja.update(EN_ONLY_JA)
    for msgid in msgids:
        if msgid not in en_to_ja:
            if re.search(r"[ぁ-んァ-ヶ一-龥]", msgid):
                en_to_ja[msgid] = msgid
            else:
                en_to_ja[msgid] = msgid
    return en_to_ja


def write_po(en_to_ja: dict[str, str]) -> None:
    LANG_DIR.mkdir(parents=True, exist_ok=True)
    now = datetime.now(timezone.utc).strftime("%Y-%m-%d %H:%M+0000")
    lines = [
        'msgid ""',
        'msgstr ""',
        f'"Project-Id-Version: Gaming Hub\\n"',
        f'"POT-Creation-Date: {now}\\n"',
        f'"PO-Revision-Date: {now}\\n"',
        '"Language: ja\\n"',
        '"MIME-Version: 1.0\\n"',
        '"Content-Type: text/plain; charset=UTF-8\\n"',
        '"Content-Transfer-Encoding: 8bit\\n"',
        '"Plural-Forms: nplurals=1; plural=0;\\n"',
        "",
    ]
    for msgid in sorted(en_to_ja):
        msgstr = en_to_ja[msgid]
        lines.append(f'msgid "{po_escape(msgid)}"')
        lines.append(f'msgstr "{po_escape(msgstr)}"')
        lines.append("")
    PO_FILE.write_text("\n".join(lines), encoding="utf-8")


def write_js_map(en_to_ja: dict[str, str]) -> None:
    lines = [
        "<?php",
        "/**",
        " * English msgid → Japanese (for gamingHubT in the browser).",
        " * Generated by scripts/build-i18n-standard.py — do not edit by hand.",
        " *",
        " * @package Gaming_Hub",
        " */",
        "",
        "if ( ! defined( 'ABSPATH' ) ) {",
        "\texit;",
        "}",
        "",
        "return array(",
    ]
    for msgid in sorted(en_to_ja):
        ja = en_to_ja[msgid]
        if msgid == ja:
            continue
        lines.append(f"\t'{escape_php(msgid)}' => '{escape_php(ja)}',")
    lines.extend([");", ""])
    JS_MAP.write_text("\n".join(lines), encoding="utf-8")


def write_db_en_map(ja_to_en: dict[str, str]) -> None:
    lines = [
        "<?php",
        "/**",
        " * Japanese DB strings → English (menus, site title/tagline).",
        " * Generated by scripts/build-i18n-standard.py — do not edit by hand.",
        " *",
        " * @package Gaming_Hub",
        " */",
        "",
        "if ( ! defined( 'ABSPATH' ) ) {",
        "\texit;",
        "}",
        "",
        "return array(",
    ]
    for ja in sorted(ja_to_en):
        en = ja_to_en[ja]
        if ja == en:
            continue
        lines.append(f"\t'{escape_php(ja)}' => '{escape_php(en)}',")
    lines.extend([");", ""])
    DB_EN_MAP.write_text("\n".join(lines), encoding="utf-8")


def compile_mo() -> None:
    subprocess.run(["msgfmt", "-o", str(MO_FILE), str(PO_FILE)], check=True)


def main() -> None:
    parser = argparse.ArgumentParser()
    parser.add_argument("--migrate", action="store_true", help="Rewrite PHP msgids to English")
    parser.add_argument("--js", action="store_true", help="Rewrite JS gamingHubT keys to English")
    parser.add_argument("--all", action="store_true", help="migrate + js + po + mo + i18n-ja.php")
    args = parser.parse_args()

    if args.all:
        args.migrate = True
        args.js = True

    ja_to_en = parse_i18n_en(I18N_EN)

    if args.migrate:
        warnings = migrate_php_files(ROOT, ja_to_en)
        for warning in warnings:
            print("WARN", warning)

    if args.js:
        migrate_js_sources(ROOT)

    msgids = collect_php_msgids(ROOT)
    en_to_ja = build_en_to_ja(ja_to_en, msgids)
    write_po(en_to_ja)
    write_js_map(en_to_ja)
    write_db_en_map(ja_to_en)
    compile_mo()
    print(f"Wrote {PO_FILE} ({len(en_to_ja)} strings)")
    print(f"Wrote {MO_FILE}")
    print(f"Wrote {JS_MAP}")
    print(f"Wrote {DB_EN_MAP}")


if __name__ == "__main__":
    main()
