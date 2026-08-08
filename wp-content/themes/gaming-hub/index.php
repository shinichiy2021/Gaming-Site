<?php
/**
 * Main template
 *
 * @package Gaming_Hub
 */

get_header();
?>

<div class="container content-area">
	<?php if ( have_posts() ) : ?>
		<div class="posts-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/content', get_post_type() );
			endwhile;
			?>
		</div>

		<?php the_posts_pagination( array(
			'prev_text' => '&larr; ' . __( 'Previous', 'gaming-hub' ),
			'next_text' => __( 'Next', 'gaming-hub' ) . ' &rarr;',
		) ); ?>

	<?php else : ?>
		<div class="no-results">
			<h2><?php esc_html_e( 'Nothing Found', 'gaming-hub' ); ?></h2>
			<p><?php esc_html_e( 'It seems we can\'t find what you\'re looking for.', 'gaming-hub' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
