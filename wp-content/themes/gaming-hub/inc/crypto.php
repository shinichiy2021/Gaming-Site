<?php
/**
 * Crypto portfolio — WordPress tag redirects to the Next.js app (hidden from nav).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_CRYPTO_TAG_SLUG', 'crypto' );

/**
 * Register the crypto tag (direct URL entry → Next.js redirect).
 */
function gaming_hub_setup_crypto_tag() {
	if ( get_option( 'gaming_hub_crypto_tag_created' ) ) {
		if ( term_exists( GAMING_HUB_CRYPTO_TAG_SLUG, 'post_tag' ) ) {
			return;
		}
		delete_option( 'gaming_hub_crypto_tag_created' );
	}

	if ( ! term_exists( GAMING_HUB_CRYPTO_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'Crypto',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_CRYPTO_TAG_SLUG,
				'description' => __( 'Cryptocurrency portfolio balances', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_crypto_tag_created', 1 );
}
add_action( 'init', 'gaming_hub_setup_crypto_tag' );

/**
 * Next.js crypto app base URL.
 */
function gaming_hub_crypto_app_url() {
	$candidates = array(
		getenv( 'CRYPTO_APP_URL' ),
		isset( $_ENV['CRYPTO_APP_URL'] ) ? $_ENV['CRYPTO_APP_URL'] : null,
		isset( $_SERVER['CRYPTO_APP_URL'] ) ? $_SERVER['CRYPTO_APP_URL'] : null,
	);

	foreach ( $candidates as $from_env ) {
		if ( is_string( $from_env ) && '' !== $from_env ) {
			return untrailingslashit( $from_env );
		}
	}

	if ( defined( 'CRYPTO_APP_URL' ) && is_string( CRYPTO_APP_URL ) && '' !== CRYPTO_APP_URL ) {
		return untrailingslashit( CRYPTO_APP_URL );
	}

	$host = '';
	if ( ! empty( $_SERVER['HTTP_HOST'] ) ) {
		$host = strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) );
		$host = preg_replace( '/:\d+$/', '', $host );
	}

	if ( $host && ! in_array( $host, array( 'localhost', '127.0.0.1' ), true ) ) {
		$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
			|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );
		return ( $https ? 'https://' : 'http://' ) . $host . ':3002';
	}

	return 'http://localhost:3002';
}

/**
 * Allow redirects to the Next.js crypto host/port.
 *
 * @param array<int, string> $hosts Allowed hosts.
 * @return array<int, string>
 */
function gaming_hub_crypto_allowed_redirect_hosts( $hosts ) {
	$host = wp_parse_url( gaming_hub_crypto_app_url(), PHP_URL_HOST );
	if ( is_string( $host ) && '' !== $host ) {
		$hosts[] = $host;
	}
	return $hosts;
}
add_filter( 'allowed_redirect_hosts', 'gaming_hub_crypto_allowed_redirect_hosts' );

/**
 * Crypto tag / Next app URL (not linked in UI).
 *
 * @param array<string, mixed> $query Optional query args.
 */
function gaming_hub_crypto_url( $query = array() ) {
	$url = gaming_hub_crypto_app_url() . '/';
	return empty( $query ) ? $url : add_query_arg( $query, $url );
}

/**
 * Whether the current request is the crypto tag screen.
 */
function gaming_hub_is_crypto_page() {
	return is_tag( GAMING_HUB_CRYPTO_TAG_SLUG );
}

/**
 * Send /tag/crypto/ visitors to the Next.js portfolio app.
 */
function gaming_hub_crypto_redirect_to_app() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_customize_preview() ) {
		return;
	}

	if ( ! gaming_hub_is_crypto_page() ) {
		return;
	}

	wp_safe_redirect( gaming_hub_crypto_url(), 302 );
	exit;
}
add_action( 'template_redirect', 'gaming_hub_crypto_redirect_to_app', 5 );

/**
 * Demo portfolio payload (optional REST for the Next app or tools).
 *
 * @return array<string, mixed>
 */
function gaming_hub_crypto_portfolio_payload() {
	$holdings = array(
		array(
			'symbol'     => 'BTC',
			'name'       => 'Bitcoin',
			'amount'     => 0.4821,
			'price_usd'  => 97540.0,
			'change_24h' => 1.84,
			'color'      => '#f7931a',
		),
		array(
			'symbol'     => 'ETH',
			'name'       => 'Ethereum',
			'amount'     => 4.215,
			'price_usd'  => 3420.5,
			'change_24h' => -0.62,
			'color'      => '#627eea',
		),
		array(
			'symbol'     => 'SOL',
			'name'       => 'Solana',
			'amount'     => 62.4,
			'price_usd'  => 178.3,
			'change_24h' => 3.41,
			'color'      => '#14f195',
		),
		array(
			'symbol'     => 'XRP',
			'name'       => 'XRP',
			'amount'     => 1850.0,
			'price_usd'  => 2.48,
			'change_24h' => 0.95,
			'color'      => '#23292f',
		),
		array(
			'symbol'     => 'USDT',
			'name'       => 'Tether',
			'amount'     => 1250.0,
			'price_usd'  => 1.0,
			'change_24h' => 0.01,
			'color'      => '#26a17b',
		),
	);

	$usd_jpy   = 148.5;
	$total_usd = 0.0;
	$prev_usd  = 0.0;

	foreach ( $holdings as &$row ) {
		$value_usd        = (float) $row['amount'] * (float) $row['price_usd'];
		$change           = (float) $row['change_24h'];
		$prev             = 0.0 !== ( 1 + $change / 100 ) ? $value_usd / ( 1 + $change / 100 ) : $value_usd;
		$row['value_usd'] = round( $value_usd, 2 );
		$row['value_jpy'] = (int) round( $value_usd * $usd_jpy );
		$row['weight']    = 0;
		$total_usd       += $value_usd;
		$prev_usd        += $prev;
	}
	unset( $row );

	foreach ( $holdings as &$row ) {
		$row['weight'] = $total_usd > 0 ? round( ( $row['value_usd'] / $total_usd ) * 100, 1 ) : 0;
	}
	unset( $row );

	$change_usd = $total_usd - $prev_usd;
	$change_pct = $prev_usd > 0 ? ( $change_usd / $prev_usd ) * 100 : 0;

	return array(
		'currency'       => 'JPY',
		'usd_jpy'        => $usd_jpy,
		'total_usd'      => round( $total_usd, 2 ),
		'total_jpy'      => (int) round( $total_usd * $usd_jpy ),
		'change_24h_usd' => round( $change_usd, 2 ),
		'change_24h_jpy' => (int) round( $change_usd * $usd_jpy ),
		'change_24h_pct' => round( $change_pct, 2 ),
		'updated_at'     => gmdate( 'c' ),
		'demo'           => true,
		'holdings'       => $holdings,
	);
}

/**
 * REST: crypto portfolio snapshot.
 */
function gaming_hub_crypto_register_rest() {
	register_rest_route(
		'gaming-hub/v1',
		'/crypto/portfolio',
		array(
			'methods'             => 'GET',
			'callback'            => static function () {
				return rest_ensure_response( gaming_hub_crypto_portfolio_payload() );
			},
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_crypto_register_rest' );
