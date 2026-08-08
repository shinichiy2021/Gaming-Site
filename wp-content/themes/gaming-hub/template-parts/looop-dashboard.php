<?php
/**
 * LOOOP electricity forecast dashboard
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed>|WP_Error $forecast Forecast payload.
 */

$forecast   = isset( $args['forecast'] ) ? $args['forecast'] : gaming_hub_get_looop_forecast();
$active_day = 'today';

if ( ! is_wp_error( $forecast ) ) {
	$chart_scale = gaming_hub_looop_chart_scale( gaming_hub_looop_collect_hourly_prices( $forecast ) );
	$fixed_costs = $forecast['fixed_costs'] ?? array();
} else {
	$chart_scale = gaming_hub_looop_chart_scale( array() );
	$fixed_costs = array();
}
?>

<section class="looop-dashboard" aria-label="<?php esc_attr_e( 'LOOOP でんき予報', 'gaming-hub' ); ?>" data-active-day="<?php echo esc_attr( $active_day ); ?>">
	<div class="looop-dashboard-header">
		<div>
			<h2><?php esc_html_e( 'でんき予報', 'gaming-hub' ); ?></h2>
			<p class="looop-area-label"><?php echo esc_html( is_wp_error( $forecast ) ? __( '中部電力エリア', 'gaming-hub' ) : $forecast['area_label'] ); ?></p>
		</div>
		<?php if ( ! is_wp_error( $forecast ) && ! empty( $forecast['updated_at'] ) ) : ?>
			<p class="looop-updated">
				<?php
				printf(
					/* translators: %s: last updated time */
					esc_html__( '最終更新: %s', 'gaming-hub' ),
					esc_html( $forecast['updated_at'] )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( is_wp_error( $forecast ) ) : ?>
		<div class="looop-error-panel">
			<p><?php echo esc_html( $forecast->get_error_message() ); ?></p>
			<p class="looop-error-hint"><?php esc_html_e( 'JEPX 前日価格は毎日10:30頃に更新されます。しばらくしてから再度お試しください。', 'gaming-hub' ); ?></p>
			<a href="https://looop-denki.com/home/denkiforecast/" target="_blank" rel="noopener noreferrer" class="btn btn-outline looop-btn-outline">
				<?php esc_html_e( 'LOOOP 公式でんき予報', 'gaming-hub' ); ?>
			</a>
		</div>
	<?php else : ?>
		<?php if ( ! empty( $forecast['current'] ) ) : ?>
			<div class="looop-current-card looop-mark-<?php echo esc_attr( $forecast['current']['forecast_mark'] ); ?>">
				<div class="looop-current-main">
					<span class="looop-current-label"><?php esc_html_e( '現在の使用単価（概算）', 'gaming-hub' ); ?></span>
					<strong class="looop-current-price" data-looop-current-price><?php echo esc_html( number_format( (float) $forecast['current']['total_price'], 2 ) ); ?></strong>
					<span class="looop-current-unit"><?php esc_html_e( '円/kWh（税込）', 'gaming-hub' ); ?></span>
					<span class="looop-current-breakdown">
						<?php
						printf(
							/* translators: 1: variable power price, 2: fixed surcharge */
							esc_html__( '内訳: 電源料金 %1$s + 固定費 %2$s', 'gaming-hub' ),
							esc_html( number_format( (float) $forecast['current']['power_price'], 2 ) ),
							esc_html( number_format( (float) ( $fixed_costs['total'] ?? 0 ), 2 ) )
						);
						?>
					</span>
				</div>
				<div class="looop-current-meta">
					<span data-looop-current-time><?php echo esc_html( $forecast['current']['label'] ); ?></span>
					<?php
					$mark_label = gaming_hub_looop_mark_label( $forecast['current']['forecast_mark'] );
					if ( $mark_label ) :
						?>
						<span class="looop-mark-badge" data-looop-current-mark><?php echo esc_html( $mark_label ); ?></span>
					<?php endif; ?>
				</div>
			</div>
		<?php endif; ?>

		<?php if ( ! empty( $fixed_costs ) ) : ?>
			<div class="looop-fixed-costs">
				<strong><?php esc_html_e( '固定費加算（1kWhあたり）', 'gaming-hub' ); ?></strong>
				<ul>
					<li><?php printf( esc_html__( 'サービス料 %s 円', 'gaming-hub' ), esc_html( number_format( $fixed_costs['service_fee'], 2 ) ) ); ?></li>
					<li><?php printf( esc_html__( '託送従量 %s 円', 'gaming-hub' ), esc_html( number_format( $fixed_costs['transmission_volumetric'], 2 ) ) ); ?></li>
					<li><?php printf( esc_html__( '再エネ賦課金 %s 円', 'gaming-hub' ), esc_html( number_format( $fixed_costs['renewable_surcharge'], 2 ) ) ); ?></li>
					<li><?php printf( esc_html__( '基本料金按分 %s 円', 'gaming-hub' ), esc_html( number_format( $fixed_costs['basic_amortized'], 2 ) ) ); ?></li>
				</ul>
				<p class="looop-pricing-note"><?php echo esc_html( $forecast['pricing_note'] ?? '' ); ?></p>
			</div>
		<?php endif; ?>

		<div class="looop-legend">
			<span class="looop-legend-item looop-mark-sunny"><?php esc_html_e( 'でんき日和', 'gaming-hub' ); ?></span>
			<span class="looop-legend-item looop-mark-caution"><?php esc_html_e( 'でんき注意報', 'gaming-hub' ); ?></span>
			<span class="looop-legend-item looop-mark-alert"><?php esc_html_e( 'でんき警報', 'gaming-hub' ); ?></span>
		</div>

		<div class="looop-day-tabs" role="tablist">
			<?php foreach ( array( 'yesterday' => __( '昨日', 'gaming-hub' ), 'today' => __( '本日', 'gaming-hub' ), 'tomorrow' => __( '明日', 'gaming-hub' ) ) as $day_key => $day_label ) : ?>
				<?php
				$day_data = $forecast['days'][ $day_key ] ?? array();
				$disabled = empty( $day_data['hourly'] );
				?>
				<button
					type="button"
					class="looop-day-tab <?php echo $day_key === $active_day ? 'is-active' : ''; ?>"
					data-day="<?php echo esc_attr( $day_key ); ?>"
					role="tab"
					aria-selected="<?php echo $day_key === $active_day ? 'true' : 'false'; ?>"
					<?php echo $disabled ? 'disabled' : ''; ?>
				>
					<?php echo esc_html( $day_label ); ?>
					<?php if ( ! empty( $day_data['date_label'] ) ) : ?>
						<small><?php echo esc_html( $day_data['date_label'] ); ?></small>
					<?php endif; ?>
				</button>
			<?php endforeach; ?>
		</div>

		<?php foreach ( $forecast['days'] as $day_key => $day_data ) : ?>
			<?php if ( empty( $day_data['hourly'] ) ) { continue; } ?>
			<div class="looop-chart-panel <?php echo $day_key === $active_day ? 'is-active' : ''; ?>" data-day-panel="<?php echo esc_attr( $day_key ); ?>" role="tabpanel">
				<div class="looop-chart-layout">
					<div class="looop-chart-y-axis" aria-hidden="true">
						<span class="looop-y-axis-title"><?php esc_html_e( '円/kWh', 'gaming-hub' ); ?></span>
						<div class="looop-y-axis-ticks">
							<?php foreach ( $chart_scale['ticks'] as $tick ) : ?>
								<?php
								$tick_percent = gaming_hub_looop_bar_height_percent( $tick, $chart_scale['min'], $chart_scale['max'] );
								$tick_label   = number_format( $tick, 0 );
								?>
								<span class="looop-y-tick" style="bottom: <?php echo esc_attr( $tick_percent ); ?>%;">
									<?php echo esc_html( $tick_label ); ?>
								</span>
							<?php endforeach; ?>
						</div>
					</div>

					<div class="looop-chart-scroll">
						<div class="looop-chart-body" style="--looop-axis-min: <?php echo esc_attr( $chart_scale['min'] ); ?>; --looop-axis-max: <?php echo esc_attr( $chart_scale['max'] ); ?>;">
							<div class="looop-chart-grid" aria-hidden="true">
								<?php foreach ( $chart_scale['ticks'] as $tick ) : ?>
									<span
										class="looop-grid-line <?php echo (float) $tick === (float) $chart_scale['min'] ? 'is-min' : ''; ?>"
										style="bottom: <?php echo esc_attr( gaming_hub_looop_bar_height_percent( $tick, $chart_scale['min'], $chart_scale['max'] ) ); ?>%;"
									></span>
								<?php endforeach; ?>
							</div>

							<div class="looop-line-chart-area">
								<?php gaming_hub_looop_render_line_chart( $day_data['hourly'], $chart_scale, $day_key, $forecast ); ?>
							</div>

							<div class="looop-x-axis-label"><?php esc_html_e( '時刻', 'gaming-hub' ); ?></div>
						</div>
					</div>
				</div>

				<?php if ( ! empty( $day_data['avg_jepx'] ) ) : ?>
					<p class="looop-day-meta">
						<?php
						printf(
							/* translators: 1: min axis value, 2: max axis value, 3: step, 4: average JEPX price */
							esc_html__( '縦軸: %1$s〜%2$s 円/kWh（%3$s 刻み・固定費込み） · 過去平均 JEPX: %4$s 円/kWh', 'gaming-hub' ),
							esc_html( number_format( $chart_scale['min'], 0 ) ),
							esc_html( number_format( $chart_scale['max'], 0 ) ),
							esc_html( number_format( $chart_scale['step'], 0 ) ),
							esc_html( number_format( (float) $day_data['avg_jepx'], 2 ) )
						);
						?>
					</p>
				<?php endif; ?>
			</div>
		<?php endforeach; ?>

		<?php if ( ! empty( $forecast['cheapest_hour'] ) ) : ?>
			<div class="looop-tip-card">
				<strong><?php esc_html_e( '今日の最安時間帯', 'gaming-hub' ); ?></strong>
				<span data-looop-cheapest>
					<?php
					printf(
						/* translators: 1: hour label, 2: price */
						esc_html__( '%1$s 頃（%2$s 円/kWh）', 'gaming-hub' ),
						esc_html( $forecast['cheapest_hour']['label'] ),
						esc_html( number_format( (float) $forecast['cheapest_hour']['total_price'], 2 ) )
					);
					?>
				</span>
			</div>
		<?php endif; ?>

		<p class="looop-disclaimer"><?php echo esc_html( $forecast['disclaimer'] ); ?></p>
		<p class="looop-source"><?php echo esc_html( $forecast['source'] ); ?> · <?php esc_html_e( '1時間ごとに自動更新', 'gaming-hub' ); ?></p>
	<?php endif; ?>
</section>
