<?php
/**
 * Pokémon GO news card partial
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
<article class="pgo-home-card">
	<a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener noreferrer">
		<?php gaming_hub_render_pokemon_go_image( $item ); ?>
		<div class="pgo-home-card-body">
			<div class="pgo-home-card-meta">
				<?php if ( ! empty( $item['categories'][0] ) ) : ?>
					<span class="pgo-badge <?php echo esc_attr( gaming_hub_pokemon_go_category_class( $item['categories'][0] ) ); ?>">
						<?php echo esc_html( $item['categories'][0] ); ?>
					</span>
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
