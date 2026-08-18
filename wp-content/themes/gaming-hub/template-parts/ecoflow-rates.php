<?php
/**
 * EcoFlow electricity rate HUD (Smart Time ONE / Chubu).
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed>|WP_Error $forecast Forecast payload.
 */

$forecast = isset( $args['forecast'] ) ? $args['forecast'] : gaming_hub_get_looop_forecast();
$plan     = isset( $args['plan'] ) && is_array( $args['plan'] ) ? $args['plan'] : array();
$hourly   = ( ! is_wp_error( $forecast ) ) ? ( $forecast['days']['today']['hourly'] ?? array() ) : array();
$current  = ( ! is_wp_error( $forecast ) ) ? ( $forecast['current'] ?? array() ) : array();
$cheapest = ( ! is_wp_error( $forecast ) ) ? ( $forecast['cheapest_hour'] ?? array() ) : array();
$mark     = $current['forecast_mark'] ?? 'normal';
$mark_label = function_exists( 'gaming_hub_looop_mark_label' )
	? gaming_hub_looop_mark_label( $mark )
	: '';

$prices = array();
foreach ( $hourly as $hour ) {
	$prices[] = (float) ( $hour['total_price'] ?? 0 );
}
$min = 0;
$max = $prices ? max( $prices ) : 70;
$max = max( $max, $min + 1 );
$span = max( 1, $max - $min );
$now_hour = (int) wp_date( 'G' );

$soc_series = is_array( $plan['soc_series'] ?? null ) ? $plan['soc_series'] : array();
$soc_bar_pro = is_array( $plan['soc_bar_pro'] ?? null ) ? $plan['soc_bar_pro'] : array();
$soc_bar_delta = is_array( $plan['soc_bar_delta'] ?? null ) ? $plan['soc_bar_delta'] : array();
$solar_hours = is_array( $plan['solar_chart'] ?? null ) ? $plan['solar_chart'] : ( is_array( $plan['solar_hours'] ?? null ) ? $plan['solar_hours'] : array() );
$solar_pro_h = is_array( $plan['solar_chart_pro'] ?? null ) ? $plan['solar_chart_pro'] : array();
$solar_delta_h = is_array( $plan['solar_chart_delta'] ?? null ) ? $plan['solar_chart_delta'] : array();
$solar_cap   = max( 1, (int) ( $plan['solar_capacity_w'] ?? ( defined( 'GAMING_HUB_ECOFLOW_SOLAR_CAPACITY_W' ) ? GAMING_HUB_ECOFLOW_SOLAR_CAPACITY_W : 1300 ) ) );
$solar_now   = isset( $args['solar_now'] ) && null !== $args['solar_now']
	? (int) $args['solar_now']
	: (int) ( $plan['solar_now_w'] ?? ( $solar_hours[ $now_hour ] ?? 0 ) );
$price_points = array();
foreach ( $hourly as $hour_row ) {
	$hour_num = (int) ( $hour_row['hour'] ?? 0 );
	$price    = (float) ( $hour_row['total_price'] ?? 0 );
	$y        = max( 0, min( 100, 100 - ( ( $price - $min ) / $span ) * 100 ) );
	$price_points[] = ( ( $hour_num + 0.5 ) * 10 ) . ',' . round( $y, 1 );
}
$price_polyline = implode( ' ', $price_points );
if ( ! $solar_pro_h && $solar_hours ) {
	$split         = function_exists( 'gaming_hub_ecoflow_split_solar_hours' )
		? gaming_hub_ecoflow_split_solar_hours( $solar_hours )
		: array( 'pro' => $solar_hours, 'delta' => array() );
	$solar_pro_h   = $split['pro'];
	$solar_delta_h = $split['delta'];
}
$solar_stack = function_exists( 'gaming_hub_ecoflow_chart_solar_stack_points' )
	? gaming_hub_ecoflow_chart_solar_stack_points( $solar_pro_h, $solar_delta_h, $solar_cap )
	: array( 'delta_area' => '', 'pro_area' => '', 'total_line' => '' );
$solar_polyline = $solar_stack['total_line'];
$solar_area     = $solar_stack['pro_area'];
$solar_delta_area = $solar_stack['delta_area'];
?>

<section class="ecoflow-rates" aria-label="<?php esc_attr_e( 'でんき予報', 'gaming-hub' ); ?>">
	<div class="ecoflow-rates-header">
		<div>
			<p class="ecoflow-rates-kicker"><?php esc_html_e( 'RATE MAP', 'gaming-hub' ); ?></p>
			<h3><?php esc_html_e( '今日の電気代', 'gaming-hub' ); ?></h3>
			<p class="ecoflow-rates-sub"><?php esc_html_e( 'スマートタイムONE（電灯）· 中部 · 請求単価', 'gaming-hub' ); ?></p>
		</div>
		<?php if ( ! is_wp_error( $forecast ) && ! empty( $forecast['updated_at'] ) ) : ?>
			<p class="ecoflow-rates-updated" data-ecoflow-rates-updated>
				<?php
				printf(
					/* translators: %s: last updated time */
					esc_html__( '更新 %s', 'gaming-hub' ),
					esc_html( $forecast['updated_at'] )
				);
				?>
			</p>
		<?php endif; ?>
	</div>

	<?php if ( is_wp_error( $forecast ) ) : ?>
		<p class="ecoflow-rates-empty"><?php echo esc_html( $forecast->get_error_message() ); ?></p>
	<?php else : ?>
		<div class="ecoflow-rates-hud">
			<div class="ecoflow-rates-stat ecoflow-rates-stat-now ecoflow-rate-mark-<?php echo esc_attr( $mark ); ?>">
				<span><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-rates-now><?php echo esc_html( isset( $current['total_price'] ) ? number_format( (float) $current['total_price'], 1 ) : '—' ); ?></strong>
				<small><?php esc_html_e( '請求単価 円/kWh', 'gaming-hub' ); ?></small>
			</div>
			<div class="ecoflow-rates-stat ecoflow-rates-stat-low">
				<span><?php esc_html_e( 'LOW', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-rates-low><?php echo esc_html( isset( $cheapest['total_price'] ) ? number_format( (float) $cheapest['total_price'], 1 ) : '—' ); ?></strong>
				<small data-ecoflow-rates-low-label><?php echo esc_html( $cheapest['label'] ?? '—' ); ?></small>
			</div>
			<div class="ecoflow-rates-stat ecoflow-rates-stat-batt">
				<span><?php esc_html_e( 'SOC', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-soc-now><?php echo esc_html( isset( $plan['soc_now'] ) ? number_format( (float) $plan['soc_now'], 0 ) . '%' : '—' ); ?></strong>
				<small data-ecoflow-soc-end>
					<?php
					$pro_now   = $plan['soc_now_pro'] ?? null;
					$delta_now = $plan['soc_now_delta'] ?? null;
					if ( null !== $pro_now || null !== $delta_now ) {
						echo esc_html(
							sprintf(
								/* translators: 1: Pro SOC, 2: 1500 SOC */
								__( 'Pro %s%% · 1500 %s%%', 'gaming-hub' ),
								null !== $pro_now ? number_format( (float) $pro_now, 0 ) : '—',
								null !== $delta_now ? number_format( (float) $delta_now, 0 ) : '—'
							)
						);
					} elseif ( isset( $plan['soc_end'] ) ) {
						printf(
							/* translators: %s: percent */
							esc_html__( '24時 %s%%', 'gaming-hub' ),
							esc_html( number_format( (float) $plan['soc_end'], 0 ) )
						);
					} else {
						esc_html_e( '残量予測', 'gaming-hub' );
					}
					?>
				</small>
			</div>
			<div class="ecoflow-rates-stat ecoflow-rates-stat-pv">
				<span><?php esc_html_e( 'PV', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-pv-now><?php echo esc_html( gaming_hub_format_ecoflow_watts( $solar_now ) ); ?></strong>
				<small data-ecoflow-pv-today>
					<?php
					if ( isset( $plan['solar_today_kwh'] ) ) {
						printf(
							/* translators: %s: kWh */
							esc_html__( '今日 %s kWh', 'gaming-hub' ),
							esc_html( number_format( (float) $plan['solar_today_kwh'], 1 ) )
						);
					} else {
						esc_html_e( '発電見込み', 'gaming-hub' );
					}
					?>
				</small>
			</div>
		</div>

		<?php if ( ! empty( $hourly ) ) : ?>
			<?php
			$soc_ticks = array( 100, 75, 50, 25, 0 );
			$yen_ticks = array();
			for ( $i = 0; $i < 5; $i++ ) {
				$yen_ticks[] = $max - ( $span * $i / 4 );
			}
			?>
			<div class="ecoflow-rate-chart">
				<div class="ecoflow-rate-y ecoflow-rate-y-soc" aria-hidden="true">
					<span class="ecoflow-rate-y-unit"><?php esc_html_e( '%', 'gaming-hub' ); ?></span>
					<?php foreach ( $soc_ticks as $tick ) : ?>
						<span><?php echo esc_html( (string) $tick ); ?></span>
					<?php endforeach; ?>
				</div>
				<div class="ecoflow-rate-plot">
					<div class="ecoflow-rate-track" role="img" aria-label="<?php esc_attr_e( '本日の時間別単価・発電見込み・Pro / 1500 残量予測', 'gaming-hub' ); ?>">
						<svg class="ecoflow-solar-line" viewBox="0 0 240 100" preserveAspectRatio="none" aria-hidden="true">
							<polygon class="ecoflow-solar-delta" data-ecoflow-solar-delta-area points="<?php echo esc_attr( $solar_delta_area ); ?>"></polygon>
							<polygon class="ecoflow-solar-pro" data-ecoflow-solar-area points="<?php echo esc_attr( $solar_area ); ?>"></polygon>
							<polyline data-ecoflow-solar-line points="<?php echo esc_attr( $solar_polyline ); ?>" vector-effect="non-scaling-stroke"></polyline>
						</svg>
						<?php foreach ( $hourly as $hour ) : ?>
							<?php
							$hour_num = (int) $hour['hour'];
							$is_now   = $hour_num === $now_hour;
							$soc_pct  = $soc_series[ $hour_num ] ?? null;
							$has_soc  = is_numeric( $soc_pct );
							$pro_h    = isset( $soc_bar_pro[ $hour_num ] ) ? max( 0, min( 100, (float) $soc_bar_pro[ $hour_num ] ) ) : ( $has_soc ? max( 0, min( 100, (float) $soc_pct ) ) : 0 );
							$delta_h  = isset( $soc_bar_delta[ $hour_num ] ) ? max( 0, min( 100, (float) $soc_bar_delta[ $hour_num ] ) ) : 0;
							$soc_kind = is_array( $plan['soc_chart_kind'] ?? null ) ? ( $plan['soc_chart_kind'][ $hour_num ] ?? '' ) : '';
							$col_class = 'ecoflow-rate-col';
							if ( $is_now ) {
								$col_class .= ' is-now';
							}
							if ( ! $has_soc ) {
								$col_class .= ' is-empty';
							} elseif ( 'actual' === $soc_kind || 'live' === $soc_kind ) {
								$col_class .= ' is-actual';
							} elseif ( 'forecast' === $soc_kind ) {
								$col_class .= ' is-forecast';
							}
							$pro_now_h = $plan['soc_series_pro'][ $hour_num ] ?? null;
							$delta_now_h = $plan['soc_series_delta'][ $hour_num ] ?? null;
							$tip = $hour['label'];
							if ( $has_soc ) {
								$tip .= ' ' . number_format( (float) $soc_pct, 0 ) . '%';
							}
							if ( is_numeric( $pro_now_h ) || is_numeric( $delta_now_h ) ) {
								$tip .= ' · Pro ' . ( is_numeric( $pro_now_h ) ? number_format( (float) $pro_now_h, 0 ) . '%' : '—' );
								$tip .= ' · 1500 ' . ( is_numeric( $delta_now_h ) ? number_format( (float) $delta_now_h, 0 ) . '%' : '—' );
							}
							?>
							<div class="<?php echo esc_attr( $col_class ); ?>">
								<?php if ( $is_now ) : ?>
									<span class="ecoflow-rate-now-pip"><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
								<?php endif; ?>
								<span class="ecoflow-soc-stack" data-ecoflow-soc-bar title="<?php echo esc_attr( $tip ); ?>">
									<span
										class="ecoflow-rate-bar ecoflow-soc-bar-pro"
										data-ecoflow-soc-bar-pro
										style="height: <?php echo esc_attr( (string) round( $pro_h, 1 ) ); ?>%;"
									></span>
									<span
										class="ecoflow-rate-bar ecoflow-soc-bar-delta"
										data-ecoflow-soc-bar-delta
										style="height: <?php echo esc_attr( (string) round( $delta_h, 1 ) ); ?>%;"
									></span>
								</span>
							</div>
						<?php endforeach; ?>
						<svg class="ecoflow-price-line" viewBox="0 0 240 100" preserveAspectRatio="none" aria-hidden="true">
							<polyline data-ecoflow-price-line points="<?php echo esc_attr( $price_polyline ); ?>" vector-effect="non-scaling-stroke"></polyline>
						</svg>
					</div>
					<div class="ecoflow-rate-hours" aria-hidden="true">
						<?php foreach ( $hourly as $hour ) : ?>
							<?php
							$hour_num   = (int) $hour['hour'];
							$is_now     = $hour_num === $now_hour;
							$show_label = 0 === $hour_num % 3 || $is_now;
							?>
							<span class="ecoflow-rate-hour<?php echo $is_now ? ' is-now' : ''; ?>"><?php echo $show_label ? esc_html( (string) $hour_num ) : ''; ?></span>
						<?php endforeach; ?>
					</div>
				</div>
				<div class="ecoflow-rate-y ecoflow-rate-y-yen" aria-hidden="true">
					<span class="ecoflow-rate-y-unit"><?php esc_html_e( '円', 'gaming-hub' ); ?></span>
					<?php foreach ( $yen_ticks as $tick_yen ) : ?>
						<span data-ecoflow-yen-tick><?php echo esc_html( number_format( $tick_yen, 1 ) ); ?></span>
					<?php endforeach; ?>
				</div>
			</div>
			<p class="ecoflow-rate-legend"><?php esc_html_e( '黄棒: Pro 残量 · 青棒: 1500 残量（合算%）· 橙: 発電見込み Pro 800W + 1500 500W · 青緑線: LOOOP 請求単価', 'gaming-hub' ); ?></p>
		<?php endif; ?>
	<?php endif; ?>
</section>
