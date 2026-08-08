<?php
/**
 * LOOOP home page widget
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed>|WP_Error $forecast Forecast payload.
 */

$forecast = isset( $args['forecast'] ) ? $args['forecast'] : gaming_hub_get_looop_forecast();

if ( is_wp_error( $forecast ) ) {
	?>
	<div class="looop-home-empty">
		<p><?php echo esc_html( $forecast->get_error_message() ); ?></p>
		<a href="<?php echo esc_url( gaming_hub_looop_url() ); ?>" class="btn btn-outline looop-btn-outline">
			<?php esc_html_e( 'LOOOP ページへ', 'gaming-hub' ); ?>
		</a>
	</div>
	<?php
	return;
}

$today_hourly = $forecast['days']['today']['hourly'] ?? array();
$chart_scale  = gaming_hub_looop_chart_scale( gaming_hub_looop_collect_hourly_prices( $forecast ) );
$mark_label   = ! empty( $forecast['current']['forecast_mark'] )
	? gaming_hub_looop_mark_label( $forecast['current']['forecast_mark'] )
	: '';
?>

<div class="looop-home-widget" data-looop-widget>
	<div class="looop-home-stats">
		<?php if ( ! empty( $forecast['current'] ) ) : ?>
			<div class="looop-home-stat looop-home-stat-current looop-mark-<?php echo esc_attr( $forecast['current']['forecast_mark'] ); ?>">
				<span class="looop-home-stat-label"><?php esc_html_e( '現在の単価', 'gaming-hub' ); ?></span>
				<strong class="looop-home-stat-value" data-looop-current-price><?php echo esc_html( number_format( (float) $forecast['current']['total_price'], 2 ) ); ?></strong>
				<span class="looop-home-stat-unit"><?php esc_html_e( '円/kWh', 'gaming-hub' ); ?></span>
				<span class="looop-home-stat-meta" data-looop-current-time><?php echo esc_html( $forecast['current']['label'] ); ?></span>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $forecast['cheapest_hour'] ) ) : ?>
			<div class="looop-home-stat looop-home-stat-cheapest">
				<span class="looop-home-stat-label"><?php esc_html_e( '今日の最安', 'gaming-hub' ); ?></span>
				<strong class="looop-home-stat-value" data-looop-cheapest-price><?php echo esc_html( number_format( (float) $forecast['cheapest_hour']['total_price'], 2 ) ); ?></strong>
				<span class="looop-home-stat-unit"><?php esc_html_e( '円/kWh', 'gaming-hub' ); ?></span>
				<span class="looop-home-stat-meta" data-looop-cheapest><?php echo esc_html( $forecast['cheapest_hour']['label'] ); ?></span>
			</div>
		<?php endif; ?>

		<div class="looop-home-stat looop-home-stat-mark">
			<span class="looop-home-stat-label"><?php esc_html_e( 'でんき予報', 'gaming-hub' ); ?></span>
			<strong class="looop-home-stat-value looop-home-mark-text" data-looop-current-mark>
				<?php echo esc_html( $mark_label ? $mark_label : __( '通常', 'gaming-hub' ) ); ?>
			</strong>
			<span class="looop-home-stat-meta"><?php echo esc_html( $forecast['area_label'] ); ?></span>
		</div>
	</div>

	<?php if ( ! empty( $today_hourly ) ) : ?>
		<div class="looop-home-chart">
			<div class="looop-home-chart-header">
				<h3><?php esc_html_e( '本日の時間別単価', 'gaming-hub' ); ?></h3>
				<span class="looop-home-updated" data-looop-updated>
					<?php
					printf(
						/* translators: %s: last updated time */
						esc_html__( '更新: %s', 'gaming-hub' ),
						esc_html( $forecast['updated_at'] )
					);
					?>
				</span>
			</div>
			<div class="looop-home-chart-body">
				<div class="looop-chart-layout looop-chart-layout-compact">
					<div class="looop-chart-y-axis" aria-hidden="true">
						<div class="looop-y-axis-ticks looop-y-axis-ticks-compact">
							<?php foreach ( array( 70, 50, 30 ) as $tick ) : ?>
								<span class="looop-y-tick" style="bottom: <?php echo esc_attr( gaming_hub_looop_bar_height_percent( $tick, $chart_scale['min'], $chart_scale['max'] ) ); ?>%;">
									<?php echo esc_html( number_format( $tick, 0 ) ); ?>
								</span>
							<?php endforeach; ?>
						</div>
					</div>
					<div class="looop-chart-scroll">
						<div class="looop-line-chart-area looop-line-chart-area-compact">
							<?php gaming_hub_looop_render_line_chart( $today_hourly, $chart_scale, 'today', $forecast, true ); ?>
						</div>
					</div>
				</div>
			</div>
		</div>
	<?php endif; ?>

	<div class="looop-home-legend">
		<span class="looop-legend-item looop-mark-sunny"><?php esc_html_e( 'でんき日和', 'gaming-hub' ); ?></span>
		<span class="looop-legend-item looop-mark-caution"><?php esc_html_e( 'でんき注意報', 'gaming-hub' ); ?></span>
		<span class="looop-legend-item looop-mark-alert"><?php esc_html_e( 'でんき警報', 'gaming-hub' ); ?></span>
	</div>
</div>
