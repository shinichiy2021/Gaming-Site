<?php
/**
 * Template Name: Pokémon GO
 * Description: Latest Pokémon GO news and updates page
 *
 * @package Gaming_Hub
 */

get_header();
?>

<section id="pokemon-go" class="hub-section hub-pokemon-go">
	<?php get_template_part( 'template-parts/pokemon-go', 'page' ); ?>
</section>

<?php
get_footer();
