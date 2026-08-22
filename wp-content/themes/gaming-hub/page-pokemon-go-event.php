<?php
/**
 * Template Name: Pokémon GO イベント特集
 * Description: Special feature page for a large Pokémon GO event
 *
 * @package Gaming_Hub
 */

get_header();

$slug  = (string) get_post_meta( get_the_ID(), '_pgo_event_slug', true );
if ( '' === $slug ) {
	$slug = (string) get_post_field( 'post_name', get_the_ID() );
}
$event = function_exists( 'gaming_hub_pgo_event' ) ? gaming_hub_pgo_event( $slug ) : null;
?>

<section class="hub-section hub-pokemon-go pgo-tokushuu-section">
	<?php
	if ( $event ) {
		get_template_part( 'template-parts/pokemon-go', 'event', array( 'event' => $event ) );
	} else {
		get_template_part( 'template-parts/pokemon-go', 'tokushuu' );
	}
	?>
</section>

<?php
get_footer();
