<?php
/**
 * Tag archive — EcoFlow / Tesla / Pokémon GO screens.
 *
 * @package Gaming_Hub
 */

get_header();

$is_ecoflow = is_tag( 'ecoflow' );
$is_tesla   = is_tag( 'tesla' );
$is_pgo     = is_tag( 'pokemon-go' );
$is_dash    = $is_ecoflow || $is_tesla || $is_pgo;
$is_spa     = function_exists( 'gaming_hub_is_hub_spa_page' ) && gaming_hub_is_hub_spa_page();
?>

<?php if ( $is_spa && function_exists( 'gaming_hub_render_hub_spa_panels' ) ) : ?>
	<?php gaming_hub_render_hub_spa_panels( gaming_hub_hub_spa_active_slug() ); ?>
<?php else : ?>

<?php if ( ! $is_dash ) : ?>
	<div class="archive-header">
		<div class="container">
			<?php the_archive_title( '<h1 class="archive-title">', '</h1>' ); ?>
			<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
		</div>
	</div>
<?php endif; ?>

<?php if ( $is_ecoflow ) : ?>
	<?php gaming_hub_render_ecoflow_hub_intro(); ?>
	<?php gaming_hub_render_ecoflow_hub_dashboard_sections(); ?>

	<?php if ( have_posts() ) : ?>
	<section id="ecoflow-posts" class="ecoflow-hub-section">
		<div class="container content-area content-area--hub-top">
			<?php
			gaming_hub_render_ecoflow_section_head(
				__( '記事', 'gaming-hub' ),
				__( '実測レビュー・運用メモ', 'gaming-hub' )
			);
			?>
			<div class="posts-grid">
				<?php
				while ( have_posts() ) :
					the_post();
					get_template_part( 'template-parts/content', get_post_type() );
				endwhile;
				?>
			</div>

			<?php
			the_posts_pagination(
				array(
					'prev_text' => '&larr; ' . __( 'Previous', 'gaming-hub' ),
					'next_text' => __( 'Next', 'gaming-hub' ) . ' &rarr;',
				)
			);
			?>
		</div>
	</section>
	<?php else : ?>
	<section id="ecoflow-posts" class="ecoflow-hub-section">
		<div class="container content-area content-area--hub-top">
			<?php
			gaming_hub_render_ecoflow_section_head(
				__( '記事', 'gaming-hub' ),
				__( '実測レビュー・運用メモ', 'gaming-hub' )
			);
			?>
			<div class="ecoflow-empty">
				<p><?php esc_html_e( 'EcoFlow タグの記事はまだありません。上の実測構成・発電ログから機材を確認できます。', 'gaming-hub' ); ?></p>
			</div>
		</div>
	</section>
	<?php endif; ?>

<?php elseif ( $is_tesla ) : ?>
	<?php gaming_hub_render_tesla_hub_intro(); ?>
	<?php gaming_hub_render_tesla_hub_dashboard_sections(); ?>
	<?php gaming_hub_render_tesla_hub_posts_section(); ?>

<?php elseif ( have_posts() ) : ?>
	<div class="container content-area <?php echo $is_dash ? 'content-area--hub-top' : ''; ?>">
		<div class="posts-grid">
			<?php
			while ( have_posts() ) :
				the_post();
				get_template_part( 'template-parts/content', get_post_type() );
			endwhile;
			?>
		</div>

		<?php
		the_posts_pagination(
			array(
				'prev_text' => '&larr; ' . __( 'Previous', 'gaming-hub' ),
				'next_text' => __( 'Next', 'gaming-hub' ) . ' &rarr;',
			)
		);
		?>
	</div>
<?php elseif ( ! $is_dash ) : ?>
	<div class="container content-area">
		<div class="no-results">
			<h2><?php esc_html_e( 'Nothing Found', 'gaming-hub' ); ?></h2>
			<p><?php esc_html_e( 'No posts found with this tag.', 'gaming-hub' ); ?></p>
		</div>
	</div>
<?php endif; ?>

<?php if ( $is_pgo ) : ?>
	<section class="hub-section hub-pokemon-go">
		<?php get_template_part( 'template-parts/pokemon-go', 'page' ); ?>
	</section>
<?php endif; ?>

<?php endif; ?>

<?php
get_footer();
