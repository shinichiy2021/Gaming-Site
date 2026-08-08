<?php
/**
 * Page template
 *
 * @package Gaming_Hub
 */

get_header();

while ( have_posts() ) :
	the_post();
	?>

	<article id="post-<?php the_ID(); ?>" <?php post_class( 'page-content' ); ?>>
		<div class="container">
			<header class="page-header">
				<h1 class="page-title"><?php the_title(); ?></h1>
			</header>

			<div class="content-wrapper">
				<?php the_content(); ?>
			</div>
		</div>
	</article>

	<?php
endwhile;

get_footer();
