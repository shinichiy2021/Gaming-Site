<?php
/**
 * EcoFlow dashboard template
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed>|WP_Error $status Dashboard data or error.
 */

$status = isset( $args['status'] ) ? $args['status'] : gaming_hub_get_ecoflow_status();
?>

<section class="ecoflow-dashboard" aria-label="<?php esc_attr_e( 'EcoFlow Device Status', 'gaming-hub' ); ?>">
	<?php if ( is_wp_error( $status ) ) : ?>
		<div class="ecoflow-setup-panel">
			<p class="ecoflow-setup-title"><?php echo esc_html( $status->get_error_message() ); ?></p>
			<?php gaming_hub_render_ecoflow_setup_instructions(); ?>
		</div>
	<?php else : ?>
		<?php
		$plan = is_array( $status['charge_plan'] ?? null ) ? $status['charge_plan'] : array();
		$needs_grid = ! empty( $plan['needs_grid'] );
		$can_approve = ! empty( $plan['can_approve'] );
		$approved = ! empty( $plan['is_approved_current'] );
		$stale = ! empty( $plan['needs_reapprove'] );
		$slots = is_array( $plan['slots'] ?? null ) ? $plan['slots'] : array();
		$today = wp_date( 'Y-m-d' );
		$now_hour = (int) wp_date( 'G' );
		$send_notice = isset( $plan['send_notice'] ) && is_array( $plan['send_notice'] ) ? $plan['send_notice'] : null;
		$notice_ts = ! empty( $send_notice['at'] ) ? strtotime( (string) $send_notice['at'] ) : 0;
		$notice_fresh = $notice_ts && ( time() - $notice_ts ) <= 90;
		$notice_classes = 'ecoflow-send-toast';
		if ( $send_notice && empty( $send_notice['ok'] ) ) {
			$notice_classes .= ' is-error';
		}
		if ( $notice_fresh ) {
			$notice_classes .= ' is-visible';
		}

		$today_by_hour       = array();
		$next_charge_labels  = array();
		foreach ( $slots as $slot ) {
			if ( ! is_array( $slot ) ) {
				continue;
			}
			$slot_date = (string) ( $slot['date'] ?? '' );
			$slot_hour = (int) ( $slot['hour'] ?? -1 );
			if ( $slot_date === $today && $slot_hour >= 0 && $slot_hour <= 23 ) {
				$today_by_hour[ $slot_hour ] = $slot;
			} elseif ( $slot_date !== $today && 'charge' === ( $slot['mode'] ?? '' ) ) {
				$next_charge_labels[] = (string) ( $slot['label'] ?? '' );
			}
		}

		$charge_w    = max( 1, (int) ( $plan['charge_w'] ?? 1000 ) );
		$solar_hours = is_array( $plan['solar_chart'] ?? null )
			? $plan['solar_chart']
			: ( is_array( $plan['solar_hours'] ?? null ) ? $plan['solar_hours'] : array() );
		$solar_cap   = max( 1, (int) ( $plan['solar_capacity_w'] ?? ( defined( 'GAMING_HUB_ECOFLOW_SOLAR_CAPACITY_W' ) ? GAMING_HUB_ECOFLOW_SOLAR_CAPACITY_W : 1300 ) ) );

		$plan_prices = array();
		$last_yen    = 30.0;
		for ( $h = 0; $h < 24; $h++ ) {
			$yen = $today_by_hour[ $h ]['yen'] ?? null;
			if ( null !== $yen ) {
				$last_yen = (float) $yen;
			}
			$plan_prices[ $h ] = $last_yen;
		}
		$plan_min  = 0;
		$plan_max  = $plan_prices ? max( $plan_prices ) : 70;
		$plan_max  = max( $plan_max, $plan_min + 1 );
		$plan_span = max( 1, $plan_max - $plan_min );
		$plan_price_points = array();
		for ( $h = 0; $h < 24; $h++ ) {
			$price = (float) $plan_prices[ $h ];
			$y     = max( 0, min( 100, 100 - ( ( $price - $plan_min ) / $plan_span ) * 100 ) );
			$plan_price_points[] = ( ( $h + 0.5 ) * 10 ) . ',' . round( $y, 1 );
		}
		$show_delta_soc = defined( 'GAMING_HUB_ECOFLOW_PLAN_SHOW_DELTA_SOC' ) && GAMING_HUB_ECOFLOW_PLAN_SHOW_DELTA_SOC;
		$soc_series = is_array( $plan['soc_series'] ?? null ) ? $plan['soc_series'] : array();
		$soc_bar_pro = is_array( $plan['soc_bar_pro'] ?? null ) ? $plan['soc_bar_pro'] : array();
		$soc_bar_delta = is_array( $plan['soc_bar_delta'] ?? null ) ? $plan['soc_bar_delta'] : array();
		$solar_pro_h = is_array( $plan['solar_chart_pro'] ?? null ) ? $plan['solar_chart_pro'] : array();
		$solar_delta_h = is_array( $plan['solar_chart_delta'] ?? null ) ? $plan['solar_chart_delta'] : array();
		if ( ! $solar_pro_h && $solar_hours ) {
			$split         = function_exists( 'gaming_hub_ecoflow_split_solar_hours' )
				? gaming_hub_ecoflow_split_solar_hours( $solar_hours )
				: array( 'pro' => $solar_hours, 'delta' => array() );
			$solar_pro_h   = $split['pro'];
			$solar_delta_h = $split['delta'];
		}
		$plan_solar_stack = function_exists( 'gaming_hub_ecoflow_chart_solar_stack_points' )
			? gaming_hub_ecoflow_chart_solar_stack_points( $solar_pro_h, $solar_delta_h, $solar_cap )
			: array( 'delta_area' => '', 'pro_area' => '', 'total_line' => '' );
		$plan_price_polyline = implode( ' ', $plan_price_points );
		$plan_solar_polyline = $plan_solar_stack['total_line'];
		$plan_solar_area     = $plan_solar_stack['pro_area'];
		$plan_solar_delta_area = $plan_solar_stack['delta_area'];
		$ac_hours = is_array( $plan['ac_chart'] ?? null ) ? $plan['ac_chart'] : array();
		$ac_cap   = max(
			$solar_cap,
			max( 1, (int) ( $plan['ac_chart_cap'] ?? ( defined( 'GAMING_HUB_ECOFLOW_AC_MAX_W' ) ? GAMING_HUB_ECOFLOW_AC_MAX_W : 1000 ) ) )
		);
		$plan_ac_polyline = function_exists( 'gaming_hub_ecoflow_chart_watts_line' )
			? gaming_hub_ecoflow_chart_watts_line( $ac_hours, $ac_cap )
			: '';

		$now_slot   = $today_by_hour[ $now_hour ] ?? array();
		$now_mode   = (string) ( $now_slot['mode'] ?? 'idle' );
		$mode_label = array(
			'charge' => __( '充電', 'gaming-hub' ),
			'solar'  => __( '太陽光', 'gaming-hub' ),
			'idle'   => __( '充電オフ', 'gaming-hub' ),
			'past'   => __( '経過', 'gaming-hub' ),
		);
		$soc_ticks  = array( 100, 75, 50, 25, 0 );
		$plan_yen_ticks = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$plan_yen_ticks[] = $plan_max - ( $plan_span * $i / 4 );
		}
		$next_note = $next_charge_labels
			? sprintf(
				/* translators: %s: hour ranges */
				__( '翌 %s も充電', 'gaming-hub' ),
				implode( '、', $next_charge_labels )
			)
			: '';
		?>
		<div
			class="<?php echo esc_attr( $notice_classes ); ?>"
			data-ecoflow-send-toast
			<?php if ( ! empty( $send_notice['id'] ) ) : ?>
				data-notice-id="<?php echo esc_attr( (string) $send_notice['id'] ); ?>"
			<?php endif; ?>
			role="status"
			<?php echo $notice_fresh ? '' : 'hidden'; ?>
		><?php echo $notice_fresh ? esc_html( (string) ( $send_notice['message'] ?? '' ) ) : ''; ?></div>
		<div
			class="ecoflow-plan<?php echo $needs_grid ? ' is-deficit' : ' is-ok'; ?><?php echo $approved ? ' is-approved' : ''; ?><?php echo $stale ? ' is-stale' : ''; ?>"
			data-plan-id="<?php echo esc_attr( $plan['plan_id'] ?? '' ); ?>"
		>
			<nav class="ecoflow-plan-day-nav" aria-label="<?php esc_attr_e( '計画の日付', 'gaming-hub' ); ?>">
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-plan-day="yesterday"><?php esc_html_e( '昨日', 'gaming-hub' ); ?></button>
				<button type="button" class="ecoflow-plan-cancel is-active" data-ecoflow-plan-day="today"><?php esc_html_e( '今日', 'gaming-hub' ); ?></button>
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-plan-day="tomorrow"><?php esc_html_e( '明日', 'gaming-hub' ); ?></button>
			</nav>
			<div class="ecoflow-plan-header ecoflow-plan-head">
				<div>
					<p class="ecoflow-plan-kicker"><?php esc_html_e( 'AI PLAN', 'gaming-hub' ); ?></p>
					<h3 data-ecoflow-field="plan_title"><?php esc_html_e( '今日の充電計画', 'gaming-hub' ); ?></h3>
					<p class="ecoflow-plan-note" data-ecoflow-field="plan_note"><?php echo esc_html( $plan['note'] ?? '' ); ?></p>
				</div>
			</div>

			<div class="ecoflow-rates-hud ecoflow-plan-hud">
				<div class="ecoflow-rates-stat ecoflow-plan-stat-now is-<?php echo esc_attr( sanitize_html_class( $now_mode ) ); ?>">
					<span><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_now_mode"><?php echo esc_html( $mode_label[ $now_mode ] ?? $now_mode ); ?></strong>
					<small data-ecoflow-field="plan_now_watts">
						<?php
						echo null === ( $now_slot['watts'] ?? null )
							? '—'
							: esc_html( gaming_hub_format_ecoflow_watts( $now_slot['watts'] ) );
						?>
					</small>
				</div>
				<div class="ecoflow-rates-stat">
					<span><?php esc_html_e( 'WINDOW', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_window"><?php echo esc_html( $plan['window_label'] ?? '—' ); ?></strong>
					<small data-ecoflow-field="plan_window_price">
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
					<strong data-ecoflow-field="plan_deficit"><?php echo esc_html( isset( $plan['deficit_kwh'] ) ? number_format_i18n( (float) $plan['deficit_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
					<small data-ecoflow-field="plan_deficit_label"><?php echo esc_html( (string) ( $plan['deficit_hud_label'] ?? __( '今日の不足', 'gaming-hub' ) ) ); ?></small>
				</div>
				<div class="ecoflow-rates-stat ecoflow-rates-stat-pv">
					<span><?php esc_html_e( 'PV', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_solar"><?php echo esc_html( isset( $plan['solar_hud_kwh'] ) ? number_format_i18n( (float) $plan['solar_hud_kwh'], 1 ) . ' kWh' : ( isset( $plan['solar_remaining_kwh'] ) ? number_format_i18n( (float) $plan['solar_remaining_kwh'], 1 ) . ' kWh' : '—' ) ); ?></strong>
					<small data-ecoflow-field="plan_solar_hud_label"><?php echo esc_html( (string) ( $plan['solar_hud_label'] ?? __( '残り予想発電', 'gaming-hub' ) ) ); ?></small>
				</div>
			</div>

			<div class="ecoflow-rate-chart ecoflow-plan-chart<?php echo $show_delta_soc ? '' : ' is-pro-soc-only'; ?>">
				<div class="ecoflow-rate-y ecoflow-rate-y-soc" aria-hidden="true">
					<span class="ecoflow-rate-y-unit"><?php echo $show_delta_soc ? esc_html__( '合算%', 'gaming-hub' ) : esc_html__( '%', 'gaming-hub' ); ?></span>
					<?php foreach ( $soc_ticks as $tick ) : ?>
						<span><?php echo esc_html( (string) $tick ); ?></span>
					<?php endforeach; ?>
				</div>
				<div class="ecoflow-rate-plot">
					<div class="ecoflow-rate-track" data-ecoflow-plan-track role="img" aria-label="<?php esc_attr_e( '本日のグリッド充電計画・残量予測・発電見込み・AC出力見込み・請求単価', 'gaming-hub' ); ?>">
						<svg class="ecoflow-solar-line" viewBox="0 0 240 100" preserveAspectRatio="none" aria-hidden="true">
							<polygon class="ecoflow-solar-delta" data-ecoflow-plan-solar-delta-area points="<?php echo esc_attr( $plan_solar_delta_area ); ?>"></polygon>
							<polygon class="ecoflow-solar-pro" data-ecoflow-plan-solar-area points="<?php echo esc_attr( $plan_solar_area ); ?>"></polygon>
							<polyline data-ecoflow-plan-solar-line points="<?php echo esc_attr( $plan_solar_polyline ); ?>" vector-effect="non-scaling-stroke"></polyline>
						</svg>
						<?php for ( $h = 0; $h < 24; $h++ ) : ?>
							<?php
							$slot      = $today_by_hour[ $h ] ?? array();
							$mode      = (string) ( $slot['mode'] ?? 'idle' );
							$is_now    = $h === $now_hour;
							$is_charge = 'charge' === $mode;
							$soc_pct   = $soc_series[ $h ] ?? null;
							$has_soc   = is_numeric( $soc_pct );
							$pro_h     = isset( $soc_bar_pro[ $h ] ) ? max( 0, min( 100, (float) $soc_bar_pro[ $h ] ) ) : ( $has_soc ? max( 0, min( 100, (float) $soc_pct ) ) : 0 );
							$delta_h   = $show_delta_soc && isset( $soc_bar_delta[ $h ] ) ? max( 0, min( 100, (float) $soc_bar_delta[ $h ] ) ) : 0;
							$charge_h  = $is_charge
								? max( 8, min( 100, ( (int) ( $slot['watts'] ?? $charge_w ) / $charge_w ) * 100 ) )
								: 0;
							$col_class = 'ecoflow-rate-col ecoflow-plan-col is-' . sanitize_html_class( $mode );
							if ( $is_now ) {
								$col_class .= ' is-now';
							}
							if ( ! $has_soc ) {
								$col_class .= ' is-empty';
							}
							$tip_parts = array( sprintf( '%d:00', $h ), $mode_label[ $mode ] ?? $mode );
							if ( $has_soc ) {
								$tip_parts[] = 'Pro ' . number_format_i18n( (float) $soc_pct, 0 ) . '%';
							}
							if ( $is_charge ) {
								$tip_parts[] = gaming_hub_format_ecoflow_watts( $slot['watts'] ?? $charge_w );
							}
							if ( isset( $slot['yen'] ) && null !== $slot['yen'] ) {
								$tip_parts[] = number_format_i18n( (float) $slot['yen'], 1 ) . ' 円';
							}
							$ac_w_h = (int) round( max( 0, (float) ( $ac_hours[ $h ] ?? 0 ) ) );
							$tip_parts[] = 'AC ' . number_format_i18n( $ac_w_h ) . ' W';
							?>
							<div class="<?php echo esc_attr( $col_class ); ?>" data-ecoflow-plan-col data-hour="<?php echo esc_attr( (string) $h ); ?>">
								<?php if ( $is_now ) : ?>
									<span class="ecoflow-rate-now-pip"><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
								<?php endif; ?>
								<span
									class="ecoflow-plan-charge-bar"
									data-ecoflow-plan-charge-bar
									style="height: <?php echo esc_attr( (string) round( $charge_h, 1 ) ); ?>%;"
									<?php echo $is_charge ? '' : 'hidden'; ?>
								></span>
								<span class="ecoflow-soc-stack" title="<?php echo esc_attr( implode( ' · ', $tip_parts ) ); ?>">
									<span
										class="ecoflow-rate-bar ecoflow-soc-bar-pro"
										data-ecoflow-plan-bar
										style="height: <?php echo esc_attr( (string) round( $pro_h, 1 ) ); ?>%;"
									></span>
									<span
										class="ecoflow-rate-bar ecoflow-soc-bar-delta"
										data-ecoflow-plan-bar-delta
										style="height: <?php echo esc_attr( (string) round( $delta_h, 1 ) ); ?>%;"
										<?php echo $show_delta_soc ? '' : 'hidden'; ?>
									></span>
								</span>
							</div>
						<?php endfor; ?>
						<svg class="ecoflow-price-line" viewBox="0 0 240 100" preserveAspectRatio="none" aria-hidden="true">
							<polyline data-ecoflow-plan-price-line points="<?php echo esc_attr( $plan_price_polyline ); ?>" vector-effect="non-scaling-stroke"></polyline>
						</svg>
						<svg class="ecoflow-ac-line" viewBox="0 0 240 100" preserveAspectRatio="none" aria-hidden="true">
							<polyline class="ecoflow-ac-line-under" data-ecoflow-plan-ac-line points="<?php echo esc_attr( $plan_ac_polyline ); ?>" vector-effect="non-scaling-stroke"></polyline>
							<polyline class="ecoflow-ac-line-over" data-ecoflow-plan-ac-line points="<?php echo esc_attr( $plan_ac_polyline ); ?>" vector-effect="non-scaling-stroke"></polyline>
						</svg>
					</div>
					<div class="ecoflow-rate-hours" aria-hidden="true">
						<?php for ( $h = 0; $h < 24; $h++ ) : ?>
							<?php
							$is_now     = $h === $now_hour;
							$show_label = 0 === $h % 3 || $is_now;
							?>
							<span class="ecoflow-rate-hour<?php echo $is_now ? ' is-now' : ''; ?>" data-ecoflow-plan-hour data-hour="<?php echo esc_attr( (string) $h ); ?>"><?php echo $show_label ? esc_html( (string) $h ) : ''; ?></span>
						<?php endfor; ?>
					</div>
				</div>
				<div class="ecoflow-rate-y ecoflow-rate-y-yen" aria-hidden="true">
					<span class="ecoflow-rate-y-unit"><?php esc_html_e( '円', 'gaming-hub' ); ?></span>
					<?php foreach ( $plan_yen_ticks as $tick_yen ) : ?>
						<span data-ecoflow-plan-yen-tick><?php echo esc_html( number_format( $tick_yen, 1 ) ); ?></span>
					<?php endforeach; ?>
				</div>
			</div>
			<p class="ecoflow-rate-legend"><?php echo $show_delta_soc
				? esc_html__( '黄棒: Pro 残量W · 橙棒: 1500 残量W · 棒の高さ: 合算容量に対する割合 · 金の帯: グリッド充電（計画）· 橙の帯: 発電見込み Pro 800W + 1500 500W · 朱橙線: AC出力見込み · 青緑線: 請求単価', 'gaming-hub' )
				: esc_html__( '黄棒: Pro 残量 · 金の帯: グリッド充電（計画）· 橙の帯: 発電見込み Pro 800W + 1500 500W · 朱橙線: AC出力見込み · 青緑線: 請求単価', 'gaming-hub' ); ?></p>
			<p class="ecoflow-plan-next" data-ecoflow-plan-next <?php echo $next_note ? '' : 'hidden'; ?>><?php echo esc_html( $next_note ); ?></p>

			<details class="ecoflow-plan-more">
				<summary><?php esc_html_e( '内訳を見る', 'gaming-hub' ); ?></summary>
				<p class="ecoflow-plan-limits">
					<?php
					printf(
						/* translators: 1: charge watts, 2: idle watts, 3: dc watts, 4: reserve on, 5: reserve off */
						esc_html__( '充電時 %1$s W / それ以外 %2$s W · DC 12V→1500 常時 %3$s W · 予備残量 グリッドOn %4$s%% / Off %5$s%%', 'gaming-hub' ),
						esc_html( number_format_i18n( (int) ( $plan['charge_w'] ?? 0 ) ) ),
						esc_html( number_format_i18n( (int) ( $plan['idle_w'] ?? 0 ) ) ),
						esc_html( number_format_i18n( (int) ( $plan['dc1500_w'] ?? 100 ) ) ),
						esc_html( number_format_i18n( defined( 'GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_ON' ) ? GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_ON : 100 ) ),
						esc_html( number_format_i18n( defined( 'GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_OFF' ) ? GAMING_HUB_ECOFLOW_BACKUP_RESERVE_GRID_OFF : 5 ) )
					);
					?>
					<span data-ecoflow-field="plan_provider">
						<?php
						if ( ! empty( $plan['price_provider'] ) ) {
							echo ' · ' . esc_html( $plan['price_provider'] );
						}
						?>
					</span>
				</p>
				<div class="ecoflow-plan-grid">
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e( '外気温（多治見）', 'gaming-hub' ); ?></span>
						<strong data-ecoflow-field="plan_temp">
							<?php
							echo isset( $plan['temp_now'] ) && null !== $plan['temp_now']
								? esc_html( number_format_i18n( (float) $plan['temp_now'], 1 ) . ' ℃' )
								: '—';
							?>
						</strong>
						<small data-ecoflow-field="plan_temp_meta">
							<?php
							if ( isset( $plan['temp_max'] ) && null !== $plan['temp_max'] ) {
								printf(
									/* translators: 1: min C, 2: max C */
									esc_html__( '最低 %1$s℃ / 最高 %2$s℃', 'gaming-hub' ),
									esc_html( number_format_i18n( (float) ( $plan['temp_min'] ?? $plan['temp_max'] ), 1 ) ),
									esc_html( number_format_i18n( (float) $plan['temp_max'], 1 ) )
								);
							}
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e( 'エアコン予想', 'gaming-hub' ); ?></span>
						<strong data-ecoflow-field="plan_ac"><?php echo esc_html( isset( $plan['ac_today_kwh'] ) ? number_format_i18n( (float) $plan['ac_today_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
						<small data-ecoflow-field="plan_ac_meta">
							<?php
							printf(
								/* translators: 1: watts now, 2: start C, 3: start watts, 4: max C */
								esc_html__( 'いま %1$s W · %2$s℃で %3$s W開始 / %4$s℃以上で 1 kW', 'gaming-hub' ),
								esc_html( number_format_i18n( (int) ( $plan['ac_now_w'] ?? 0 ) ) ),
								esc_html( number_format_i18n( (float) ( $plan['ac_start_c'] ?? 28 ), 0 ) ),
								esc_html( number_format_i18n( (int) ( $plan['ac_start_w'] ?? 300 ) ) ),
								'35'
							);
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e( '今日の発電見込み', 'gaming-hub' ); ?></span>
						<strong data-ecoflow-field="plan_solar_today"><?php echo esc_html( isset( $plan['solar_today_kwh'] ) ? number_format_i18n( (float) $plan['solar_today_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
						<small>
							<?php
							$panel_note = function_exists( 'gaming_hub_ecoflow_solar_panel_label' )
								? gaming_hub_ecoflow_solar_panel_label()
								: __( 'Pro 800 W + 1500 500 W', 'gaming-hub' );
							echo esc_html( $panel_note . ' · ' . __( '多治見', 'gaming-hub' ) );
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e( '推奨充電ウィンドウ', 'gaming-hub' ); ?></span>
						<strong data-ecoflow-field="plan_window"><?php echo esc_html( $plan['window_label'] ?? '—' ); ?></strong>
						<small data-ecoflow-field="plan_window_price">
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
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e( '今日の天気', 'gaming-hub' ); ?></span>
						<strong data-ecoflow-field="plan_weather"><?php echo esc_html( $plan['weather'] ?? '—' ); ?></strong>
						<small data-ecoflow-field="plan_weather_meta"><?php echo esc_html( $plan['weather_location'] ?? '' ); ?></small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e( '残り予想使用（リビングエアコン他）', 'gaming-hub' ); ?></span>
						<strong data-ecoflow-field="plan_load"><?php echo esc_html( isset( $plan['room_remaining_kwh'] ) ? number_format_i18n( (float) $plan['room_remaining_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
						<small data-ecoflow-field="plan_load_meta">
							<?php
							if ( isset( $plan['room_daily_kwh'] ) ) {
								printf(
									/* translators: 1: daily kWh, 2: AC kWh, 3: base kWh */
									esc_html__( '今日 %1$s kWh（AC %2$s + その他 %3$s）', 'gaming-hub' ),
									esc_html( number_format_i18n( (float) $plan['room_daily_kwh'], 1 ) ),
									esc_html( number_format_i18n( (float) ( $plan['ac_today_kwh'] ?? 0 ), 1 ) ),
									esc_html( number_format_i18n( (float) ( $plan['base_today_kwh'] ?? 0 ), 1 ) )
								);
							}
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e( '1500 DC（常時）', 'gaming-hub' ); ?></span>
						<?php
						$dc1500_w = (int) ( $plan['dc1500_w'] ?? ( defined( 'GAMING_HUB_ECOFLOW_DELTA1500_DC_W' ) ? GAMING_HUB_ECOFLOW_DELTA1500_DC_W : 100 ) );
						$dc1500_kwh = isset( $plan['dc1500_remaining_kwh'] )
							? (float) $plan['dc1500_remaining_kwh']
							: round( ( $dc1500_w / 1000 ) * ( 24 - (int) wp_date( 'G' ) ), 1 );
						?>
						<strong data-ecoflow-field="plan_dc1500"><?php echo esc_html( number_format_i18n( $dc1500_kwh, 1 ) . ' kWh' ); ?></strong>
						<small data-ecoflow-field="plan_dc1500_meta">
							<?php
							printf(
								/* translators: %s: kilowatts */
								esc_html__( '%s kW 固定', 'gaming-hub' ),
								esc_html( number_format_i18n( $dc1500_w / 1000, 2 ) )
							);
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e( '使える電池（容量の 80% · 予備 20%除く）', 'gaming-hub' ); ?></span>
						<strong data-ecoflow-field="plan_battery"><?php echo esc_html( isset( $plan['usable_battery_kwh'] ) ? number_format_i18n( (float) $plan['usable_battery_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
					</div>
				</div>
			</details>

			<div class="ecoflow-plan-actions" data-ecoflow-plan-actions>
				<p class="ecoflow-plan-approval" data-ecoflow-field="plan_approval"><?php echo esc_html( $plan['approval_note'] ?? '' ); ?></p>
				<?php if ( $can_approve ) : ?>
					<button type="button" class="ecoflow-plan-approve" data-ecoflow-approve<?php echo $approved && ! $stale ? ' hidden' : ''; ?>>
						<?php esc_html_e( 'このスケジュールを承認して Pro 3 に送る', 'gaming-hub' ); ?>
					</button>
					<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cancel<?php echo ( $approved || $stale ) ? '' : ' hidden'; ?>>
						<?php esc_html_e( '承認を取り消す', 'gaming-hub' ); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>

		<div class="ecoflow-dashboard-header">
			<h2><?php esc_html_e( 'デバイスステータス', 'gaming-hub' ); ?></h2>
			<?php if ( ! empty( $status['updated_at'] ) ) : ?>
				<p class="ecoflow-updated">
					<?php
					printf(
						/* translators: %s: last updated time */
						esc_html__( '最終更新: %s', 'gaming-hub' ),
						esc_html( $status['updated_at'] )
					);
					?>
				</p>
			<?php endif; ?>
		</div>

		<div class="ecoflow-device-bars">
			<div class="ecoflow-device-bar">
				<div>
					<strong><?php echo esc_html( $status['device_name'] ); ?></strong>
					<span class="ecoflow-sn"><?php echo esc_html( $status['device_sn'] ); ?></span>
				</div>
				<span class="ecoflow-online-badge <?php echo $status['online'] ? 'is-online' : 'is-offline'; ?>">
					<?php echo $status['online'] ? esc_html__( 'オンライン', 'gaming-hub' ) : esc_html__( 'オフライン', 'gaming-hub' ); ?>
				</span>
			</div>

			<?php if ( ! empty( $status['secondary'] ) && is_array( $status['secondary'] ) ) : ?>
				<div class="ecoflow-device-bar ecoflow-device-bar-secondary">
					<div>
						<strong><?php echo esc_html( $status['secondary']['device_name'] ); ?></strong>
						<span class="ecoflow-sn">
							<?php
							echo ! empty( $status['secondary']['device_sn'] )
								? esc_html( $status['secondary']['device_sn'] )
								: esc_html__( '独立運転', 'gaming-hub' );
							?>
						</span>
					</div>
					<span class="ecoflow-online-badge <?php echo ! empty( $status['secondary']['online'] ) ? 'is-online' : 'is-offline'; ?>">
						<?php echo ! empty( $status['secondary']['online'] ) ? esc_html__( 'オンライン', 'gaming-hub' ) : esc_html__( 'オフライン', 'gaming-hub' ); ?>
					</span>
				</div>
				<?php if ( ! empty( $status['secondary']['inferred_note'] ) ) : ?>
					<p class="ecoflow-inferred-note"><?php echo esc_html( $status['secondary']['inferred_note'] ); ?></p>
				<?php endif; ?>
			<?php endif; ?>
		</div>

		<div
			id="ecoflow-energy-flow-root"
			class="ecoflow-energy-flow-root"
			data-initial="<?php echo esc_attr( wp_json_encode( gaming_hub_ecoflow_flow_payload( $status ) ) ); ?>"
		></div>

		<p class="ecoflow-page-nav">
			<a href="<?php echo esc_url( gaming_hub_energy_url() ); ?>"><?php esc_html_e( '発電ログ →', 'gaming-hub' ); ?></a>
		</p>

		<div class="ecoflow-stats-grid">
			<?php
			$pro_grid = is_array( $status['pro_grid_charge'] ?? null ) ? $status['pro_grid_charge'] : array();
			?>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'Pro グリッド補充電', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="pro_grid_charge">
					<?php
					echo ! empty( $pro_grid['active'] )
						? esc_html( gaming_hub_format_ecoflow_watts( $pro_grid['watts'] ?? 0 ) )
						: esc_html__( '待機', 'gaming-hub' );
					?>
				</strong>
				<small data-ecoflow-field="pro_grid_charge_note"><?php echo esc_html( (string) ( $pro_grid['message'] ?? '' ) ); ?></small>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'ハイボルト入力 (Pro)', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="hv_in"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['hv_in'] ?? 0 ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'AC 出力 → リビングエアコン他', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="ac_out"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['ac_out'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label" data-ecoflow-field="solar_delta_label"><?php echo esc_html( gaming_hub_ecoflow_solar_delta_label( (string) ( $status['secondary']['solar_in_source'] ?? $status['solar_in_source'] ?? '' ) ) ); ?></span>
				<strong data-ecoflow-field="solar_delta"><?php echo esc_html( gaming_hub_format_ecoflow_delta_solar( $status ) ); ?></strong>
			</div>
			<?php if ( ! empty( $status['secondary'] ) ) : ?>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label" data-ecoflow-field="secondary_soc_label">
						<?php echo esc_html( gaming_hub_ecoflow_pack_soc_label( $status['secondary'] ) ); ?>
					</span>
					<strong data-ecoflow-field="secondary_soc">
						<?php
						echo esc_html(
							gaming_hub_format_ecoflow_percent( $status['secondary']['battery_percent'] ?? null )
						);
						?>
					</strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label" data-ecoflow-field="secondary_remain_label"><?php echo esc_html( gaming_hub_ecoflow_pack_capacity_label( $status['secondary'] ) ); ?></span>
					<strong data-ecoflow-field="secondary_remain">
						<?php
						echo esc_html(
							gaming_hub_format_ecoflow_pack(
								$status['secondary']['remain_capacity'] ?? null,
								$status['secondary']['capacity_wh'] ?? gaming_hub_ecoflow_main_pack_default_wh()
							)
						);
						?>
					</strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( 'AC 入力 (1500)', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="delta_ac_in"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['secondary']['ac_in'] ?? null ) ); ?></strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( '1500 グリッド補充電', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="delta_rescue">
						<?php
						$mqtt_live = ! empty( $status['secondary']['mqtt_live'] );
						$rescue = is_array( $status['secondary']['grid_rescue'] ?? null ) ? $status['secondary']['grid_rescue'] : array();
						if ( ! $mqtt_live ) {
							echo esc_html( gaming_hub_ecoflow_unavailable_label() );
						} else {
							echo ! empty( $rescue['active'] )
								? esc_html( gaming_hub_format_ecoflow_watts( $rescue['watts'] ?? 0 ) )
								: esc_html__( '待機 (5%以下で開始)', 'gaming-hub' );
						}
						?>
					</strong>
					<small data-ecoflow-field="delta_rescue_note"><?php echo esc_html( (string) ( $rescue['message'] ?? '' ) ); ?></small>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( '残量 (Extra Battery)', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="extra_soc">
						<?php
						echo esc_html(
							gaming_hub_format_ecoflow_percent( $status['secondary']['extra']['battery_percent'] ?? null )
						);
						?>
					</strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label" data-ecoflow-field="extra_remain_label">
						<?php
						$extra_pack = is_array( $status['secondary']['extra'] ?? null ) ? $status['secondary']['extra'] : array();
						echo esc_html( gaming_hub_ecoflow_extra_capacity_label( $extra_pack ) );
						?>
					</span>
					<strong data-ecoflow-field="extra_remain">
						<?php
						echo esc_html(
							gaming_hub_format_ecoflow_pack(
								$extra_pack['remain_capacity'] ?? null,
								$extra_pack['capacity_wh'] ?? GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH
							)
						);
						?>
					</strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label" data-ecoflow-field="ups_out_label">
						<?php
					echo 'ecoflow' === gaming_hub_ecoflow_ups_source( $status )
						? esc_html__( 'AC 出力 → UPS (1500 · 実測 · MQTT)', 'gaming-hub' )
						: esc_html__( 'AC 出力 → UPS (未取得)', 'gaming-hub' );
						?>
					</span>
					<strong data-ecoflow-field="ups_out"><?php echo esc_html( gaming_hub_format_ecoflow_ups( $status ) ); ?></strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( '状態 (1500)', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="secondary_charge_state"><?php echo esc_html( $status['secondary']['charge_state'] ); ?></strong>
				</div>
			<?php endif; ?>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'DC 出力 (Pro)', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="dc_out"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['dc_out'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'バッテリー温度', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="battery_temp"><?php echo esc_html( gaming_hub_format_ecoflow_temp( $status['battery_temp'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( '残容量 (Pro)', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="remain_capacity"><?php echo esc_html( gaming_hub_format_ecoflow_wh( $status['remain_capacity'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( '状態 (Pro)', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="charge_state_stat"><?php echo esc_html( $status['charge_state'] ); ?></strong>
			</div>
		</div>
	<?php endif; ?>
</section>
