<?php
/**
 * Tesla gasoline-savings log (daily / hourly charts + calendar details).
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $calendar Month payload.
 */

$calendar = isset( $args['calendar'] ) && is_array( $args['calendar'] ) ? $args['calendar'] : array();
$days     = is_array( $calendar['days'] ?? null ) ? $calendar['days'] : array();
$weekdays = is_array( $calendar['weekdays'] ?? null ) ? $calendar['weekdays'] : array( '日', '月', '火', '水', '木', '金', '土' );
$totals   = is_array( $calendar['totals'] ?? null ) ? $calendar['totals'] : array();
$now      = is_array( $calendar['now'] ?? null ) ? $calendar['now'] : array();
$today_st = is_array( $calendar['today_stats'] ?? null ) ? $calendar['today_stats'] : array();
$start_w  = (int) ( $calendar['start_wday'] ?? 0 );
$today    = (string) ( $calendar['today'] ?? '' );
$now_hour = (int) wp_date( 'G' );

$format_km = static function ( $value, $digits = 1 ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format( (float) $value, $digits ) . ' km';
};

$format_l = static function ( $value ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format( (float) $value, 2 ) . ' L';
};

$format_yen = static function ( $value ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format( (float) $value, 0 ) . ' 円';
};

$format_tick = static function ( $value, $yen = false ) {
	$n = (float) $value;
	if ( $yen ) {
		return number_format( $n, $n >= 10 ? 0 : 1 );
	}
	if ( $n >= 10 ) {
		return number_format( $n, 0 );
	}
	return number_format( $n, $n >= 1 ? 1 : 2 );
};

$now_yen_h = (int) ( $now['saved_yen_per_h'] ?? 0 );
$now_idle  = ! empty( $now['asleep'] ) || $now_yen_h <= 0;
$now_text  = $now_idle ? __( '待機', 'gaming-hub' ) : ( number_format( $now_yen_h ) . ' 円/時' );

$km_max    = max( 1, (float) ( $calendar['km_max'] ?? 1 ) );
$yen_max   = max( 1, (float) ( $calendar['yen_max'] ?? 1 ) );
$km_ticks  = is_array( $calendar['km_ticks'] ?? null ) ? $calendar['km_ticks'] : array( $km_max, 0 );
$yen_ticks = is_array( $calendar['yen_ticks'] ?? null ) ? $calendar['yen_ticks'] : array( $yen_max, 0 );
$day_count = max( 1, count( $days ) );

$yen_points = array();
foreach ( $days as $i => $cell ) {
	$x     = ( ( $i + 0.5 ) / $day_count ) * 100;
	$yen_y = 100 - ( max( 0, (float) ( $cell['saved_yen'] ?? 0 ) ) / $yen_max ) * 100;
	$yen_points[] = round( $x, 2 ) . ',' . round( max( 0, min( 100, $yen_y ) ), 1 );
}

$today_hours     = is_array( $calendar['today_hours'] ?? null ) ? $calendar['today_hours'] : array();
$show_today      = ! empty( $today_hours );
$today_km_max    = max( 1, (float) ( $calendar['today_km_max'] ?? 1 ) );
$today_yen_max   = max( 1, (float) ( $calendar['today_yen_max'] ?? 1 ) );
$today_km_ticks  = is_array( $calendar['today_km_ticks'] ?? null ) ? $calendar['today_km_ticks'] : array( $today_km_max, 0 );
$today_yen_ticks = is_array( $calendar['today_yen_ticks'] ?? null ) ? $calendar['today_yen_ticks'] : array( $today_yen_max, 0 );
$today_yen_pts   = array();
if ( $show_today ) {
	foreach ( $today_hours as $i => $hour_row ) {
		$x     = ( ( $i + 0.5 ) / 24 ) * 100;
		$yen_y = 100 - ( max( 0, (float) ( $hour_row['saved_yen'] ?? 0 ) ) / $today_yen_max ) * 100;
		$today_yen_pts[] = round( $x, 2 ) . ',' . round( max( 0, min( 100, $yen_y ) ), 1 );
	}
}

$price_label = (string) ( $now['price_label'] ?? '' );
?>

<section
	id="gas"
	class="ecoflow-plan ecoflow-cal tesla-cal"
	aria-label="<?php esc_attr_e( 'ガソリン節約ログ', 'gaming-hub' ); ?>"
	data-tesla-gas
	data-month="<?php echo esc_attr( $calendar['month'] ?? '' ); ?>"
>
	<div class="ecoflow-plan-header ecoflow-plan-head">
		<div>
			<p class="ecoflow-plan-kicker"><?php esc_html_e( 'GAS LOG', 'gaming-hub' ); ?></p>
			<h3><?php esc_html_e( 'ガソリン節約ログ', 'gaming-hub' ); ?></h3>
			<p class="ecoflow-plan-note">
				<?php esc_html_e( '走行距離を普通車 15 km/L 換算し、多治見のレギュラー単価と EV 電力代（150 Wh/km × LOOOP）の差を節約額にしています。', 'gaming-hub' ); ?>
				<?php if ( $price_label ) : ?>
					<span data-tesla-gas-price><?php echo esc_html( $price_label ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<p class="ecoflow-plan-limits">
			<span data-tesla-gas-label><?php echo esc_html( $calendar['label'] ?? '' ); ?></span>
			<span class="ecoflow-cal-nav">
				<button type="button" class="ecoflow-plan-cancel" data-tesla-gas-prev data-month="<?php echo esc_attr( $calendar['prev'] ?? '' ); ?>"><?php esc_html_e( '前月', 'gaming-hub' ); ?></button>
				<button type="button" class="ecoflow-plan-cancel" data-tesla-gas-next data-month="<?php echo esc_attr( $calendar['next'] ?? '' ); ?>"><?php esc_html_e( '翌月', 'gaming-hub' ); ?></button>
			</span>
		</p>
	</div>

	<div class="ecoflow-rates-hud ecoflow-plan-hud ecoflow-cal-hud">
		<div class="ecoflow-rates-stat ecoflow-rates-stat-pv">
			<span><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-now><?php echo esc_html( $now_text ); ?></strong>
			<small data-tesla-gas-now-speed>
				<?php
				printf(
					/* translators: %s: speed km/h */
					esc_html__( '%s km/h', 'gaming-hub' ),
					esc_html( (string) (int) ( $now['speed_km'] ?? 0 ) )
				);
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( 'KM', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-month-km><?php echo esc_html( $format_km( $totals['km'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '月計 走行', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-save">
			<span><?php esc_html_e( 'SAVE', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-month-save><?php echo esc_html( $format_yen( $totals['saved_yen'] ?? null ) ); ?></strong>
			<small><?php esc_html_e( '今月の節約', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( 'GAS', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-month-l><?php echo esc_html( $format_l( $totals['gas_l'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '今月 節約ガソリン', 'gaming-hub' ); ?></small>
		</div>
	</div>

	<div class="ecoflow-rates-hud ecoflow-plan-hud ecoflow-cal-hud ecoflow-cal-hud-yen" data-tesla-gas-today>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( 'KM', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-today-km><?php echo esc_html( $format_km( $today_st['km'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '今日 走行', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( 'GAS', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-today-l><?php echo esc_html( $format_l( $today_st['gas_l'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '今日 普通車換算', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-buy">
			<span><?php esc_html_e( 'EV', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-today-ev><?php echo esc_html( $format_yen( $today_st['ev_yen'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '今日 電気代', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-net">
			<span><?php esc_html_e( 'SAVE', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-today-save><?php echo esc_html( $format_yen( $today_st['saved_yen'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '今日 節約', 'gaming-hub' ); ?></small>
		</div>
	</div>

	<div class="ecoflow-cal-today" data-tesla-gas-today-chart <?php echo $show_today ? '' : 'hidden'; ?>>
		<p class="ecoflow-cal-chart-label"><?php esc_html_e( '今日の時間別', 'gaming-hub' ); ?></p>
		<div class="ecoflow-rate-chart ecoflow-cal-chart ecoflow-cal-chart-today tesla-cal-chart-today">
			<div class="ecoflow-rate-y ecoflow-rate-y-kwh" aria-hidden="true">
				<span class="ecoflow-rate-y-unit"><?php esc_html_e( 'km', 'gaming-hub' ); ?></span>
				<?php foreach ( $today_km_ticks as $tick ) : ?>
					<span data-tesla-gas-today-km-tick><?php echo esc_html( $format_tick( $tick ) ); ?></span>
				<?php endforeach; ?>
			</div>
			<div class="ecoflow-rate-plot">
				<div class="ecoflow-rate-track" data-tesla-gas-today-track role="img" aria-label="<?php esc_attr_e( '今日の時間別走行と節約額', 'gaming-hub' ); ?>">
					<?php foreach ( $today_hours as $hour_row ) : ?>
						<?php
						$h        = (int) ( $hour_row['hour'] ?? 0 );
						$is_now   = $h === $now_hour;
						$has_data = ! empty( $hour_row['has_data'] );
						$km       = $hour_row['km'] ?? null;
						$height   = ( null !== $km && $today_km_max > 0 )
							? max( 0, min( 100, ( (float) $km / $today_km_max ) * 100 ) )
							: 0;
						$col_class = 'ecoflow-rate-col ecoflow-cal-col';
						if ( $is_now ) {
							$col_class .= ' is-now';
						}
						if ( ! $has_data ) {
							$col_class .= ' is-empty';
						}
						$tip = array( sprintf( '%d:00', $h ) );
						if ( null !== $km ) {
							$tip[] = $format_km( $km );
						}
						if ( isset( $hour_row['saved_yen'] ) && null !== $hour_row['saved_yen'] ) {
							$tip[] = $format_yen( $hour_row['saved_yen'] );
						}
						?>
						<div class="<?php echo esc_attr( $col_class ); ?>" data-tesla-gas-today-col data-hour="<?php echo esc_attr( (string) $h ); ?>">
							<?php if ( $is_now ) : ?>
								<span class="ecoflow-rate-now-pip"><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
							<?php endif; ?>
							<span
								class="ecoflow-rate-bar ecoflow-cal-pv-bar"
								style="height: <?php echo esc_attr( (string) round( $height, 1 ) ); ?>%;"
								title="<?php echo esc_attr( implode( ' · ', $tip ) ); ?>"
							></span>
						</div>
					<?php endforeach; ?>
					<svg class="ecoflow-price-line" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
						<polyline data-tesla-gas-today-yen-line points="<?php echo esc_attr( implode( ' ', $today_yen_pts ) ); ?>" vector-effect="non-scaling-stroke"></polyline>
					</svg>
				</div>
				<div class="ecoflow-rate-hours" aria-hidden="true">
					<?php for ( $h = 0; $h < 24; $h++ ) : ?>
						<?php
						$is_now     = $h === $now_hour;
						$show_label = 0 === $h % 3 || $is_now;
						?>
						<span class="ecoflow-rate-hour<?php echo $is_now ? ' is-now' : ''; ?>"><?php echo $show_label ? esc_html( (string) $h ) : ''; ?></span>
					<?php endfor; ?>
				</div>
			</div>
			<div class="ecoflow-rate-y ecoflow-rate-y-yen" aria-hidden="true">
				<span class="ecoflow-rate-y-unit"><?php esc_html_e( '円', 'gaming-hub' ); ?></span>
				<?php foreach ( $today_yen_ticks as $tick ) : ?>
					<span data-tesla-gas-today-yen-tick><?php echo esc_html( $format_tick( $tick, true ) ); ?></span>
				<?php endforeach; ?>
			</div>
		</div>
	</div>

	<p class="ecoflow-cal-chart-label"><?php esc_html_e( '今月の日別', 'gaming-hub' ); ?></p>
	<div class="ecoflow-rate-chart ecoflow-cal-chart">
		<div class="ecoflow-rate-y ecoflow-rate-y-kwh" aria-hidden="true">
			<span class="ecoflow-rate-y-unit"><?php esc_html_e( 'km', 'gaming-hub' ); ?></span>
			<?php foreach ( $km_ticks as $tick ) : ?>
				<span data-tesla-gas-km-tick><?php echo esc_html( $format_tick( $tick ) ); ?></span>
			<?php endforeach; ?>
		</div>
		<div class="ecoflow-rate-plot">
			<div class="ecoflow-rate-track" data-tesla-gas-track role="img" aria-label="<?php esc_attr_e( '今月の日別走行と節約額', 'gaming-hub' ); ?>">
				<?php foreach ( $days as $cell ) : ?>
					<?php
					$is_today  = ! empty( $cell['is_today'] );
					$has_data  = ! empty( $cell['has_data'] );
					$is_future = $today && ( (string) ( $cell['date'] ?? '' ) > $today );
					$km        = $cell['km'] ?? null;
					$height    = ( null !== $km && $km_max > 0 )
						? max( 0, min( 100, ( (float) $km / $km_max ) * 100 ) )
						: 0;
					$col_class = 'ecoflow-rate-col ecoflow-cal-col';
					if ( $is_today ) {
						$col_class .= ' is-now';
					}
					if ( ! $has_data || $is_future ) {
						$col_class .= ' is-empty';
					}
					$tip = array( (string) ( $cell['date'] ?? '' ) );
					if ( $has_data ) {
						$tip[] = $format_km( $cell['km'] );
						$tip[] = $format_l( $cell['gas_l'] );
						$tip[] = $format_yen( $cell['saved_yen'] );
					}
					?>
					<div
						class="<?php echo esc_attr( $col_class ); ?>"
						data-tesla-gas-col
						data-date="<?php echo esc_attr( (string) ( $cell['date'] ?? '' ) ); ?>"
					>
						<?php if ( $is_today ) : ?>
							<span class="ecoflow-rate-now-pip"><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
						<?php endif; ?>
						<span
							class="ecoflow-rate-bar ecoflow-cal-pv-bar"
							style="height: <?php echo esc_attr( (string) round( $height, 1 ) ); ?>%;"
							title="<?php echo esc_attr( implode( ' · ', $tip ) ); ?>"
						></span>
					</div>
				<?php endforeach; ?>
				<svg class="ecoflow-price-line" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
					<polyline data-tesla-gas-yen-line points="<?php echo esc_attr( implode( ' ', $yen_points ) ); ?>" vector-effect="non-scaling-stroke"></polyline>
				</svg>
			</div>
			<div class="ecoflow-rate-hours" aria-hidden="true" data-tesla-gas-hours>
				<?php foreach ( $days as $cell ) : ?>
					<?php
					$d          = (int) ( $cell['day'] ?? 0 );
					$is_today   = ! empty( $cell['is_today'] );
					$show_label = 1 === $d || 0 === $d % 5 || $is_today;
					?>
					<span class="ecoflow-rate-hour<?php echo $is_today ? ' is-now' : ''; ?>"><?php echo $show_label ? esc_html( (string) $d ) : ''; ?></span>
				<?php endforeach; ?>
			</div>
		</div>
		<div class="ecoflow-rate-y ecoflow-rate-y-yen" aria-hidden="true">
			<span class="ecoflow-rate-y-unit"><?php esc_html_e( '円', 'gaming-hub' ); ?></span>
			<?php foreach ( $yen_ticks as $tick ) : ?>
				<span data-tesla-gas-yen-tick><?php echo esc_html( $format_tick( $tick, true ) ); ?></span>
			<?php endforeach; ?>
		</div>
	</div>
	<p class="ecoflow-rate-legend"><?php esc_html_e( '橙棒: 走行 km · 青緑線: 節約円', 'gaming-hub' ); ?></p>

	<details class="ecoflow-plan-more">
		<summary><?php esc_html_e( '日ごとの数字を見る', 'gaming-hub' ); ?></summary>
		<div class="ecoflow-cal-weekdays" aria-hidden="true">
			<?php foreach ( $weekdays as $label ) : ?>
				<span><?php echo esc_html( $label ); ?></span>
			<?php endforeach; ?>
		</div>
		<ol class="ecoflow-plan-slots ecoflow-cal-grid" data-tesla-gas-grid>
			<?php for ( $i = 0; $i < $start_w; $i++ ) : ?>
				<li class="ecoflow-plan-slot is-blank"></li>
			<?php endfor; ?>
			<?php foreach ( $days as $cell ) : ?>
				<?php
				$is_today = ! empty( $cell['is_today'] );
				$has_data = ! empty( $cell['has_data'] );
				$is_past  = ! $is_today && $today && ( (string) ( $cell['date'] ?? '' ) < $today );
				$classes  = array( 'ecoflow-plan-slot' );
				if ( $is_today ) {
					$classes[] = 'is-now';
				}
				if ( $has_data ) {
					$classes[] = 'is-solar';
				}
				if ( $is_past && ! $has_data ) {
					$classes[] = 'is-past';
				}
				if ( $has_data && (float) ( $cell['saved_yen'] ?? 0 ) > 0 ) {
					$classes[] = 'is-charge';
				}
				?>
				<li
					class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
					data-tesla-gas-day="<?php echo esc_attr( $cell['date'] ); ?>"
				>
					<span class="ecoflow-plan-slot-hour"><?php echo esc_html( (string) ( $cell['day'] ?? '' ) ); ?></span>
					<span class="ecoflow-plan-slot-watts">KM <b data-k="km"><?php echo esc_html( $format_km( $cell['km'] ?? null ) ); ?></b></span>
					<span class="ecoflow-plan-slot-mode">GAS <b data-k="l"><?php echo esc_html( $format_l( $cell['gas_l'] ?? null ) ); ?></b></span>
					<span class="ecoflow-plan-slot-yen">SAVE <b data-k="save"><?php echo esc_html( $format_yen( $cell['saved_yen'] ?? null ) ); ?></b></span>
				</li>
			<?php endforeach; ?>
		</ol>
	</details>
</section>
