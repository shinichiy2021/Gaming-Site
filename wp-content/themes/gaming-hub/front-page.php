<?php
/**
 * Front page fallback (normally redirected to EcoFlow or Tesla).
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
				<a href="#energy" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '発電ログ', 'gaming-hub' ); ?></a>
				<a href="https://jp.ecoflow.com/" target="_blank" rel="noopener noreferrer" class="btn btn-primary ecoflow-btn"><?php esc_html_e( 'EcoFlow 公式サイト', 'gaming-hub' ); ?></a>
				<a href="https://jp.ecoflow.com/pages/blog" target="_blank" rel="noopener noreferrer" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '公式ブログ', 'gaming-hub' ); ?></a>
			</div>
		</div>
		<div class="ecoflow-dashboard-wrap">
			<?php gaming_hub_render_ecoflow_dashboard(); ?>
		</div>
		<div id="energy" class="ecoflow-dashboard-wrap">
			<?php gaming_hub_render_ecoflow_energy_page(); ?>
		</div>
	</div>
</section>

<?php
get_footer();
