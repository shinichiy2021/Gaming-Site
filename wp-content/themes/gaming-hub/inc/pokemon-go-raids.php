<?php
/**
 * Pokémon GO raid invite board (trainer-to-trainer, no unofficial game API).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_PGO_RAID_TABLE', 'gaming_hub_pgo_raids' );
define( 'GAMING_HUB_PGO_RAID_DB', 'gaming_hub_pgo_raid_db_v1' );

/**
 * Raid boss picker. Featured first for the current season window.
 *
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_pgo_raid_bosses() {
	return array(
		array( 'key' => 'mega-starmie', 'name' => 'メガスターミー', 'dex' => 121, 'stars' => 6, 'type' => 'super_mega', 'featured' => true ),
		array( 'key' => 'dmax-magikarp', 'name' => 'ダイマックス コイキング', 'dex' => 129, 'stars' => 3, 'type' => 'dmax', 'featured' => true ),
		array( 'key' => 'dmax-hitmontop', 'name' => 'ダイマックス カポエラー', 'dex' => 237, 'stars' => 3, 'type' => 'dmax', 'featured' => true ),
		array( 'key' => 'xp-pikachu', 'name' => 'ポケモンXPピカチュウ', 'dex' => 25, 'stars' => 1, 'type' => 'one', 'featured' => true ),
		array( 'key' => 'impidimp', 'name' => 'ベロバー', 'dex' => 859, 'stars' => 1, 'type' => 'one' ),
		array( 'key' => 'honedge', 'name' => 'ヒトツキ', 'dex' => 679, 'stars' => 1, 'type' => 'one' ),
		array( 'key' => 'mewtwo', 'name' => 'ミュウツー', 'dex' => 150, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'lugia', 'name' => 'ルギア', 'dex' => 249, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'ho-oh', 'name' => 'ホウオウ', 'dex' => 250, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'kyogre', 'name' => 'カイオーガ', 'dex' => 382, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'groudon', 'name' => 'グラードン', 'dex' => 383, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'rayquaza', 'name' => 'レックウザ', 'dex' => 384, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'dialga', 'name' => 'ディアルガ', 'dex' => 483, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'palkia', 'name' => 'パルキア', 'dex' => 484, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'giratina', 'name' => 'ギラティナ', 'dex' => 487, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'zacian', 'name' => 'ザシアン', 'dex' => 888, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'zamazenta', 'name' => 'ザマゼンタ', 'dex' => 889, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'eternatus', 'name' => 'ムゲンダイナ', 'dex' => 890, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'koraidon', 'name' => 'コライドン', 'dex' => 1007, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'miraidon', 'name' => 'ミライドン', 'dex' => 1008, 'stars' => 5, 'type' => 'five' ),
		array( 'key' => 'mega-charizard-y', 'name' => 'メガリザードンY', 'dex' => 6, 'stars' => 6, 'type' => 'mega' ),
		array( 'key' => 'mega-gengar', 'name' => 'メガゲンガー', 'dex' => 94, 'stars' => 6, 'type' => 'mega' ),
		array( 'key' => 'mega-lucario', 'name' => 'メガルカリオ', 'dex' => 448, 'stars' => 6, 'type' => 'mega' ),
		array( 'key' => 'gmax-rillaboom', 'name' => 'キョダイゴリラーダー', 'dex' => 812, 'stars' => 6, 'type' => 'gmax' ),
		array( 'key' => 'other', 'name' => 'その他', 'dex' => 0, 'stars' => 5, 'type' => 'five' ),
	);
}

/**
 * Raid type labels and default invite slots.
 *
 * @return array<string, array<string, mixed>>
 */
function gaming_hub_pgo_raid_types() {
	return array(
		'one'        => array( 'label' => __( '1つ星', 'gaming-hub' ), 'slots' => 5 ),
		'three'      => array( 'label' => __( '3つ星', 'gaming-hub' ), 'slots' => 5 ),
		'five'       => array( 'label' => __( '5つ星', 'gaming-hub' ), 'slots' => 5 ),
		'mega'       => array( 'label' => __( 'メガ', 'gaming-hub' ), 'slots' => 5 ),
		'super_mega' => array( 'label' => __( 'スーパーメガ', 'gaming-hub' ), 'slots' => 5 ),
		'dmax'       => array( 'label' => __( 'ダイマックス', 'gaming-hub' ), 'slots' => 3 ),
		'gmax'       => array( 'label' => __( 'キョダイマックス', 'gaming-hub' ), 'slots' => 10 ),
	);
}

/**
 * Raid list / form page URL.
 */
function gaming_hub_pgo_raid_url() {
	$page = get_page_by_path( 'pokemon-go-raid' );
	return $page ? get_permalink( $page ) : home_url( '/pokemon-go-raid/' );
}

/**
 * Ensure the raid table exists.
 */
function gaming_hub_pgo_raid_install_table() {
	if ( get_option( GAMING_HUB_PGO_RAID_DB ) ) {
		return;
	}

	global $wpdb;
	$table   = $wpdb->prefix . GAMING_HUB_PGO_RAID_TABLE;
	$charset = $wpdb->get_charset_collate();
	require_once ABSPATH . 'wp-admin/includes/upgrade.php';
	dbDelta(
		"CREATE TABLE {$table} (
			id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
			public_id varchar(12) NOT NULL,
			host_token varchar(64) NOT NULL,
			trainer_name varchar(32) NOT NULL,
			friend_code varchar(12) NOT NULL,
			boss_key varchar(64) NOT NULL,
			boss_name varchar(80) NOT NULL,
			dex int(10) unsigned NOT NULL DEFAULT 0,
			stars tinyint(3) unsigned NOT NULL DEFAULT 5,
			raid_type varchar(20) NOT NULL,
			slots tinyint(3) unsigned NOT NULL DEFAULT 5,
			note varchar(140) NOT NULL DEFAULT '',
			status varchar(16) NOT NULL DEFAULT 'open',
			joiners longtext NOT NULL,
			created_at datetime NOT NULL,
			expires_at datetime NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY public_id (public_id),
			KEY status_expires (status, expires_at)
		) {$charset};"
	);
	update_option( GAMING_HUB_PGO_RAID_DB, 1, false );
}
add_action( 'init', 'gaming_hub_pgo_raid_install_table', 5 );

/**
 * Create the public raid page.
 */
function gaming_hub_pgo_raid_sync_page() {
	if ( get_option( 'gaming_hub_pgo_raid_page_created' ) ) {
		$page = get_page_by_path( 'pokemon-go-raid' );
		if ( $page ) {
			update_post_meta( $page->ID, '_wp_page_template', 'page-pokemon-go-raids.php' );
			return;
		}
	}

	$page = get_page_by_path( 'pokemon-go-raid' );
	if ( ! $page ) {
		$page_id = wp_insert_post(
			array(
				'post_title'   => __( 'レイド招待掲示板', 'gaming-hub' ),
				'post_name'    => 'pokemon-go-raid',
				'post_status'  => 'publish',
				'post_type'    => 'page',
				'post_content' => '',
			),
			true
		);
		if ( is_wp_error( $page_id ) || ! $page_id ) {
			return;
		}
		$page = get_post( $page_id );
	}

	if ( $page ) {
		update_post_meta( $page->ID, '_wp_page_template', 'page-pokemon-go-raids.php' );
		update_option( 'gaming_hub_pgo_raid_page_created', 1, false );
	}
}
add_action( 'init', 'gaming_hub_pgo_raid_sync_page' );
add_action( 'after_switch_theme', 'gaming_hub_pgo_raid_sync_page' );

/**
 * Expiry timestamp in the site timezone.
 *
 * @param string $expires_at MySQL datetime.
 */
function gaming_hub_pgo_raid_expires_ts( $expires_at ) {
	$dt = date_create( (string) $expires_at, wp_timezone() );
	return $dt ? $dt->getTimestamp() : 0;
}

/**
 * Table name.
 */
function gaming_hub_pgo_raid_table() {
	global $wpdb;
	return $wpdb->prefix . GAMING_HUB_PGO_RAID_TABLE;
}

/**
 * Normalize a 12-digit trainer code.
 *
 * @param string $value Raw code.
 */
function gaming_hub_pgo_raid_normalize_code( $value ) {
	$digits = preg_replace( '/\D+/', '', (string) $value );
	return strlen( $digits ) === 12 ? $digits : '';
}

/**
 * Format a friend code for display.
 *
 * @param string $code 12 digits.
 */
function gaming_hub_pgo_raid_format_code( $code ) {
	$code = gaming_hub_pgo_raid_normalize_code( $code );
	if ( '' === $code ) {
		return '';
	}

	return substr( $code, 0, 4 ) . ' ' . substr( $code, 4, 4 ) . ' ' . substr( $code, 8, 4 );
}

/**
 * Client IP hash for rate limits.
 */
function gaming_hub_pgo_raid_ip_key( $action ) {
	$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? (string) wp_unslash( $_SERVER['REMOTE_ADDR'] ) : '0';
	return 'pgo_raid_' . $action . '_' . md5( $ip );
}

/**
 * Whether this IP may perform an action.
 *
 * @param string $action Action key.
 * @param int    $max    Max hits.
 * @param int    $ttl    Window seconds.
 */
function gaming_hub_pgo_raid_rate_allow( $action, $max, $ttl ) {
	$key   = gaming_hub_pgo_raid_ip_key( $action );
	$hits  = (int) get_transient( $key );
	if ( $hits >= $max ) {
		return false;
	}
	set_transient( $key, $hits + 1, $ttl );
	return true;
}

/**
 * Decode joiner list.
 *
 * @param mixed $raw JSON or array.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_pgo_raid_joiners( $raw ) {
	if ( is_array( $raw ) ) {
		return $raw;
	}
	$list = json_decode( (string) $raw, true );
	return is_array( $list ) ? $list : array();
}

/**
 * Close expired open raids.
 */
function gaming_hub_pgo_raid_expire_open() {
	global $wpdb;
	$wpdb->query(
		$wpdb->prepare(
			"UPDATE %i SET status = 'closed' WHERE status IN ('open','full') AND expires_at < %s",
			gaming_hub_pgo_raid_table(),
			current_time( 'mysql' )
		)
	);
}

/**
 * Public row for the board.
 *
 * @param object $row        DB row.
 * @param bool   $with_host  Include host manage fields.
 * @return array<string, mixed>
 */
function gaming_hub_pgo_raid_payload( $row, $with_host = false ) {
	$joiners = gaming_hub_pgo_raid_joiners( $row->joiners ?? array() );
	$public_joiners = array();
	foreach ( $joiners as $joiner ) {
		$item = array(
			'id'           => (string) ( $joiner['id'] ?? '' ),
			'trainer_name' => (string) ( $joiner['trainer_name'] ?? '' ),
		);
		if ( $with_host ) {
			$item['friend_code'] = gaming_hub_pgo_raid_format_code( (string) ( $joiner['friend_code'] ?? '' ) );
		}
		$public_joiners[] = $item;
	}

	$slots = max( 1, (int) $row->slots );
	$taken = count( $joiners );
	$left  = max( 0, $slots - $taken );
	$types = gaming_hub_pgo_raid_types();
	$type  = (string) $row->raid_type;

	return array(
		'id'           => (string) $row->public_id,
		'trainer_name' => (string) $row->trainer_name,
		'friend_code'  => gaming_hub_pgo_raid_format_code( (string) $row->friend_code ),
		'boss_key'     => (string) $row->boss_key,
		'boss_name'    => (string) $row->boss_name,
		'dex'          => (int) $row->dex,
		'art'          => function_exists( 'gaming_hub_pgo_artwork_url' ) ? gaming_hub_pgo_artwork_url( (int) $row->dex ) : '',
		'stars'        => (int) $row->stars,
		'raid_type'    => $type,
		'type_label'   => isset( $types[ $type ] ) ? $types[ $type ]['label'] : $type,
		'slots'        => $slots,
		'taken'        => $taken,
		'left'         => $left,
		'note'         => (string) $row->note,
		'status'       => (string) $row->status,
		'expires_at'   => (string) $row->expires_at,
		'expires_ts'   => gaming_hub_pgo_raid_expires_ts( $row->expires_at ),
		'joiners'      => $public_joiners,
	);
}

/**
 * Fetch one raid by public id.
 *
 * @param string $public_id Public id.
 * @return object|null
 */
function gaming_hub_pgo_raid_get( $public_id ) {
	global $wpdb;
	$row = $wpdb->get_row(
		$wpdb->prepare(
			"SELECT * FROM %i WHERE public_id = %s LIMIT 1",
			gaming_hub_pgo_raid_table(),
			sanitize_key( $public_id )
		)
	);
	return $row ?: null;
}

/**
 * Count open raids.
 */
function gaming_hub_pgo_raid_open_count() {
	gaming_hub_pgo_raid_expire_open();
	global $wpdb;
	return (int) $wpdb->get_var(
		$wpdb->prepare(
			"SELECT COUNT(*) FROM %i WHERE status = 'open' AND expires_at > %s",
			gaming_hub_pgo_raid_table(),
			current_time( 'mysql' )
		)
	);
}

/**
 * REST routes.
 */
function gaming_hub_register_pgo_raid_rest() {
	register_rest_route(
		'gaming-hub/v1',
		'/pgo-raids',
		array(
			array(
				'methods'             => 'GET',
				'callback'            => 'gaming_hub_rest_pgo_raid_list',
				'permission_callback' => '__return_true',
			),
			array(
				'methods'             => 'POST',
				'callback'            => 'gaming_hub_rest_pgo_raid_create',
				'permission_callback' => '__return_true',
			),
		)
	);
	register_rest_route(
		'gaming-hub/v1',
		'/pgo-raids/(?P<id>[a-z0-9]+)',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_pgo_raid_one',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'gaming-hub/v1',
		'/pgo-raids/(?P<id>[a-z0-9]+)/join',
		array(
			'methods'             => 'POST',
			'callback'            => 'gaming_hub_rest_pgo_raid_join',
			'permission_callback' => '__return_true',
		)
	);
	register_rest_route(
		'gaming-hub/v1',
		'/pgo-raids/(?P<id>[a-z0-9]+)/host',
		array(
			'methods'             => 'POST',
			'callback'            => 'gaming_hub_rest_pgo_raid_host',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_pgo_raid_rest' );

/**
 * GET list.
 */
function gaming_hub_rest_pgo_raid_list() {
	gaming_hub_pgo_raid_expire_open();
	global $wpdb;
	$rows = $wpdb->get_results(
		$wpdb->prepare(
			"SELECT * FROM %i WHERE status IN ('open','full','started') AND expires_at > %s ORDER BY created_at DESC LIMIT 40",
			gaming_hub_pgo_raid_table(),
			current_time( 'mysql' )
		)
	);

	$data = array();
	foreach ( (array) $rows as $row ) {
		$data[] = gaming_hub_pgo_raid_payload( $row, false );
	}

	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => $data,
			'count'   => count( $data ),
		),
		200
	);
}

/**
 * GET one raid. Host token reveals joiner codes.
 *
 * @param WP_REST_Request $request Request.
 */
function gaming_hub_rest_pgo_raid_one( WP_REST_Request $request ) {
	$row = gaming_hub_pgo_raid_get( (string) $request['id'] );
	if ( ! $row ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( '募集が見つかりません', 'gaming-hub' ) ), 404 );
	}

	$token = (string) $request->get_param( 'host_token' );
	$is_host = hash_equals( (string) $row->host_token, $token );

	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => gaming_hub_pgo_raid_payload( $row, $is_host ),
			'is_host' => $is_host,
		),
		200
	);
}

/**
 * POST create.
 *
 * @param WP_REST_Request $request Request.
 */
function gaming_hub_rest_pgo_raid_create( WP_REST_Request $request ) {
	if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
		return new WP_REST_Response( array( 'success' => true, 'data' => array() ), 201 );
	}

	$nonce = (string) $request->get_param( 'nonce' );
	if ( ! wp_verify_nonce( $nonce, 'gaming_hub_pgo_raid' ) ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( '更新してやり直してください', 'gaming-hub' ) ), 403 );
	}

	if ( ! gaming_hub_pgo_raid_rate_allow( 'create', 3, 10 * MINUTE_IN_SECONDS ) ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( '投稿が多すぎます。少し待ってください', 'gaming-hub' ) ), 429 );
	}

	$trainer = sanitize_text_field( (string) $request->get_param( 'trainer_name' ) );
	$trainer = mb_substr( $trainer, 0, 20 );
	$code    = gaming_hub_pgo_raid_normalize_code( (string) $request->get_param( 'friend_code' ) );
	$boss_key = sanitize_title( (string) $request->get_param( 'boss_key' ) );
	$custom   = sanitize_text_field( (string) $request->get_param( 'boss_name' ) );
	$mins     = (int) $request->get_param( 'minutes' );
	$note     = sanitize_text_field( (string) $request->get_param( 'note' ) );
	$note     = mb_substr( $note, 0, 80 );

	if ( '' === $trainer || '' === $code ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'トレーナー名とフレンドコードを入力してください', 'gaming-hub' ) ), 400 );
	}

	$boss  = null;
	foreach ( gaming_hub_pgo_raid_bosses() as $item ) {
		if ( $item['key'] === $boss_key ) {
			$boss = $item;
			break;
		}
	}
	if ( ! $boss ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'ボスを選んでください', 'gaming-hub' ) ), 400 );
	}

	$boss_name = $boss['name'];
	if ( 'other' === $boss_key ) {
		$boss_name = $custom !== '' ? mb_substr( $custom, 0, 30 ) : __( 'その他', 'gaming-hub' );
	}

	$types = gaming_hub_pgo_raid_types();
	$type  = (string) $boss['type'];
	$slots = isset( $types[ $type ] ) ? (int) $types[ $type ]['slots'] : 5;
	$asked = (int) $request->get_param( 'slots' );
	if ( $asked >= 1 && $asked <= 10 ) {
		$slots = $asked;
	}

	$mins = min( 45, max( 8, $mins ? $mins : 25 ) );

	global $wpdb;
	$public = strtolower( wp_generate_password( 8, false, false ) );
	$token  = wp_generate_password( 24, false, false );
	$ok     = $wpdb->insert(
		gaming_hub_pgo_raid_table(),
		array(
			'public_id'    => $public,
			'host_token'   => $token,
			'trainer_name' => $trainer,
			'friend_code'  => $code,
			'boss_key'     => $boss_key,
			'boss_name'    => $boss_name,
			'dex'          => (int) $boss['dex'],
			'stars'        => (int) $boss['stars'],
			'raid_type'    => $type,
			'slots'        => $slots,
			'note'         => $note,
			'status'       => 'open',
			'joiners'      => wp_json_encode( array() ),
			'created_at'   => current_time( 'mysql' ),
			'expires_at'   => wp_date( 'Y-m-d H:i:s', time() + ( $mins * MINUTE_IN_SECONDS ) ),
		),
		array( '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%d', '%s', '%s', '%s', '%s', '%s' )
	);

	if ( ! $ok ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( '投稿できませんでした', 'gaming-hub' ) ), 500 );
	}

	$row = gaming_hub_pgo_raid_get( $public );
	return new WP_REST_Response(
		array(
			'success'    => true,
			'data'       => $row ? gaming_hub_pgo_raid_payload( $row, true ) : array(),
			'host_token' => $token,
		),
		201
	);
}

/**
 * POST join.
 *
 * @param WP_REST_Request $request Request.
 */
function gaming_hub_rest_pgo_raid_join( WP_REST_Request $request ) {
	if ( '' !== trim( (string) $request->get_param( 'website' ) ) ) {
		return new WP_REST_Response( array( 'success' => true ), 200 );
	}
	if ( ! wp_verify_nonce( (string) $request->get_param( 'nonce' ), 'gaming_hub_pgo_raid' ) ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( '更新してやり直してください', 'gaming-hub' ) ), 403 );
	}
	if ( ! gaming_hub_pgo_raid_rate_allow( 'join', 8, 10 * MINUTE_IN_SECONDS ) ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( '参加が多すぎます。少し待ってください', 'gaming-hub' ) ), 429 );
	}

	$row = gaming_hub_pgo_raid_get( (string) $request['id'] );
	if ( ! $row || ! in_array( $row->status, array( 'open' ), true ) ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'この募集は締め切られています', 'gaming-hub' ) ), 409 );
	}
	if ( gaming_hub_pgo_raid_expires_ts( $row->expires_at ) <= time() ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'この募集は終了しています', 'gaming-hub' ) ), 409 );
	}

	$trainer = sanitize_text_field( (string) $request->get_param( 'trainer_name' ) );
	$trainer = mb_substr( $trainer, 0, 20 );
	$code    = gaming_hub_pgo_raid_normalize_code( (string) $request->get_param( 'friend_code' ) );
	if ( '' === $trainer || '' === $code ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'トレーナー名とフレンドコードを入力してください', 'gaming-hub' ) ), 400 );
	}

	$joiners = gaming_hub_pgo_raid_joiners( $row->joiners );
	foreach ( $joiners as $joiner ) {
		if ( ( $joiner['friend_code'] ?? '' ) === $code || ( $joiner['trainer_name'] ?? '' ) === $trainer ) {
			return new WP_REST_Response( array( 'success' => false, 'message' => __( 'すでに参加しています', 'gaming-hub' ) ), 409 );
		}
	}
	if ( $code === (string) $row->friend_code ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'ホストと同じコードです', 'gaming-hub' ) ), 400 );
	}
	if ( count( $joiners ) >= (int) $row->slots ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( '満員です', 'gaming-hub' ) ), 409 );
	}

	$joiners[] = array(
		'id'           => strtolower( wp_generate_password( 6, false, false ) ),
		'trainer_name' => $trainer,
		'friend_code'  => $code,
		'joined_at'    => wp_date( 'c' ),
	);
	$status = count( $joiners ) >= (int) $row->slots ? 'full' : 'open';

	global $wpdb;
	$wpdb->update(
		gaming_hub_pgo_raid_table(),
		array(
			'joiners' => wp_json_encode( $joiners ),
			'status'  => $status,
		),
		array( 'id' => (int) $row->id ),
		array( '%s', '%s' ),
		array( '%d' )
	);

	$fresh = gaming_hub_pgo_raid_get( (string) $row->public_id );
	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => $fresh ? gaming_hub_pgo_raid_payload( $fresh, false ) : array(),
			'host_code' => gaming_hub_pgo_raid_format_code( (string) $row->friend_code ),
		),
		200
	);
}

/**
 * POST host action: start or close.
 *
 * @param WP_REST_Request $request Request.
 */
function gaming_hub_rest_pgo_raid_host( WP_REST_Request $request ) {
	if ( ! wp_verify_nonce( (string) $request->get_param( 'nonce' ), 'gaming_hub_pgo_raid' ) ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( '更新してやり直してください', 'gaming-hub' ) ), 403 );
	}

	$row = gaming_hub_pgo_raid_get( (string) $request['id'] );
	if ( ! $row || ! hash_equals( (string) $row->host_token, (string) $request->get_param( 'host_token' ) ) ) {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( 'ホスト操作ができません', 'gaming-hub' ) ), 403 );
	}

	$action = sanitize_key( (string) $request->get_param( 'action' ) );
	$status = $row->status;
	if ( 'start' === $action ) {
		$status = 'started';
	} elseif ( 'close' === $action ) {
		$status = 'closed';
	} else {
		return new WP_REST_Response( array( 'success' => false, 'message' => __( '不明な操作です', 'gaming-hub' ) ), 400 );
	}

	global $wpdb;
	$wpdb->update(
		gaming_hub_pgo_raid_table(),
		array( 'status' => $status ),
		array( 'id' => (int) $row->id ),
		array( '%s' ),
		array( '%d' )
	);

	$fresh = gaming_hub_pgo_raid_get( (string) $row->public_id );
	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => $fresh ? gaming_hub_pgo_raid_payload( $fresh, true ) : array(),
		),
		200
	);
}

/**
 * Enqueue raid board assets.
 */
function gaming_hub_pgo_raid_scripts() {
	if ( ! is_page( 'pokemon-go-raid' ) ) {
		return;
	}

	wp_enqueue_script(
		'gaming-hub-pgo-raids',
		get_template_directory_uri() . '/assets/js/pokemon-go-raids.js',
		array( 'gaming-hub-i18n' ),
		GAMING_HUB_VERSION,
		true
	);

	$bosses = array();
	foreach ( gaming_hub_pgo_raid_bosses() as $boss ) {
		$bosses[] = array(
			'key'      => $boss['key'],
			'name'     => $boss['name'],
			'dex'      => $boss['dex'],
			'stars'    => $boss['stars'],
			'type'     => $boss['type'],
			'featured' => ! empty( $boss['featured'] ),
			'art'      => function_exists( 'gaming_hub_pgo_artwork_url' ) ? gaming_hub_pgo_artwork_url( (int) $boss['dex'] ) : '',
			'slots'    => (int) ( gaming_hub_pgo_raid_types()[ $boss['type'] ]['slots'] ?? 5 ),
		);
	}

	wp_localize_script(
		'gaming-hub-pgo-raids',
		'gamingHubPgoRaids',
		array(
			'rest'    => esc_url_raw( rest_url( 'gaming-hub/v1/pgo-raids' ) ),
			'nonce'   => wp_create_nonce( 'gaming_hub_pgo_raid' ),
			'wpNonce' => wp_create_nonce( 'wp_rest' ),
			'bosses'  => $bosses,
			'types'   => gaming_hub_pgo_raid_types(),
			'i18n'    => array(
				'empty'     => __( 'いま募集中のレイドはありません。ホストになって投稿できます。', 'gaming-hub' ),
				'join'      => __( '参加する', 'gaming-hub' ),
				'joined'    => __( '参加しました。すぐフレンド申請してください。', 'gaming-hub' ),
				'copy'      => __( 'コピー', 'gaming-hub' ),
				'copied'    => __( 'コピーしました', 'gaming-hub' ),
				'full'      => __( '満員', 'gaming-hub' ),
				'started'   => __( '招待中', 'gaming-hub' ),
				'closed'    => __( '終了', 'gaming-hub' ),
				'open'      => __( '募集中', 'gaming-hub' ),
				'left'      => __( '残り %s', 'gaming-hub' ),
				'seats'     => __( '%1$s / %2$s 人', 'gaming-hub' ),
				'start'     => __( '招待開始', 'gaming-hub' ),
				'close'     => __( '募集終了', 'gaming-hub' ),
				'copyNames' => __( '名前をコピー', 'gaming-hub' ),
				'copyCodes' => __( 'コードをコピー', 'gaming-hub' ),
				'error'     => __( '通信に失敗しました', 'gaming-hub' ),
				'needBoss'  => __( 'ボスを選んでください', 'gaming-hub' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_pgo_raid_scripts' );
