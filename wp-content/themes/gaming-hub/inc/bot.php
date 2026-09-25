<?php
/**
 * Paper trading bot — WordPress tag redirects to the Next.js /bot app (hidden from nav).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_BOT_TAG_SLUG', 'bot' );

/**
 * Register the bot tag (direct URL entry → Next.js redirect).
 */
function gaming_hub_setup_bot_tag() {
	if ( get_option( 'gaming_hub_bot_tag_created' ) ) {
		if ( term_exists( GAMING_HUB_BOT_TAG_SLUG, 'post_tag' ) ) {
			return;
		}
		delete_option( 'gaming_hub_bot_tag_created' );
	}

	if ( ! term_exists( GAMING_HUB_BOT_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'Bot',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_BOT_TAG_SLUG,
				'description' => __( 'Paper day-trade bot (simulation only)', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_bot_tag_created', 1 );
}
add_action( 'init', 'gaming_hub_setup_bot_tag' );

/**
 * Bot app URL (Next.js crypto-app /bot route).
 */
function gaming_hub_bot_url( $query = array() ) {
	$url = gaming_hub_crypto_app_url() . '/bot';
	return empty( $query ) ? $url : add_query_arg( $query, $url );
}

/**
 * Whether the current request is the bot tag screen.
 */
function gaming_hub_is_bot_page() {
	return is_tag( GAMING_HUB_BOT_TAG_SLUG );
}

/**
 * Send /tag/bot/ visitors to the Next.js paper bot.
 */
function gaming_hub_bot_redirect_to_app() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_customize_preview() ) {
		return;
	}

	if ( ! gaming_hub_is_bot_page() ) {
		return;
	}

	wp_safe_redirect( gaming_hub_bot_url(), 302 );
	exit;
}
add_action( 'template_redirect', 'gaming_hub_bot_redirect_to_app', 5 );
