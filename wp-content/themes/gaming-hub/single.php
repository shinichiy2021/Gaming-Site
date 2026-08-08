<?php
/**
 * Single post template
 *
 * @package Gaming_Hub
 */

get_header();

while ( have_posts() ) :
	the_post();
	$meta = gaming_hub_get_game_meta();
	?>

	<article id="post-<?php the_ID(); ?>" <?php post_class( 'single-post' ); ?>>
		<?php if ( has_post_thumbnail() ) : ?>
			<div class="post-hero">
				<?php the_post_thumbnail( 'hero-banner' ); ?>
				<div class="post-hero-overlay">
					<div class="container">
						<?php the_category( ', ' ); ?>
						<h1 class="post-title"><?php the_title(); ?></h1>
						<div class="post-meta">
							<span class="post-date"><?php echo esc_html( get_the_date() ); ?></span>
							<span class="post-author"><?php the_author(); ?></span>
							<?php if ( $meta['platform'] ) : ?>
								<span class="post-platform"><?php echo esc_html( $meta['platform'] ); ?></span>
							<?php endif; ?>
							<?php if ( $meta['rating'] ) : ?>
								<span class="post-rating"><?php echo gaming_hub_get_rating_stars( $meta['rating'] ); ?> <?php echo esc_html( $meta['rating'] ); ?>/5</span>
							<?php endif; ?>
						</div>
					</div>
				</div>
			</div>
		<?php else : ?>
			<div class="container">
				<header class="post-header">
					<?php the_category( ', ' ); ?>
					<h1 class="post-title"><?php the_title(); ?></h1>
					<div class="post-meta">
						<span class="post-date"><?php echo esc_html( get_the_date() ); ?></span>
						<span class="post-author"><?php the_author(); ?></span>
					</div>
				</header>
			</div>
		<?php endif; ?>

		<div class="container post-content">
			<div class="content-wrapper">
				<?php the_content(); ?>
			</div>

			<?php if ( $meta['genre'] ) : ?>
				<div class="game-info-box">
					<h3><?php esc_html_e( 'Game Info', 'gaming-hub' ); ?></h3>
					<dl>
						<?php if ( $meta['platform'] ) : ?>
							<dt><?php esc_html_e( 'Platform', 'gaming-hub' ); ?></dt>
							<dd><?php echo esc_html( $meta['platform'] ); ?></dd>
						<?php endif; ?>
						<?php if ( $meta['genre'] ) : ?>
							<dt><?php esc_html_e( 'Genre', 'gaming-hub' ); ?></dt>
							<dd><?php echo esc_html( $meta['genre'] ); ?></dd>
						<?php endif; ?>
						<?php if ( $meta['rating'] ) : ?>
							<dt><?php esc_html_e( 'Rating', 'gaming-hub' ); ?></dt>
							<dd><?php echo gaming_hub_get_rating_stars( $meta['rating'] ); ?> <?php echo esc_html( $meta['rating'] ); ?>/5</dd>
						<?php endif; ?>
					</dl>
				</div>
			<?php endif; ?>

			<nav class="post-navigation">
				<?php
				the_post_navigation( array(
					'prev_text' => '<span class="nav-label">' . esc_html__( 'Previous', 'gaming-hub' ) . '</span><span class="nav-title">%title</span>',
					'next_text' => '<span class="nav-label">' . esc_html__( 'Next', 'gaming-hub' ) . '</span><span class="nav-title">%title</span>',
				) );
				?>
			</nav>
		</div>
	</article>

	<?php
endwhile;

get_footer();
