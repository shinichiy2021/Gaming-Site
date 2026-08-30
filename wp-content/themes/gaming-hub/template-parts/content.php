<article id="post-<?php the_ID(); ?>" <?php post_class( 'post-card' ); ?>>
	<a href="<?php the_permalink(); ?>" class="post-card-link">
		<div class="post-card-image<?php echo ( function_exists( 'gaming_hub_is_api_diagram_post' ) && gaming_hub_is_api_diagram_post() ) ? ' post-card-image--diagram' : ''; ?>">
			<?php if ( function_exists( 'gaming_hub_is_api_diagram_post' ) && gaming_hub_is_api_diagram_post() ) : ?>
				<img src="<?php echo esc_url( gaming_hub_api_diagram_hero_image_url() ); ?>" alt="<?php echo esc_attr( gaming_hub_api_diagram_hero_alt() ); ?>" width="1200" height="675" loading="lazy" decoding="async" />
			<?php elseif ( has_post_thumbnail() ) : ?>
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
