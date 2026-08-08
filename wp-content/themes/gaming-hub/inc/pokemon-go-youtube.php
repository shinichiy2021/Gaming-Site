<?php
/**
 * Pokémon GO YouTube integration
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_POKEMON_GO_YT_CACHE_KEY', 'gaming_hub_pokemon_go_youtube_v3' );
define( 'GAMING_HUB_POKEMON_GO_YT_CACHE_TTL', 30 * MINUTE_IN_SECONDS );

/**
 * Curated Pokémon GO YouTuber channels.
 *
 * @return array<int, array<string, string>>
 */
function gaming_hub_get_pokemon_go_youtube_channels() {
	return array(
		array(
			'id'   => 'UC6mtwI0mj4lcpFvSIz1KiRQ',
			'name' => 'Pokémon GO Japan',
			'lang' => 'ja',
		),
		array(
			'id'   => 'UCu1Im6gi8b1Hg6vLnVRAJsQ',
			'name' => 'JASH',
			'lang' => 'ja',
		),
		array(
			'id'   => 'UC1EVtvm213uF16HWEX6uyxw',
			'name' => 'やまだちゃんねる',
			'lang' => 'ja',
		),
		array(
			'id'   => 'UCrtyNMe3xtv3CLg5QR78HzQ',
			'name' => 'Trainer Tips',
			'lang' => 'en',
		),
	);
}

/**
 * Fetch latest Pokémon GO YouTube videos.
 *
 * @param int $limit Number of videos to return.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_get_pokemon_go_youtube_videos( $limit = 12 ) {
	$cached = get_transient( GAMING_HUB_POKEMON_GO_YT_CACHE_KEY );

	if ( false !== $cached && is_array( $cached ) ) {
		return array_slice( $cached, 0, $limit );
	}

	$videos = gaming_hub_fetch_pokemon_go_youtube_videos();

	if ( ! empty( $videos ) ) {
		set_transient( GAMING_HUB_POKEMON_GO_YT_CACHE_KEY, $videos, GAMING_HUB_POKEMON_GO_YT_CACHE_TTL );
	}

	return array_slice( $videos, 0, $limit );
}

/**
 * Fetch and merge videos from all configured channels.
 *
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_fetch_pokemon_go_youtube_videos() {
	require_once ABSPATH . WPINC . '/feed.php';

	$videos   = array();
	$channels = gaming_hub_get_pokemon_go_youtube_channels();

	foreach ( $channels as $channel ) {
		$channel_videos = gaming_hub_fetch_youtube_channel_videos( $channel['id'], $channel['name'], 3 );
		$videos         = array_merge( $videos, $channel_videos );
	}

	usort(
		$videos,
		function ( $a, $b ) {
			return strtotime( $b['date'] ) - strtotime( $a['date'] );
		}
	);

	return $videos;
}

/**
 * Fetch videos from a single YouTube channel RSS feed.
 *
 * @param string $channel_id   Channel ID.
 * @param string $channel_name Display name.
 * @param int    $max_items    Max videos to fetch.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_fetch_youtube_channel_videos( $channel_id, $channel_name, $max_items = 3 ) {
	require_once ABSPATH . WPINC . '/feed.php';

	$feed_url = 'https://www.youtube.com/feeds/videos.xml?channel_id=' . rawurlencode( $channel_id );
	$feed     = fetch_feed( $feed_url );
	$videos   = array();

	if ( is_wp_error( $feed ) ) {
		return $videos;
	}

	$count = min( $max_items, $feed->get_item_quantity( $max_items ) );

	for ( $i = 0; $i < $count; $i++ ) {
		$item = $feed->get_item( $i );
		if ( ! $item ) {
			continue;
		}

		$videos[] = array(
			'title'        => wp_strip_all_tags( $item->get_title() ),
			'link'         => esc_url_raw( gaming_hub_normalize_youtube_url( $item->get_permalink() ) ),
			'date'         => $item->get_date( 'Y-m-d H:i:s' ),
			'date_display' => $item->get_date( get_option( 'date_format' ) ),
			'image'        => gaming_hub_extract_youtube_thumbnail( $item ),
			'channel'      => $channel_name,
			'channel_id'   => $channel_id,
			'channel_url'  => 'https://www.youtube.com/channel/' . $channel_id,
		);
	}

	return $videos;
}

/**
 * Normalize YouTube watch URL from RSS links.
 *
 * @param string $url Video URL.
 * @return string
 */
function gaming_hub_normalize_youtube_url( $url ) {
	if ( preg_match( '/(?:v=|\/vi\/|\/shorts\/|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $url, $matches ) ) {
		return 'https://www.youtube.com/watch?v=' . $matches[1];
	}

	return $url;
}

/**
 * Extract thumbnail URL from a YouTube RSS item.
 *
 * @param SimplePie_Item $item Feed item.
 * @return string
 */
function gaming_hub_extract_youtube_thumbnail( $item ) {
	$enclosure = $item->get_enclosure();
	if ( $enclosure && $enclosure->get_thumbnail() ) {
		return esc_url_raw( $enclosure->get_thumbnail() );
	}

	$link = $item->get_permalink();
	if ( preg_match( '/(?:v=|\/vi\/|\/shorts\/|youtu\.be\/)([a-zA-Z0-9_-]{11})/', $link, $matches ) ) {
		return esc_url_raw( 'https://i.ytimg.com/vi/' . $matches[1] . '/hqdefault.jpg' );
	}

	return '';
}

/**
 * Clear cached YouTube videos.
 */
function gaming_hub_clear_pokemon_go_youtube_cache() {
	delete_transient( GAMING_HUB_POKEMON_GO_YT_CACHE_KEY );
}

/**
 * Render Pokémon GO YouTube video section.
 *
 * @param int  $limit       Number of videos.
 * @param bool $show_header Whether to show section header.
 */
function gaming_hub_render_pokemon_go_youtube( $limit = 12, $show_header = true ) {
	$videos = gaming_hub_get_pokemon_go_youtube_videos( $limit );

	get_template_part(
		'template-parts/pokemon-go',
		'youtube',
		array(
			'videos'      => $videos,
			'show_header' => $show_header,
			'limit'       => $limit,
		)
	);
}

/**
 * Refresh YouTube cache on scheduled event.
 */
function gaming_hub_refresh_pokemon_go_youtube_event() {
	gaming_hub_clear_pokemon_go_youtube_cache();
	gaming_hub_get_pokemon_go_youtube_videos( 20 );
}
add_action( 'gaming_hub_refresh_pokemon_go_news', 'gaming_hub_refresh_pokemon_go_youtube_event' );
