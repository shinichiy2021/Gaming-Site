<?php
/**
 * Pokémon GO special feature pages for large in-game events.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_PGO_TOKUSHUU_PARENT', 'pokemon-go-tokushuu' );
define( 'GAMING_HUB_PGO_TOKUSHUU_SYNC', 'gaming_hub_pgo_tokushuu_sync_v1' );

/**
 * Catalog of large Pokémon GO events that get a tokushuu page.
 *
 * @return array<string, array<string, mixed>>
 */
function gaming_hub_pgo_event_catalog() {
	return array(
		'ultra-unlock-water-festival-2026' => array(
			'slug'         => 'ultra-unlock-water-festival-2026',
			'title'        => 'ウルトラアンロック：ウォーターフェスティバル',
			'kicker'       => '大型イベント特集',
			'theme'        => 'water',
			'icon'         => 'water',
			'featured_dex' => 845,
			'start'        => '2026-08-18 10:00:00',
			'end'          => '2026-08-24 20:00:00',
			'preview_days' => 5,
			'archive_days' => 3,
			'official'     => 'https://pokemongolive.com/ja/events/',
			'lead'         => 'みずタイプ祭り。サシカマスとウッウが初登場し、捕獲XP・ほしのすなは日を追うごとに倍率が上がります。',
			'keywords'     => array( 'Water Festival', 'ウォーター', 'Arrokuda', 'サシカマス', 'Cramorant', 'ウッウ', 'Ultra Unlock' ),
			'today'        => array(
				array( 'icon' => 'xp', 'title' => '歩くなら今', 'text' => 'XP 4倍 · すな 5倍' ),
				array( 'icon' => 'water', 'title' => '初登場を取る', 'text' => 'サシカマスとウッウ' ),
				array( 'icon' => 'pass', 'title' => 'パスを受け取る', 'text' => '期限 8/26 20:00' ),
			),
			'phases'       => array(
				array(
					'icon'  => 'xp',
					'title' => '序盤',
					'when'  => '8月18日 10:00 – 8月20日 10:00',
					'note'  => '捕獲XP 2倍 · ほしのすな 3倍',
				),
				array(
					'icon'  => 'candy',
					'title' => '中盤',
					'when'  => '8月20日 10:00 – 8月22日 10:00',
					'note'  => '捕獲XP 3倍 · ほしのすな 4倍',
				),
				array(
					'icon'  => 'star',
					'title' => '終盤',
					'when'  => '8月22日 10:00 – 8月24日 20:00',
					'note'  => '捕獲XP 4倍 · ほしのすな 5倍',
				),
			),
			'debuts'       => array(
				array( 'name' => 'サシカマス', 'dex' => 846 ),
				array( 'name' => 'カマスジョー', 'dex' => 847 ),
				array( 'name' => 'ウッウ', 'dex' => 845 ),
			),
			'highlights'   => array(
				array( 'icon' => 'water', 'text' => 'ウルトラアンロックのみずタイプ祭り' ),
				array( 'icon' => 'spark', 'text' => 'サシカマス / ウッウが初登場' ),
				array( 'icon' => 'pass', 'text' => 'GOパス：ウォーターフェスティバル' ),
				array( 'icon' => 'raid', 'text' => '22日はメガスターミーと同時開催' ),
			),
			'bonuses'      => array(
				array( 'icon' => 'xp', 'text' => '捕獲XP・すなは終盤が最大' ),
				array( 'icon' => 'shiny', 'text' => '一部ポケモンの色違い率アップ' ),
				array( 'icon' => 'research', 'text' => 'フィールドリサーチでみずタイプ' ),
			),
			'how_to'       => array(
				array( 'icon' => 'research', 'label' => 'サシカマス', 'text' => 'リサーチ、GOパス、レイニールアー' ),
				array( 'icon' => 'egg', 'label' => 'ウッウ', 'text' => '5 km タマゴ、パス、GBL、スナップショット' ),
			),
		),
		'mega-starmie-raid-day-2026'        => array(
			'slug'         => 'mega-starmie-raid-day-2026',
			'title'        => 'メガスターミー スーパーメガレイドデイ',
			'kicker'       => '本日の大型レイド',
			'theme'        => 'raid',
			'icon'         => 'raid',
			'featured_dex' => 121,
			'start'        => '2026-08-22 11:00:00',
			'end'          => '2026-08-22 17:00:00',
			'preview_days' => 3,
			'archive_days' => 2,
			'official'     => 'https://pokemongolive.com/ja/events/',
			'lead'         => '6時間限定。スーパーメガレイドにメガスターミーが登場します。無料パスとリモート上限がいつもより緩い一日です。',
			'keywords'     => array( 'Starmie', 'スターミー', 'Mega', 'メガ', 'Raid Day', 'レイドデイ', 'スーパーメガ' ),
			'today'        => array(
				array( 'icon' => 'map', 'title' => 'ジムを探す', 'text' => '11:00 開始 · 一部ジムのみ' ),
				array( 'icon' => 'raid', 'title' => 'パスを使う', 'text' => '無料 6枚 · チケットなら +8' ),
				array( 'icon' => 'remote', 'title' => '余りはリモート', 'text' => '明日 12:00 まで 20回' ),
			),
			'phases'       => array(
				array(
					'icon'  => 'raid',
					'title' => 'レイド本体',
					'when'  => '8月22日 11:00 – 17:00',
					'note'  => 'スーパーメガレイド（一部ジム）',
				),
				array(
					'icon'  => 'remote',
					'title' => 'リモート上限',
					'when'  => '8月22日 6:00 – 8月23日 12:00',
					'note'  => 'リモートレイド 1日20回まで',
				),
			),
			'debuts'       => array(
				array( 'name' => 'メガスターミー', 'dex' => 121 ),
			),
			'highlights'   => array(
				array( 'icon' => 'spark', 'text' => 'メガスターミー解禁' ),
				array( 'icon' => 'star', 'text' => '捕まえた個体はメガレベル1解放済み' ),
				array( 'icon' => 'shiny', 'text' => '色違いスターミー率アップ' ),
				array( 'icon' => 'pass', 'text' => '無料レイドパス 最大6枚' ),
			),
			'bonuses'      => array(
				array( 'icon' => 'xp', 'text' => 'レイド勝利 XP +5,000' ),
				array( 'icon' => 'candy', 'text' => 'ふしぎなアメXL が出やすい' ),
				array( 'icon' => 'pass', 'text' => 'チケット約700円でパス +8' ),
			),
			'how_to'       => array(
				array( 'icon' => 'map', 'label' => '場所', 'text' => '一部ジムのみ。公式マップで確認' ),
				array( 'icon' => 'pass', 'label' => 'パス', 'text' => '無料6枚。チケットでさらに8枚' ),
			),
		),
		'pokemonxp-worlds-2026'             => array(
			'slug'         => 'pokemonxp-worlds-2026',
			'title'        => 'ポケモンXP & ワールドチャンピオンシップス 2026',
			'kicker'       => '今シーズン最大級',
			'theme'        => 'worlds',
			'icon'         => 'trophy',
			'featured_dex' => 25,
			'start'        => '2026-08-25 10:00:00',
			'end'          => '2026-08-30 20:00:00',
			'preview_days' => 10,
			'archive_days' => 5,
			'official'     => 'https://pokemongolive.com/ja/news/world-championships-event-2026',
			'lead'         => 'ポケモン GO 10周年の「ポケモンXP」から、サンフランシスコの世界大会へ続く6日間。限定ピカチュウ、特別なわざ、GOパス、ライブ配信リサーチがまとめて来ます。',
			'keywords'     => array( 'PokémonXP', 'PokemonXP', 'Worlds', 'World Championship', 'ワールド', 'ポケモンXP', 'WCS' ),
			'today'        => array(
				array( 'icon' => 'evolve', 'title' => '終了前に進化', 'text' => '特別なわざはこの期間だけ' ),
				array( 'icon' => 'battle', 'title' => 'GBL 15セット', 'text' => '毎日上限まで回す' ),
				array( 'icon' => 'pass', 'title' => '29日は上限なし', 'text' => 'GOポイントを貯める日' ),
			),
			'phases'       => array(
				array(
					'icon'  => 'spark',
					'title' => 'ポケモンXP',
					'when'  => '8月25日 10:00 – 8月28日 10:00',
					'note'  => 'コスモッグ衣装のピカチュウ',
				),
				array(
					'icon'  => 'trophy',
					'title' => 'WCS 2026',
					'when'  => '8月28日 10:00 – 8月30日 20:00',
					'note'  => 'WCSピカチュウと大会配信',
				),
			),
			'debuts'       => array(
				array( 'name' => 'ポケモンXP2026ピカチュウ', 'dex' => 25 ),
				array( 'name' => 'WCS 2026 ピカチュウ', 'dex' => 25 ),
			),
			'highlights'   => array(
				array( 'icon' => 'trophy', 'text' => 'サンフランシスコで世界一が決まる' ),
				array( 'icon' => 'battle', 'text' => 'GBL 1日15セット（75バトル）' ),
				array( 'icon' => 'pass', 'text' => 'GOパスが自動付与' ),
				array( 'icon' => 'star', 'text' => '8月29日はポイント上限なし' ),
			),
			'bonuses'      => array(
				array( 'icon' => 'battle', 'text' => 'GBL報酬の個体値が幅広く変化' ),
				array( 'icon' => 'tv', 'text' => 'アリーナとBGMが大会仕様' ),
				array( 'icon' => 'research', 'text' => '配信視聴でタイムチャレンジ' ),
			),
			'wild'         => array(
				array(
					'phase'   => 'ポケモンXP',
					'pokemon' => array(
						array( 'name' => 'ラルトス', 'dex' => 280 ),
						array( 'name' => 'ドンメル', 'dex' => 322 ),
						array( 'name' => 'タマザラシ', 'dex' => 363 ),
						array( 'name' => 'フワンテ', 'dex' => 425 ),
						array( 'name' => 'リグレー', 'dex' => 605 ),
						array( 'name' => 'メッソン', 'dex' => 816 ),
						array( 'name' => 'パモ', 'dex' => 921 ),
					),
					'rare'    => array(
						array( 'name' => 'モノズ', 'dex' => 633 ),
					),
				),
				array(
					'phase'   => 'WCS 2026',
					'pokemon' => array(
						array( 'name' => 'サンド（アローラ）', 'dex' => 10094 ),
						array( 'name' => 'マンキー', 'dex' => 56 ),
						array( 'name' => 'ベロリンガ', 'dex' => 108 ),
						array( 'name' => 'ワニノコ', 'dex' => 158 ),
						array( 'name' => 'ウパー', 'dex' => 194 ),
						array( 'name' => 'タマゲタケ', 'dex' => 590 ),
						array( 'name' => 'ケロマツ', 'dex' => 656 ),
						array( 'name' => 'ニャビー', 'dex' => 725 ),
					),
					'rare'    => array(
						array( 'name' => 'トゲチック', 'dex' => 176 ),
						array( 'name' => 'ダンバル', 'dex' => 374 ),
					),
				),
			),
			'raids'        => array(
				array(
					'phase'   => 'ポケモンXP · 1つ星',
					'pokemon' => array(
						array( 'name' => 'XPピカチュウ', 'dex' => 25 ),
						array( 'name' => 'ベロバー', 'dex' => 859 ),
					),
				),
				array(
					'phase'   => 'WCS 2026 · 1つ星',
					'pokemon' => array(
						array( 'name' => 'XPピカチュウ', 'dex' => 25 ),
						array( 'name' => 'WCSピカチュウ', 'dex' => 25 ),
						array( 'name' => 'ヒトツキ', 'dex' => 679 ),
					),
				),
			),
			'moves'        => array(
				array( 'pokemon' => 'サンドパン（アローラ）', 'move' => 'シャドークロー', 'kind' => 'ノーマル' ),
				array( 'pokemon' => 'オコリザル / コノヨザル', 'move' => 'ふんどのこぶし', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'ベロリンガ / ベロベルト', 'move' => 'のしかかり', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'オーダイル / ゲッコウガ / インテレオン', 'move' => 'ハイドロカノン', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'ヌオー', 'move' => 'アクアテール', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'サーナイト / エルレイド', 'move' => 'シンクロノイズ', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'トドゼルガ', 'move' => 'こなゆき / つららばり', 'kind' => '両方' ),
				array( 'pokemon' => 'メタグロス', 'move' => 'コメットパンチ', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'トゲキッス', 'move' => 'はどうだん', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'サザンドラ', 'move' => 'ぶんまわす', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'ジュナイパー', 'move' => 'ハードプラント', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'ガオガエン', 'move' => 'ブラストバーン', 'kind' => 'スペシャル' ),
				array( 'pokemon' => 'アーマーガア', 'move' => 'エアカッター', 'kind' => 'スペシャル' ),
			),
			'watch'        => array(
				array(
					'icon'  => 'tv',
					'title' => '日本語配信',
					'text'  => '公式YouTube · 29日 0:30 / 30日 0:45 / 31日 0:30',
				),
				array(
					'icon'  => 'research',
					'title' => '視聴リサーチ',
					'text'  => '配信を見て限定ピカチュウ',
				),
				array(
					'icon'  => 'star',
					'title' => 'コード',
					'text'  => '配信内で Tシャツ緑 のコード',
				),
			),
			'how_to'       => array(
				array( 'icon' => 'pass', 'label' => 'GOパス', 'text' => '8/25 10:00 自動付与 · 期限 9/1 19:59' ),
				array( 'icon' => 'star', 'label' => '上限なし', 'text' => '8/29 0:00 – 8/30 19:59' ),
			),
		),
	);
}

/**
 * One event by slug, or null.
 *
 * @param string $slug Event slug.
 * @return array<string, mixed>|null
 */
function gaming_hub_pgo_event( $slug ) {
	$catalog = gaming_hub_pgo_event_catalog();
	$slug    = sanitize_title( $slug );
	if ( '' === $slug || ! isset( $catalog[ $slug ] ) ) {
		return null;
	}

	$event = $catalog[ $slug ];
	$event['start_dt'] = gaming_hub_pgo_parse_local_time( (string) ( $event['start'] ?? '' ) );
	$event['end_dt']   = gaming_hub_pgo_parse_local_time( (string) ( $event['end'] ?? '' ) );
	$event['status']   = gaming_hub_pgo_event_status( $event );
	$event['url']      = gaming_hub_pgo_event_url( $slug );

	return $event;
}

/**
 * Official artwork URL for a Pokédex number (PokeAPI sprites).
 *
 * @param int $dex National dex or form id.
 */
function gaming_hub_pgo_artwork_url( $dex ) {
	$dex = (int) $dex;
	if ( $dex < 1 ) {
		return '';
	}

	return 'https://raw.githubusercontent.com/PokeAPI/sprites/master/sprites/pokemon/other/official-artwork/' . $dex . '.png';
}

/**
 * Normalize a list of Pokémon names or name/dex pairs.
 *
 * @param array<int, mixed> $items Items.
 * @return array<int, array{name: string, dex: int}>
 */
function gaming_hub_pgo_mons( $items ) {
	$out = array();
	foreach ( (array) $items as $item ) {
		if ( is_string( $item ) ) {
			$out[] = array(
				'name' => $item,
				'dex'  => 0,
			);
			continue;
		}
		if ( ! is_array( $item ) ) {
			continue;
		}
		$out[] = array(
			'name' => (string) ( $item['name'] ?? '' ),
			'dex'  => (int) ( $item['dex'] ?? 0 ),
		);
	}

	return $out;
}

/**
 * Inline SVG icon for tokushuu UI.
 *
 * @param string $name  Icon key.
 * @param string $class CSS class.
 */
function gaming_hub_pgo_icon( $name, $class = 'pgo-ico' ) {
	$name = sanitize_key( $name );
	$svgs = array(
		'ball'     => '<circle cx="12" cy="12" r="9"/><path d="M3 12h18"/><circle cx="12" cy="12" r="3"/>',
		'raid'     => '<path d="M12 3 5 6v5c0 5 3 8 7 10 4-2 7-5 7-10V6z"/><path d="M12 8v5M10 11h4"/>',
		'remote'   => '<circle cx="12" cy="18" r="1.5"/><path d="M8 15a6 6 0 0 1 8 0M6 12a9 9 0 0 1 12 0"/>',
		'map'      => '<path d="M4 6l5-2 6 2 5-2v14l-5 2-6-2-5 2z"/><circle cx="12" cy="11" r="2"/>',
		'pass'     => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M7 10h6M7 14h4"/>',
		'water'    => '<path d="M4 14c2-1 3-1 5 0s3 1 5 0 3-1 5 0"/><path d="M4 18c2-1 3-1 5 0s3 1 5 0 3-1 5 0"/><path d="M12 4c2 3 5 6 5 8a5 5 0 1 1-10 0c0-2 3-5 5-8z"/>',
		'trophy'   => '<path d="M8 4h8v3a4 4 0 0 1-8 0z"/><path d="M8 6H5a3 3 0 0 0 3 4M16 6h3a3 3 0 0 1-3 4"/><path d="M10 14h4v3l-2 3-2-3z"/>',
		'tv'       => '<rect x="3" y="6" width="18" height="12" rx="2"/><path d="M8 21h8M12 18v3"/>',
		'spark'    => '<path d="M12 2v6M12 16v6M2 12h6M16 12h6M5 5l4 4M15 15l4 4M19 5l-4 4M9 15l-4 4"/>',
		'candy'    => '<path d="M10 8 8 4l4 2 4-2-2 4M10 16l-2 4 4-2 4 2-2-4"/><ellipse cx="12" cy="12" rx="5" ry="4"/>',
		'egg'      => '<path d="M12 3c4 0 7 6 7 11a7 7 0 1 1-14 0c0-5 3-11 7-11z"/>',
		'research' => '<rect x="6" y="3" width="12" height="18" rx="2"/><path d="M9 8h6M9 12h6M9 16h4"/>',
		'star'     => '<path d="M12 3 9 9H3l5 4-2 7 6-4 6 4-2-7 5-4h-6z"/>',
		'shiny'    => '<path d="M12 2 13.5 8 20 8 15 12 17 19 12 15 7 19 9 12 4 8h6.5z"/>',
		'evolve'   => '<path d="M12 20V8M7 13l5-5 5 5"/><path d="M6 6h12"/>',
		'battle'   => '<path d="M5 19 12 5l7 14"/><path d="M8 13h8"/>',
		'xp'       => '<path d="M4 16V8l4 8 4-8v8M14 8h6M17 8v8"/>',
		'clock'    => '<circle cx="12" cy="12" r="9"/><path d="M12 7v6l4 2"/>',
		'check'    => '<circle cx="12" cy="12" r="9"/><path d="m8 12 3 3 5-6"/>',
	);
	$body = isset( $svgs[ $name ] ) ? $svgs[ $name ] : $svgs['ball'];

	printf(
		'<svg class="%1$s" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">%2$s</svg>',
		esc_attr( $class ),
		$body
	);
}

/**
 * Render a Pokémon artwork tile.
 *
 * @param array{name?: string, dex?: int}|string $mon Pokémon.
 * @param string                                 $class CSS class.
 */
function gaming_hub_render_pgo_mon( $mon, $class = 'pgo-mon' ) {
	$list = gaming_hub_pgo_mons( array( $mon ) );
	$mon  = $list[0] ?? array( 'name' => '', 'dex' => 0 );
	$url  = gaming_hub_pgo_artwork_url( (int) $mon['dex'] );
	?>
	<figure class="<?php echo esc_attr( $class ); ?>">
		<?php if ( $url ) : ?>
			<img src="<?php echo esc_url( $url ); ?>" alt="<?php echo esc_attr( $mon['name'] ); ?>" loading="lazy" decoding="async" width="180" height="180" onerror="this.remove()" />
		<?php else : ?>
			<span class="pgo-mon-fallback"><?php gaming_hub_pgo_icon( 'ball' ); ?></span>
		<?php endif; ?>
		<figcaption><?php echo esc_html( $mon['name'] ); ?></figcaption>
	</figure>
	<?php
}

/**
 * Render a row of Pokémon tiles.
 *
 * @param array<int, mixed> $items Mons.
 * @param string            $class CSS class.
 */
function gaming_hub_render_pgo_mon_row( $items, $class = 'pgo-mon-row' ) {
	$mons = gaming_hub_pgo_mons( $items );
	if ( empty( $mons ) ) {
		return;
	}
	echo '<div class="' . esc_attr( $class ) . '">';
	foreach ( $mons as $mon ) {
		gaming_hub_render_pgo_mon( $mon );
	}
	echo '</div>';
}

/**
 * Parse a local datetime string in the site timezone.
 *
 * @param string $value Y-m-d H:i:s.
 * @return DateTimeImmutable|null
 */
function gaming_hub_pgo_parse_local_time( $value ) {
	if ( '' === $value ) {
		return null;
	}

	try {
		return new DateTimeImmutable( $value, wp_timezone() );
	} catch ( Exception $e ) {
		return null;
	}
}

/**
 * Event lifecycle status.
 *
 * @param array<string, mixed> $event Event.
 * @return string live|today|soon|upcoming|ended
 */
function gaming_hub_pgo_event_status( array $event ) {
	$start = $event['start_dt'] ?? null;
	$end   = $event['end_dt'] ?? null;
	if ( ! $start instanceof DateTimeInterface || ! $end instanceof DateTimeInterface ) {
		return 'upcoming';
	}

	$now = new DateTimeImmutable( 'now', wp_timezone() );
	if ( $now > $end ) {
		return 'ended';
	}
	if ( $now >= $start ) {
		return 'live';
	}

	$today = $now->format( 'Y-m-d' );
	if ( $today === $start->format( 'Y-m-d' ) ) {
		return 'today';
	}

	$preview = max( 0, (int) ( $event['preview_days'] ?? 7 ) );
	if ( $start <= $now->modify( '+' . $preview . ' days' ) ) {
		return 'soon';
	}

	return 'upcoming';
}

/**
 * Human status label.
 *
 * @param string $status Status key.
 */
function gaming_hub_pgo_event_status_label( $status ) {
	$map = array(
		'live'     => __( '開催中', 'gaming-hub' ),
		'today'    => __( '本日開催', 'gaming-hub' ),
		'soon'     => __( 'まもなく', 'gaming-hub' ),
		'upcoming' => __( '開催予定', 'gaming-hub' ),
		'ended'    => __( '終了', 'gaming-hub' ),
	);

	return isset( $map[ $status ] ) ? $map[ $status ] : $map['upcoming'];
}

/**
 * Format a start–end range in Japanese-friendly local time.
 *
 * @param DateTimeInterface|null $start Start.
 * @param DateTimeInterface|null $end   End.
 */
function gaming_hub_pgo_format_range( $start, $end ) {
	if ( ! $start instanceof DateTimeInterface || ! $end instanceof DateTimeInterface ) {
		return '';
	}

	$same_day = $start->format( 'Y-m-d' ) === $end->format( 'Y-m-d' );
	$from     = wp_date( 'n月j日 G:i', $start->getTimestamp() );
	$to       = $same_day
		? wp_date( 'G:i', $end->getTimestamp() )
		: wp_date( 'n月j日 G:i', $end->getTimestamp() );

	return $from . ' – ' . $to;
}

/**
 * Events to show on the hub banner (live / today / soon).
 *
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_pgo_hub_events() {
	$out = array();
	foreach ( array_keys( gaming_hub_pgo_event_catalog() ) as $slug ) {
		$event = gaming_hub_pgo_event( $slug );
		if ( ! $event ) {
			continue;
		}
		if ( in_array( $event['status'], array( 'live', 'today', 'soon' ), true ) ) {
			$out[] = $event;
		}
	}

	usort( $out, 'gaming_hub_pgo_sort_featured' );
	return $out;
}

/**
 * Events for the tokushuu index.
 *
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_pgo_index_events() {
	$now = new DateTimeImmutable( 'now', wp_timezone() );
	$out = array();

	foreach ( array_keys( gaming_hub_pgo_event_catalog() ) as $slug ) {
		$event = gaming_hub_pgo_event( $slug );
		if ( ! $event || ! ( $event['end_dt'] instanceof DateTimeInterface ) ) {
			continue;
		}

		$archive = max( 0, (int) ( $event['archive_days'] ?? 3 ) );
		$keep    = $event['end_dt']->modify( '+' . $archive . ' days' );
		if ( $now > $keep ) {
			continue;
		}

		$out[] = $event;
	}

	usort( $out, 'gaming_hub_pgo_sort_featured' );
	return $out;
}

/**
 * Sort featured events: today's timed event, then live, then soonest start.
 *
 * @param array<string, mixed> $a Event.
 * @param array<string, mixed> $b Event.
 */
function gaming_hub_pgo_sort_featured( $a, $b ) {
	$rank = static function ( $event ) {
		$status  = (string) ( $event['status'] ?? '' );
		$today   = wp_date( 'Y-m-d' );
		$ends    = ( $event['end_dt'] instanceof DateTimeInterface ) ? $event['end_dt']->format( 'Y-m-d' ) : '';
		if ( 'today' === $status || ( 'live' === $status && $ends === $today ) ) {
			return 0;
		}
		if ( 'live' === $status ) {
			return 1;
		}
		if ( 'soon' === $status ) {
			return 2;
		}
		if ( 'upcoming' === $status ) {
			return 3;
		}
		return 4;
	};

	$cmp = $rank( $a ) - $rank( $b );
	if ( 0 !== $cmp ) {
		return $cmp;
	}

	$a_ts = ( $a['start_dt'] instanceof DateTimeInterface ) ? $a['start_dt']->getTimestamp() : 0;
	$b_ts = ( $b['start_dt'] instanceof DateTimeInterface ) ? $b['start_dt']->getTimestamp() : 0;
	return $a_ts <=> $b_ts;
}

/**
 * Tokushuu index URL.
 */
function gaming_hub_pgo_tokushuu_url() {
	$page = get_page_by_path( GAMING_HUB_PGO_TOKUSHUU_PARENT );
	if ( $page ) {
		return get_permalink( $page );
	}

	return home_url( '/' . GAMING_HUB_PGO_TOKUSHUU_PARENT . '/' );
}

/**
 * Single event tokushuu URL.
 *
 * @param string $slug Event slug.
 */
function gaming_hub_pgo_event_url( $slug ) {
	$slug = sanitize_title( $slug );
	$page = get_page_by_path( GAMING_HUB_PGO_TOKUSHUU_PARENT . '/' . $slug );
	if ( $page ) {
		return get_permalink( $page );
	}

	return home_url( '/' . GAMING_HUB_PGO_TOKUSHUU_PARENT . '/' . $slug . '/' );
}

/**
 * Related RSS items for an event.
 *
 * @param array<string, mixed> $event Event.
 * @param int                  $limit Max items.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_pgo_event_related_news( array $event, $limit = 6 ) {
	if ( ! function_exists( 'gaming_hub_get_pokemon_go_news' ) ) {
		return array();
	}

	$needles = array_map( 'strtolower', (array) ( $event['keywords'] ?? array() ) );
	$needles = array_filter( $needles );
	if ( empty( $needles ) ) {
		return array();
	}

	$out = array();
	foreach ( gaming_hub_get_pokemon_go_news( 20 ) as $item ) {
		$hay = strtolower( (string) ( $item['title'] ?? '' ) . ' ' . (string) ( $item['excerpt'] ?? '' ) );
		foreach ( $needles as $needle ) {
			if ( '' !== $needle && false !== strpos( $hay, $needle ) ) {
				$out[] = $item;
				break;
			}
		}
		if ( count( $out ) >= $limit ) {
			break;
		}
	}

	return $out;
}

/**
 * Create parent + child tokushuu pages when the catalog changes.
 */
function gaming_hub_pgo_tokushuu_sync_pages() {
	$catalog = gaming_hub_pgo_event_catalog();
	$hash    = md5(
		wp_json_encode(
			array_map(
				static function ( $event ) {
					return array(
						'slug'  => $event['slug'] ?? '',
						'title' => $event['title'] ?? '',
					);
				},
				$catalog
			)
		)
	);
	if ( get_option( GAMING_HUB_PGO_TOKUSHUU_SYNC ) === $hash ) {
		$parent = get_page_by_path( GAMING_HUB_PGO_TOKUSHUU_PARENT );
		if ( $parent ) {
			return;
		}
	}

	$parent = get_page_by_path( GAMING_HUB_PGO_TOKUSHUU_PARENT );
	if ( ! $parent ) {
		$parent_id = wp_insert_post(
			array(
				'post_title'   => __( 'Pokémon GO 特集', 'gaming-hub' ),
				'post_name'    => GAMING_HUB_PGO_TOKUSHUU_PARENT,
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $parent_id ) || ! $parent_id ) {
			return;
		}
		$parent = get_post( $parent_id );
	}

	if ( ! $parent ) {
		return;
	}

	update_post_meta( $parent->ID, '_wp_page_template', 'page-pokemon-go-tokushuu.php' );

	foreach ( $catalog as $slug => $event ) {
		$path = GAMING_HUB_PGO_TOKUSHUU_PARENT . '/' . $slug;
		$page = get_page_by_path( $path );
		if ( ! $page ) {
			$page_id = wp_insert_post(
				array(
					'post_title'   => (string) ( $event['title'] ?? $slug ),
					'post_name'    => $slug,
					'post_parent'  => (int) $parent->ID,
					'post_status'  => 'publish',
					'post_type'    => 'page',
					'post_content' => '',
				),
				true
			);
			if ( is_wp_error( $page_id ) || ! $page_id ) {
				continue;
			}
			$page = get_post( $page_id );
		}

		if ( ! $page ) {
			continue;
		}

		if ( (string) $page->post_title !== (string) ( $event['title'] ?? '' ) && ! empty( $event['title'] ) ) {
			wp_update_post(
				array(
					'ID'         => $page->ID,
					'post_title' => (string) $event['title'],
				)
			);
		}

		update_post_meta( $page->ID, '_wp_page_template', 'page-pokemon-go-event.php' );
		update_post_meta( $page->ID, '_pgo_event_slug', $slug );
	}

	update_option( GAMING_HUB_PGO_TOKUSHUU_SYNC, $hash, false );
}
add_action( 'init', 'gaming_hub_pgo_tokushuu_sync_pages' );
add_action( 'after_switch_theme', 'gaming_hub_pgo_tokushuu_sync_pages' );
