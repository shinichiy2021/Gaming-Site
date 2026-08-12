<?php
/**
 * LOOOP tag integration – Chubu electricity forecast
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require get_template_directory() . '/inc/looop-api.php';

define( 'GAMING_HUB_LOOOP_TAG_SLUG', 'looop' );
define( 'GAMING_HUB_LOOOP_CACHE_TTL', HOUR_IN_SECONDS );

/**
 * Register LOOOP post tag on theme setup.
 */
function gaming_hub_setup_looop_tag() {
	if ( get_option( 'gaming_hub_looop_tag_created' ) ) {
		return;
	}

	if ( ! term_exists( GAMING_HUB_LOOOP_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'LOOOP',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_LOOOP_TAG_SLUG,
				'description' => __( 'LOOOP-style electricity price forecast for Chubu area', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_looop_tag_created', 1 );
}
add_action( 'init', 'gaming_hub_setup_looop_tag' );

/**
 * Ensure WordPress site timezone is Japan (JEPX uses JST).
 */
function gaming_hub_setup_site_timezone() {
	if ( get_option( 'gaming_hub_site_timezone_set' ) ) {
		return;
	}

	update_option( 'timezone_string', 'Asia/Tokyo' );
	update_option( 'gmt_offset', '0' );
	update_option( 'gaming_hub_site_timezone_set', 1 );
}
add_action( 'init', 'gaming_hub_setup_site_timezone', 5 );

/**
 * Get LOOOP tag archive URL.
 */
function gaming_hub_looop_url() {
	$term = get_term_by( 'slug', GAMING_HUB_LOOOP_TAG_SLUG, 'post_tag' );
	$link = $term ? get_tag_link( $term ) : '';
	return $link && ! is_wp_error( $link ) ? $link : home_url( '/tag/looop/' );
}

/**
 * Fetch LOOOP forecast data.
 *
 * @param bool $force_refresh Skip cache.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_get_looop_forecast( $force_refresh = false ) {
	$api = new Gaming_Hub_Looop_Api();
	return $api->get_forecast( $force_refresh );
}

/**
 * Map today's LOOOP hourly total price (¥/kWh) by hour 0–23.
 *
 * @param bool $force_refresh Skip LOOOP cache.
 * @return array{map: array<int, float>, fallback: float, forecast: array<string, mixed>}|WP_Error
 */
function gaming_hub_looop_hourly_price_map_today( $force_refresh = false ) {
	$forecast = gaming_hub_get_looop_forecast( $force_refresh );

	if ( is_wp_error( $forecast ) ) {
		return $forecast;
	}

	$map = array();
	foreach ( $forecast['hourly_today'] ?? array() as $row ) {
		$map[ (int) $row['hour'] ] = (float) $row['total_price'];
	}

	$fallback = 0.0;
	if ( ! empty( $map ) ) {
		$fallback = array_sum( $map ) / count( $map );
	} else {
		$api    = new Gaming_Hub_Looop_Api();
		$fixed  = $api->get_fixed_cost_breakdown();
		$fallback = $fixed['total'] + 12.0;
	}

	return array(
		'map'      => $map,
		'fallback' => round( $fallback, 2 ),
		'forecast' => $forecast,
	);
}

/**
 * Forecast mark label.
 *
 * @param string $mark Mark key.
 */
function gaming_hub_looop_mark_label( $mark ) {
	$labels = array(
		'sunny'   => __( 'でんき日和', 'gaming-hub' ),
		'caution' => __( 'でんき注意報', 'gaming-hub' ),
		'alert'   => __( 'でんき警報', 'gaming-hub' ),
		'normal'  => '',
	);

	return $labels[ $mark ] ?? '';
}

/**
 * Collect hourly rows from all forecast days.
 *
 * @param array<string, mixed> $forecast Forecast payload.
 * @return array<int, array<string, mixed>>
 */
function gaming_hub_looop_collect_hourly_prices( array $forecast ) {
	$rows = array();

	foreach ( $forecast['days'] ?? array() as $day ) {
		if ( empty( $day['hourly'] ) ) {
			continue;
		}

		foreach ( $day['hourly'] as $hour ) {
			$rows[] = (float) ( $hour['total_price'] ?? $hour['power_price'] );
		}
	}

	return $rows;
}

/**
 * Build Y-axis ticks for the price chart (fixed 30–70 yen/kWh).
 *
 * @param array<int, float> $prices Unused; kept for call-site compatibility.
 * @return array{min: float, max: float, step: float, ticks: array<int, float>}
 */
function gaming_hub_looop_chart_scale( array $prices ) {
	$min  = 30.0;
	$max  = 70.0;
	$step = 5.0;
	$ticks = array();

	for ( $value = $max; $value >= $min; $value -= $step ) {
		$ticks[] = $value;
	}

	return array(
		'min'   => $min,
		'max'   => $max,
		'step'  => $step,
		'ticks' => $ticks,
	);
}

/**
 * Convert a price to chart height percentage within axis range.
 *
 * @param float $price    Power price.
 * @param float $axis_min Y-axis minimum.
 * @param float $axis_max Y-axis maximum.
 */
function gaming_hub_looop_bar_height_percent( $price, $axis_min, $axis_max ) {
	if ( $axis_max <= $axis_min ) {
		return 0;
	}

	$percent = ( ( $price - $axis_min ) / ( $axis_max - $axis_min ) ) * 100;

	return min( 100, max( 0, round( $percent, 2 ) ) );
}

/**
 * Render SVG line chart for hourly prices.
 *
 * @param array<int, array<string, mixed>> $hourly      Hourly rows.
 * @param array{max: float, step: float, ticks: array<int, float>} $chart_scale Y-axis scale.
 * @param string                            $day_key     Day key (today/yesterday/tomorrow).
 * @param array<string, mixed>              $forecast    Forecast payload.
 * @param bool                              $compact     Compact layout for home page.
 */
function gaming_hub_looop_render_line_chart( array $hourly, array $chart_scale, $day_key, array $forecast, $compact = false ) {
	if ( empty( $hourly ) ) {
		return;
	}

	$count       = count( $hourly );
	$point_width = $compact ? 28 : 44;
	$plot_width  = max( $compact ? 480 : 720, $count * $point_width );
	$plot_height = $compact ? 120 : 220;
	$axis_min    = (float) ( $chart_scale['min'] ?? 0 );
	$axis_max    = (float) $chart_scale['max'];
	$points      = array();

	foreach ( $hourly as $index => $hour ) {
		$x = ( $count > 1 ) ? ( $index / ( $count - 1 ) ) * $plot_width : ( $plot_width / 2 );
		$y = $plot_height - ( gaming_hub_looop_bar_height_percent( $hour['total_price'], $axis_min, $axis_max ) / 100 ) * $plot_height;
		$points[] = array(
			'x'     => round( $x, 2 ),
			'y'     => round( $y, 2 ),
			'hour'  => $hour,
			'index' => $index,
		);
	}

	$polyline = implode(
		' ',
		array_map(
			static function ( $point ) {
				return $point['x'] . ',' . $point['y'];
			},
			$points
		)
	);

	$current_hour = null;
	if ( 'today' === $day_key && ! empty( $forecast['current']['datetime'] ) ) {
		$current_hour = (int) ( new DateTimeImmutable( $forecast['current']['datetime'], gaming_hub_looop_timezone() ) )->format( 'G' );
	}
	?>
	<div class="looop-line-chart-wrap <?php echo $compact ? 'is-compact' : ''; ?>" style="min-width: <?php echo esc_attr( $plot_width ); ?>px;">
		<svg
			class="looop-line-chart"
			viewBox="0 0 <?php echo esc_attr( $plot_width ); ?> <?php echo esc_attr( $plot_height ); ?>"
			preserveAspectRatio="none"
			role="img"
			aria-label="<?php esc_attr_e( '時間別電気代の折れ線グラフ', 'gaming-hub' ); ?>"
		>
			<polyline class="looop-line" points="<?php echo esc_attr( $polyline ); ?>" vector-effect="non-scaling-stroke"></polyline>
			<?php foreach ( $points as $point ) : ?>
				<?php
				$is_current = null !== $current_hour && (int) $point['hour']['hour'] === $current_hour;
				$mark       = $point['hour']['forecast_mark'];
				?>
				<circle
					class="looop-point looop-mark-<?php echo esc_attr( $mark ); ?><?php echo $is_current ? ' is-current' : ''; ?>"
					cx="<?php echo esc_attr( $point['x'] ); ?>"
					cy="<?php echo esc_attr( $point['y'] ); ?>"
					r="<?php echo $is_current ? ( $compact ? '5' : '6' ) : ( $compact ? '3.5' : '4.5' ); ?>"
				>
					<title><?php echo esc_attr( $point['hour']['label'] . ' ' . number_format( $point['hour']['total_price'], 2 ) . ' 円/kWh' ); ?></title>
				</circle>
			<?php endforeach; ?>
		</svg>
		<div class="looop-line-labels">
			<?php foreach ( $points as $point ) : ?>
				<?php
				$is_current   = null !== $current_hour && (int) $point['hour']['hour'] === $current_hour;
				$show_label   = ! $compact || 0 === ( $point['index'] % 3 ) || $is_current;
				$label_class  = 'looop-line-label' . ( $is_current ? ' is-current' : '' ) . ( $show_label ? '' : ' is-spaced' );
				?>
				<span class="<?php echo esc_attr( $label_class ); ?>">
					<?php
					if ( $show_label ) {
						echo esc_html( $point['hour']['label'] );
					}
					?>
				</span>
			<?php endforeach; ?>
		</div>
		<?php if ( ! $compact ) : ?>
		<div class="looop-line-values">
			<?php foreach ( $points as $point ) : ?>
				<span class="looop-line-value"><?php echo esc_html( number_format( $point['hour']['total_price'], 1 ) ); ?></span>
			<?php endforeach; ?>
		</div>
		<?php endif; ?>
	</div>
	<?php
}

/**
 * Render LOOOP home section widget.
 */
function gaming_hub_render_looop_home() {
	get_template_part(
		'template-parts/looop',
		'home',
		array(
			'forecast' => gaming_hub_get_looop_forecast(),
		)
	);
}

/**
 * Render LOOOP dashboard.
 */
function gaming_hub_render_looop_dashboard() {
	get_template_part(
		'template-parts/looop',
		'dashboard',
		array(
			'forecast' => gaming_hub_get_looop_forecast(),
		)
	);
}

/**
 * Render LOOOP tag badge.
 */
function gaming_hub_render_looop_tag_badge() {
	echo '<a href="' . esc_url( gaming_hub_looop_url() ) . '" class="looop-tag-badge">LOOOP</a>';
}

/**
 * REST route for hourly refresh.
 */
function gaming_hub_register_looop_rest_route() {
	register_rest_route(
		'gaming-hub/v1',
		'/looop/forecast',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_looop_forecast',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_looop_rest_route' );

/**
 * REST callback.
 */
function gaming_hub_rest_looop_forecast() {
	$forecast = gaming_hub_get_looop_forecast( true );

	if ( is_wp_error( $forecast ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $forecast->get_error_message(),
			),
			500
		);
	}

	return new WP_REST_Response(
		array(
			'success'  => true,
			'forecast' => $forecast,
		),
		200
	);
}

/**
 * Enqueue LOOOP dashboard assets.
 */
function gaming_hub_looop_scripts() {
	if ( ! is_tag( GAMING_HUB_LOOOP_TAG_SLUG ) && ! is_front_page() ) {
		return;
	}

	wp_enqueue_script(
		'gaming-hub-looop',
		get_template_directory_uri() . '/assets/js/looop-dashboard.js',
		array( 'gaming-hub-active-refresh' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-looop',
		'gamingHubLooop',
		array(
			'refreshUrl' => rest_url( 'gaming-hub/v1/looop/forecast' ),
			'interval'   => GAMING_HUB_LOOOP_CACHE_TTL * 1000,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_looop_scripts' );
