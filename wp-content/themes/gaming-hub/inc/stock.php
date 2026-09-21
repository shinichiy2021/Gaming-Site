<?php
/**
 * Stock portfolio — WordPress tag redirects to the Next.js /stock app (hidden from nav).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_STOCK_TAG_SLUG', 'stock' );

/**
 * Register the stock tag (direct URL entry → Next.js redirect).
 */
function gaming_hub_setup_stock_tag() {
	if ( get_option( 'gaming_hub_stock_tag_created' ) ) {
		if ( term_exists( GAMING_HUB_STOCK_TAG_SLUG, 'post_tag' ) ) {
			return;
		}
		delete_option( 'gaming_hub_stock_tag_created' );
	}

	if ( ! term_exists( GAMING_HUB_STOCK_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'Stock',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_STOCK_TAG_SLUG,
				'description' => __( 'Domestic stock portfolio (SBI CSV)', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_stock_tag_created', 1 );
}
add_action( 'init', 'gaming_hub_setup_stock_tag' );

/**
 * Stock app URL (Next.js crypto-app /stock route).
 */
function gaming_hub_stock_url( $query = array() ) {
	$url = gaming_hub_crypto_app_url() . '/stock';
	return empty( $query ) ? $url : add_query_arg( $query, $url );
}

/**
 * Whether the current request is the stock tag screen.
 */
function gaming_hub_is_stock_page() {
	return is_tag( GAMING_HUB_STOCK_TAG_SLUG );
}

/**
 * Send /tag/stock/ visitors to the Next.js stock dashboard.
 */
function gaming_hub_stock_redirect_to_app() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_customize_preview() ) {
		return;
	}

	if ( ! gaming_hub_is_stock_page() ) {
		return;
	}

	wp_safe_redirect( gaming_hub_stock_url(), 302 );
	exit;
}
add_action( 'template_redirect', 'gaming_hub_stock_redirect_to_app', 5 );
