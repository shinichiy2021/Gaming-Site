<?php
/**
 * Full Tesla hub section used on the tag page and hub SPA.
 *
 * @package Gaming_Hub
 */

if ( function_exists( 'gaming_hub_render_tesla_hub_dashboard_sections' ) ) {
	gaming_hub_render_tesla_hub_dashboard_sections();
	return;
}
?>
<div class="powerwall-page">
	<?php $energy_status = gaming_hub_get_powerwall_flow_status(); ?>
	<section class="section tesla-flow-section">
		<div class="container">
			<?php
			get_template_part(
				'template-parts/tesla',
				'flow',
				array(
					'status' => $energy_status,
				)
			);
			?>
		</div>
	</section>
	<section class="section tesla-kit-section">
		<div class="container">
			<?php get_template_part( 'template-parts/tesla', 'kit' ); ?>
		</div>
	</section>
</div>
