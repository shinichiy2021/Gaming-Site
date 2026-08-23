<?php
/**
 * Full Powerwall section used on the one-page hub.
 *
 * @package Gaming_Hub
 */
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
</div>
