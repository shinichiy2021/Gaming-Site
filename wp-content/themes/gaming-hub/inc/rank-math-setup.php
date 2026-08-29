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

	$sitemap = get_option( 'rank-math-options-sitemap', array() );
	if ( ! is_array( $sitemap ) ) {
		$sitemap = array();
	}
	$sitemap['items_per_page']       = $sitemap['items_per_page'] ?? 200;
	$sitemap['pt_post_sitemap']      = 'on';
	$sitemap['pt_page_sitemap']      = 'on';
	$sitemap['tax_category_sitemap'] = 'on';
	$sitemap['tax_post_tag_sitemap'] = 'on';
	update_option( 'rank-math-options-sitemap', $sitemap );

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

/**
 * Ensure Rank Math sitemap settings include posts/pages (production was empty → 404).
 */
function gaming_hub_ensure_rank_math_sitemap_settings() {
	if ( get_option( 'gaming_hub_rank_math_sitemap_settings_v1' ) ) {
		return;
	}

	$sitemap = get_option( 'rank-math-options-sitemap', array() );
	if ( ! is_array( $sitemap ) ) {
		$sitemap = array();
	}

	$sitemap['items_per_page']          = isset( $sitemap['items_per_page'] ) ? absint( $sitemap['items_per_page'] ) : 200;
	$sitemap['include_images']          = $sitemap['include_images'] ?? 'on';
	$sitemap['pt_post_sitemap']         = 'on';
	$sitemap['pt_page_sitemap']         = 'on';
	$sitemap['tax_category_sitemap']    = 'on';
	$sitemap['tax_post_tag_sitemap']    = 'on';
	$sitemap['authors_sitemap']         = $sitemap['authors_sitemap'] ?? 'off';

	update_option( 'rank-math-options-sitemap', $sitemap );
	update_option( 'gaming_hub_rank_math_sitemap_settings_v1', 1 );

	// Allow rewrite flush to run again after settings fix.
	delete_option( 'gaming_hub_rank_math_sitemap_flush_v3' );
}
add_action( 'init', 'gaming_hub_ensure_rank_math_sitemap_settings', 4 );

/**
 * Flush Rank Math sitemap rewrites after the plugin has registered them.
 */
function gaming_hub_flush_rank_math_sitemap_rewrites() {
	if ( get_option( 'gaming_hub_rank_math_sitemap_flush_v4' ) ) {
		return;
	}
	if ( ! class_exists( '\RankMath\Helper' ) ) {
		return;
	}

	\RankMath\Helper::update_modules( array( 'sitemap' => 'on' ) );
	flush_rewrite_rules( false );
	update_option( 'gaming_hub_rank_math_sitemap_flush_v4', 1 );
}
add_action( 'wp_loaded', 'gaming_hub_flush_rank_math_sitemap_rewrites', 99 );

/**
 * Fallback sitemap when Rank Math rewrite/settings fail.
 *
 * @param string $type Sitemap type (1 = index, post, page, …).
 */
function gaming_hub_output_simple_sitemap( $type ) {
	nocache_headers();
	header( 'Content-Type: application/xml; charset=UTF-8' );
	status_header( 200 );

	if ( '1' === $type ) {
		$sitemaps = array(
			home_url( '/post-sitemap.xml' ),
			home_url( '/page-sitemap.xml' ),
			home_url( '/category-sitemap.xml' ),
			home_url( '/post_tag-sitemap.xml' ),
		);
		echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
		echo '<sitemapindex xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
		foreach ( $sitemaps as $loc ) {
			echo "\t<sitemap>\n\t\t<loc>" . esc_url( $loc ) . "</loc>\n\t</sitemap>\n";
		}
		echo '</sitemapindex>';
		exit;
	}

	$urls = array();
	if ( 'post' === $type || 'page' === $type ) {
		$q = new WP_Query(
			array(
				'post_type'              => $type,
				'post_status'            => 'publish',
				'posts_per_page'         => 200,
				'orderby'                => 'modified',
				'order'                  => 'DESC',
				'no_found_rows'          => true,
				'update_post_meta_cache' => false,
				'update_post_term_cache' => false,
			)
		);
		foreach ( $q->posts as $post ) {
			$urls[] = array(
				'loc'     => get_permalink( $post ),
				'lastmod' => get_post_modified_time( 'c', true, $post ),
			);
		}
	} elseif ( 'category' === $type || 'post_tag' === $type ) {
		$terms = get_terms(
			array(
				'taxonomy'   => $type,
				'hide_empty' => true,
				'number'     => 200,
			)
		);
		if ( ! is_wp_error( $terms ) ) {
			foreach ( $terms as $term ) {
				$link = get_term_link( $term );
				if ( is_wp_error( $link ) ) {
					continue;
				}
				$urls[] = array( 'loc' => $link, 'lastmod' => '' );
			}
		}
	}

	echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
	echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">' . "\n";
	foreach ( $urls as $url ) {
		echo "\t<url>\n\t\t<loc>" . esc_url( $url['loc'] ) . "</loc>\n";
		if ( ! empty( $url['lastmod'] ) ) {
			echo "\t\t<lastmod>" . esc_html( $url['lastmod'] ) . "</lastmod>\n";
		}
		echo "\t</url>\n";
	}
	echo '</urlset>';
	exit;
}

/**
 * Fallback: serve sitemaps when Rank Math rewrite/settings fail (production 404).
 */
function gaming_hub_rank_math_sitemap_fallback() {
	if ( empty( $_SERVER['REQUEST_URI'] ) ) {
		return;
	}

	$path = (string) wp_parse_url( (string) wp_unslash( $_SERVER['REQUEST_URI'] ), PHP_URL_PATH );
	$path = untrailingslashit( $path );
	if ( '' === $path ) {
		return;
	}

	$type = '';

	if ( '/sitemap_index.xml' === $path || '/sitemap.xml' === $path ) {
		$type = '1';
	} elseif ( preg_match( '#^/([a-z0-9_-]+)-sitemap([0-9]*)\.xml$#', $path, $matches ) ) {
		$type = $matches[1];
	} else {
		return;
	}

	// Prefer Rank Math when it can build a non-empty document via query vars + rewrites.
	// If that path 404s (common on fresh prod), always emit a simple valid sitemap.
	if ( class_exists( '\RankMath\Sitemap\Generator' ) ) {
		$generator = new \RankMath\Sitemap\Generator();
		$built     = $generator->get_output( $type, 1 );
		if ( is_string( $built ) && '' !== trim( $built ) ) {
			nocache_headers();
			header( 'Content-Type: application/xml; charset=UTF-8' );
			status_header( 200 );
			echo $built; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			echo "\n<!-- XML Sitemap generated by Rank Math SEO Plugin (c) Rank Math - rankmath.com -->";
			exit;
		}
	}

	gaming_hub_output_simple_sitemap( $type );
}
add_action( 'template_redirect', 'gaming_hub_rank_math_sitemap_fallback', 0 );
