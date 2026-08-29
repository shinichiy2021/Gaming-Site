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

	flush_rewrite_rules( false );
}
