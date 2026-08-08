<article id="post-<?php the_ID(); ?>" <?php post_class( 'post-card' ); ?>>
	<a href="<?php the_permalink(); ?>" class="post-card-link">
		<div class="post-card-image">
			<?php if ( has_post_thumbnail() ) : ?>
				<?php the_post_thumbnail( 'game-card' ); ?>
			<?php else : ?>
				<div class="placeholder-image"></div>
			<?php endif; ?>
			<?php
			$meta = gaming_hub_get_game_meta();
			if ( ! empty( $meta['rating'] ) ) :
				?>
				<span class="game-rating"><?php echo esc_html( $meta['rating'] ); ?>/5</span>
			<?php endif; ?>
		</div>
		<div class="post-card-body">
			<div class="post-card-meta">
				<?php the_category( ', ' ); ?>
				<?php if ( gaming_hub_has_ecoflow_tag() ) : ?>
					<?php gaming_hub_render_ecoflow_tag_badge(); ?>
				<?php endif; ?>
				<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
			</div>
			<h2 class="post-card-title"><?php the_title(); ?></h2>
			<p class="post-card-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
		</div>
	</a>
</article>
