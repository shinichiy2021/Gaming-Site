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
	<?php
	if ( ! is_wp_error( $status ) && function_exists( 'gaming_hub_render_ecoflow_rates' ) ) {
		gaming_hub_render_ecoflow_rates(
			array(
				'plan'      => is_array( $status['charge_plan'] ?? null ) ? $status['charge_plan'] : array(),
				'solar_now' => $status['solar_in'] ?? null,
			)
		);
	}
	?>

	<div class="ecoflow-dashboard-header">
		<h2><?php esc_html_e( 'デバイスステータス', 'gaming-hub' ); ?></h2>
		<?php if ( ! is_wp_error( $status ) && ! empty( $status['updated_at'] ) ) : ?>
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

	<?php if ( is_wp_error( $status ) ) : ?>
		<div class="ecoflow-setup-panel">
			<p class="ecoflow-setup-title"><?php echo esc_html( $status->get_error_message() ); ?></p>
			<?php gaming_hub_render_ecoflow_setup_instructions(); ?>
		</div>
	<?php else : ?>
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

		<?php
		$plan = is_array( $status['charge_plan'] ?? null ) ? $status['charge_plan'] : array();
		$needs_grid = ! empty( $plan['needs_grid'] );
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
			class="ecoflow-plan<?php echo $needs_grid ? ' is-deficit' : ' is-ok'; ?>"
			data-plan-id="<?php echo esc_attr( $plan['plan_id'] ?? '' ); ?>"
		>
			<div class="ecoflow-plan-header">
				<h3><?php esc_html_e( '今日の充電計画', 'gaming-hub' ); ?></h3>
				<p class="ecoflow-plan-note" data-ecoflow-field="plan_note"><?php echo esc_html( $plan['note'] ?? '' ); ?></p>
				<p class="ecoflow-plan-limits">
					<?php
					printf(
						/* translators: 1: charge watts, 2: idle watts, 3: dc watts, 4: reserve on, 5: reserve off */
						esc_html__( '充電時 %1$s W / それ以外 %2$s W（本体の下限） · DC 12V→1500 常時 %3$s W · 予備残量 グリッドOn %4$s%% / Off %5$s%%', 'gaming-hub' ),
						esc_html( number_format_i18n( (int) ( $plan['charge_w'] ?? 1000 ) ) ),
						esc_html( number_format_i18n( (int) ( $plan['idle_w'] ?? 200 ) ) ),
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
			</div>
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
							/* translators: 1: watts now, 2: setpoint C */
							esc_html__( 'いま %1$s W · 設定 %2$s℃', 'gaming-hub' ),
							esc_html( number_format_i18n( (int) ( $plan['ac_now_w'] ?? 0 ) ) ),
							esc_html( number_format_i18n( (float) ( $plan['ac_setpoint_c'] ?? 26 ), 0 ) )
						);
						?>
					</small>
				</div>
				<div class="ecoflow-plan-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( '今日の発電見込み', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_solar_today"><?php echo esc_html( isset( $plan['solar_today_kwh'] ) ? number_format_i18n( (float) $plan['solar_today_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
					<small>
						<?php
						$panel_note = function_exists( 'gaming_hub_powerwall_solar_panel_label' )
							? gaming_hub_powerwall_solar_panel_label()
							: __( '1.5 kW パネル', 'gaming-hub' );
						echo esc_html( $panel_note . ' · ' . __( '多治見', 'gaming-hub' ) );
						?>
					</small>
				</div>
				<div class="ecoflow-plan-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( '今日の不足', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_deficit"><?php echo esc_html( isset( $plan['deficit_kwh'] ) ? number_format_i18n( (float) $plan['deficit_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
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
					<span class="ecoflow-stat-label"><?php esc_html_e( '残り予想発電', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_solar"><?php echo esc_html( isset( $plan['solar_remaining_kwh'] ) ? number_format_i18n( (float) $plan['solar_remaining_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
				</div>
				<div class="ecoflow-plan-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( '今日の天気', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_weather"><?php echo esc_html( $plan['weather'] ?? '—' ); ?></strong>
					<small data-ecoflow-field="plan_weather_meta"><?php echo esc_html( $plan['weather_location'] ?? '' ); ?></small>
				</div>
				<div class="ecoflow-plan-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( '残り予想使用（部屋）', 'gaming-hub' ); ?></span>
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
					<span class="ecoflow-stat-label"><?php esc_html_e( '使える電池（予備 25%除く）', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="plan_battery"><?php echo esc_html( isset( $plan['usable_battery_kwh'] ) ? number_format_i18n( (float) $plan['usable_battery_kwh'], 1 ) . ' kWh' : '—' ); ?></strong>
				</div>
			</div>

			<ol class="ecoflow-plan-slots" data-ecoflow-slots>
				<?php foreach ( $slots as $slot ) : ?>
					<?php
					$mode = $slot['mode'] ?? 'idle';
					$is_now = ( $slot['date'] ?? '' ) === $today && (int) ( $slot['hour'] ?? -1 ) === $now_hour;
					$is_next = ( $slot['date'] ?? '' ) !== $today;
					$classes = array( 'ecoflow-plan-slot', 'is-' . sanitize_html_class( $mode ) );
					if ( $is_now ) {
						$classes[] = 'is-now';
					}
					if ( $is_next ) {
						$classes[] = 'is-tomorrow';
					}
					$mode_label = array(
						'charge' => __( '充電', 'gaming-hub' ),
						'solar'  => __( '太陽光', 'gaming-hub' ),
						'idle'   => __( '充電オフ', 'gaming-hub' ),
						'past'   => __( '経過', 'gaming-hub' ),
					);
					?>
					<li class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>">
						<span class="ecoflow-plan-slot-hour"><?php echo $is_next ? esc_html( '翌 ' . ( $slot['label'] ?? '' ) ) : esc_html( $slot['label'] ?? '' ); ?></span>
						<span class="ecoflow-plan-slot-mode"><?php echo esc_html( $mode_label[ $mode ] ?? $mode ); ?></span>
						<span class="ecoflow-plan-slot-watts">
							<?php
							echo null === ( $slot['watts'] ?? null )
								? '—'
								: esc_html( number_format_i18n( (int) $slot['watts'] ) . ' W' );
							?>
						</span>
						<?php if ( isset( $slot['yen'] ) && null !== $slot['yen'] ) : ?>
							<span class="ecoflow-plan-slot-yen"><?php echo esc_html( number_format_i18n( (float) $slot['yen'], 1 ) . ' 円' ); ?></span>
						<?php endif; ?>
					</li>
				<?php endforeach; ?>
			</ol>

			<div class="ecoflow-plan-actions">
				<p class="ecoflow-plan-approval" data-ecoflow-field="plan_approval"><?php echo esc_html( $plan['approval_note'] ?? '' ); ?></p>
			</div>
		</div>

		<div class="ecoflow-stats-grid">
			<?php
			$pro_grid = is_array( $status['pro_grid_charge'] ?? null ) ? $status['pro_grid_charge'] : array();
			?>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'Pro グリッド補充電', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="pro_grid_charge">
					<?php
					echo ! empty( $pro_grid['active'] )
						? esc_html( number_format_i18n( (int) ( $pro_grid['watts'] ?? 0 ) ) . ' W' )
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
				<span class="ecoflow-stat-label"><?php esc_html_e( 'AC 出力 → 部屋', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="ac_out"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['ac_out'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label" data-ecoflow-field="solar_delta_label"><?php echo esc_html( gaming_hub_ecoflow_solar_delta_label( (string) ( $status['secondary']['solar_in_source'] ?? $status['solar_in_source'] ?? '' ) ) ); ?></span>
				<strong data-ecoflow-field="solar_delta"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['secondary']['solar_in'] ?? $status['solar_delta'] ?? 0 ) ); ?></strong>
			</div>
			<?php if ( ! empty( $status['secondary'] ) ) : ?>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label" data-ecoflow-field="secondary_soc_label">
						<?php
						$soc_source = (string) ( $status['secondary']['soc_source'] ?? '' );
						echo str_starts_with( $soc_source, 'baseline_minus_ups' )
							? esc_html__( '残量 (1500 · 6%起点)', 'gaming-hub' )
							: esc_html__( '残量 (1500 · 実測)', 'gaming-hub' );
						?>
					</span>
					<strong data-ecoflow-field="secondary_soc">
						<?php
						echo isset( $status['secondary']['battery_percent'] ) && null !== $status['secondary']['battery_percent']
							? esc_html( (int) $status['secondary']['battery_percent'] . '%' )
							: '—';
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
								$status['secondary']['capacity_wh'] ?? GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH
							)
						);
						?>
					</strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( '1500 グリッド補充電', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="delta_rescue">
						<?php
						$rescue = is_array( $status['secondary']['grid_rescue'] ?? null ) ? $status['secondary']['grid_rescue'] : array();
						echo ! empty( $rescue['active'] )
							? esc_html( number_format_i18n( (int) ( $rescue['watts'] ?? 0 ) ) . ' W' )
							: esc_html__( '待機 (5%以下で開始)', 'gaming-hub' );
						?>
					</strong>
					<small data-ecoflow-field="delta_rescue_note"><?php echo esc_html( (string) ( $rescue['message'] ?? '' ) ); ?></small>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label"><?php esc_html_e( '残量 (Extra Battery)', 'gaming-hub' ); ?></span>
					<strong data-ecoflow-field="extra_soc">
						<?php
						$extra_soc = $status['secondary']['extra']['battery_percent'] ?? $status['secondary']['battery_percent'] ?? null;
						echo null !== $extra_soc ? esc_html( (int) $extra_soc . '%' ) : '—';
						?>
					</strong>
				</div>
				<div class="ecoflow-stat-card">
					<span class="ecoflow-stat-label" data-ecoflow-field="ups_out_label">
						<?php
						echo 'switchbot' === gaming_hub_ecoflow_ups_source( $status )
							? esc_html__( 'AC 出力 → UPS (SwitchBot)', 'gaming-hub' )
							: esc_html__( 'AC 出力 → UPS (1500)', 'gaming-hub' );
						?>
					</span>
					<strong data-ecoflow-field="ups_out"><?php echo esc_html( gaming_hub_format_ecoflow_watts( gaming_hub_ecoflow_ups_watts( $status, (int) ( $status['secondary']['ac_out'] ?? 0 ) ) ) ); ?></strong>
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
