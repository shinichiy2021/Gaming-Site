<?php
/**
 * Powerwall news card partial (home grid)
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $item News item data.
 */

$item = isset( $args['item'] ) ? $args['item'] : array();
if ( empty( $item ) ) {
	return;
}
?>
<article class="pw-home-card">
	<a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener noreferrer">
		<?php gaming_hub_render_powerwall_image( $item ); ?>
		<div class="pw-home-card-body">
			<div class="pw-home-card-meta">
				<?php if ( ! empty( $item['source'] ) ) : ?>
					<span class="pw-badge pw-badge-source"><?php echo esc_html( $item['source'] ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $item['date_display'] ) ) : ?>
					<time datetime="<?php echo esc_attr( $item['date'] ); ?>"><?php echo esc_html( $item['date_display'] ); ?></time>
				<?php endif; ?>
			</div>
			<h3><?php echo esc_html( $item['title'] ); ?></h3>
			<?php if ( ! empty( $item['excerpt'] ) ) : ?>
				<p><?php echo esc_html( $item['excerpt'] ); ?></p>
			<?php endif; ?>
		</div>
	</a>
</article>
