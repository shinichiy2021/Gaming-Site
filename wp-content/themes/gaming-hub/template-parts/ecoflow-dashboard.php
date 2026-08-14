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
						<span class="ecoflow-sn"><?php esc_html_e( 'Pro DC 12V 接続', 'gaming-hub' ); ?></span>
					</div>
					<span class="ecoflow-online-badge <?php echo ! empty( $status['secondary']['is_charging'] ) ? 'is-online' : 'is-offline'; ?>">
						<?php echo ! empty( $status['secondary']['is_charging'] ) ? esc_html__( '受電中', 'gaming-hub' ) : esc_html__( '待機', 'gaming-hub' ); ?>
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

		<div class="ecoflow-stats-grid">
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'AC 入力 (Pro)', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="ac_in_stat"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['ac_in'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'AC 出力 → 部屋', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="ac_out"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['ac_out'] ) ); ?></strong>
			</div>
			<div class="ecoflow-stat-card">
				<span class="ecoflow-stat-label"><?php esc_html_e( 'DC 12V → 1500', 'gaming-hub' ); ?></span>
				<strong data-ecoflow-field="dc12v_link"><?php echo esc_html( gaming_hub_format_ecoflow_watts( $status['dc_out'] ) ); ?></strong>
			</div>
			<?php if ( ! empty( $status['secondary'] ) ) : ?>
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
