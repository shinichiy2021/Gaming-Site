<?php
/**
 * Front page — one-page hub (EcoFlow first).
 *
 * @package Gaming_Hub
 */

get_header();
?>

<section id="ecoflow" class="hub-section hub-ecoflow">
	<div class="container">
		<div class="hub-section-intro">
			<span class="ecoflow-tag-badge ecoflow-tag-badge-lg">EcoFlow</span>
			<div class="ecoflow-official-links">
				<a href="<?php echo esc_url( gaming_hub_energy_url() ); ?>" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '発電ログ', 'gaming-hub' ); ?></a>
				<a href="https://jp.ecoflow.com/" target="_blank" rel="noopener noreferrer" class="btn btn-primary ecoflow-btn"><?php esc_html_e( 'EcoFlow 公式サイト', 'gaming-hub' ); ?></a>
				<a href="https://jp.ecoflow.com/pages/blog" target="_blank" rel="noopener noreferrer" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '公式ブログ', 'gaming-hub' ); ?></a>
			</div>
		</div>
		<div class="ecoflow-dashboard-wrap">
			<?php gaming_hub_render_ecoflow_dashboard(); ?>
		</div>
	</div>
</section>

<section id="energy" class="hub-section hub-energy">
	<div class="container">
		<div class="hub-section-intro">
			<span class="ecoflow-tag-badge ecoflow-tag-badge-lg">Energy</span>
			<p class="ecoflow-archive-desc"><?php esc_html_e( 'EcoFlow の実測ワットを積算した発電・入出力ログです。', 'gaming-hub' ); ?></p>
			<div class="ecoflow-official-links">
				<a href="<?php echo esc_url( gaming_hub_ecoflow_url() ); ?>" class="btn btn-primary ecoflow-btn"><?php esc_html_e( 'EcoFlow ダッシュボード', 'gaming-hub' ); ?></a>
			</div>
		</div>
		<div class="ecoflow-dashboard-wrap">
			<?php gaming_hub_render_ecoflow_energy_page(); ?>
		</div>
	</div>
</section>

<section id="powerwall" class="hub-section hub-powerwall">
	<?php get_template_part( 'template-parts/powerwall', 'page' ); ?>
</section>

<section id="pokemon-go" class="hub-section hub-pokemon-go">
	<?php get_template_part( 'template-parts/pokemon-go', 'page' ); ?>
</section>

<?php
get_footer();
