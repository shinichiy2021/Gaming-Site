<?php
/**
 * All assets — WordPress tag redirects to the Next.js /all app (hidden from nav).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_ALL_TAG_SLUG', 'all' );

/**
 * Register the all-assets tag (direct URL entry → Next.js redirect).
 */
function gaming_hub_setup_all_tag() {
	if ( get_option( 'gaming_hub_all_tag_created' ) ) {
		if ( term_exists( GAMING_HUB_ALL_TAG_SLUG, 'post_tag' ) ) {
			return;
		}
		delete_option( 'gaming_hub_all_tag_created' );
	}

	if ( ! term_exists( GAMING_HUB_ALL_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'All',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_ALL_TAG_SLUG,
				'description' => __( 'Combined crypto + stock risk-asset overview', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_all_tag_created', 1 );
}
add_action( 'init', 'gaming_hub_setup_all_tag' );

/**
 * All-assets app URL (Next.js crypto-app /all route).
 */
function gaming_hub_all_url( $query = array() ) {
	$url = gaming_hub_crypto_app_url() . '/all';
	return empty( $query ) ? $url : add_query_arg( $query, $url );
}

/**
 * Whether the current request is the all-assets tag screen.
 */
function gaming_hub_is_all_page() {
	return is_tag( GAMING_HUB_ALL_TAG_SLUG );
}

/**
 * Send /tag/all/ visitors to the Next.js all-assets page.
 */
function gaming_hub_all_redirect_to_app() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_customize_preview() ) {
		return;
	}

	if ( ! gaming_hub_is_all_page() ) {
		return;
	}

	wp_safe_redirect( gaming_hub_all_url(), 302 );
	exit;
}
add_action( 'template_redirect', 'gaming_hub_all_redirect_to_app', 5 );
