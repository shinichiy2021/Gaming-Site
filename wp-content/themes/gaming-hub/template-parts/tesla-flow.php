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

	<p class="tesla-flow-note">
		<?php esc_html_e( '入力は普通充電（Wall Connector）と急速充電（Supercharger）。出力は走行消費と車内電力です。', 'gaming-hub' ); ?>
	</p>

	<?php if ( 'tesla' === $source ) : ?>
		<?php gaming_hub_render_tesla_link_status( $status ); ?>
	<?php elseif ( ! empty( $status['model3_error'] ) ) : ?>
		<div class="pw-flow-error-action">
			<p class="pw-flow-error"><?php echo esc_html( 'Model 3 API: ' . $status['model3_error'] ); ?></p>
			<?php gaming_hub_render_tesla_oauth_button(); ?>
			<?php gaming_hub_render_tesla_revoke_button(); ?>
		</div>
	<?php endif; ?>

	<div
		id="tesla-energy-flow-root"
		class="tesla-flow-root"
		data-initial="<?php echo esc_attr( wp_json_encode( $flow ) ); ?>"
	></div>
</section>
