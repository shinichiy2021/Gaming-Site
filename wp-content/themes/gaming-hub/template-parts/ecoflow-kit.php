<?php
/**
 * EcoFlow kit: live month stats + affiliate purchase links.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gaming_hub_affiliate_kit_enabled' ) || ! gaming_hub_affiliate_kit_enabled() ) {
	return;
}

$stats = gaming_hub_affiliate_ecoflow_month_stats();
$items = gaming_hub_affiliate_ecoflow_kit_items();
$rel   = gaming_hub_affiliate_rel();
$yen   = (int) round( (float) $stats['saved_yen'] );
$solar = (float) $stats['solar_kwh'];
?>
<section id="kit" class="ecoflow-kit" aria-label="<?php esc_attr_e( 'うちの実測構成', 'gaming-hub' ); ?>">
	<header class="ecoflow-kit-head">
		<p class="ecoflow-kit-eyebrow"><?php esc_html_e( '実測 × 購入リンク', 'gaming-hub' ); ?></p>
		<h2 class="ecoflow-kit-title"><?php esc_html_e( 'うちの実測構成', 'gaming-hub' ); ?></h2>
		<p class="ecoflow-kit-lead">
			<?php
			printf(
				/* translators: 1: month label, 2: savings yen, 3: solar kWh */
				esc_html__( '%1$sの節約 %2$s円 · 発電 %3$s kWh。このサイトで動かしている機材です。', 'gaming-hub' ),
				esc_html( $stats['label'] ),
				esc_html( number_format_i18n( $yen ) ),
				esc_html( number_format_i18n( $solar, 1 ) )
			);
			?>
		</p>
		<p class="ecoflow-kit-disclaimer">
			<?php esc_html_e( '当サイトのリンクにはアフィリエイト（広告）が含まれる場合があります。', 'gaming-hub' ); ?>
		</p>
	</header>

	<ul class="ecoflow-kit-list">
		<?php foreach ( $items as $item ) : ?>
			<?php
			$primary = gaming_hub_affiliate_url( $item['primary'] ?? '' );
			$amazon  = ! empty( $item['amazon'] ) ? gaming_hub_affiliate_url( $item['amazon'] ) : '';
			if ( '' === $primary && '' === $amazon ) {
				continue;
			}
			?>
			<li class="ecoflow-kit-item">
				<div class="ecoflow-kit-copy">
					<h3 class="ecoflow-kit-name"><?php echo esc_html( $item['name'] ); ?></h3>
					<p class="ecoflow-kit-role"><?php echo esc_html( $item['role'] ); ?></p>
				</div>
				<div class="ecoflow-kit-actions">
					<?php if ( $primary ) : ?>
						<a class="btn btn-primary ecoflow-btn ecoflow-kit-btn" href="<?php echo esc_url( $primary ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
							<?php esc_html_e( '公式で見る', 'gaming-hub' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $amazon ) : ?>
						<a class="btn btn-outline ecoflow-btn-outline ecoflow-kit-btn" href="<?php echo esc_url( $amazon ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
							<?php esc_html_e( 'Amazon', 'gaming-hub' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="ecoflow-kit-foot">
		<a href="#energy"><?php esc_html_e( '発電ログで数字を確認 →', 'gaming-hub' ); ?></a>
	</p>
</section>
