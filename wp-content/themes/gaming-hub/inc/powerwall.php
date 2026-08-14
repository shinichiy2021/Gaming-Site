<?php
/**
 * Tesla Powerwall 3 news integration
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_POWERWALL_CACHE_KEY', 'gaming_hub_powerwall_news_v3' );
define( 'GAMING_HUB_POWERWALL_CACHE_TTL', 30 * MINUTE_IN_SECONDS );

/**
 * RSS sources for Powerwall news.
 *
 * @return array<int, array<string, string>>
 */
function gaming_hub_powerwall_feed_sources() {
	return array(
		array(
			'url'    => 'https://electrek.co/guides/tesla-powerwall/feed/',
			'source' => 'Electrek',
			'filter' => '',
		),
		array(
			'url'    => 'https://electrek.co/guides/energy-storage/feed/',
			'source' => 'Electrek',
			'filter' => 'powerwall',
		),
		array(
			'url'    => 'https://www.ess-news.com/feed/',
			'source' => 'ESS News',
			'filter' => 'powerwall',
		),
	);
}

/**
 * Fetch latest Powerwall news (cached).
 *
 * @param int $limit Number of items to return.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_get_powerwall_news( $limit = 10 ) {
	$cached = get_transient( GAMING_HUB_POWERWALL_CACHE_KEY );

	if ( false !== $cached && is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	$items = gaming_hub_fetch_powerwall_feeds( 30 );

	if ( ! empty( $items ) ) {
		set_transient( GAMING_HUB_POWERWALL_CACHE_KEY, $items, GAMING_HUB_POWERWALL_CACHE_TTL );
	}

	return array_slice( $items, 0, $limit );
}

/**
 * Parse configured RSS feeds into normalized news items.
 *
 * @param int $max_items Maximum items to collect before dedupe.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_fetch_powerwall_feeds( $max_items = 30 ) {
	require_once ABSPATH . WPINC . '/feed.php';

	$items = array();

	foreach ( gaming_hub_powerwall_feed_sources() as $source ) {
		$feed = fetch_feed( $source['url'] );

		if ( is_wp_error( $feed ) ) {
			continue;
		}

		$count = min( 15, $feed->get_item_quantity( 15 ) );

		for ( $i = 0; $i < $count; $i++ ) {
			$item = $feed->get_item( $i );

			if ( ! $item ) {
				continue;
			}

			$title       = wp_strip_all_tags( $item->get_title() );
			$description = wp_strip_all_tags( $item->get_description() );
			$haystack    = strtolower( $title . ' ' . $description );

			if ( ! empty( $source['filter'] ) && false === strpos( $haystack, strtolower( $source['filter'] ) ) ) {
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
				'title'        => $title,
				'link'         => esc_url_raw( $item->get_permalink() ),
				'date'         => $item->get_date( 'Y-m-d H:i:s' ),
				'date_display' => $item->get_date( get_option( 'date_format' ) ),
				'excerpt'      => wp_trim_words( $description, 28, '...' ),
				'image'        => gaming_hub_extract_feed_item_image( $item ),
				'categories'   => $categories,
				'source'       => $source['source'],
			);
		}
	}

	usort(
		$items,
		static function ( $a, $b ) {
			return strcmp( $b['date'], $a['date'] );
		}
	);

	return gaming_hub_dedupe_powerwall_news( $items, $max_items );
}

/**
 * Remove duplicate articles by URL.
 *
 * @param array<int, array<string, mixed>> $items     News items.
 * @param int                              $max_items Maximum items to keep.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_dedupe_powerwall_news( array $items, $max_items ) {
	$seen    = array();
	$unique  = array();

	foreach ( $items as $item ) {
		$key = untrailingslashit( strtolower( $item['link'] ) );

		if ( isset( $seen[ $key ] ) ) {
			continue;
		}

		$seen[ $key ] = true;
		$unique[]     = $item;

		if ( count( $unique ) >= $max_items ) {
			break;
		}
	}

	return $unique;
}

/**
 * Static Powerwall 3 / 3P specification highlights.
 *
 * @return array<string, array<int, array<string, string>>>
 */
function gaming_hub_get_powerwall_specs() {
	return array(
		'pw3' => array(
			array(
				'label' => __( '使用可能容量', 'gaming-hub' ),
				'value' => '13.5 kWh',
			),
			array(
				'label' => __( '連続出力', 'gaming-hub' ),
				'value' => '11.5 kW',
			),
			array(
				'label' => __( 'ソーラー入力', 'gaming-hub' ),
				'value' => '最大 20 kW (DC)',
			),
			array(
				'label' => __( 'バッテリー', 'gaming-hub' ),
				'value' => 'LFP',
			),
			array(
				'label' => __( '保証', 'gaming-hub' ),
				'value' => __( '10年', 'gaming-hub' ),
			),
			array(
				'label' => __( '拡張', 'gaming-hub' ),
				'value' => 'Powerwall 3 Expansion',
			),
		),
		'pw3p' => array(
			array(
				'label' => __( '使用可能容量', 'gaming-hub' ),
				'value' => '13.4 kWh',
			),
			array(
				'label' => __( '連続出力', 'gaming-hub' ),
				'value' => '15.4 kW',
			),
			array(
				'label' => __( 'ピーク出力', 'gaming-hub' ),
				'value' => '21 kW',
			),
			array(
				'label' => __( '方式', 'gaming-hub' ),
				'value' => __( '三相ネイティブ', 'gaming-hub' ),
			),
			array(
				'label' => __( '用途', 'gaming-hub' ),
				'value' => __( '欧州グリッド向け', 'gaming-hub' ),
			),
			array(
				'label' => __( '連携', 'gaming-hub' ),
				'value' => 'SG-Ready / Wall Connector',
			),
		),
	);
}

function gaming_hub_powerwall_house_image_url() {
	return get_template_directory_uri() . '/assets/images/powerwall-house.jpg';
}

/**
 * Get Powerwall 3 product photograph URL.
 */
function gaming_hub_powerwall_product_image_url() {
	return get_template_directory_uri() . '/assets/images/powerwall-3.jpg';
}

/**
 * Get Model 3 product photograph URL.
 *
 * @param bool $thumb Smaller image for flow diagram.
 */
function gaming_hub_model3_product_image_url( $thumb = false ) {
	$file = $thumb ? 'model-3-thumb.jpg' : 'model-3.jpg';

	return get_template_directory_uri() . '/assets/images/' . $file;
}

/**
 * Get Powerwall 3 thumbnail URL for flow diagram.
 */
function gaming_hub_powerwall_product_thumb_url() {
	return get_template_directory_uri() . '/assets/images/powerwall-3-thumb.jpg';
}

/**
 * Render Powerwall news thumbnail.
 *
 * @param array<string, mixed> $item  News item.
 * @param string               $class CSS class for wrapper.
 */
function gaming_hub_render_powerwall_image( $item, $class = 'pw-card-image' ) {
	$title = isset( $item['title'] ) ? $item['title'] : '';

	if ( ! empty( $item['image'] ) ) {
		?>
		<div class="<?php echo esc_attr( $class ); ?>">
			<img
				src="<?php echo esc_url( $item['image'] ); ?>"
				alt="<?php echo esc_attr( $title ); ?>"
				class="pw-image-photo"
				loading="lazy"
			/>
		</div>
		<?php
		return;
	}
	?>
	<div class="<?php echo esc_attr( $class ); ?> pw-card-image-fallback">
		<img
			src="<?php echo esc_url( gaming_hub_powerwall_product_image_url() ); ?>"
			alt="<?php echo esc_attr__( 'Tesla Powerwall 3', 'gaming-hub' ); ?>"
			class="pw-image-product"
			loading="lazy"
		/>
	</div>
	<?php
}

/**
 * Get Powerwall page URL.
 */
function gaming_hub_powerwall_url() {
	$page = get_page_by_path( 'powerwall' );
	return $page ? get_permalink( $page ) : home_url( '/powerwall/' );
}

/**
 * Clear cached Powerwall news.
 */
function gaming_hub_clear_powerwall_cache() {
	delete_transient( GAMING_HUB_POWERWALL_CACHE_KEY );
}

/**
 * Schedule periodic cache refresh.
 */
function gaming_hub_schedule_powerwall_refresh() {
	if ( ! wp_next_scheduled( 'gaming_hub_refresh_powerwall_news' ) ) {
		wp_schedule_event( time(), 'hourly', 'gaming_hub_refresh_powerwall_news' );
	}
}
add_action( 'wp', 'gaming_hub_schedule_powerwall_refresh' );

function gaming_hub_refresh_powerwall_news_event() {
	gaming_hub_clear_powerwall_cache();
	gaming_hub_get_powerwall_news( 20 );
}
add_action( 'gaming_hub_refresh_powerwall_news', 'gaming_hub_refresh_powerwall_news_event' );

/**
 * Shortcode: [powerwall_news count="10"]
 *
 * @param array<string, string> $atts Shortcode attributes.
 */
function gaming_hub_powerwall_news_shortcode( $atts ) {
	$atts = shortcode_atts(
		array(
			'count' => 10,
		),
		$atts,
		'powerwall_news'
	);

	ob_start();
	gaming_hub_render_powerwall_news( (int) $atts['count'], false );
	return ob_get_clean();
}
add_shortcode( 'powerwall_news', 'gaming_hub_powerwall_news_shortcode' );

/**
 * Render Powerwall news list.
 *
 * @param int  $limit       Number of items.
 * @param bool $show_header Whether to show section header.
 */
function gaming_hub_render_powerwall_news( $limit = 10, $show_header = true ) {
	$news = gaming_hub_get_powerwall_news( $limit );

	get_template_part(
		'template-parts/powerwall',
		'news',
		array(
			'news'        => $news,
			'show_header' => $show_header,
			'limit'       => $limit,
		)
	);
}

/**
 * Render Powerwall spec cards.
 */
function gaming_hub_render_powerwall_specs() {
	get_template_part( 'template-parts/powerwall', 'specs' );
}

/**
 * Get category badge class for Powerwall news item.
 *
 * @param string $category Category name.
 */
function gaming_hub_powerwall_category_class( $category ) {
	$map = array(
		'News'           => 'pw-badge-news',
		'Tesla Powerwall' => 'pw-badge-product',
		'energy storage' => 'pw-badge-storage',
	);

	return isset( $map[ $category ] ) ? $map[ $category ] : 'pw-badge-default';
}

/**
 * Create Powerwall page on theme activation if missing.
 */
function gaming_hub_create_powerwall_page() {
	$existing = get_page_by_path( 'powerwall' );

	if ( $existing ) {
		update_post_meta( $existing->ID, '_wp_page_template', 'page-powerwall.php' );
		return;
	}

	wp_insert_post(
		array(
			'post_title'   => 'Tesla Powerwall 3',
			'post_name'    => 'powerwall',
			'post_status'  => 'publish',
			'post_type'    => 'page',
			'post_content' => '',
		)
	);

	$page = get_page_by_path( 'powerwall' );
	if ( $page ) {
		update_post_meta( $page->ID, '_wp_page_template', 'page-powerwall.php' );
	}
}
add_action( 'after_switch_theme', 'gaming_hub_create_powerwall_page' );

/**
 * Ensure page exists after theme files update (one-time check).
 */
function gaming_hub_maybe_create_powerwall_page() {
	if ( get_option( 'gaming_hub_powerwall_page_created' ) ) {
		return;
	}

	gaming_hub_create_powerwall_page();
	update_option( 'gaming_hub_powerwall_page_created', 1 );
}
add_action( 'init', 'gaming_hub_maybe_create_powerwall_page' );
