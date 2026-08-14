<?php
/**
 * Powerwall 3 + Model 3 energy flow dashboard
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $status Flow status.
 */

$status     = isset( $args['status'] ) ? $args['status'] : gaming_hub_get_powerwall_flow_status();
$flow       = gaming_hub_powerwall_flow_payload( $status );
$solar_meta = is_array( $status['solar_meta'] ?? null ) ? $status['solar_meta'] : array();
$home_meta  = is_array( $status['home_meta'] ?? null ) ? $status['home_meta'] : array();
$cost_meta  = is_array( $status['cost_meta'] ?? null ) ? $status['cost_meta'] : array();
$model3_meta = is_array( $status['model3_meta'] ?? null ) ? $status['model3_meta'] : array();
?>

<section class="pw-flow-dashboard" aria-label="<?php esc_attr_e( 'Powerwall Energy Flow', 'gaming-hub' ); ?>">
	<div class="pw-flow-dashboard-header">
		<h2><?php esc_html_e( '電力フロー', 'gaming-hub' ); ?></h2>
		<?php if ( ! empty( $status['updated_at'] ) ) : ?>
			<p class="pw-flow-updated">
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

	<p class="pw-flow-solar-note" data-pw-field="solar_note">
		<?php
		$cloud_label = null !== ( $solar_meta['cloud_cover'] ?? null )
			? sprintf( __( '雲量 %s%%', 'gaming-hub' ), (int) $solar_meta['cloud_cover'] )
			: __( '雲量 —', 'gaming-hub' );
		printf(
			/* translators: 1: panel label, 2: location, 3: hour slot, 4: weather, 5: cloud cover */
			esc_html__( 'ソーラー (%1$s): %2$s · 気象庁日照平年値 + 天気連動 · %3$s 時点 · %4$s · %5$s · 1時間ごとに更新', 'gaming-hub' ),
			esc_html( $solar_meta['panel_label'] ?? gaming_hub_powerwall_solar_panel_label() ),
			esc_html( $solar_meta['location'] ?? __( '岐阜県多治見市', 'gaming-hub' ) ),
			esc_html( $solar_meta['hour_slot'] ?? '—' ),
			esc_html( $solar_meta['weather'] ?? '—' ),
			esc_html( $cloud_label )
		);
		?>
	</p>

	<p class="pw-flow-home-note" data-pw-field="home_note">
		<?php
		printf(
			/* translators: 1: profile label, 2: daily kWh, 3: time band, 4: hour slot */
			esc_html__( 'ホーム: %1$s · 1日約 %2$s kWh · %3$s · %4$s 時点', 'gaming-hub' ),
			esc_html( $home_meta['profile'] ?? __( '大人3人世帯（平均）', 'gaming-hub' ) ),
			esc_html( (string) ( $home_meta['daily_kwh'] ?? '10.5' ) ),
			esc_html( $home_meta['time_band'] ?? '—' ),
			esc_html( $home_meta['hour_slot'] ?? '—' )
		);
		?>
	</p>

	<?php if ( 'simulated' === ( $status['model3_source'] ?? '' ) && ! empty( $model3_meta ) ) : ?>
		<p class="pw-flow-model3-note" data-pw-field="model3_note">
			<?php
			printf(
				/* translators: 1: daily km, 2: daily kWh, 3: charge window, 4: charge watts */
				esc_html__( 'Model 3: 1日平均 %1$s km · 充電約 %2$s kWh · %3$s · 約 %4$s W', 'gaming-hub' ),
				esc_html( number_format_i18n( (int) ( $model3_meta['daily_km'] ?? 30 ) ) ),
				esc_html( (string) ( $model3_meta['daily_kwh'] ?? '4.5' ) ),
				esc_html( $model3_meta['charge_window'] ?? '17:00–22:30' ),
				esc_html( number_format_i18n( (int) ( $model3_meta['charge_watts'] ?? 0 ) ) )
			);
			?>
		</p>
	<?php endif; ?>

	<?php if ( ! gaming_hub_tesla_model3_is_configured() ) : ?>
		<div class="pw-flow-setup-panel">
			<p class="pw-flow-setup-title"><?php esc_html_e( 'Model 3 API 未設定 — Model 3 はデモデータです', 'gaming-hub' ); ?></p>
			<?php gaming_hub_render_tesla_setup_instructions(); ?>
		</div>
	<?php elseif ( ! empty( $status['model3_error'] ) ) : ?>
		<p class="pw-flow-error"><?php echo esc_html( 'Model 3 API: ' . $status['model3_error'] ); ?></p>
	<?php elseif ( 'tesla' === ( $status['model3_source'] ?? '' ) ) : ?>
		<p class="pw-flow-live-note"><?php esc_html_e( 'Model 3: Tesla Fleet API 実データ', 'gaming-hub' ); ?></p>
	<?php endif; ?>

	<p class="pw-flow-sim-note"><?php esc_html_e( 'グリッドは買電のみ（売電なし）。Powerwall SOC はデモ。', 'gaming-hub' ); ?></p>

	<div
		id="powerwall-energy-flow-root"
		class="pw-flow-root"
		data-initial="<?php echo esc_attr( wp_json_encode( $flow ) ); ?>"
	></div>

	<div class="pw-flow-stats-grid">
		<div class="pw-flow-stat-card">
			<span class="pw-flow-stat-label"><?php esc_html_e( 'ソーラー発電 (1.5kW)', 'gaming-hub' ); ?></span>
			<strong data-pw-field="solar_w"><?php echo esc_html( number_format_i18n( (int) $status['solar_w'] ) . ' W' ); ?></strong>
		</div>
		<div class="pw-flow-stat-card">
			<span class="pw-flow-stat-label"><?php esc_html_e( 'ホーム消費', 'gaming-hub' ); ?></span>
			<strong data-pw-field="home_w"><?php echo esc_html( number_format_i18n( (int) $status['home_w'] ) . ' W' ); ?></strong>
		</div>
		<div class="pw-flow-stat-card">
			<span class="pw-flow-stat-label">
				<?php esc_html_e( 'Model 3 充電', 'gaming-hub' ); ?>
				<?php if ( 'tesla' === ( $status['model3_source'] ?? '' ) ) : ?>
					<small class="pw-flow-stat-badge"><?php esc_html_e( 'Tesla API', 'gaming-hub' ); ?></small>
				<?php endif; ?>
			</span>
			<strong data-pw-field="model3_w"><?php echo esc_html( number_format_i18n( (int) ( $status['model3']['watts'] ?? 0 ) ) . ' W' ); ?></strong>
			<small data-pw-field="model3_state"><?php echo esc_html( $status['model3']['charge_state'] ?? '—' ); ?></small>
		</div>
		<div class="pw-flow-stat-card">
			<span class="pw-flow-stat-label"><?php esc_html_e( 'グリッド買電', 'gaming-hub' ); ?></span>
			<strong data-pw-field="grid_import_w"><?php echo esc_html( number_format_i18n( (int) $status['grid_import_w'] ) . ' W' ); ?></strong>
		</div>
		<div class="pw-flow-stat-card">
			<span class="pw-flow-stat-label"><?php esc_html_e( 'Model 3 SOC', 'gaming-hub' ); ?></span>
			<strong data-pw-field="model3_soc"><?php echo esc_html( (int) ( $status['model3']['battery_percent'] ?? 0 ) . '%' ); ?></strong>
		</div>
		<div class="pw-flow-stat-card">
			<span class="pw-flow-stat-label"><?php esc_html_e( 'Powerwall SOC', 'gaming-hub' ); ?></span>
			<strong data-pw-field="powerwall_soc"><?php echo esc_html( (int) ( $status['powerwall']['battery_percent'] ?? 0 ) . '%' ); ?></strong>
		</div>
	</div>

	<?php if ( ! empty( $cost_meta ) ) : ?>
		<div class="pw-flow-cost-section" aria-label="<?php esc_attr_e( '本日の電気代見込み', 'gaming-hub' ); ?>">
			<div class="pw-flow-cost-header">
				<h3><?php esc_html_e( '本日の電気代見込み', 'gaming-hub' ); ?></h3>
				<p class="pw-flow-cost-subtitle" data-pw-field="cost_subtitle">
					<?php
					printf(
						/* translators: 1: provider, 2: contract kW, 3: date label */
						esc_html__( '%1$s · 契約 %2$s kW · %3$s（24時間シミュレーション）', 'gaming-hub' ),
						esc_html( $cost_meta['provider'] ?? __( 'LOOOP スマートタイムONE（電灯）', 'gaming-hub' ) ),
						esc_html( number_format_i18n( (float) ( $cost_meta['contract_kw'] ?? 6 ), 1 ) ),
						esc_html( $cost_meta['date_label'] ?? wp_date( get_option( 'date_format' ) ) )
					);
					?>
				</p>
			</div>

			<div class="pw-flow-cost-grid">
				<div class="pw-flow-cost-card">
					<span class="pw-flow-cost-label"><?php esc_html_e( '1日の使用量', 'gaming-hub' ); ?></span>
					<strong data-pw-field="cost_total_kwh"><?php echo esc_html( number_format_i18n( (float) ( $cost_meta['total_kwh'] ?? 0 ), 1 ) . ' kWh' ); ?></strong>
					<small data-pw-field="cost_grid_kwh">
						<?php
						printf(
							/* translators: 1: grid import kWh, 2: solar self kWh */
							esc_html__( '買電 %1$s kWh · ソーラー自家消費 %2$s kWh', 'gaming-hub' ),
							esc_html( number_format_i18n( (float) ( $cost_meta['grid_import_kwh'] ?? 0 ), 1 ) ),
							esc_html( number_format_i18n( (float) ( $cost_meta['solar_self_kwh'] ?? 0 ), 1 ) )
						);
						?>
					</small>
				</div>
				<div class="pw-flow-cost-card">
					<span class="pw-flow-cost-label"><?php esc_html_e( '電気代（ソーラーあり）', 'gaming-hub' ); ?></span>
					<strong data-pw-field="cost_with_solar"><?php echo esc_html( '¥' . number_format_i18n( (int) ( $cost_meta['cost_with_solar_yen'] ?? 0 ) ) ); ?></strong>
					<small data-pw-field="cost_without_solar">
						<?php
						printf(
							/* translators: %s: cost without solar */
							esc_html__( 'ソーラーなし想定: ¥%s', 'gaming-hub' ),
							esc_html( number_format_i18n( (int) ( $cost_meta['cost_without_solar_yen'] ?? 0 ) ) )
						);
						?>
					</small>
				</div>
				<div class="pw-flow-cost-card is-highlight">
					<span class="pw-flow-cost-label"><?php esc_html_e( '節約額', 'gaming-hub' ); ?></span>
					<strong class="pw-flow-cost-saved" data-pw-field="cost_saved"><?php echo esc_html( '¥' . number_format_i18n( (int) ( $cost_meta['saved_yen'] ?? 0 ) ) ); ?></strong>
					<small data-pw-field="cost_saved_percent">
						<?php
						printf(
							/* translators: %s: savings percent */
							esc_html__( '約 %s%% 削減', 'gaming-hub' ),
							esc_html( number_format_i18n( (float) ( $cost_meta['saved_percent'] ?? 0 ), 1 ) )
						);
						?>
					</small>
				</div>
				<div class="pw-flow-cost-card">
					<span class="pw-flow-cost-label"><?php esc_html_e( 'ソーラー発電 (1.5kW)', 'gaming-hub' ); ?></span>
					<strong data-pw-field="cost_solar_gen"><?php echo esc_html( number_format_i18n( (float) ( $cost_meta['solar_generation_kwh'] ?? 0 ), 1 ) . ' kWh' ); ?></strong>
					<small data-pw-field="cost_battery_self">
						<?php
						printf(
							/* translators: %s: battery self-consumption kWh */
							esc_html__( 'Powerwall 自家消費 %s kWh', 'gaming-hub' ),
							esc_html( number_format_i18n( (float) ( $cost_meta['battery_self_kwh'] ?? 0 ), 1 ) )
						);
						?>
					</small>
				</div>
			</div>

			<p class="pw-flow-cost-note" data-pw-field="cost_note">
				<?php
				echo esc_html( $cost_meta['pricing_note'] ?? '' );
				if ( ! empty( $cost_meta['disclaimer'] ) ) {
					echo ' · ' . esc_html( $cost_meta['disclaimer'] );
				}
				?>
			</p>
		</div>
	<?php elseif ( ! empty( $status['cost_error'] ) ) : ?>
		<p class="pw-flow-error"><?php echo esc_html( $status['cost_error'] ); ?></p>
	<?php endif; ?>
</section>
