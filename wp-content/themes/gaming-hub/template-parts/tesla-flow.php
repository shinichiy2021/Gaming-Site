<?php
/**
 * Tesla-only vehicle energy flow.
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $status Powerwall/Tesla status.
 */

$status = isset( $args['status'] ) && is_array( $args['status'] )
	? $args['status']
	: gaming_hub_get_powerwall_flow_status();
$model3 = is_array( $status['model3'] ?? null ) ? $status['model3'] : array();
$source = (string) ( $status['model3_source'] ?? 'simulated' );
$flow   = isset( $status['tesla_flow'] ) && is_array( $status['tesla_flow'] )
	? $status['tesla_flow']
	: gaming_hub_tesla_vehicle_flow_payload( $model3, $source );
$efficiency = isset( $flow['efficiency'] ) && is_array( $flow['efficiency'] )
	? $flow['efficiency']
	: ( isset( $model3['efficiency'] ) && is_array( $model3['efficiency'] ) ? $model3['efficiency'] : array() );
?>
<section class="tesla-flow-dashboard" aria-label="<?php esc_attr_e( 'Tesla 電力フロー', 'gaming-hub' ); ?>">
	<div class="pw-flow-dashboard-header">
		<h2><?php esc_html_e( 'Tesla 電力フロー', 'gaming-hub' ); ?></h2>
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

	<?php if ( ! empty( $efficiency['badge_wh'] ) || ! empty( $efficiency['badge_regen'] ) ) : ?>
		<div class="tesla-eff-badges" data-tesla-eff-badges aria-label="<?php esc_attr_e( '効率バッジ', 'gaming-hub' ); ?>">
			<?php if ( ! empty( $efficiency['badge_wh'] ) ) : ?>
				<span
					class="tesla-eff-badge tesla-eff-badge-wh is-<?php echo esc_attr( (string) ( $efficiency['tier_wh'] ?? 'idle' ) ); ?>"
					data-tesla-eff-wh
				><?php echo esc_html( (string) $efficiency['badge_wh'] ); ?></span>
			<?php else : ?>
				<span class="tesla-eff-badge tesla-eff-badge-wh is-idle" data-tesla-eff-wh hidden></span>
			<?php endif; ?>
			<?php if ( ! empty( $efficiency['badge_regen'] ) ) : ?>
				<span
					class="tesla-eff-badge tesla-eff-badge-regen is-<?php echo esc_attr( (string) ( $efficiency['tier_regen'] ?? 'idle' ) ); ?>"
					data-tesla-eff-regen
				><?php echo esc_html( (string) $efficiency['badge_regen'] ); ?></span>
			<?php else : ?>
				<span class="tesla-eff-badge tesla-eff-badge-regen is-idle" data-tesla-eff-regen hidden></span>
			<?php endif; ?>
		</div>
	<?php else : ?>
		<div class="tesla-eff-badges" data-tesla-eff-badges aria-label="<?php esc_attr_e( '効率バッジ', 'gaming-hub' ); ?>" hidden>
			<span class="tesla-eff-badge tesla-eff-badge-wh is-idle" data-tesla-eff-wh hidden></span>
			<span class="tesla-eff-badge tesla-eff-badge-regen is-idle" data-tesla-eff-regen hidden></span>
		</div>
	<?php endif; ?>

	<p class="tesla-flow-note">
		<?php esc_html_e( '入力は自宅の 200V 普通充電と急速充電（Supercharger）。走行は多治見のガソリン価格で普通車換算し、節約額を出します。', 'gaming-hub' ); ?>
		<a href="#plan"><?php esc_html_e( 'AI PLAN', 'gaming-hub' ); ?></a>
		<a href="#charge"><?php esc_html_e( '充電履歴', 'gaming-hub' ); ?></a>
		<a href="#drive"><?php esc_html_e( '走行ログ', 'gaming-hub' ); ?></a>
	</p>

	<?php if ( 'tesla' === $source ) : ?>
		<?php gaming_hub_render_tesla_link_status( $status ); ?>
	<?php elseif ( ! empty( $status['model3_error'] ) ) : ?>
		<div class="pw-flow-error-action">
			<p class="pw-flow-error"><?php echo esc_html( 'Model 3 API: ' . $status['model3_error'] ); ?></p>
			<?php gaming_hub_render_tesla_oauth_button(); ?>
		</div>
	<?php endif; ?>

	<div
		id="tesla-energy-flow-root"
		class="tesla-flow-root"
		data-initial="<?php echo esc_attr( wp_json_encode( $flow ) ); ?>"
	></div>
</section>
<?php
if ( function_exists( 'gaming_hub_render_tesla_plan' ) ) {
	gaming_hub_render_tesla_plan( $status );
}
if ( function_exists( 'gaming_hub_render_tesla_charge_log' ) ) {
	gaming_hub_render_tesla_charge_log( $status );
}
if ( function_exists( 'gaming_hub_render_tesla_gas_log' ) ) {
	gaming_hub_render_tesla_gas_log( $status );
}
?>
