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
				<a href="#kit" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '実測構成', 'gaming-hub' ); ?></a>
			</div>
		</div>
		<div class="ecoflow-dashboard-wrap">
			<?php gaming_hub_render_ecoflow_dashboard(); ?>
		</div>
		<div id="energy" class="ecoflow-dashboard-wrap">
			<?php gaming_hub_render_ecoflow_energy_page(); ?>
		</div>
		<div class="ecoflow-dashboard-wrap">
			<?php get_template_part( 'template-parts/ecoflow', 'kit' ); ?>
		</div>
	</div>
</section>

<?php
get_footer();
