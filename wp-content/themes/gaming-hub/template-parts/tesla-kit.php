<?php
/**
 * Tesla kit: live driving-log stats + affiliate purchase links.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! function_exists( 'gaming_hub_affiliate_tesla_kit_enabled' ) || ! gaming_hub_affiliate_tesla_kit_enabled() ) {
	return;
}

$stats = gaming_hub_affiliate_tesla_month_stats();
$items = gaming_hub_affiliate_tesla_kit_items();
$rel   = gaming_hub_affiliate_rel();
$yen   = (int) round( (float) $stats['saved_yen'] );
$km    = (float) $stats['km'];
$drive = function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() . '#drive' : home_url( '/tag/tesla/#drive' );
?>
<section id="tesla-kit" class="ecoflow-kit tesla-kit" aria-label="<?php esc_attr_e( 'うちの実測構成', 'gaming-hub' ); ?>">
	<header class="ecoflow-kit-head">
		<p class="ecoflow-kit-eyebrow"><?php esc_html_e( '実測 × 購入リンク', 'gaming-hub' ); ?></p>
		<h2 class="ecoflow-kit-title"><?php esc_html_e( 'うちの実測構成', 'gaming-hub' ); ?></h2>
		<p class="ecoflow-kit-lead">
			<?php
			printf(
				/* translators: 1: month label, 2: savings yen, 3: distance km */
				esc_html__( '%1$sのガソリン比較節約 %2$s円 · 走行 %3$s km。このサイトで動かしている Tesla です。', 'gaming-hub' ),
				esc_html( $stats['label'] ),
				esc_html( number_format_i18n( $yen ) ),
				esc_html( number_format_i18n( $km, 1 ) )
			);
			?>
		</p>
		<p class="ecoflow-kit-offer">
			<?php esc_html_e( '紹介リンク経由の購入で、最大 35,000 円相当の特典が付く場合があります。', 'gaming-hub' ); ?>
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
						<a class="btn btn-primary tesla-kit-btn ecoflow-kit-btn" href="<?php echo esc_url( $primary ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
							<?php
							echo ( 'tesla_model3' === ( $item['primary'] ?? '' ) || 'tesla_home' === ( $item['primary'] ?? '' ) )
								? esc_html__( '紹介リンクで見る', 'gaming-hub' )
								: esc_html__( '公式で見る', 'gaming-hub' );
							?>
						</a>
					<?php endif; ?>
					<?php if ( $amazon ) : ?>
						<a class="btn btn-outline tesla-kit-btn-outline ecoflow-kit-btn" href="<?php echo esc_url( $amazon ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
							<?php esc_html_e( 'Amazon', 'gaming-hub' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="ecoflow-kit-foot">
		<a href="<?php echo esc_url( $drive ); ?>"><?php esc_html_e( 'Driving Log で数字を確認 →', 'gaming-hub' ); ?></a>
	</p>
</section>
