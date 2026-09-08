<?php
/**
 * Front page fallback (normally redirected to EcoFlow or Tesla).
 *
 * @package Gaming_Hub
 */

get_header();
?>

<section id="ecoflow" class="hub-section hub-ecoflow">
	<?php gaming_hub_render_ecoflow_hub_intro(); ?>
	<section id="ecoflow-live" class="ecoflow-hub-section">
		<div class="container ecoflow-dashboard-wrap">
			<?php
			gaming_hub_render_ecoflow_section_head(
				__('Live', 'gaming-hub'),
				__('Energy flow diagram, AI PLAN, and device status', 'gaming-hub')
			);
			gaming_hub_render_ecoflow_dashboard();
			?>
		</div>
	</section>
	<section id="energy" class="ecoflow-hub-section">
		<div class="container ecoflow-dashboard-wrap">
			<?php
			gaming_hub_render_ecoflow_section_head(
				__('Generation log', 'gaming-hub'),
				__('Daily and hourly generation with savings', 'gaming-hub')
			);
			gaming_hub_render_ecoflow_energy_page();
			?>
		</div>
	</section>
	<section class="ecoflow-hub-section">
		<div class="container ecoflow-dashboard-wrap">
			<?php get_template_part( 'template-parts/ecoflow', 'kit' ); ?>
		</div>
	</section>
</section>

<?php
get_footer();
