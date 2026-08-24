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
		<?php esc_html_e( '入力は自宅の 200V 普通充電と急速充電（Supercharger）。走行は多治見のガソリン価格で普通車換算し、節約額を出します。', 'gaming-hub' ); ?>
		<a href="#plan"><?php esc_html_e( 'AI PLAN', 'gaming-hub' ); ?></a>
		<a href="#gas"><?php esc_html_e( '節約ログ', 'gaming-hub' ); ?></a>
	</p>

	<?php if ( 'tesla' === $source ) : ?>
		<?php gaming_hub_render_tesla_link_status( $status ); ?>
	<?php elseif ( ! empty( $status['model3_error'] ) ) : ?>
		<div class="pw-flow-error-action">
			<p class="pw-flow-error"><?php echo esc_html( 'Model 3 API: ' . $status['model3_error'] ); ?></p>
			<?php gaming_hub_render_tesla_oauth_button(); ?>
		</div>
	<?php endif; ?>

	<div class="tesla-charge-controls" data-tesla-charge>
		<p class="tesla-charge-note">
			<?php esc_html_e( 'テスト用。ケーブル接続中の充電オン／オフを Tesla に送ります。', 'gaming-hub' ); ?>
		</p>
		<div class="tesla-charge-buttons">
			<button type="button" class="tesla-charge-on" data-tesla-charge-action="start">
				<?php esc_html_e( '充電オン', 'gaming-hub' ); ?>
			</button>
			<button type="button" class="tesla-charge-off" data-tesla-charge-action="stop">
				<?php esc_html_e( '充電オフ', 'gaming-hub' ); ?>
			</button>
		</div>
		<p class="tesla-charge-status" data-tesla-charge-status></p>
		<?php
		$virtual_key_url = function_exists( 'gaming_hub_tesla_virtual_key_url' )
			? gaming_hub_tesla_virtual_key_url()
			: '';
		if ( '' !== $virtual_key_url ) :
			?>
			<p class="tesla-charge-key">
				<a href="<?php echo esc_url( $virtual_key_url ); ?>" rel="noopener noreferrer">
					<?php esc_html_e( '仮想キーを追加', 'gaming-hub' ); ?>
				</a>
			</p>
		<?php endif; ?>
		<?php if ( 'tesla' === $source && function_exists( 'gaming_hub_tesla_has_charging_scope' ) && ! gaming_hub_tesla_has_charging_scope() ) : ?>
			<p class="tesla-charge-auth">
				<?php esc_html_e( '充電操作には再認証が必要です。', 'gaming-hub' ); ?>
				<?php gaming_hub_render_tesla_oauth_button( true, true ); ?>
			</p>
		<?php endif; ?>
	</div>

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
if ( function_exists( 'gaming_hub_render_tesla_gas_log' ) ) {
	gaming_hub_render_tesla_gas_log( $status );
}
?>
