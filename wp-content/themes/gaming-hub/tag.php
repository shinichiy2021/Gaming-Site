<?php
/**
 * Tag archive template
 *
 * @package Gaming_Hub
 */

get_header();

$is_ecoflow = is_tag( 'ecoflow' );
$is_energy  = is_tag( 'energy' );
?>

<div class="archive-header <?php echo ( $is_ecoflow || $is_energy ) ? 'ecoflow-archive-header' : ''; ?>">
	<div class="container">
		<?php if ( $is_ecoflow ) : ?>
			<span class="ecoflow-tag-badge ecoflow-tag-badge-lg">EcoFlow</span>
		<?php elseif ( $is_energy ) : ?>
			<span class="ecoflow-tag-badge ecoflow-tag-badge-lg">Energy</span>
		<?php endif; ?>
		<?php the_archive_title( '<h1 class="archive-title">', '</h1>' ); ?>
		<?php the_archive_description( '<div class="archive-description">', '</div>' ); ?>
		<?php if ( $is_ecoflow ) : ?>
			<p class="ecoflow-archive-desc"><?php esc_html_e( 'ポータブル電源・ソーラーパネル・防災・キャンプ関連の記事', 'gaming-hub' ); ?></p>
			<div class="ecoflow-official-links">
				<a href="<?php echo esc_url( gaming_hub_energy_url() ); ?>" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '発電ログ', 'gaming-hub' ); ?></a>
				<a href="https://jp.ecoflow.com/" target="_blank" rel="noopener noreferrer" class="btn btn-primary ecoflow-btn"><?php esc_html_e( 'EcoFlow 公式サイト', 'gaming-hub' ); ?></a>
				<a href="https://jp.ecoflow.com/pages/blog" target="_blank" rel="noopener noreferrer" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '公式ブログ', 'gaming-hub' ); ?></a>
			</div>
		<?php elseif ( $is_energy ) : ?>
			<p class="ecoflow-archive-desc"><?php esc_html_e( 'EcoFlow の実測ワットを積算した発電・入出力ログです。', 'gaming-hub' ); ?></p>
			<div class="ecoflow-official-links">
				<a href="<?php echo esc_url( gaming_hub_ecoflow_url() ); ?>" class="btn btn-primary ecoflow-btn"><?php esc_html_e( 'EcoFlow ダッシュボード', 'gaming-hub' ); ?></a>
			</div>
		<?php endif; ?>
	</div>
</div>

<?php if ( $is_ecoflow ) : ?>
	<div class="container ecoflow-dashboard-wrap">
		<?php gaming_hub_render_ecoflow_dashboard(); ?>
	</div>
<?php elseif ( $is_energy ) : ?>
	<div class="container ecoflow-dashboard-wrap">
		<?php gaming_hub_render_ecoflow_energy_page(); ?>
	</div>
<?php endif; ?>

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

	<?php elseif ( $is_ecoflow ) : ?>
		<div class="ecoflow-empty">
			<p><?php esc_html_e( 'EcoFlow タグの記事はまだありません。', 'gaming-hub' ); ?></p>
			<div class="ecoflow-links-grid">
				<a href="https://jp.ecoflow.com/products/delta-pro-3" target="_blank" rel="noopener noreferrer" class="ecoflow-link-card">
					<span class="ecoflow-link-icon">🔋</span>
					<h3><?php esc_html_e( 'ポータブル電源', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( 'DELTA シリーズなど製品情報', 'gaming-hub' ); ?></p>
				</a>
				<a href="https://jp.ecoflow.com/collections/solar-panels" target="_blank" rel="noopener noreferrer" class="ecoflow-link-card">
					<span class="ecoflow-link-icon">☀️</span>
					<h3><?php esc_html_e( 'ソーラーパネル', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( 'ソーラー充電ソリューション', 'gaming-hub' ); ?></p>
				</a>
				<a href="https://jp.ecoflow.com/pages/blog" target="_blank" rel="noopener noreferrer" class="ecoflow-link-card">
					<span class="ecoflow-link-icon">📝</span>
					<h3><?php esc_html_e( '公式ブログ', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( '活用方法・最新情報', 'gaming-hub' ); ?></p>
				</a>
			</div>
		</div>
	<?php elseif ( $is_energy ) : ?>
		<div class="ecoflow-empty">
			<p><?php esc_html_e( 'Energy タグの記事はまだありません。上のグラフが発電ログです。', 'gaming-hub' ); ?></p>
		</div>
	<?php else : ?>
		<div class="no-results">
			<h2><?php esc_html_e( 'Nothing Found', 'gaming-hub' ); ?></h2>
			<p><?php esc_html_e( 'No posts found with this tag.', 'gaming-hub' ); ?></p>
		</div>
	<?php endif; ?>
</div>

<?php
get_footer();
