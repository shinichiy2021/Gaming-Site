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
$img_base = trailingslashit( get_template_directory_uri() ) . 'assets/images/';
$img_ver  = defined( 'GAMING_HUB_VERSION' ) ? '?ver=' . rawurlencode( (string) GAMING_HUB_VERSION ) : '';
?>
<section id="tesla-kit" class="ecoflow-kit tesla-kit" aria-label="<?php esc_attr_e('Our measured kit', 'gaming-hub'); ?>">
	<header class="ecoflow-kit-head">
		<p class="ecoflow-kit-eyebrow"><?php esc_html_e('Measured data × shop links', 'gaming-hub'); ?></p>
		<h2 class="ecoflow-kit-title"><?php esc_html_e('Our measured kit', 'gaming-hub'); ?></h2>
		<p class="ecoflow-kit-lead">
			<?php
			printf(
				/* translators: 1: month label, 2: savings yen, 3: distance km */
				esc_html__('%1$s gasoline-vs-EV savings: ¥%2$s · distance %3$s km. The Tesla powering this site’s logs.', 'gaming-hub'),
				esc_html( $stats['label'] ),
				esc_html( number_format_i18n( $yen ) ),
				esc_html( number_format_i18n( $km, 1 ) )
			);
			?>
		</p>
		<p class="ecoflow-kit-offer">
			<?php esc_html_e('Purchases via the referral link may include up to ¥35,000 in benefits.', 'gaming-hub'); ?>
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
			$is_refer = in_array( (string) ( $item['primary'] ?? '' ), array( 'tesla_model3', 'tesla_home' ), true );
			$cta_label = $is_refer
				? __('Open referral link', 'gaming-hub')
				: __('View official', 'gaming-hub');
			$href = $primary ? $primary : $amazon;
			?>
			<li class="ecoflow-kit-item tesla-kit-card">
				<?php if ( $href ) : ?>
					<a class="tesla-kit-card-media" href="<?php echo esc_url( $href ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
						<?php if ( $img_url ) : ?>
							<img src="<?php echo esc_url( $img_url ); ?>" alt="<?php echo esc_attr( $item['name'] ); ?>" width="640" height="360" loading="lazy" decoding="async" />
						<?php endif; ?>
						<span class="tesla-kit-card-badge"><?php echo esc_html( $is_refer ? __('Referral', 'gaming-hub') : __('Official', 'gaming-hub') ); ?></span>
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
						<a class="btn btn-primary tesla-kit-btn ecoflow-kit-btn" href="<?php echo esc_url( $primary ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
							<?php echo esc_html( $cta_label ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $amazon ) : ?>
						<a class="btn btn-outline tesla-kit-btn-outline ecoflow-kit-btn" href="<?php echo esc_url( $amazon ); ?>" target="_blank" rel="<?php echo esc_attr( $rel ); ?>">
							<?php esc_html_e('Amazon', 'gaming-hub'); ?>
						</a>
					<?php endif; ?>
				</div>
			</li>
		<?php endforeach; ?>
	</ul>

	<p class="ecoflow-kit-foot">
		<a href="<?php echo esc_url( $drive ); ?>"><?php esc_html_e('See numbers in the Driving Log →', 'gaming-hub'); ?></a>
	</p>
</section>
