<?php
/**
 * Template Name: Pokémon GO 特集
 * Description: Index of large Pokémon GO event feature pages
 *
 * @package Gaming_Hub
 */

get_header();
?>

<section class="hub-section hub-pokemon-go pgo-tokushuu-section">
	<?php get_template_part( 'template-parts/pokemon-go', 'tokushuu' ); ?>
</section>

<?php
get_footer();
