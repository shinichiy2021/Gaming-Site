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
				__( 'ライブ', 'gaming-hub' ),
				__( '電力フロー図・AI PLAN・機器ステータス', 'gaming-hub' )
			);
			gaming_hub_render_ecoflow_dashboard();
			?>
		</div>
	</section>
	<section id="energy" class="ecoflow-hub-section">
		<div class="container ecoflow-dashboard-wrap">
			<?php
			gaming_hub_render_ecoflow_section_head(
				__( '発電ログ', 'gaming-hub' ),
				__( '日別・時間別の発電量と節約額', 'gaming-hub' )
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
