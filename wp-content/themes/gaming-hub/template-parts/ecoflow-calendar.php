<?php
/**
 * EcoFlow generation log (daily / hourly charts + calendar details).
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
$start_w  = (int) ( $calendar['start_wday'] ?? 0 );
$today    = (string) ( $calendar['today'] ?? '' );
$now_hour = (int) wp_date( 'G' );

$format_kwh = static function ( $value, $digits = 2 ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format( (float) $value, $digits );
};

$format_yen = static function ( $value ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format( (float) $value, 0 ) . ' 円';
};

$format_w = static function ( $value ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format_i18n( (int) $value ) . ' W';
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

$kwh_max   = max( 1, (float) ( $calendar['kwh_max'] ?? 1 ) );
$yen_max   = max( 1, (float) ( $calendar['yen_max'] ?? 1 ) );
$kwh_ticks = is_array( $calendar['kwh_ticks'] ?? null ) ? $calendar['kwh_ticks'] : array( $kwh_max, 0 );
$yen_ticks = is_array( $calendar['yen_ticks'] ?? null ) ? $calendar['yen_ticks'] : array( $yen_max, 0 );
$day_count = max( 1, count( $days ) );

$out_points = array();
$yen_points = array();
foreach ( $days as $i => $cell ) {
	$x      = ( ( $i + 0.5 ) / $day_count ) * 100;
	$out_y  = 100 - ( max( 0, (float) ( $cell['output_kwh'] ?? 0 ) ) / $kwh_max ) * 100;
	$yen_y  = 100 - ( max( 0, (float) ( $cell['saved_yen'] ?? 0 ) ) / $yen_max ) * 100;
	$out_points[] = round( $x, 2 ) . ',' . round( max( 0, min( 100, $out_y ) ), 1 );
	$yen_points[] = round( $x, 2 ) . ',' . round( max( 0, min( 100, $yen_y ) ), 1 );
}

$today_hours     = is_array( $calendar['today_hours'] ?? null ) ? $calendar['today_hours'] : array();
$show_today      = ! empty( $today_hours );
$today_kwh_max   = max( 1, (float) ( $calendar['today_kwh_max'] ?? 1 ) );
$today_kwh_ticks = is_array( $calendar['today_kwh_ticks'] ?? null ) ? $calendar['today_kwh_ticks'] : array( $today_kwh_max, 0 );
$today_out_pts   = array();
if ( $show_today ) {
	foreach ( $today_hours as $i => $hour_row ) {
		$x     = ( ( $i + 0.5 ) / 24 ) * 100;
		$out_y = 100 - ( max( 0, (float) ( $hour_row['output_kwh'] ?? 0 ) ) / $today_kwh_max ) * 100;
		$today_out_pts[] = round( $x, 2 ) . ',' . round( max( 0, min( 100, $out_y ) ), 1 );
	}
}
?>

<section class="ecoflow-plan ecoflow-cal" aria-label="<?php esc_attr_e( '発電ログ', 'gaming-hub' ); ?>" data-ecoflow-cal data-month="<?php echo esc_attr( $calendar['month'] ?? '' ); ?>">
	<div class="ecoflow-plan-header ecoflow-plan-head">
		<div>
			<p class="ecoflow-plan-kicker"><?php esc_html_e( 'GEN LOG', 'gaming-hub' ); ?></p>
			<h3><?php esc_html_e( '発電ログ', 'gaming-hub' ); ?></h3>
			<p class="ecoflow-plan-note"><?php esc_html_e( 'Pro 3 ハイボルト + Delta 1500 Low Volt の合算を積算しています。入力・出力も両機の合計です。節約額は慎一の部屋（Pro AC 出力）と UPS（1500 AC 出力）× その時間の買電単価から、1500 と Pro のグリッド AC 入力（買電）× 同単価を引いた額です。', 'gaming-hub' ); ?></p>
		</div>
		<p class="ecoflow-plan-limits">
			<span data-ecoflow-cal-label><?php echo esc_html( $calendar['label'] ?? '' ); ?></span>
			<span class="ecoflow-cal-nav">
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-prev data-month="<?php echo esc_attr( $calendar['prev'] ?? '' ); ?>"><?php esc_html_e( '前月', 'gaming-hub' ); ?></button>
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-next data-month="<?php echo esc_attr( $calendar['next'] ?? '' ); ?>"><?php esc_html_e( '翌月', 'gaming-hub' ); ?></button>
			</span>
		</p>
	</div>

	<div class="ecoflow-rates-hud ecoflow-plan-hud ecoflow-cal-hud">
		<div class="ecoflow-rates-stat ecoflow-rates-stat-pv">
			<span><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-now-pv><?php echo esc_html( $format_w( $now['solar'] ?? null ) ); ?></strong>
			<small>
				IN <span data-ecoflow-cal-now-in><?php echo esc_html( $format_w( $now['input'] ?? null ) ); ?></span>
				· OUT <span data-ecoflow-cal-now-out><?php echo esc_html( $format_w( $now['output'] ?? null ) ); ?></span>
			</small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( 'PV', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-month-pv><?php echo esc_html( $format_kwh( $totals['solar_kwh'] ?? 0 ) . ' kWh' ); ?></strong>
			<small><?php esc_html_e( '月計 発電', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-save">
			<span><?php esc_html_e( 'SAVE', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-month-save><?php echo esc_html( $format_yen( $totals['saved_yen'] ?? null ) ); ?></strong>
			<small><?php esc_html_e( '今月の節約', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( 'I/O', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-month-out><?php echo esc_html( $format_kwh( $totals['output_kwh'] ?? 0 ) . ' kWh' ); ?></strong>
			<small data-ecoflow-cal-month-in>
				<?php
				printf(
					/* translators: %s: input kWh */
					esc_html__( '入力 %s kWh', 'gaming-hub' ),
					esc_html( $format_kwh( $totals['input_kwh'] ?? 0 ) )
				);
				?>
			</small>
		</div>
	</div>

	<?php
	$today_yen = is_array( $calendar['today_yen'] ?? null ) ? $calendar['today_yen'] : array();
	?>
	<div class="ecoflow-rates-hud ecoflow-plan-hud ecoflow-cal-hud ecoflow-cal-hud-yen" data-ecoflow-cal-today-yen>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-room">
			<span><?php esc_html_e( 'ROOM', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-today-room><?php echo esc_html( $format_yen( $today_yen['room_yen'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '今日 部屋節約', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-ups">
			<span><?php esc_html_e( 'UPS', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-today-ups><?php echo esc_html( $format_yen( $today_yen['ups_yen'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '今日 UPS節約', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-buy">
			<span><?php esc_html_e( 'GRID', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-today-grid><?php echo esc_html( $format_yen( $today_yen['buy_yen'] ?? ( ( $today_yen['grid_yen'] ?? 0 ) + ( $today_yen['pro_grid_yen'] ?? 0 ) ) ) ); ?></strong>
			<small><?php esc_html_e( '今日 買電', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-net">
			<span><?php esc_html_e( 'NET', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-today-net><?php echo esc_html( $format_yen( $today_yen['net_yen'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '今日 差引', 'gaming-hub' ); ?></small>
		</div>
	</div>

	<div class="ecoflow-cal-today" data-ecoflow-cal-today <?php echo $show_today ? '' : 'hidden'; ?>>
		<p class="ecoflow-cal-chart-label"><?php esc_html_e( '今日の時間別', 'gaming-hub' ); ?></p>
		<div class="ecoflow-rate-chart ecoflow-cal-chart ecoflow-cal-chart-today">
			<div class="ecoflow-rate-y ecoflow-rate-y-kwh" aria-hidden="true">
				<span class="ecoflow-rate-y-unit"><?php esc_html_e( 'kWh', 'gaming-hub' ); ?></span>
				<?php foreach ( $today_kwh_ticks as $tick ) : ?>
					<span data-ecoflow-cal-today-kwh-tick><?php echo esc_html( $format_tick( $tick ) ); ?></span>
				<?php endforeach; ?>
			</div>
			<div class="ecoflow-rate-plot">
				<div class="ecoflow-rate-track" data-ecoflow-cal-today-track role="img" aria-label="<?php esc_attr_e( '今日の時間別発電と出力', 'gaming-hub' ); ?>">
					<svg class="ecoflow-cal-out-line" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
						<polyline data-ecoflow-cal-today-out-line points="<?php echo esc_attr( implode( ' ', $today_out_pts ) ); ?>" vector-effect="non-scaling-stroke"></polyline>
					</svg>
					<?php foreach ( $today_hours as $hour_row ) : ?>
						<?php
						$h         = (int) ( $hour_row['hour'] ?? 0 );
						$is_now    = $h === $now_hour;
						$has_data  = ! empty( $hour_row['has_data'] );
						$solar     = $hour_row['solar_kwh'] ?? null;
						$height    = ( null !== $solar && $today_kwh_max > 0 )
							? max( 0, min( 100, ( (float) $solar / $today_kwh_max ) * 100 ) )
							: 0;
						$col_class = 'ecoflow-rate-col ecoflow-cal-col';
						if ( $is_now ) {
							$col_class .= ' is-now';
						}
						if ( ! $has_data ) {
							$col_class .= ' is-empty';
						}
						$tip = array( sprintf( '%d:00', $h ) );
						if ( null !== $solar ) {
							$tip[] = $format_kwh( $solar, 2 ) . ' kWh';
						}
						if ( isset( $hour_row['output_kwh'] ) && null !== $hour_row['output_kwh'] ) {
							$tip[] = 'OUT ' . $format_kwh( $hour_row['output_kwh'], 2 );
						}
						?>
						<div class="<?php echo esc_attr( $col_class ); ?>" data-ecoflow-cal-today-col data-hour="<?php echo esc_attr( (string) $h ); ?>">
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
		</div>
	</div>

	<p class="ecoflow-cal-chart-label"><?php esc_html_e( '今月の日別', 'gaming-hub' ); ?></p>
	<div class="ecoflow-rate-chart ecoflow-cal-chart">
		<div class="ecoflow-rate-y ecoflow-rate-y-kwh" aria-hidden="true">
			<span class="ecoflow-rate-y-unit"><?php esc_html_e( 'kWh', 'gaming-hub' ); ?></span>
			<?php foreach ( $kwh_ticks as $tick ) : ?>
				<span data-ecoflow-cal-kwh-tick><?php echo esc_html( $format_tick( $tick ) ); ?></span>
			<?php endforeach; ?>
		</div>
		<div class="ecoflow-rate-plot">
			<div class="ecoflow-rate-track" data-ecoflow-cal-track role="img" aria-label="<?php esc_attr_e( '今月の日別発電・出力・節約額', 'gaming-hub' ); ?>">
				<svg class="ecoflow-cal-out-line" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
					<polyline data-ecoflow-cal-out-line points="<?php echo esc_attr( implode( ' ', $out_points ) ); ?>" vector-effect="non-scaling-stroke"></polyline>
				</svg>
				<?php foreach ( $days as $cell ) : ?>
					<?php
					$is_today  = ! empty( $cell['is_today'] );
					$has_data  = ! empty( $cell['has_data'] );
					$is_future = $today && ( (string) ( $cell['date'] ?? '' ) > $today );
					$solar     = $cell['solar_kwh'] ?? null;
					$height    = ( null !== $solar && $kwh_max > 0 )
						? max( 0, min( 100, ( (float) $solar / $kwh_max ) * 100 ) )
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
						$tip[] = 'PV ' . $format_kwh( $cell['solar_kwh'] );
						$tip[] = 'OUT ' . $format_kwh( $cell['output_kwh'] );
						$tip[] = $format_yen( $cell['saved_yen'] );
					}
					?>
					<div
						class="<?php echo esc_attr( $col_class ); ?>"
						data-ecoflow-cal-col
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
					<polyline data-ecoflow-cal-yen-line points="<?php echo esc_attr( implode( ' ', $yen_points ) ); ?>" vector-effect="non-scaling-stroke"></polyline>
				</svg>
			</div>
			<div class="ecoflow-rate-hours" aria-hidden="true" data-ecoflow-cal-hours>
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
				<span data-ecoflow-cal-yen-tick><?php echo esc_html( $format_tick( $tick, true ) ); ?></span>
			<?php endforeach; ?>
		</div>
	</div>
	<p class="ecoflow-rate-legend"><?php esc_html_e( '橙棒: 発電 kWh · 水色線: 出力 kWh · 青緑線: 節約円', 'gaming-hub' ); ?></p>

	<details class="ecoflow-plan-more">
		<summary><?php esc_html_e( '日ごとの数字を見る', 'gaming-hub' ); ?></summary>
		<div class="ecoflow-cal-weekdays" aria-hidden="true">
			<?php foreach ( $weekdays as $label ) : ?>
				<span><?php echo esc_html( $label ); ?></span>
			<?php endforeach; ?>
		</div>
		<ol class="ecoflow-plan-slots ecoflow-cal-grid" data-ecoflow-cal-grid>
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
					data-ecoflow-cal-day="<?php echo esc_attr( $cell['date'] ); ?>"
				>
					<span class="ecoflow-plan-slot-hour"><?php echo esc_html( (string) ( $cell['day'] ?? '' ) ); ?></span>
					<span class="ecoflow-plan-slot-mode">IN <b data-k="in"><?php echo esc_html( $format_kwh( $cell['input_kwh'] ?? null ) ); ?></b></span>
					<span class="ecoflow-plan-slot-mode">OUT <b data-k="out"><?php echo esc_html( $format_kwh( $cell['output_kwh'] ?? null ) ); ?></b></span>
					<span class="ecoflow-plan-slot-watts">PV <b data-k="pv"><?php echo esc_html( $format_kwh( $cell['solar_kwh'] ?? null ) ); ?></b></span>
					<span class="ecoflow-plan-slot-yen">SAVE <b data-k="save"><?php echo esc_html( $format_yen( $cell['saved_yen'] ?? null ) ); ?></b></span>
				</li>
			<?php endforeach; ?>
		</ol>
	</details>
</section>
