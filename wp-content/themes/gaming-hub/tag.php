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
?>

<?php if ( $is_ecoflow ) : ?>
	<div class="archive-header ecoflow-archive-header">
		<div class="container">
			<span class="ecoflow-tag-badge ecoflow-tag-badge-lg">EcoFlow</span>
			<p class="ecoflow-archive-desc"><?php esc_html_e( 'ポータブル電源・ソーラーパネル・防災・キャンプ関連の記事', 'gaming-hub' ); ?></p>
			<div class="ecoflow-official-links">
				<a href="#energy" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '発電ログ', 'gaming-hub' ); ?></a>
				<a href="#kit" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '実測構成', 'gaming-hub' ); ?></a>
			</div>
		</div>
	</div>
<?php elseif ( ! $is_dash ) : ?>
	<div class="archive-header">
		<div class="container">
			<?php the_archive_title( '<h1 class="archive-title">', '</h1>' ); ?>
			<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
		</div>
	</div>
<?php endif; ?>

<?php if ( have_posts() ) : ?>
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
<?php elseif ( $is_ecoflow ) : ?>
	<div class="container content-area content-area--hub-top">
		<div class="ecoflow-empty">
			<p><?php esc_html_e( 'EcoFlow タグの記事はまだありません。下の実測構成・発電ログから機材を確認できます。', 'gaming-hub' ); ?></p>
		</div>
	</div>
<?php elseif ( ! $is_dash ) : ?>
	<div class="container content-area">
		<div class="no-results">
			<h2><?php esc_html_e( 'Nothing Found', 'gaming-hub' ); ?></h2>
			<p><?php esc_html_e( 'No posts found with this tag.', 'gaming-hub' ); ?></p>
		</div>
	</div>
<?php endif; ?>

<?php if ( $is_tesla ) : ?>
	<section class="hub-section hub-tesla">
		<?php get_template_part( 'template-parts/powerwall', 'page' ); ?>
	</section>
<?php elseif ( $is_pgo ) : ?>
	<section class="hub-section hub-pokemon-go">
		<?php get_template_part( 'template-parts/pokemon-go', 'page' ); ?>
	</section>
<?php elseif ( $is_ecoflow ) : ?>
	<div class="container ecoflow-dashboard-wrap">
		<?php gaming_hub_render_ecoflow_dashboard(); ?>
	</div>
	<div id="energy" class="container ecoflow-dashboard-wrap">
		<?php gaming_hub_render_ecoflow_energy_page(); ?>
	</div>
	<div class="container ecoflow-dashboard-wrap">
		<?php get_template_part( 'template-parts/ecoflow', 'kit' ); ?>
	</div>
<?php endif; ?>

<?php
get_footer();
