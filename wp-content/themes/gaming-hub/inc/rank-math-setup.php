<?php
/**
 * Ensure Rank Math SEO is active with a minimal first-time setup.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Activate Rank Math once when the plugin files are present.
 */
function gaming_hub_maybe_activate_rank_math() {
	if ( get_option( 'gaming_hub_rank_math_activated_v1' ) ) {
		return;
	}

	$plugin = 'seo-by-rank-math/rank-math.php';
	$path   = WP_PLUGIN_DIR . '/seo-by-rank-math/rank-math.php';
	if ( ! file_exists( $path ) ) {
		return;
	}

	if ( ! function_exists( 'activate_plugin' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin.php';
	}

	if ( ! is_plugin_active( $plugin ) ) {
		$result = activate_plugin( $plugin, '', false, true );
		if ( is_wp_error( $result ) ) {
			return;
		}
	}

	gaming_hub_configure_rank_math_defaults();
	update_option( 'gaming_hub_rank_math_activated_v1', 1 );
}
add_action( 'init', 'gaming_hub_maybe_activate_rank_math', 5 );

/**
 * Skip wizard / registration blockers and enable core SEO modules.
 */
function gaming_hub_configure_rank_math_defaults() {
	update_option( 'rank_math_registration_skip', true );
	update_option( 'rank_math_wizard_completed', true );
	update_option( 'rank_math_know_you_completed', true );
	update_option( 'rank_math_install_date', time() );

	update_option(
		'rank_math_modules',
		array_values(
			array_unique(
				array(
					'sitemap',
					'rich-snippet',
					'seo-analysis',
					'link-counter',
					'404-monitor',
				)
			)
		)
	);

	if ( class_exists( '\RankMath\Helper' ) ) {
		\RankMath\Helper::is_configured( true );
		\RankMath\Helper::update_modules(
			array(
				'sitemap'       => 'on',
				'rich-snippet'  => 'on',
				'seo-analysis'  => 'on',
				'link-counter'  => 'on',
				'404-monitor'   => 'on',
			)
		);
	} else {
		update_option( 'rank_math_is_configured', true );
	}

	$titles = get_option( 'rank-math-options-titles', array() );
	if ( ! is_array( $titles ) ) {
		$titles = array();
	}
	if ( empty( $titles['homepage_description'] ) ) {
		$titles['homepage_description'] = 'EcoFlow・Tesla・Pokémon GO の実測ダッシュボードとレビュー。家庭の電力とゲーム情報をまとめた Gaming-Hub。';
	}
	if ( empty( $titles['pt_post_description'] ) ) {
		$titles['pt_post_description'] = '%excerpt%';
	}
	update_option( 'rank-math-options-titles', $titles );

	$general = get_option( 'rank-math-options-general', array() );
	if ( ! is_array( $general ) ) {
		$general = array();
	}
	$general['google_verify'] = 'kBdmp1szFKqha1zPV34NzHC38k-U08kf8jZ1BDGIm1k';
	update_option( 'rank-math-options-general', $general );

	flush_rewrite_rules( false );
}

/**
 * Output Google Search Console meta on every page.
 *
 * Rank Math only prints webmaster tags on is_front_page(), but this site
 * redirects the front page to /tag/ecoflow/, so a theme-wide tag is required.
 */
function gaming_hub_google_site_verification_meta() {
	echo '<meta name="google-site-verification" content="kBdmp1szFKqha1zPV34NzHC38k-U08kf8jZ1BDGIm1k" />' . "\n";
}
add_action( 'wp_head', 'gaming_hub_google_site_verification_meta', 1 );

/**
 * Keep Rank Math google_verify in sync (including already-activated installs).
 */
function gaming_hub_ensure_rank_math_google_verify() {
	if ( get_option( 'gaming_hub_google_verify_v1' ) ) {
		return;
	}

	$general = get_option( 'rank-math-options-general', array() );
	if ( ! is_array( $general ) ) {
		$general = array();
	}
	$general['google_verify'] = 'kBdmp1szFKqha1zPV34NzHC38k-U08kf8jZ1BDGIm1k';
	update_option( 'rank-math-options-general', $general );
	update_option( 'gaming_hub_google_verify_v1', 1 );
}
add_action( 'init', 'gaming_hub_ensure_rank_math_google_verify', 6 );
