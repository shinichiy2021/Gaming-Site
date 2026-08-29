<?php
/**
 * Single post template
 *
 * @package Gaming_Hub
 */

get_header();

	while ( have_posts() ) :
	the_post();
	$meta       = gaming_hub_get_game_meta();
	$is_ecoflow = function_exists( 'gaming_hub_has_ecoflow_tag' ) && gaming_hub_has_ecoflow_tag();
	$is_tesla   = has_tag( 'tesla' );
	?>

	<article id="post-<?php the_ID(); ?>" <?php post_class( array( 'single-post', $is_ecoflow ? 'single-post-ecoflow' : '', $is_tesla ? 'single-post-tesla' : '' ) ); ?>>
		<?php if ( has_post_thumbnail() ) : ?>
			<div class="post-hero">
				<?php the_post_thumbnail( 'hero-banner' ); ?>
				<div class="post-hero-overlay">
					<div class="container">
						<?php if ( $is_ecoflow ) : ?>
							<?php gaming_hub_render_ecoflow_tag_badge(); ?>
						<?php elseif ( $is_tesla ) : ?>
							<a href="<?php echo esc_url( function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ) ); ?>" class="ecoflow-tag-badge tesla-tag-badge">Tesla</a>
						<?php else : ?>
							<?php the_category( ', ' ); ?>
						<?php endif; ?>
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
					<?php if ( $is_ecoflow ) : ?>
						<?php gaming_hub_render_ecoflow_tag_badge(); ?>
					<?php elseif ( $is_tesla ) : ?>
						<a href="<?php echo esc_url( function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ) ); ?>" class="ecoflow-tag-badge tesla-tag-badge">Tesla</a>
					<?php else : ?>
						<?php the_category( ', ' ); ?>
					<?php endif; ?>
					<h1 class="post-title"><?php the_title(); ?></h1>
					<div class="post-meta">
						<span class="post-date"><?php echo esc_html( get_the_date() ); ?></span>
						<span class="post-author"><?php the_author(); ?></span>
					</div>
					<?php if ( $is_ecoflow ) : ?>
						<p class="post-ecoflow-links">
							<a href="<?php echo esc_url( gaming_hub_ecoflow_url() ); ?>"><?php esc_html_e( 'EcoFlow ダッシュボード', 'gaming-hub' ); ?></a>
							<span aria-hidden="true">·</span>
							<a href="<?php echo esc_url( gaming_hub_ecoflow_url() ); ?>#energy"><?php esc_html_e( '発電ログ', 'gaming-hub' ); ?></a>
							<span aria-hidden="true">·</span>
							<a href="<?php echo esc_url( gaming_hub_ecoflow_url() ); ?>#kit"><?php esc_html_e( '実測構成', 'gaming-hub' ); ?></a>
						</p>
					<?php elseif ( $is_tesla ) : ?>
						<p class="post-ecoflow-links post-tesla-links">
							<a href="<?php echo esc_url( function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ) ); ?>"><?php esc_html_e( 'Tesla ダッシュボード', 'gaming-hub' ); ?></a>
							<span aria-hidden="true">·</span>
							<a href="<?php echo esc_url( ( function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ) ) . '#drive' ); ?>"><?php esc_html_e( 'Driving Log', 'gaming-hub' ); ?></a>
							<span aria-hidden="true">·</span>
							<a href="<?php echo esc_url( ( function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ) ) . '#tesla-kit' ); ?>"><?php esc_html_e( '実測構成', 'gaming-hub' ); ?></a>
						</p>
					<?php endif; ?>
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
				the_post_navigation(
					array(
						'prev_text' => '<span class="nav-label">' . esc_html__( 'Previous', 'gaming-hub' ) . '</span><span class="nav-title">%title</span>',
						'next_text' => '<span class="nav-label">' . esc_html__( 'Next', 'gaming-hub' ) . '</span><span class="nav-title">%title</span>',
					)
				);
				?>
			</nav>
		</div>
	</article>

	<?php
endwhile;

get_footer();
