<?php
/**
 * Tesla AI charge plan (cheapest LOOOP hours / 200V AC home charging).
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $plan Charge plan.
 */

$plan       = isset( $args['plan'] ) && is_array( $args['plan'] ) ? $args['plan'] : array();
$needs_grid = ! empty( $plan['needs_grid'] );
$slots      = is_array( $plan['slots'] ?? null ) ? $plan['slots'] : array();
$view_date  = (string) ( $plan['plan_date'] ?? wp_date( 'Y-m-d' ) );
$now_hour   = (int) wp_date( 'G' );
$is_today   = ( $plan['plan_day'] ?? 'today' ) === 'today';

$by_hour            = array();
$next_hours         = array();
foreach ( $slots as $slot ) {
	if ( ! is_array( $slot ) ) {
		continue;
	}
	$slot_date = (string) ( $slot['date'] ?? '' );
	$slot_hour = (int) ( $slot['hour'] ?? -1 );
	if ( $slot_date === $view_date && $slot_hour >= 0 && $slot_hour <= 23 ) {
		$by_hour[ $slot_hour ] = $slot;
	} elseif ( $slot_date !== $view_date && 'charge' === ( $slot['mode'] ?? '' ) && $slot_hour >= 0 ) {
		$next_hours[] = $slot_hour;
	}
}
sort( $next_hours );
$next_charge_labels = array();
if ( $next_hours ) {
	$start = $prev = (int) $next_hours[0];
	for ( $i = 1, $n = count( $next_hours ); $i < $n; $i++ ) {
		$hour = (int) $next_hours[ $i ];
		if ( $hour === $prev + 1 ) {
			$prev = $hour;
			continue;
		}
		$next_charge_labels[] = gaming_hub_tesla_plan_hour_range_label( $start, $prev );
		$start = $prev = $hour;
	}
	$next_charge_labels[] = gaming_hub_tesla_plan_hour_range_label( $start, $prev );
}

$charge_w  = max( 1, (int) ( $plan['charge_w'] ?? GAMING_HUB_TESLA_PLAN_CHARGE_W ) );
$soc_series = is_array( $plan['soc_series'] ?? null ) ? $plan['soc_series'] : array();
$drive_w    = is_array( $plan['drive_chart'] ?? null ) ? $plan['drive_chart'] : array();
$drive_cap  = max( 1, (int) ( $plan['drive_chart_cap'] ?? 2000 ) );

$plan_prices = array();
$last_yen    = 30.0;
for ( $h = 0; $h < 24; $h++ ) {
	$yen = $by_hour[ $h ]['yen'] ?? null;
	if ( null !== $yen ) {
		$last_yen = (float) $yen;
	}
	$plan_prices[ $h ] = $last_yen;
}
$plan_min  = 0;
$plan_max  = $plan_prices ? max( $plan_prices ) : 70;
$plan_max  = max( $plan_max, $plan_min + 1 );
$plan_span = max( 1, $plan_max - $plan_min );
$price_pts = array();
$drive_pts = array();
for ( $h = 0; $h < 24; $h++ ) {
	$x        = ( ( $h + 0.5 ) * 10 );
	$price_y  = max( 0, min( 100, 100 - ( ( (float) $plan_prices[ $h ] - $plan_min ) / $plan_span ) * 100 ) );
	$drive_y  = max( 0, min( 100, 100 - ( ( (float) ( $drive_w[ $h ] ?? 0 ) ) / $drive_cap ) * 100 ) );
	$price_pts[] = $x . ',' . round( $price_y, 1 );
	$drive_pts[] = $x . ',' . round( $drive_y, 1 );
}

$now_slot = $by_hour[ $now_hour ] ?? array();
$now_mode = (string) ( $now_slot['mode'] ?? 'idle' );
$live_charging = $is_today && ! empty( $plan['live_charging'] );
$live_charge_w = (int) ( $plan['live_charge_w'] ?? 0 );
if ( $live_charging ) {
	$now_mode = 'charge';
}
$mode_label = array(
	'charge' => __( '充電', 'gaming-hub' ),
	'drive'  => __( '走行', 'gaming-hub' ),
	'idle'   => __( '待機', 'gaming-hub' ),
	'past'   => __( '経過', 'gaming-hub' ),
);
$soc_ticks = array( 100, 75, 50, 25, 0 );
$yen_ticks = array();
for ( $i = 0; $i < 5; $i++ ) {
	$yen_ticks[] = $plan_max - ( $plan_span * $i / 4 );
}
$next_note = $next_charge_labels
	? sprintf(
		/* translators: %s: hour ranges */
		__( '翌 %s も充電', 'gaming-hub' ),
		implode( '、', $next_charge_labels )
	)
	: '';
?>

<section
	id="plan"
	class="ecoflow-plan tesla-plan<?php echo $needs_grid ? ' is-deficit' : ' is-ok'; ?>"
	aria-label="<?php esc_attr_e( 'Tesla 充電計画', 'gaming-hub' ); ?>"
	data-tesla-plan
	data-plan-id="<?php echo esc_attr( $plan['plan_id'] ?? '' ); ?>"
	data-plan-date="<?php echo esc_attr( $view_date ); ?>"
	data-initial="<?php echo esc_attr( wp_json_encode( $plan ) ); ?>"
>
	<nav class="ecoflow-plan-day-nav" aria-label="<?php esc_attr_e( '計画の日付', 'gaming-hub' ); ?>">
		<button type="button" class="ecoflow-plan-cancel" data-tesla-plan-day="yesterday"><?php esc_html_e( '昨日', 'gaming-hub' ); ?></button>
		<button type="button" class="ecoflow-plan-cancel is-active" data-tesla-plan-day="today"><?php esc_html_e( '今日', 'gaming-hub' ); ?></button>
		<button type="button" class="ecoflow-plan-cancel" data-tesla-plan-day="tomorrow"><?php esc_html_e( '明日', 'gaming-hub' ); ?></button>
	</nav>
	<div class="ecoflow-plan-header ecoflow-plan-head">
		<div>
			<p class="ecoflow-plan-kicker"><?php esc_html_e( 'AI PLAN', 'gaming-hub' ); ?></p>
			<h3 data-tesla-plan-title><?php echo esc_html( $plan['title'] ?? __( '今日の充電計画', 'gaming-hub' ) ); ?></h3>
			<p class="ecoflow-plan-note" data-tesla-plan-note><?php echo esc_html( $plan['note'] ?? '' ); ?></p>
		</div>
	</div>

	<div class="ecoflow-rates-hud ecoflow-plan-hud">
		<div class="ecoflow-rates-stat ecoflow-plan-stat-now is-<?php echo esc_attr( sanitize_html_class( $now_mode ) ); ?>">
			<span><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
			<strong data-tesla-plan-now-mode><?php echo esc_html( $mode_label[ $now_mode ] ?? $now_mode ); ?></strong>
			<small data-tesla-plan-now-watts>
				<?php
				if ( $live_charging ) {
					echo esc_html( number_format_i18n( max( 0, $live_charge_w ) ) . ' W' );
				} elseif ( 'drive' === $now_mode && isset( $now_slot['drive_km'] ) ) {
					echo esc_html( number_format_i18n( (float) $now_slot['drive_km'], 1 ) . ' km' );
				} elseif ( isset( $now_slot['watts'] ) && null !== $now_slot['watts'] ) {
					echo esc_html( number_format_i18n( (int) $now_slot['watts'] ) . ' W' );
				} else {
					echo '—';
				}
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( 'WINDOW', 'gaming-hub' ); ?></span>
			<strong data-tesla-plan-window><?php echo esc_html( $plan['window_label'] ?? '—' ); ?></strong>
			<small data-tesla-plan-window-price>
				<?php
				if ( isset( $plan['window_avg_yen'] ) && null !== $plan['window_avg_yen'] ) {
					printf(
						/* translators: %s: yen per kWh */
						esc_html__( '平均 %s 円/kWh', 'gaming-hub' ),
						esc_html( number_format_i18n( (float) $plan['window_avg_yen'], 1 ) )
					);
				}
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-plan-stat-buy">
			<span><?php esc_html_e( 'BUY', 'gaming-hub' ); ?></span>
			<strong data-tesla-plan-deficit><?php echo esc_html( isset( $plan['deficit_kwh'] ) ? number_format_i18n( (float) $plan['deficit_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
			<small data-tesla-plan-deficit-label><?php echo esc_html( (string) ( $plan['deficit_hud_label'] ?? __( '今日の不足', 'gaming-hub' ) ) ); ?></small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-rates-stat-pv">
			<span><?php esc_html_e( 'KM', 'gaming-hub' ); ?></span>
			<strong data-tesla-plan-km><?php echo esc_html( isset( $plan['km_hud'] ) ? number_format_i18n( (float) $plan['km_hud'], 1 ) . ' km' : '—' ); ?></strong>
			<small data-tesla-plan-km-label><?php echo esc_html( (string) ( $plan['km_hud_label'] ?? __( '残り走行', 'gaming-hub' ) ) ); ?></small>
		</div>
	</div>

	<div class="ecoflow-rate-chart ecoflow-plan-chart is-pro-soc-only">
		<div class="ecoflow-rate-y ecoflow-rate-y-soc" aria-hidden="true">
			<span class="ecoflow-rate-y-unit"><?php esc_html_e( '%', 'gaming-hub' ); ?></span>
			<?php foreach ( $soc_ticks as $tick ) : ?>
				<span><?php echo esc_html( (string) $tick ); ?></span>
			<?php endforeach; ?>
		</div>
		<div class="ecoflow-rate-plot">
			<div class="ecoflow-rate-track" data-tesla-plan-track role="img" aria-label="<?php esc_attr_e( 'Model 3 の充電計画・残量予測・走行見込み・請求単価', 'gaming-hub' ); ?>">
				<?php for ( $h = 0; $h < 24; $h++ ) : ?>
					<?php
					$slot      = $by_hour[ $h ] ?? array();
					$mode      = (string) ( $slot['mode'] ?? 'idle' );
					$is_now    = $is_today && $h === $now_hour;
					if ( $is_now && $live_charging ) {
						$mode = 'charge';
					}
					$is_charge = 'charge' === $mode;
					$soc_pct   = $soc_series[ $h ] ?? null;
					$has_soc   = is_numeric( $soc_pct );
					$soc_h     = $has_soc ? max( 0, min( 100, (float) $soc_pct ) ) : 0;
					$col_watts = ( $is_now && $live_charging )
						? max( $live_charge_w, (int) ( $slot['watts'] ?? $charge_w ) )
						: (int) ( $slot['watts'] ?? $charge_w );
					$charge_h  = $is_charge
						? max( 8, min( 100, ( $col_watts / $charge_w ) * 100 ) )
						: 0;
					$col_class = 'ecoflow-rate-col ecoflow-plan-col is-' . sanitize_html_class( $mode );
					if ( $is_now ) {
						$col_class .= ' is-now';
					}
					if ( ! $has_soc ) {
						$col_class .= ' is-empty';
					}
					$tip = array( sprintf( '%d:00', $h ), $mode_label[ $mode ] ?? $mode );
					if ( $has_soc ) {
						$tip[] = number_format_i18n( (float) $soc_pct, 0 ) . '%';
					}
					if ( $is_charge ) {
						$tip[] = number_format_i18n( $col_watts ) . ' W';
					}
					if ( isset( $slot['drive_km'] ) && null !== $slot['drive_km'] ) {
						$tip[] = number_format_i18n( (float) $slot['drive_km'], 1 ) . ' km';
					}
					if ( isset( $slot['yen'] ) && null !== $slot['yen'] ) {
						$tip[] = number_format_i18n( (float) $slot['yen'], 1 ) . ' 円';
					}
					?>
					<div class="<?php echo esc_attr( $col_class ); ?>" data-tesla-plan-col data-hour="<?php echo esc_attr( (string) $h ); ?>">
						<?php if ( $is_now ) : ?>
							<span class="ecoflow-rate-now-pip"><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
						<?php endif; ?>
						<span
							class="ecoflow-plan-charge-bar"
							data-tesla-plan-charge-bar
							style="height: <?php echo esc_attr( (string) round( $charge_h, 1 ) ); ?>%;"
							<?php echo $is_charge ? '' : 'hidden'; ?>
						></span>
						<span class="ecoflow-soc-stack" title="<?php echo esc_attr( implode( ' · ', $tip ) ); ?>">
							<span
								class="ecoflow-rate-bar ecoflow-soc-bar-pro"
								data-tesla-plan-bar
								style="height: <?php echo esc_attr( (string) round( $soc_h, 1 ) ); ?>%;"
							></span>
						</span>
					</div>
				<?php endfor; ?>
				<svg class="ecoflow-price-line" viewBox="0 0 240 100" preserveAspectRatio="none" aria-hidden="true">
					<polyline data-tesla-plan-price-line points="<?php echo esc_attr( implode( ' ', $price_pts ) ); ?>" vector-effect="non-scaling-stroke"></polyline>
				</svg>
				<svg class="ecoflow-ac-line" viewBox="0 0 240 100" preserveAspectRatio="none" aria-hidden="true">
					<polyline class="ecoflow-ac-line-under" data-tesla-plan-drive-line points="<?php echo esc_attr( implode( ' ', $drive_pts ) ); ?>" vector-effect="non-scaling-stroke"></polyline>
					<polyline class="ecoflow-ac-line-over" data-tesla-plan-drive-line points="<?php echo esc_attr( implode( ' ', $drive_pts ) ); ?>" vector-effect="non-scaling-stroke"></polyline>
				</svg>
			</div>
			<div class="ecoflow-rate-hours" aria-hidden="true">
				<?php for ( $h = 0; $h < 24; $h++ ) : ?>
					<?php
					$is_now     = $is_today && $h === $now_hour;
					$show_label = 0 === $h % 3 || $is_now;
					?>
					<span class="ecoflow-rate-hour<?php echo $is_now ? ' is-now' : ''; ?>" data-tesla-plan-hour data-hour="<?php echo esc_attr( (string) $h ); ?>"><?php echo $show_label ? esc_html( (string) $h ) : ''; ?></span>
				<?php endfor; ?>
			</div>
		</div>
		<div class="ecoflow-rate-y ecoflow-rate-y-yen" aria-hidden="true">
			<span class="ecoflow-rate-y-unit"><?php esc_html_e( '円', 'gaming-hub' ); ?></span>
			<?php foreach ( $yen_ticks as $tick_yen ) : ?>
				<span data-tesla-plan-yen-tick><?php echo esc_html( number_format( $tick_yen, 1 ) ); ?></span>
			<?php endforeach; ?>
		</div>
	</div>
	<p class="ecoflow-rate-legend"><?php esc_html_e( '黄棒: Model 3 残量 · 金の帯: 自宅充電（計画）· 朱橙線: 走行見込み · 青緑線: 請求単価', 'gaming-hub' ); ?></p>
	<p class="ecoflow-plan-next" data-tesla-plan-next <?php echo $next_note ? '' : 'hidden'; ?>><?php echo esc_html( $next_note ); ?></p>

	<details class="ecoflow-plan-more">
		<summary><?php esc_html_e( '内訳を見る', 'gaming-hub' ); ?></summary>
		<p class="ecoflow-plan-limits">
			<?php
			printf(
				/* translators: 1: amps, 2: kW, 3: daily km, 4: Wh/km, 5: min SOC, 6: daily target, 7: Saturday hour */
				esc_html__( '200V 普通充電 %1$sA · %2$s kW · 1日 %3$s km · %4$s Wh/km · 残量 %5$s–%6$s%% · 土曜 %7$s 時までに 100%%', 'gaming-hub' ),
				esc_html( number_format_i18n( (int) ( $plan['charge_a'] ?? GAMING_HUB_TESLA_PLAN_AMPS ) ) ),
				esc_html( number_format_i18n( $charge_w / 1000, 1 ) ),
				esc_html( number_format_i18n( gaming_hub_tesla_plan_daily_km(), 0 ) ),
				esc_html( number_format_i18n( gaming_hub_tesla_plan_wh_per_km(), 0 ) ),
				esc_html( number_format_i18n( GAMING_HUB_TESLA_PLAN_MIN_SOC ) ),
				esc_html( number_format_i18n( GAMING_HUB_TESLA_PLAN_TARGET_SOC ) ),
				esc_html( number_format_i18n( GAMING_HUB_TESLA_PLAN_SATURDAY_HOUR ) )
			);
			?>
			<span data-tesla-plan-provider>
				<?php
				if ( ! empty( $plan['price_provider'] ) ) {
					echo ' · ' . esc_html( $plan['price_provider'] );
				}
				?>
			</span>
		</p>
		<div class="ecoflow-plan-grid">
			<div class="ecoflow-plan-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'いまの残量', 'gaming-hub' ); ?></span>
				<strong data-tesla-plan-soc-now><?php echo esc_html( isset( $plan['soc_now'] ) ? number_format_i18n( (float) $plan['soc_now'], 0 ) . '%' : '—' ); ?></strong>
				<small data-tesla-plan-soc-end>
					<?php
					if ( isset( $plan['soc_end'] ) && null !== $plan['soc_end'] ) {
						printf(
							/* translators: %s: SOC */
							esc_html__( '計画後 %s%%', 'gaming-hub' ),
							esc_html( number_format_i18n( (float) $plan['soc_end'], 0 ) )
						);
					}
					?>
				</small>
			</div>
			<div class="ecoflow-plan-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( '目標残量', 'gaming-hub' ); ?></span>
				<strong data-tesla-plan-target><?php echo esc_html( isset( $plan['target_soc'] ) ? number_format_i18n( (int) $plan['target_soc'] ) . '%' : '—' ); ?></strong>
				<small data-tesla-plan-target-note><?php echo esc_html( (string) ( $plan['target_note'] ?? __( '電池ケア 20–80%', 'gaming-hub' ) ) ); ?></small>
			</div>
			<div class="ecoflow-plan-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( '推奨充電ウィンドウ', 'gaming-hub' ); ?></span>
				<strong data-tesla-plan-window-card><?php echo esc_html( $plan['window_label'] ?? '—' ); ?></strong>
				<small data-tesla-plan-window-price-card>
					<?php
					if ( isset( $plan['window_avg_yen'] ) && null !== $plan['window_avg_yen'] ) {
						printf(
							esc_html__( '平均 %s 円/kWh', 'gaming-hub' ),
							esc_html( number_format_i18n( (float) $plan['window_avg_yen'], 1 ) )
						);
					}
					?>
				</small>
			</div>
			<div class="ecoflow-plan-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( '走行', 'gaming-hub' ); ?></span>
				<strong data-tesla-plan-drive><?php echo esc_html( isset( $plan['km'] ) ? number_format_i18n( (float) $plan['km'], 1 ) . ' km' : '—' ); ?></strong>
				<small data-tesla-plan-save>
					<?php
					if ( ! empty( $plan['saved_yen'] ) ) {
						printf(
							/* translators: 1: liters, 2: yen */
							esc_html__( '普通車換算 %1$s L · 節約 %2$s 円', 'gaming-hub' ),
							esc_html( number_format_i18n( (float) ( $plan['gas_l'] ?? 0 ), 2 ) ),
							esc_html( number_format_i18n( (int) $plan['saved_yen'] ) )
						);
					}
					?>
				</small>
			</div>
		</div>
	</details>
</section>
