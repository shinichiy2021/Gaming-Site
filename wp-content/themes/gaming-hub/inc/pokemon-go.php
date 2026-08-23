<?php
/**
 * Pokémon GO news integration
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_POKEMON_GO_FEED', 'https://pokemongohub.net/feed/' );
define( 'GAMING_HUB_POKEMON_GO_CACHE_KEY', 'gaming_hub_pokemon_go_news_v2' );
define( 'GAMING_HUB_POKEMON_GO_CACHE_TTL', 30 * MINUTE_IN_SECONDS );
define( 'GAMING_HUB_POKEMON_GO_TAG_SLUG', 'pokemon-go' );

/**
 * Register the Pokémon GO tag used as the one-page screen.
 */
function gaming_hub_setup_pokemon_go_tag() {
	if ( get_option( 'gaming_hub_pokemon_go_tag_created' ) ) {
		if ( ! term_exists( GAMING_HUB_POKEMON_GO_TAG_SLUG, 'post_tag' ) ) {
			delete_option( 'gaming_hub_pokemon_go_tag_created' );
		} else {
			return;
		}
	}

	if ( ! term_exists( GAMING_HUB_POKEMON_GO_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'Pokémon GO',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_POKEMON_GO_TAG_SLUG,
				'description' => __( 'Pokémon GO のイベント・レイド・ニュース', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_pokemon_go_tag_created', 1 );
}
add_action( 'init', 'gaming_hub_setup_pokemon_go_tag' );

/**
 * Fetch latest Pokémon GO news from RSS feed.
 *
 * @param int $limit Number of items to return.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_get_pokemon_go_news( $limit = 10 ) {
	$cached = get_transient( GAMING_HUB_POKEMON_GO_CACHE_KEY );

	if ( false !== $cached && is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	$items = gaming_hub_fetch_pokemon_go_feed( 20 );

	if ( ! empty( $items ) ) {
		set_transient( GAMING_HUB_POKEMON_GO_CACHE_KEY, $items, GAMING_HUB_POKEMON_GO_CACHE_TTL );
	}

	return array_slice( $items, 0, $limit );
}

/**
 * Parse RSS feed into normalized news items.
 *
 * @param int $max_items Maximum items to fetch.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_fetch_pokemon_go_feed( $max_items = 20 ) {
	require_once ABSPATH . WPINC . '/feed.php';

	$feed = fetch_feed( GAMING_HUB_POKEMON_GO_FEED );

	if ( is_wp_error( $feed ) ) {
		return array();
	}

	$items   = array();
	$count   = min( $max_items, $feed->get_item_quantity( $max_items ) );

	for ( $i = 0; $i < $count; $i++ ) {
		$item = $feed->get_item( $i );

		if ( ! $item ) {
			continue;
		}

		$categories = array();
		foreach ( (array) $item->get_categories() as $category ) {
			if ( is_object( $category ) && method_exists( $category, 'get_term' ) ) {
				$term = $category->get_term();
				if ( $term ) {
					$categories[] = $term;
				}
			}
		}

		$items[] = array(
			'title'        => wp_strip_all_tags( $item->get_title() ),
			'link'         => esc_url_raw( $item->get_permalink() ),
			'date'         => $item->get_date( 'Y-m-d H:i:s' ),
			'date_display' => $item->get_date( get_option( 'date_format' ) ),
			'excerpt'      => wp_trim_words( wp_strip_all_tags( $item->get_description() ), 28, '...' ),
			'image'        => gaming_hub_extract_feed_item_image( $item ),
			'categories'   => $categories,
			'source'       => 'Pokémon GO Hub',
		);
	}

	return $items;
}

/**
 * Extract the best thumbnail image from an RSS feed item.
 *
 * @param SimplePie_Item $item Feed item.
 * @return string Image URL or empty string.
 */
function gaming_hub_extract_feed_item_image( $item ) {
	$enclosure = $item->get_enclosure();
	if ( $enclosure && $enclosure->get_link() && $enclosure->get_thumbnail() ) {
		return esc_url_raw( $enclosure->get_link() );
	}

	$content = $item->get_content();
	if ( ! $content ) {
		$content = $item->get_description();
	}

	if ( ! $content ) {
		return '';
	}

	preg_match_all( '/src=["\']([^"\']+)["\']/', $content, $matches );
	$candidates = isset( $matches[1] ) ? $matches[1] : array();

	foreach ( $candidates as $url ) {
		$url  = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$path = preg_replace( '/[?#].*$/', '', $url );
		if ( preg_match( '#/wp-content/uploads/.*\.(jpe?g|webp)$#i', $path ) && ! preg_match( '/ico_\d+_|icon|favicon|default-electrek-related-guide/i', $path ) ) {
			return esc_url_raw( $url );
		}
	}

	foreach ( $candidates as $url ) {
		$url = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		if ( preg_match( '#/images/official/detail/\d+#', $url ) ) {
			return esc_url_raw( $url );
		}
	}

	foreach ( $candidates as $url ) {
		$url  = html_entity_decode( $url, ENT_QUOTES | ENT_HTML5, 'UTF-8' );
		$path = preg_replace( '/[?#].*$/', '', $url );
		if ( preg_match( '#/wp-content/uploads/.*\.(png|gif)$#i', $path ) && ! preg_match( '/ico_|icon|favicon|type-icon|default-electrek-related-guide/i', $path ) ) {
			return esc_url_raw( $url );
		}
	}

	return '';
}

/**
 * Render Pokémon GO news thumbnail.
 *
 * @param array<string, mixed> $item News item.
 * @param string               $class CSS class for wrapper.
 */
function gaming_hub_render_pokemon_go_image( $item, $class = 'pgo-card-image' ) {
	$title = isset( $item['title'] ) ? $item['title'] : '';

	if ( ! empty( $item['image'] ) ) {
		$is_artwork = (bool) preg_match( '#/images/official/detail/#', $item['image'] );
		$image_class = $is_artwork ? 'pgo-image-artwork' : 'pgo-image-photo';
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<img
				src="<?php echo esc_url( $item['image'] ); ?>"
				alt="<?php echo esc_attr( $title ); ?>"
				class="<?php echo esc_attr( $image_class ); ?>"
				loading="lazy"
			/>
		</div>
		<?php
		return;
	}
	?>
	<div class="<?php echo esc_attr( $class ); ?> pgo-card-image-placeholder">
		<span aria-hidden="true">⚡</span>
	</div>
	<?php
}

/**
 * Pokémon GO tag URL.
 *
 * @param array<string, mixed> $query Optional query args.
 */
function gaming_hub_pokemon_go_url( $query = array() ) {
	return function_exists( 'gaming_hub_tag_url' )
		? gaming_hub_tag_url( GAMING_HUB_POKEMON_GO_TAG_SLUG, $query )
		: home_url( '/tag/' . GAMING_HUB_POKEMON_GO_TAG_SLUG . '/' );
}

/**
 * Clear cached Pokémon GO news.
 */
function gaming_hub_clear_pokemon_go_cache() {
	delete_transient( GAMING_HUB_POKEMON_GO_CACHE_KEY );
	gaming_hub_clear_pokemon_go_youtube_cache();
}

/**
 * Schedule periodic cache refresh.
 */
function gaming_hub_schedule_pokemon_go_refresh() {
	if ( ! wp_next_scheduled( 'gaming_hub_refresh_pokemon_go_news' ) ) {
		wp_schedule_event( time(), 'hourly', 'gaming_hub_refresh_pokemon_go_news' );
	}
}
add_action( 'wp', 'gaming_hub_schedule_pokemon_go_refresh' );

function gaming_hub_refresh_pokemon_go_news_event() {
	gaming_hub_clear_pokemon_go_cache();
	gaming_hub_get_pokemon_go_news( 20 );
}
add_action( 'gaming_hub_refresh_pokemon_go_news', 'gaming_hub_refresh_pokemon_go_news_event' );

/**
 * Shortcode: [pokemon_go_news count="10"]
 *
 * @param array<string, string> $atts Shortcode attributes.
 */
function gaming_hub_pokemon_go_news_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'count' => 10,
		),
		$atts,
		'pokemon_go_news'
	);

	ob_start();
	gaming_hub_render_pokemon_go_news( (int) $atts['count'], false );
	return ob_get_clean();
}
add_shortcode( 'pokemon_go_news', 'gaming_hub_pokemon_go_news_shortcode' );

/**
 * Render Pokémon GO news list.
 *
 * @param int  $limit       Number of items.
 * @param bool $show_header Whether to show section header.
 */
function gaming_hub_render_pokemon_go_news( $limit = 10, $show_header = true ) {
	$news = gaming_hub_get_pokemon_go_news( $limit );

	get_template_part(
		'template-parts/pokemon-go',
		'news',
		array(
			'news'        => $news,
			'show_header' => $show_header,
			'limit'       => $limit,
		)
	);
}

/**
 * Get category badge class for Pokémon GO news item.
 *
 * @param string $category Category name.
 */
function gaming_hub_pokemon_go_category_class( $category ) {
	$map = array(
		'News'    => 'pgo-badge-news',
		'Guides'  => 'pgo-badge-guide',
		'Events'  => 'pgo-badge-event',
		'Updates' => 'pgo-badge-update',
	);

	return isset( $map[ $category ] ) ? $map[ $category ] : 'pgo-badge-default';
}

/**
 * Create Pokémon GO page on theme activation if missing.
 */
function gaming_hub_create_pokemon_go_page() {
	$existing = get_page_by_path( 'pokemon-go' );

	if ( $existing ) {
		update_post_meta( $existing->ID, '_wp_page_template', 'page-pokemon-go.php' );
		return;
	}

	wp_insert_post(
		array(
			'post_title'    => 'Pokémon GO',
			'post_name'     => 'pokemon-go',
			'post_status'   => 'publish',
			'post_type'     => 'page',
			'post_content'  => '',
		)
	);

	$page = get_page_by_path( 'pokemon-go' );
	if ( $page ) {
		update_post_meta( $page->ID, '_wp_page_template', 'page-pokemon-go.php' );
	}
}
add_action( 'after_switch_theme', 'gaming_hub_create_pokemon_go_page' );

/**
 * Ensure page exists after theme files update (one-time check).
 */
function gaming_hub_maybe_create_pokemon_go_page() {
	if ( get_option( 'gaming_hub_pokemon_go_page_created' ) ) {
		return;
	}

	gaming_hub_create_pokemon_go_page();
	update_option( 'gaming_hub_pokemon_go_page_created', 1 );
}
add_action( 'init', 'gaming_hub_maybe_create_pokemon_go_page' );

/**
 * Set permalink structure on theme activation.
 */
function gaming_hub_setup_permalinks() {
	if ( get_option( 'gaming_hub_permalinks_set' ) ) {
		return;
	}

	update_option( 'permalink_structure', '/%postname%/' );
	flush_rewrite_rules();
	update_option( 'gaming_hub_permalinks_set', 1 );
}
add_action( 'after_switch_theme', 'gaming_hub_setup_permalinks' );
