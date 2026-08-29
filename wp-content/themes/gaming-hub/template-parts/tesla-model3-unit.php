<?php
/**
 * Model 3 unit card (vitals + battery HUD).
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $status Full flow status.
 */

$status = isset( $args['status'] ) && is_array( $args['status'] )
	? $args['status']
	: ( function_exists( 'gaming_hub_get_powerwall_flow_status' ) ? gaming_hub_get_powerwall_flow_status() : array() );
$model3 = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
$model3_charging = ! empty( $model3['is_charging'] );
?>
<div class="pw-model3-unit" data-pw-model3-unit data-status="<?php echo esc_attr( (string) ( $model3['status_key'] ?? 'idle' ) ); ?>" aria-label="<?php esc_attr_e( 'Model 3 ユニット', 'gaming-hub' ); ?>">
	<div class="pw-model3-unit-header">
		<img
			src="<?php echo esc_url( get_template_directory_uri() . '/assets/images/tesla-model3-gaming.jpg?ver=' . rawurlencode( (string) GAMING_HUB_VERSION ) ); ?>"
			alt=""
			class="pw-model3-unit-photo<?php echo $model3_charging ? ' is-charging' : ''; ?>"
			data-pw-model3-photo
		/>
		<div class="pw-model3-unit-identity">
			<p class="pw-model3-unit-class"><?php esc_html_e( 'UNIT · EV', 'gaming-hub' ); ?></p>
			<h3 data-pw-field="model3_name"><?php echo esc_html( $model3['vehicle_name'] ?? 'Model 3' ); ?></h3>
			<div class="pw-model3-badges">
				<span class="pw-model3-badge is-<?php echo esc_attr( (string) ( $model3['status_key'] ?? 'idle' ) ); ?>" data-pw-badge="status">
					<?php echo esc_html( $model3['badge_status'] ?? __( '待機', 'gaming-hub' ) ); ?>
				</span>
				<span
					class="pw-model3-badge is-supply"
					data-pw-badge="supply"
					<?php echo empty( $model3['plugged'] ) ? ' hidden' : ''; ?>
				><?php echo esc_html( $model3['supply_label'] ?? '' ); ?></span>
				<span
					class="pw-model3-badge is-sentry"
					data-pw-badge="sentry"
					<?php echo empty( $model3['sentry_label'] ) ? ' hidden' : ''; ?>
				><?php echo esc_html( $model3['sentry_label'] ?? '' ); ?></span>
				<span
					class="pw-model3-badge is-lock"
					data-pw-badge="lock"
					<?php echo empty( $model3['lock_label'] ) ? ' hidden' : ''; ?>
				><?php echo esc_html( $model3['lock_label'] ?? '' ); ?></span>
				<?php if ( 'tesla' === ( $status['model3_source'] ?? '' ) ) : ?>
					<span class="pw-model3-badge is-live"><?php esc_html_e( 'LIVE', 'gaming-hub' ); ?></span>
				<?php endif; ?>
			</div>
		</div>
	</div>

	<div class="pw-model3-unit-body">
		<div
			class="pw-model3-battery-gauge"
			data-pw-field="model3_gauge"
			style="<?php echo esc_attr( '--battery-level: ' . (int) ( $model3['battery_percent'] ?? 0 ) ); ?>"
		>
			<div class="pw-flow-battery-ring pw-model3-battery-ring<?php echo $model3_charging ? ' is-charging' : ''; ?>">
				<div class="pw-flow-battery-inner">
					<span class="pw-flow-battery-value" data-pw-field="model3_soc_gauge"><?php echo esc_html( (int) ( $model3['battery_percent'] ?? 0 ) . '%' ); ?></span>
					<span class="pw-flow-battery-label"><?php esc_html_e( 'HP', 'gaming-hub' ); ?></span>
				</div>
			</div>
		</div>

		<div class="pw-model3-bars">
			<div class="pw-model3-bar">
				<div class="pw-model3-bar-meta">
					<span><?php esc_html_e( 'HP · バッテリー', 'gaming-hub' ); ?></span>
					<strong data-pw-field="model3_hp"><?php echo esc_html( $model3['hp_label'] ?? '' ); ?></strong>
				</div>
				<div class="pw-model3-bar-track" aria-hidden="true">
					<span class="pw-model3-bar-fill is-hp" data-pw-bar="hp" style="<?php echo esc_attr( 'width:' . (int) ( $model3['battery_percent'] ?? 0 ) . '%' ); ?>"></span>
				</div>
				<small data-pw-field="model3_kwh"><?php echo esc_html( $model3['battery_kwh_label'] ?? '—' ); ?></small>
			</div>
			<div class="pw-model3-bar">
				<div class="pw-model3-bar-meta">
					<span><?php esc_html_e( 'MP · 航続', 'gaming-hub' ); ?></span>
					<strong data-pw-field="model3_range"><?php echo esc_html( $model3['range_label'] ?? '—' ); ?></strong>
				</div>
				<div class="pw-model3-bar-track" aria-hidden="true">
					<span class="pw-model3-bar-fill is-mp" data-pw-bar="mp" style="<?php echo esc_attr( 'width:' . (int) ( $model3['mp_percent'] ?? 0 ) . '%' ); ?>"></span>
				</div>
				<small data-pw-field="model3_limit"><?php echo esc_html( $model3['cap_label'] ?? '' ); ?></small>
			</div>
			<div class="pw-model3-bar">
				<div class="pw-model3-bar-meta">
					<span><?php esc_html_e( '本日クエスト', 'gaming-hub' ); ?></span>
					<strong data-pw-field="model3_quest"><?php echo esc_html( $model3['quest_label'] ?? '—' ); ?></strong>
				</div>
				<div class="pw-model3-bar-track" aria-hidden="true">
					<span class="pw-model3-bar-fill is-quest" data-pw-bar="quest" style="<?php echo esc_attr( 'width:' . (int) ( $model3['quest_percent'] ?? 0 ) . '%' ); ?>"></span>
				</div>
				<small data-pw-field="model3_quest_note"><?php esc_html_e( '1日 30 km', 'gaming-hub' ); ?></small>
			</div>
		</div>
	</div>

	<div
		class="pw-model3-raid<?php echo $model3_charging ? ' is-visible' : ''; ?>"
		data-pw-charging-panel
		<?php echo $model3_charging ? '' : ' hidden'; ?>
	>
		<p class="pw-model3-raid-title"><?php esc_html_e( 'チャージレイド', 'gaming-hub' ); ?></p>
		<div class="pw-model3-raid-grid">
			<div class="pw-model3-raid-stat">
				<span><?php esc_html_e( 'DPS', 'gaming-hub' ); ?></span>
				<strong data-pw-field="model3_charge_rate"><?php echo esc_html( $model3['charge_rate_label'] ?? '—' ); ?></strong>
			</div>
			<div class="pw-model3-raid-stat">
				<span><?php esc_html_e( '今回ドロップ', 'gaming-hub' ); ?></span>
				<strong data-pw-field="model3_drop"><?php echo esc_html( $model3['drop_label'] ?? '—' ); ?></strong>
			</div>
			<div class="pw-model3-raid-stat">
				<span><?php esc_html_e( 'クリアETA', 'gaming-hub' ); ?></span>
				<strong data-pw-field="model3_charge_eta"><?php echo esc_html( $model3['charge_eta_label'] ?? '—' ); ?></strong>
			</div>
			<div class="pw-model3-raid-stat">
				<span><?php esc_html_e( 'クリア予定', 'gaming-hub' ); ?></span>
				<strong data-pw-field="model3_charge_complete"><?php echo esc_html( $model3['charge_complete_label'] ?? '—' ); ?></strong>
			</div>
		</div>
		<p class="pw-model3-raid-combo" data-pw-field="model3_combo">
			<?php
			$combo  = (string) ( $model3['combo_label'] ?? '' );
			$supply = (string) ( $model3['supply_label'] ?? '' );
			echo esc_html( $combo ? $combo . ( $supply ? ' · ' . $supply : '' ) : $supply );
			?>
		</p>
	</div>

	<div class="pw-model3-career">
		<div>
			<span><?php esc_html_e( 'オドメーター', 'gaming-hub' ); ?></span>
			<strong data-pw-field="model3_odometer"><?php echo esc_html( $model3['odometer_plain_label'] ?? $model3['odometer_label'] ?? __( 'オドメーター —', 'gaming-hub' ) ); ?></strong>
		</div>
		<div>
			<span><?php esc_html_e( '室内温度', 'gaming-hub' ); ?></span>
			<strong data-pw-field="model3_cabin_temp"><?php echo esc_html( $model3['cabin_temp_label'] ?? __( '室内 —', 'gaming-hub' ) ); ?></strong>
		</div>
		<div>
			<span><?php esc_html_e( '空気圧', 'gaming-hub' ); ?></span>
			<strong data-pw-field="model3_tire_pressure"><?php echo esc_html( $model3['tire_pressure_label'] ?? __( '空気圧 —', 'gaming-hub' ) ); ?></strong>
		</div>
		<div>
			<span><?php esc_html_e( 'ファーム', 'gaming-hub' ); ?></span>
			<strong data-pw-field="model3_patch"><?php echo esc_html( $model3['patch_label'] ?? __( 'パッチ —', 'gaming-hub' ) ); ?></strong>
		</div>
		<div data-pw-next-raid <?php echo empty( $model3['next_raid_label'] ) ? ' hidden' : ''; ?>>
			<span><?php esc_html_e( '予約', 'gaming-hub' ); ?></span>
			<strong data-pw-field="model3_next_raid"><?php echo esc_html( $model3['next_raid_label'] ?? '' ); ?></strong>
		</div>
	</div>
</div>
