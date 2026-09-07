<?php
/**
 * Pokémon GO news integration
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_POKEMON_GO_FEED', 'https://pokemongo.com/feed' );
define( 'GAMING_HUB_POKEMON_GO_CACHE_KEY', 'gaming_hub_pokemon_go_news_v4' );
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
		$feed = gaming_hub_fetch_pokemon_go_feed_via_http();
	}

	if ( is_wp_error( $feed ) ) {
		return array();
	}

	$items      = array();
	$count      = min( $max_items, $feed->get_item_quantity( $max_items ) );
	$enrich_cap = 15;

	for ( $i = 0; $i < $count; $i++ ) {
		$item = $feed->get_item( $i );

		if ( ! $item ) {
			continue;
		}

		$link = gaming_hub_pokemon_go_localize_link( $item->get_permalink() );
		$title = wp_strip_all_tags( $item->get_title() );
		$categories = gaming_hub_pokemon_go_infer_categories( $title );

		$news_item = array(
			'title'        => $title,
			'link'         => esc_url_raw( $link ),
			'date'         => $item->get_date( 'Y-m-d H:i:s' ),
			'date_display' => $item->get_date( get_option( 'date_format' ) ),
			'excerpt'      => wp_trim_words( wp_strip_all_tags( $item->get_description() ), 28, '...' ),
			'image'        => gaming_hub_extract_feed_item_image( $item ),
			'categories'   => $categories,
			'source'       => __( 'Pokémon GO 公式', 'gaming-hub' ),
		);

		if ( $i < $enrich_cap ) {
			$news_item = gaming_hub_pokemon_go_enrich_item_from_page( $news_item );
		}

		$items[] = $news_item;
	}

	return $items;
}

/**
 * Fallback RSS fetch when fetch_feed() fails (redirects, user-agent blocks, etc.).
 *
 * @return WP_Feed|WP_Error
 */
function gaming_hub_fetch_pokemon_go_feed_via_http() {
	require_once ABSPATH . WPINC . '/class-simplepie.php';

	$response = wp_remote_get(
		GAMING_HUB_POKEMON_GO_FEED,
		array(
			'timeout'     => 15,
			'redirection' => 5,
			'headers'     => array(
				'User-Agent' => 'GamingHub/1.0 (+https://shinichiy-gaming-hub.com)',
				'Accept'     => 'application/rss+xml, application/xml, text/xml, */*',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$code = (int) wp_remote_retrieve_response_code( $response );
	if ( $code < 200 || $code >= 300 ) {
		return new WP_Error( 'pokemon_go_feed_http', 'Pokémon GO feed HTTP ' . $code );
	}

	$body = wp_remote_retrieve_body( $response );
	if ( '' === $body ) {
		return new WP_Error( 'pokemon_go_feed_empty', 'Pokémon GO feed body empty' );
	}

	$simplepie = new SimplePie();
	$simplepie->set_raw_data( $body );
	$simplepie->enable_cache( false );
	$simplepie->init();

	if ( $simplepie->error() ) {
		return new WP_Error( 'pokemon_go_feed_parse', $simplepie->error() );
	}

	require_once ABSPATH . WPINC . '/feed.php';

	return new WP_Feed( $simplepie );
}

/**
 * Point official news links at the Japanese locale.
 *
 * @param string $link Feed permalink.
 */
function gaming_hub_pokemon_go_localize_link( $link ) {
	$link = (string) $link;

	if ( preg_match( '#https://pokemongo\.com/news/([^/?]+)#', $link, $matches ) ) {
		return 'https://pokemongo.com/ja/news/' . $matches[1];
	}

	if ( preg_match( '#https://pokemongolive\.com/(?:en/)?news/([^/?]+)#', $link, $matches ) ) {
		return 'https://pokemongo.com/ja/news/' . $matches[1];
	}

	return $link;
}

/**
 * Infer display categories from an English RSS title.
 *
 * @param string $title Item title.
 * @return array<int, string>
 */
function gaming_hub_pokemon_go_infer_categories( $title ) {
	$haystack = strtolower( (string) $title );

	if ( false !== strpos( $haystack, 'community day' ) || false !== strpos( $haystack, 'raid day' ) || false !== strpos( $haystack, 'celebration event' ) || false !== strpos( $haystack, 'fest' ) ) {
		return array( 'Events' );
	}

	if ( false !== strpos( $haystack, 'go battle league' ) || false !== strpos( $haystack, 'update' ) ) {
		return array( 'Updates' );
	}

	if ( false !== strpos( $haystack, 'raid' ) ) {
		return array( 'Events' );
	}

	return array( 'News' );
}

/**
 * Read a meta tag value regardless of attribute order.
 *
 * @param string $body     HTML document.
 * @param string $property Meta property or name.
 */
function gaming_hub_pokemon_go_extract_html_meta( $body, $property ) {
	$body     = (string) $body;
	$property = (string) $property;
	$quoted   = preg_quote( $property, '/' );

	if ( preg_match( '/property="' . $quoted . '"[^>]*\scontent="([^"]+)"/', $body, $matches ) ) {
		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	if ( preg_match( '/content="([^"]+)"[^>]*\sproperty="' . $quoted . '"/', $body, $matches ) ) {
		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	if ( preg_match( '/name="' . $quoted . '"[^>]*\scontent="([^"]+)"/', $body, $matches ) ) {
		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	if ( preg_match( '/content="([^"]+)"[^>]*\sname="' . $quoted . '"/', $body, $matches ) ) {
		return html_entity_decode( $matches[1], ENT_QUOTES | ENT_HTML5, 'UTF-8' );
	}

	return '';
}

/**
 * Best-effort hero image URL from an official news page.
 *
 * @param string $body HTML document.
 */
function gaming_hub_pokemon_go_extract_news_image_from_html( $body ) {
	$body = (string) $body;

	foreach ( array( 'og:image', 'twitter:image' ) as $property ) {
		$image = gaming_hub_pokemon_go_extract_html_meta( $body, $property );
		if ( '' !== $image ) {
			return esc_url_raw( $image );
		}
	}

	if ( preg_match( '/"@type":"NewsArticle"[^}]*"image":"([^"]+)"/', $body, $matches ) ) {
		return esc_url_raw( $matches[1] );
	}

	if ( preg_match( '/"@type":"NewsArticle"[\s\S]*?"image"\s*:\s*"([^"]+)"/', $body, $matches ) ) {
		return esc_url_raw( $matches[1] );
	}

	return '';
}

/**
 * Pull Japanese title and hero image from the localized news page.
 *
 * @param array<string, mixed> $item Normalized news item.
 * @return array<string, mixed>
 */
function gaming_hub_pokemon_go_enrich_item_from_page( $item ) {
	$link = isset( $item['link'] ) ? (string) $item['link'] : '';
	if ( '' === $link ) {
		return $item;
	}

	$response = wp_remote_get(
		$link,
		array(
			'timeout' => 5,
			'headers' => array(
				'User-Agent' => 'GamingHub/1.0 (+https://shinichiy-gaming-hub.com)',
				'Accept'     => 'text/html',
			),
		)
	);

	if ( is_wp_error( $response ) ) {
		return $item;
	}

	$body = wp_remote_retrieve_body( $response );
	if ( '' === $body ) {
		return $item;
	}

	$title = gaming_hub_pokemon_go_extract_html_meta( $body, 'og:title' );
	if ( '' !== $title ) {
		$title = preg_replace( '/\s*[—–-]\s*Pokémon GO\s*$/u', '', $title );
		if ( '' !== $title ) {
			$item['title'] = wp_strip_all_tags( $title );
		}
	}

	if ( empty( $item['image'] ) ) {
		$image = gaming_hub_pokemon_go_extract_news_image_from_html( $body );
		if ( '' !== $image ) {
			$item['image'] = $image;
		}
	}

	return $item;
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
	delete_transient( 'gaming_hub_pokemon_go_news_v3' );
	delete_transient( 'gaming_hub_pokemon_go_news_v2' );
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
