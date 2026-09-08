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
$energy = function_exists( 'gaming_hub_ecoflow_url' ) ? gaming_hub_ecoflow_url() . '#energy' : '#energy';
$img_base = trailingslashit( get_template_directory_uri() ) . 'assets/images/';
$img_ver  = defined( 'GAMING_HUB_VERSION' ) ? '?ver=' . rawurlencode( (string) GAMING_HUB_VERSION ) : '';
?>
<section id="kit" class="ecoflow-kit" aria-label="<?php esc_attr_e('Our measured kit', 'gaming-hub'); ?>">
	<header class="ecoflow-kit-head">
		<p class="ecoflow-kit-eyebrow"><?php esc_html_e('Measured data × shop links', 'gaming-hub'); ?></p>
		<h2 class="ecoflow-kit-title"><?php esc_html_e('Our measured kit', 'gaming-hub'); ?></h2>
		<p class="ecoflow-kit-lead">
			<?php
			printf(
				/* translators: 1: month label, 2: savings yen, 3: solar kWh */
				esc_html__('%1$s savings: ¥%2$s · generation %3$s kWh. Gear running this site’s dashboards.', 'gaming-hub'),
				esc_html( $stats['label'] ),
				esc_html( number_format_i18n( $yen ) ),
				esc_html( number_format_i18n( $solar, 1 ) )
			);
			?>
		</p>
		<p class="ecoflow-kit-disclaimer">
			<?php esc_html_e('Some links on this site may be affiliate (advertising) links.', 'gaming-hub'); ?>
		</p>
	</header>

	<ul class="ecoflow-kit-list tesla-kit-grid">
		<?php foreach ( $items as $item ) : ?>
			<?php
			$primary = gaming_hub_affiliate_url( $item['primary'] ?? '' );
			$amazon  = ! empty( $item['amazon'] ) ? gaming_hub_affiliate_url( $item['amazon'] ) : '';
			if ( '' === $primary && '' === $amazon ) {
				continue;
			}
			$image   = (string) ( $item['image'] ?? '' );
			$img_url = '' !== $image ? $img_base . ltrim( $image, '/' ) . $img_ver : '';
			$href    = $primary ? $primary : $amazon;
			?>
			<li class="ecoflow-kit-item tesla-kit-card">
				<?php if ( $href ) : ?>
					<a class="tesla-kit-card-media" href="<?php echo esc_url( $href ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
						<?php if ( $img_url ) : ?>
							<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $item['name'] ); ?>" width="640" height="360" loading="lazy" decoding="async" />
						<?php endif; ?>
						<span class="tesla-kit-card-badge"><?php esc_html_e('Official', 'gaming-hub'); ?></span>
					</a>
				<?php elseif ( $img_url ) : ?>
					<div class="tesla-kit-card-media" aria-hidden="true">
						<img src="<?php echo esc_url( $img_url ); ?>" alt="" width="640" height="360" loading="lazy" decoding="async" />
					</div>
				<?php endif; ?>
				<div class="ecoflow-kit-copy tesla-kit-card-copy">
					<h3 class="ecoflow-kit-name"><?php echo esc_html( $item['name'] ); ?></h3>
					<p class="ecoflow-kit-role"><?php echo esc_html( $item['role'] ); ?></p>
				</div>
				<div class="ecoflow-kit-actions">
					<?php if ( $primary ) : ?>
						<a class="btn btn-primary ecoflow-btn ecoflow-kit-btn" href="<?php echo esc_url( $primary ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
							<?php esc_html_e('View official', 'gaming-hub'); ?>
						</a>
					<?php endif; ?>
					<?php if ( $amazon ) : ?>
						<a class="btn btn-outline ecoflow-btn-outline ecoflow-kit-btn" href="<?php echo esc_url( $amazon ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
							<?php esc_html_e('Amazon', 'gaming-hub'); ?>
						</a>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="ecoflow-kit-foot">
		<a href="<?php echo esc_url( $energy ); ?>"><?php esc_html_e('See numbers in the generation log →', 'gaming-hub'); ?></a>
	</p>
</section>
