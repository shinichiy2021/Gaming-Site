<?php
/**
 * EcoFlow dashboard template
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed>|WP_Error $status Dashboard data or error.
 */

$status = isset( $args['status'] ) ? $args['status'] : gaming_hub_get_ecoflow_status();
$async  = ! empty( $args['async'] ) && ! is_wp_error( $status );
?>

<section
	class="ecoflow-dashboard<?php echo $async ? ' is-skeleton' : ''; ?>"
	aria-label="<?php esc_attr_e( 'EcoFlow Device Status', 'gaming-hub' ); ?>"
	<?php if ( $async ) : ?>
		data-ecoflow-hydrate="1"
		aria-busy="true"
	<?php endif; ?>
>
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

		$selfpowered = is_array( $plan['selfpowered_schedule'] ?? null ) ? $plan['selfpowered_schedule'] : array();
		$sp_hours    = is_array( $selfpowered['hours'] ?? null ) ? $selfpowered['hours'] : array();
		$sp_rows     = is_array( $selfpowered['rows'] ?? null ) ? $selfpowered['rows'] : array();
		$sp_enabled  = ! empty( $selfpowered['enabled'] );
		if ( $sp_enabled && $sp_hours ) {
			foreach ( $sp_hours as $h => $sp_slot ) {
				$h = (int) $h;
				if ( $h < 0 || $h > 23 || ! is_array( $sp_slot ) ) {
					continue;
				}
				$base = isset( $today_by_hour[ $h ] ) && is_array( $today_by_hour[ $h ] )
					? $today_by_hour[ $h ]
					: array(
						'date' => $today,
						'hour' => $h,
						'yen'  => null,
					);
				// Self-powered owns the day shape: always paint charge / peak / standby.
				$sp_mode       = (string) ( $sp_slot['mode'] ?? 'idle' );
				$base['mode']  = $sp_mode;
				$base['watts'] = 'charge' === $sp_mode
					? (int) ( $sp_slot['watts'] ?? ( $plan['charge_w'] ?? 1000 ) )
					: ( isset( $sp_slot['watts'] ) ? (int) $sp_slot['watts'] : ( $base['watts'] ?? null ) );
				$base['selfpowered'] = $sp_slot;
				$today_by_hour[ $h ] = $base;
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
			'charge' => __('Charge', 'gaming-hub'),
			'bypass' => __('Bypass', 'gaming-hub'),
			'use'    => __('Use pack', 'gaming-hub'),
			'solar'  => __('Solar', 'gaming-hub'),
			'idle'   => __('Use pack', 'gaming-hub'),
			'past'   => __('Past', 'gaming-hub'),
		);
		$soc_ticks  = array( 100, 75, 50, 25, 0 );
		$plan_yen_ticks = array();
		for ( $i = 0; $i < 5; $i++ ) {
			$plan_yen_ticks[] = $plan_max - ( $plan_span * $i / 4 );
		}
		$next_note = $next_charge_labels
			? sprintf(
				/* translators: %s: hour ranges */
				__('Also charging next day %s', 'gaming-hub'),
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
			<nav class="ecoflow-plan-day-nav" aria-label="<?php esc_attr_e('Plan date', 'gaming-hub'); ?>">
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-plan-day="yesterday"><?php esc_html_e('Yesterday', 'gaming-hub'); ?></button>
				<button type="button" class="ecoflow-plan-cancel is-active" data-ecoflow-plan-day="today"><?php esc_html_e('Today', 'gaming-hub'); ?></button>
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-plan-day="tomorrow"><?php esc_html_e('Tomorrow', 'gaming-hub'); ?></button>
			</nav>
			<div class="ecoflow-plan-header ecoflow-plan-head">
				<div>
					<p class="ecoflow-plan-kicker"><?php esc_html_e( 'AI PLAN', 'gaming-hub' ); ?></p>
					<h3 data-ecoflow-field="plan_title"><?php esc_html_e('Today’s energy plan', 'gaming-hub'); ?></h3>
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
					<span data-ecoflow-field="plan_window_kicker"><?php echo ! empty( $selfpowered['enabled'] ) ? esc_html__( 'CHARGE', 'gaming-hub' ) : esc_html__( 'WINDOW', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_window"><?php echo esc_html( $plan['window_label'] ?? '—' ); ?></strong>
					<small data-ecoflow-field="plan_window_price">
						<?php
						if ( isset( $plan['window_avg_yen'] ) && null !== $plan['window_avg_yen'] ) {
							printf(
								/* translators: %s: yen per kWh */
								esc_html__('Avg %s yen/kWh', 'gaming-hub'),
								esc_html( number_format_i18n( (float) $plan['window_avg_yen'], 1 ) )
							);
						}
						?>
					</small>
				</div>
				<div class="ecoflow-rates-stat ecoflow-plan-stat-buy">
					<span><?php esc_html_e( 'BUY', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_deficit"><?php echo esc_html( isset( $plan['deficit_kwh'] ) ? number_format_i18n( (float) $plan['deficit_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
					<small data-ecoflow-field="plan_deficit_label"><?php echo esc_html( (string) ( $plan['deficit_hud_label'] ?? __('Today’s shortfall', 'gaming-hub') ) ); ?></small>
				</div>
				<div class="ecoflow-rates-stat ecoflow-rates-stat-pv">
					<span><?php esc_html_e( 'PV', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_solar"><?php echo esc_html( isset( $plan['solar_hud_kwh'] ) ? number_format_i18n( (float) $plan['solar_hud_kwh'], 1 ) . ' kWh' : ( isset( $plan['solar_remaining_kwh'] ) ? number_format_i18n( (float) $plan['solar_remaining_kwh'], 1 ) . ' kWh' : '—' ) ); ?></strong>
					<small data-ecoflow-field="plan_solar_hud_label"><?php echo esc_html( (string) ( $plan['solar_hud_label'] ?? __('Remaining expected generation', 'gaming-hub') ) ); ?></small>
				</div>
			</div>

			<div class="ecoflow-rate-chart ecoflow-plan-chart<?php echo $show_delta_soc ? '' : ' is-pro-soc-only'; ?><?php echo $sp_enabled ? ' is-selfpowered' : ''; ?>" data-ecoflow-plan-chart>
				<div class="ecoflow-rate-y ecoflow-rate-y-soc" aria-hidden="true">
					<span class="ecoflow-rate-y-unit"><?php esc_html_e( '%', 'gaming-hub' ); ?></span>
					<?php foreach ( $soc_ticks as $tick ) : ?>
						<span><?php echo esc_html( (string) $tick ); ?></span>
					<?php endforeach; ?>
				</div>
				<div class="ecoflow-rate-plot">
					<div class="ecoflow-rate-track" data-ecoflow-plan-track role="img" aria-label="<?php echo $sp_enabled ? esc_attr__( 'Self-powered charge, peak discharge, and standby windows with SOC, AC-out, and rate', 'gaming-hub' ) : esc_attr__( 'Today’s planned grid charge, SOC forecast, expected generation, AC-out forecast, and billed rate', 'gaming-hub' ); ?>">
						<div class="ecoflow-sp-phases" data-ecoflow-sp-phases <?php echo $sp_enabled ? '' : 'hidden'; ?> aria-hidden="true">
							<?php
							$sp_phase_hours = array();
							for ( $ph = 0; $ph < 24; $ph++ ) {
								$slot_m = (string) ( ( $today_by_hour[ $ph ]['mode'] ?? 'idle' ) );
								if ( 'charge' === $slot_m ) {
									$sp_phase_hours[ $ph ] = 'charge';
								} elseif ( 'bypass' === $slot_m ) {
									$sp_phase_hours[ $ph ] = 'bypass';
								} elseif ( $ph >= 15 && $ph <= 21 ) {
									$sp_phase_hours[ $ph ] = 'peak';
								} else {
									$sp_phase_hours[ $ph ] = 'standby';
								}
							}
							$seg_start = 0;
							$seg_kind  = $sp_phase_hours[0] ?? 'standby';
							for ( $ph = 1; $ph <= 24; $ph++ ) {
								$next = $ph < 24 ? ( $sp_phase_hours[ $ph ] ?? 'standby' ) : null;
								if ( $ph < 24 && $next === $seg_kind ) {
									continue;
								}
								if ( 'charge' === $seg_kind ) {
									$seg_label = __( 'Charge', 'gaming-hub' );
								} elseif ( 'bypass' === $seg_kind ) {
									$seg_label = __( 'Bypass', 'gaming-hub' );
								} elseif ( 'peak' === $seg_kind ) {
									$seg_label = __( 'Discharge', 'gaming-hub' );
								} else {
									$seg_label = __( 'Standby', 'gaming-hub' );
								}
								$left  = round( $seg_start / 24 * 100, 4 );
								$width = round( ( $ph - $seg_start ) / 24 * 100, 4 );
								printf(
									'<span class="ecoflow-sp-phase is-%1$s" style="left: %2$s%%; width: %3$s%%;"><span>%4$s</span></span>',
									esc_attr( $seg_kind ),
									esc_attr( (string) $left ),
									esc_attr( (string) $width ),
									esc_html( $seg_label )
								);
								if ( $ph < 24 ) {
									$seg_start = $ph;
									$seg_kind  = $next;
								}
							}
							?>
						</div>
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
							$is_past_h = $h < $now_hour;
							$sp_phase = 'standby';
							if ( 'charge' === $mode ) {
								$sp_phase = 'charge';
							} elseif ( 'bypass' === $mode ) {
								$sp_phase = 'bypass';
							} elseif ( $h >= 15 && $h <= 21 ) {
								$sp_phase = 'peak';
							}
							$is_charge = 'charge' === $mode;
							$sp_slot   = is_array( $slot['selfpowered'] ?? null ) ? $slot['selfpowered'] : array();
							$pro_chg_w = isset( $sp_slot['pro_watts'] ) && is_numeric( $sp_slot['pro_watts'] )
								? max( 0, (int) $sp_slot['pro_watts'] )
								: 0;
							$delta_chg_w = isset( $sp_slot['delta_watts'] ) && is_numeric( $sp_slot['delta_watts'] )
								? max( 0, (int) $sp_slot['delta_watts'] )
								: 0;
							if ( $is_charge && $pro_chg_w <= 0 && $delta_chg_w <= 0 ) {
								$pro_chg_w = $charge_w;
							}
							$soc_pct   = $soc_series[ $h ] ?? null;
							$has_soc   = is_numeric( $soc_pct );
							$pro_h     = isset( $soc_bar_pro[ $h ] ) ? max( 0, min( 100, (float) $soc_bar_pro[ $h ] ) ) : ( $has_soc ? max( 0, min( 100, (float) $soc_pct ) ) : 0 );
							$delta_h   = $show_delta_soc && isset( $soc_bar_delta[ $h ] ) ? max( 0, min( 100, (float) $soc_bar_delta[ $h ] ) ) : 0;
							$pro_charge_h = $pro_chg_w > 0
								? max( 8, min( 100, ( $pro_chg_w / $charge_w ) * 100 ) )
								: 0;
							$delta_charge_h = $delta_chg_w > 0
								? max( 8, min( 100, ( $delta_chg_w / $charge_w ) * 100 ) )
								: 0;
							$col_class = 'ecoflow-rate-col ecoflow-plan-col is-' . sanitize_html_class( $mode );
							if ( $sp_enabled ) {
								$col_class .= ' is-sp-' . sanitize_html_class( $sp_phase );
							}
							if ( $pro_chg_w > 0 ) {
								$col_class .= ' is-charge-pro';
							}
							if ( $delta_chg_w > 0 ) {
								$col_class .= ' is-charge-delta';
							}
							if ( $is_now ) {
								$col_class .= ' is-now';
							}
							if ( $is_past_h ) {
								$col_class .= ' is-past';
							}
							if ( ! $has_soc ) {
								$col_class .= ' is-empty';
							}
							$phase_tip = $mode_label[ $mode ] ?? $mode;
							if ( $sp_enabled ) {
								if ( 'charge' === $sp_phase ) {
									$phase_tip = __( 'Charge', 'gaming-hub' );
								} elseif ( 'bypass' === $sp_phase ) {
									$phase_tip = __( 'Bypass', 'gaming-hub' );
								} elseif ( 'peak' === $sp_phase ) {
									$phase_tip = __( 'Discharge', 'gaming-hub' );
								} else {
									$phase_tip = __( 'Standby', 'gaming-hub' );
								}
							}
							$tip_parts = array( sprintf( '%d:00', $h ), $phase_tip );
							$pro_tip   = isset( $plan['soc_series_pro'][ $h ] ) && is_numeric( $plan['soc_series_pro'][ $h ] )
								? (float) $plan['soc_series_pro'][ $h ]
								: ( $has_soc ? (float) $soc_pct : null );
							$delta_tip = isset( $plan['soc_series_delta'][ $h ] ) && is_numeric( $plan['soc_series_delta'][ $h ] )
								? (float) $plan['soc_series_delta'][ $h ]
								: null;
							if ( null !== $pro_tip ) {
								$tip_parts[] = 'Pro ' . number_format_i18n( $pro_tip, 0 ) . '%';
							}
							if ( $show_delta_soc && null !== $delta_tip ) {
								$tip_parts[] = '1500 ' . number_format_i18n( $delta_tip, 0 ) . '%';
							}
							if ( $pro_chg_w > 0 ) {
								$tip_parts[] = 'Pro ' . gaming_hub_format_ecoflow_watts( $pro_chg_w );
							}
							if ( $delta_chg_w > 0 ) {
								$tip_parts[] = '1500 ' . gaming_hub_format_ecoflow_watts( $delta_chg_w );
							}
							if ( isset( $slot['yen'] ) && null !== $slot['yen'] ) {
								$tip_parts[] = number_format_i18n( (float) $slot['yen'], 1 ) . ' 円';
							}
							$ac_w_h = (int) round( max( 0, (float) ( $ac_hours[ $h ] ?? 0 ) ) );
							$tip_parts[] = 'AC ' . number_format_i18n( $ac_w_h ) . ' W';
							?>
							<div class="<?php echo esc_attr( $col_class ); ?>" data-ecoflow-plan-col data-hour="<?php echo esc_attr( (string) $h ); ?>"<?php echo $sp_enabled ? ' data-sp-phase="' . esc_attr( $sp_phase ) . '"' : ''; ?>>
								<?php if ( $is_now ) : ?>
									<span class="ecoflow-rate-now-pip"><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
								<?php endif; ?>
								<span
									class="ecoflow-plan-charge-bar is-pro"
									data-ecoflow-plan-charge-bar-pro
									style="height: <?php echo esc_attr( (string) round( $pro_charge_h, 1 ) ); ?>%;"
									<?php echo $pro_chg_w > 0 ? '' : 'hidden'; ?>
								></span>
								<span
									class="ecoflow-plan-charge-bar is-delta"
									data-ecoflow-plan-charge-bar-delta
									style="height: <?php echo esc_attr( (string) round( $delta_charge_h, 1 ) ); ?>%;"
									<?php echo $delta_chg_w > 0 ? '' : 'hidden'; ?>
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
							$is_now      = $h === $now_hour;
							$hour_mode   = (string) ( $today_by_hour[ $h ]['mode'] ?? 'idle' );
							$is_boundary = $sp_enabled && ( in_array( $h, array( 14, 15, 22 ), true ) || 'charge' === $hour_mode );
							$show_label  = 0 === $h % 3 || $is_now || $is_boundary;
							$hour_class  = 'ecoflow-rate-hour';
							if ( $is_now ) {
								$hour_class .= ' is-now';
							}
							if ( $is_boundary ) {
								$hour_class .= ' is-sp-boundary';
							}
							?>
							<span class="<?php echo esc_attr( $hour_class ); ?>" data-ecoflow-plan-hour data-hour="<?php echo esc_attr( (string) $h ); ?>"><?php echo $show_label ? esc_html( (string) $h ) : ''; ?></span>
						<?php endfor; ?>
					</div>
				</div>
				<div class="ecoflow-rate-y ecoflow-rate-y-yen" aria-hidden="true">
					<span class="ecoflow-rate-y-unit"><?php esc_html_e('yen', 'gaming-hub'); ?></span>
					<?php foreach ( $plan_yen_ticks as $tick_yen ) : ?>
						<span data-ecoflow-plan-yen-tick><?php echo esc_html( number_format( $tick_yen, 1 ) ); ?></span>
					<?php endforeach; ?>
				</div>
			</div>
			<p class="ecoflow-plan-chart-hint"><?php esc_html_e('Swipe the chart sideways', 'gaming-hub'); ?></p>
			<p class="ecoflow-rate-legend" data-ecoflow-plan-legend><?php
			if ( $sp_enabled ) {
				echo $show_delta_soc
					? esc_html__('Gold charge: Pro · Orange charge: 1500 · Cyan: bypass (hold) · Coral: discharge 15–22 · Cool: standby · Yellow/Orange bars: Pro / 1500 SOC · Orange line: AC load · Teal: rate', 'gaming-hub')
					: esc_html__('Gold charge: Pro · Cyan: bypass (hold) · Coral: discharge 15–22 · Cool: standby · Yellow: Pro SOC · Vermilion: AC-out · Teal: rate', 'gaming-hub');
			} else {
				echo $show_delta_soc
					? esc_html__('Yellow: Pro SOC · Orange: 1500 SOC · Cool band: cheap bypass (hold pack) · Orange band: forecast solar · Warm hours: use pack · Orange line: AC load · Teal: rate', 'gaming-hub')
					: esc_html__('Yellow: Pro SOC · Cool band: cheap bypass (hold pack) · Orange band: solar forecast · Warm hours: use pack · Vermilion: AC-out · Teal: rate', 'gaming-hub');
			}
			?></p>
			<p class="ecoflow-plan-schedule-summary" data-ecoflow-plan-schedule-summary <?php echo ! empty( $selfpowered['enabled'] ) && ! empty( $selfpowered['summary'] ) ? '' : 'hidden'; ?>>
				<?php echo esc_html( (string) ( $selfpowered['summary'] ?? '' ) ); ?>
			</p>
			<ul class="ecoflow-plan-schedule" data-ecoflow-plan-schedule <?php echo ! empty( $selfpowered['enabled'] ) && $sp_rows ? '' : 'hidden'; ?>>
				<?php foreach ( $sp_rows as $row ) : ?>
					<li class="ecoflow-plan-schedule-row is-<?php echo esc_attr( sanitize_html_class( (string) ( $row['mode'] ?? 'idle' ) ) ); ?><?php echo ! empty( $row['active'] ) ? ' is-active' : ''; ?><?php echo ! empty( $row['has_actual'] ) ? ' has-actual' : ''; ?>">
						<span class="ecoflow-plan-schedule-time"><?php echo esc_html( (string) ( $row['time'] ?? '' ) ); ?></span>
						<span class="ecoflow-plan-schedule-body">
							<span class="ecoflow-plan-schedule-planned">
								<?php esc_html_e('Plan', 'gaming-hub'); ?>
								<b><?php echo esc_html( (string) ( $row['action'] ?? '' ) ); ?></b>
							</span>
							<span class="ecoflow-plan-schedule-actual">
								<?php esc_html_e('Actual', 'gaming-hub'); ?>
								<b><?php echo esc_html( ! empty( $row['has_actual'] ) ? (string) ( $row['actual'] ?? '—' ) : '—' ); ?></b>
							</span>
						</span>
					</li>
				<?php endforeach; ?>
			</ul>
			<p class="ecoflow-plan-next" data-ecoflow-plan-next <?php echo $next_note ? '' : 'hidden'; ?>><?php echo esc_html( $next_note ); ?></p>

			<details class="ecoflow-plan-more">
				<summary><?php esc_html_e('See details', 'gaming-hub'); ?></summary>
				<p class="ecoflow-plan-limits">
					<?php
					printf(
						/* translators: 1: dc watts, 2: bypass reserve, 3: use-pack reserve */
						esc_html__('No grid→storage charge · DC 12V→1500 always %1$s W · backup reserve bypass %2$s%% / use pack %3$s%%', 'gaming-hub'),
						esc_html( number_format_i18n( (int) ( $plan['dc1500_w'] ?? 100 ) ) ),
						esc_html( number_format_i18n( (int) ( $plan['backup_bypass_soc'] ?? ( defined( 'GAMING_HUB_ECOFLOW_BACKUP_RESERVE_BYPASS' ) ? GAMING_HUB_ECOFLOW_BACKUP_RESERVE_BYPASS : 100 ) ) ) ),
						esc_html( number_format_i18n( (int) ( $plan['backup_use_pack_soc'] ?? ( defined( 'GAMING_HUB_ECOFLOW_BACKUP_RESERVE_USE_PACK' ) ? GAMING_HUB_ECOFLOW_BACKUP_RESERVE_USE_PACK : 5 ) ) ) )
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
						<span class="ecoflow-stat-label"><?php esc_html_e('Outdoor temp (Tajimi)', 'gaming-hub'); ?></span>
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
									esc_html__('Low %1$s°C / high %2$s°C', 'gaming-hub'),
									esc_html( number_format_i18n( (float) ( $plan['temp_min'] ?? $plan['temp_max'] ), 1 ) ),
									esc_html( number_format_i18n( (float) $plan['temp_max'], 1 ) )
								);
							}
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e('AC estimate', 'gaming-hub'); ?></span>
						<strong data-ecoflow-field="plan_ac"><?php echo esc_html( isset( $plan['ac_today_kwh'] ) ? number_format_i18n( (float) $plan['ac_today_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
						<small data-ecoflow-field="plan_ac_meta">
							<?php
							if ( 'similar_weather' === ( $plan['ac_source'] ?? '' ) ) {
								printf(
									/* translators: 1: watts now, 2: matched similar days count */
									esc_html__('Now %1$s W · similar weather over past month (%2$s days)', 'gaming-hub'),
									esc_html( number_format_i18n( (int) ( $plan['ac_now_w'] ?? 0 ) ) ),
									esc_html( number_format_i18n( (int) ( $plan['ac_match_days'] ?? 0 ) ) )
								);
							} elseif ( isset( $plan['ac_source'] ) || isset( $plan['ac_today_kwh'] ) ) {
								printf(
									/* translators: 1: watts now, 2: cool start C, 3: start watts, 4: cool max C, 5: heat start C, 6: heat min C */
									esc_html__('Now %1$s W · cool %3$s W@%2$s°C→1 kW@%4$s°C+ · heat %3$s W@%5$s°C→1 kW@%6$s°C−', 'gaming-hub'),
									esc_html( number_format_i18n( (int) ( $plan['ac_now_w'] ?? 0 ) ) ),
									esc_html( number_format_i18n( (float) ( $plan['ac_start_c'] ?? ( defined( 'GAMING_HUB_ECOFLOW_AC_START_C' ) ? GAMING_HUB_ECOFLOW_AC_START_C : 27 ) ), 0 ) ),
									esc_html( number_format_i18n( (int) ( $plan['ac_start_w'] ?? 300 ) ) ),
									esc_html( number_format_i18n( (float) ( $plan['ac_max_c'] ?? ( function_exists( 'gaming_hub_ecoflow_ac_max_c' ) ? gaming_hub_ecoflow_ac_max_c() : 34 ) ), 0 ) ),
									esc_html( number_format_i18n( (float) ( $plan['ac_heat_start_c'] ?? ( defined( 'GAMING_HUB_ECOFLOW_AC_HEAT_START_C' ) ? GAMING_HUB_ECOFLOW_AC_HEAT_START_C : 10 ) ), 0 ) ),
									esc_html( number_format_i18n( (float) ( $plan['ac_heat_min_c'] ?? ( function_exists( 'gaming_hub_ecoflow_ac_heat_min_c' ) ? gaming_hub_ecoflow_ac_heat_min_c() : 3 ) ), 0 ) )
								);
							} else {
								echo '—';
							}
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e('Remaining expected generation', 'gaming-hub'); ?></span>
						<strong data-ecoflow-field="plan_solar_today"><?php echo esc_html( isset( $plan['solar_hud_kwh'] ) ? number_format_i18n( (float) $plan['solar_hud_kwh'], 1 ) . ' kWh' : ( isset( $plan['solar_remaining_kwh'] ) ? number_format_i18n( (float) $plan['solar_remaining_kwh'], 1 ) . ' kWh' : '—' ) ); ?></strong>
						<small data-ecoflow-field="plan_solar_meta">
							<?php
							$panel_note = function_exists( 'gaming_hub_ecoflow_solar_panel_label' )
								? gaming_hub_ecoflow_solar_panel_label()
								: __('Pro 800 W + 1500 500 W', 'gaming-hub');
							if ( 'similar_weather' === ( $plan['solar_source'] ?? '' ) ) {
								printf(
									/* translators: 1: panel label, 2: matched similar days count */
									esc_html__( '%1$s · similar weather over past month (%2$s days)', 'gaming-hub' ),
									esc_html( $panel_note ),
									esc_html( number_format_i18n( (int) ( $plan['solar_match_days'] ?? 0 ) ) )
								);
							} elseif ( isset( $plan['solar_source'] ) || isset( $plan['solar_remaining_kwh'] ) || isset( $plan['solar_hud_kwh'] ) ) {
								echo esc_html( $panel_note . ' · ' . __('From now', 'gaming-hub') . ' · ' . __('Tajimi', 'gaming-hub') );
							} else {
								// Async skeleton stub has no solar fields — avoid fake open_meteo/Tajimi copy.
								echo '—';
							}
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e('Suggested charge window', 'gaming-hub'); ?></span>
						<strong data-ecoflow-field="plan_window"><?php echo esc_html( $plan['window_label'] ?? '—' ); ?></strong>
						<small data-ecoflow-field="plan_window_price">
							<?php
							if ( isset( $plan['window_avg_yen'] ) && null !== $plan['window_avg_yen'] ) {
								printf(
									/* translators: %s: yen per kWh */
									esc_html__('Avg %s yen/kWh', 'gaming-hub'),
									esc_html( number_format_i18n( (float) $plan['window_avg_yen'], 1 ) )
								);
							}
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e('Today’s weather', 'gaming-hub'); ?></span>
						<strong data-ecoflow-field="plan_weather"><?php echo esc_html( ( $plan['weather_summary'] ?? '' ) ?: ( $plan['weather'] ?? '—' ) ); ?></strong>
						<small data-ecoflow-field="plan_weather_meta">
							<?php
							$weather_meta = array_filter( array(
								isset( $plan['precip_max'] ) && is_numeric( $plan['precip_max'] )
									/* translators: %s: max precipitation probability percent */
									? sprintf( __('Rain chance %s%%', 'gaming-hub'), (int) $plan['precip_max'] )
									: '',
								(string) ( $plan['weather_location'] ?? '' ),
							) );
							echo esc_html( implode( ' · ', $weather_meta ) );
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e('Remaining expected Living AC & others use', 'gaming-hub'); ?></span>
						<strong data-ecoflow-field="plan_load"><?php echo esc_html( isset( $plan['room_remaining_kwh'] ) ? number_format_i18n( (float) $plan['room_remaining_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
						<small data-ecoflow-field="plan_load_meta">
							<?php
							if ( isset( $plan['room_daily_kwh'] ) ) {
								printf(
									/* translators: 1: daily kWh, 2: AC kWh, 3: base kWh */
									esc_html__('Today %1$s kWh (AC %2$s + other %3$s)', 'gaming-hub'),
									esc_html( number_format_i18n( (float) $plan['room_daily_kwh'], 1 ) ),
									esc_html( number_format_i18n( (float) ( $plan['ac_today_kwh'] ?? 0 ), 1 ) ),
									esc_html( number_format_i18n( (float) ( $plan['base_today_kwh'] ?? 0 ), 1 ) )
								);
							}
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label"><?php esc_html_e('1500 DC (always-on)', 'gaming-hub'); ?></span>
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
								esc_html__('%s kW fixed', 'gaming-hub'),
								esc_html( number_format_i18n( $dc1500_w / 1000, 2 ) )
							);
							?>
						</small>
					</div>
					<div class="ecoflow-plan-card">
						<span class="ecoflow-stat-label" data-ecoflow-field="plan_battery_label"><?php
						printf(
							/* translators: 1: usable percent of capacity, 2: discharge reserve percent */
							esc_html__('Usable battery (%1$s%% of capacity · excludes %2$s%% reserve)', 'gaming-hub'),
							esc_html( number_format_i18n( (int) ( $plan['usable_soc'] ?? ( defined( 'GAMING_HUB_ECOFLOW_PLAN_USABLE_SOC' ) ? GAMING_HUB_ECOFLOW_PLAN_USABLE_SOC : 95 ) ) ) ),
							esc_html( number_format_i18n( (int) ( $plan['reserve_soc'] ?? ( defined( 'GAMING_HUB_ECOFLOW_PLAN_MIN_SOC' ) ? GAMING_HUB_ECOFLOW_PLAN_MIN_SOC : 5 ) ) ) )
						);
						?></span>
						<strong data-ecoflow-field="plan_battery"><?php echo esc_html( isset( $plan['usable_battery_kwh'] ) ? number_format_i18n( (float) $plan['usable_battery_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
					</div>
				</div>
			</details>

			<div class="ecoflow-plan-actions" data-ecoflow-plan-actions>
				<p class="ecoflow-plan-approval" data-ecoflow-field="plan_approval"><?php echo esc_html( $plan['approval_note'] ?? '' ); ?></p>
				<?php if ( $can_approve ) : ?>
					<button type="button" class="ecoflow-plan-approve" data-ecoflow-approve<?php echo $approved && ! $stale ? ' hidden' : ''; ?>>
						<?php esc_html_e('Approve this schedule and send it to Pro 3', 'gaming-hub'); ?>
					</button>
					<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cancel<?php echo ( $approved || $stale ) ? '' : ' hidden'; ?>>
						<?php esc_html_e('Cancel approval', 'gaming-hub'); ?>
					</button>
				<?php endif; ?>
			</div>
		</div>

		<div class="ecoflow-dashboard-header">
			<h2><?php esc_html_e('Device status', 'gaming-hub'); ?></h2>
			<p class="ecoflow-updated"<?php echo empty( $status['updated_at'] ) ? ' hidden' : ''; ?>>
				<?php
				if ( ! empty( $status['updated_at'] ) ) {
					printf(
						/* translators: %s: last updated time */
						esc_html__( 'Updated: %s', 'gaming-hub' ),
						esc_html( $status['updated_at'] )
					);
				}
				?>
			</p>
		</div>

		<div class="ecoflow-device-bars">
			<div class="ecoflow-device-bar">
				<div>
					<strong><?php echo esc_html( $status['device_name'] ); ?></strong>
					<span class="ecoflow-sn"><?php echo esc_html( $status['device_sn'] ); ?></span>
				</div>
				<span class="ecoflow-online-badge <?php echo $status['online'] ? 'is-online' : 'is-offline'; ?>">
					<?php echo $status['online'] ? esc_html__('Online', 'gaming-hub') : esc_html__('Offline', 'gaming-hub'); ?>
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
								: esc_html__('Standalone', 'gaming-hub');
							?>
						</span>
					</div>
					<span class="ecoflow-online-badge <?php echo ! empty( $status['secondary']['online'] ) ? 'is-online' : 'is-offline'; ?>">
						<?php echo ! empty( $status['secondary']['online'] ) ? esc_html__('Online', 'gaming-hub') : esc_html__('Offline', 'gaming-hub'); ?>
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
			<a href="<?php echo esc_url( gaming_hub_energy_url() ); ?>"><?php esc_html_e('Generation log →', 'gaming-hub'); ?></a>
		</p>

		<div class="ecoflow-stats-grid">
			<?php
			$pro_grid = is_array( $status['pro_grid_charge'] ?? null ) ? $status['pro_grid_charge'] : array();
			?>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e('Pro grid top-up', 'gaming-hub'); ?></span>
				<strong data-ecoflow-field="pro_grid_charge">
					<?php echo esc_html( gaming_hub_format_ecoflow_watts( gaming_hub_ecoflow_pro_grid_live_watts( $status ) ) ); ?>
				</strong>
				<small data-ecoflow-field="pro_grid_charge_note"><?php echo esc_html( (string) ( $pro_grid['message'] ?? '' ) ); ?></small>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e('High-volt in (Pro)', 'gaming-hub'); ?></span>
				<strong data-ecoflow-field="hv_in"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['hv_in'] ?? 0 ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e('AC out → Living AC & others', 'gaming-hub'); ?></span>
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
					<span class="ecoflow-stat-label"><?php esc_html_e('AC in (1500)', 'gaming-hub'); ?></span>
					<strong data-ecoflow-field="delta_ac_in"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['secondary']['ac_in'] ?? null ) ); ?></strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label"><?php esc_html_e('1500 grid top-up', 'gaming-hub'); ?></span>
					<strong data-ecoflow-field="delta_rescue">
						<?php
						$mqtt_live = ! empty( $status['secondary']['mqtt_live'] );
						$rescue = is_array( $status['secondary']['grid_rescue'] ?? null ) ? $status['secondary']['grid_rescue'] : array();
						if ( ! $mqtt_live ) {
							echo esc_html( gaming_hub_ecoflow_unavailable_label() );
						} else {
							echo ! empty( $rescue['active'] )
								? esc_html( gaming_hub_format_ecoflow_watts( $rescue['watts'] ?? 0 ) )
								: esc_html__('Standby (starts at 5% or below)', 'gaming-hub');
						}
						?>
					</strong>
					<small data-ecoflow-field="delta_rescue_note"><?php echo esc_html( (string) ( $rescue['message'] ?? '' ) ); ?></small>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label"><?php esc_html_e('SOC (Extra Battery)', 'gaming-hub'); ?></span>
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
						? esc_html__('AC out → UPS (1500 · live · MQTT)', 'gaming-hub')
						: esc_html__('AC out → UPS (n/a)', 'gaming-hub');
						?>
					</span>
					<strong data-ecoflow-field="ups_out"><?php echo esc_html( gaming_hub_format_ecoflow_ups( $status ) ); ?></strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label"><?php esc_html_e('Status (1500)', 'gaming-hub'); ?></span>
					<strong data-ecoflow-field="secondary_charge_state"<?php echo ! empty( $status['secondary']['charge_state_key'] ) ? ' data-ecoflow-state="' . esc_attr( $status['secondary']['charge_state_key'] ) . '"' : ''; ?>><?php echo esc_html( $status['secondary']['charge_state'] ); ?></strong>
				</div>
			<?php endif; ?>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e('DC out (Pro)', 'gaming-hub'); ?></span>
				<strong data-ecoflow-field="dc_out"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['dc_out'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e('Battery temp', 'gaming-hub'); ?></span>
				<strong data-ecoflow-field="battery_temp"><?php echo esc_html( gaming_hub_format_ecoflow_temp( $status['battery_temp'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e('Remaining (Pro)', 'gaming-hub'); ?></span>
				<strong data-ecoflow-field="remain_capacity"><?php echo esc_html( gaming_hub_format_ecoflow_wh( $status['remain_capacity'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e('Status (Pro)', 'gaming-hub'); ?></span>
				<strong data-ecoflow-field="charge_state_stat"<?php echo ! empty( $status['charge_state_key'] ) ? ' data-ecoflow-state="' . esc_attr( $status['charge_state_key'] ) . '"' : ''; ?>><?php echo esc_html( $status['charge_state'] ); ?></strong>
			</div>
		</div>
	<?php endif; ?>
</section>
