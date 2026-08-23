<?php
/**
 * Template Name: Pokémon GO レイド招待
 * Description: Trainer-to-trainer raid invite board
 *
 * @package Gaming_Hub
 */

get_header();
?>

<section class="hub-section hub-pokemon-go pgo-raid-section">
	<?php get_template_part( 'template-parts/pokemon-go', 'raids' ); ?>
</section>

<?php
get_footer();
