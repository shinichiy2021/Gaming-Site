<?php
/**
 * Front page template
 *
 * @package Gaming_Hub
 */

get_header();
?>

<section id="pokemon-go" class="section pokemon-go-section pokemon-go-section-top">
	<div class="container">
		<div class="pgo-home-header">
			<div>
				<span class="pgo-section-badge">⚡ Pokémon GO</span>
				<h2 class="section-title"><?php esc_html_e( 'Pokémon GO 最新情報', 'gaming-hub' ); ?></h2>
				<p class="section-desc"><?php esc_html_e( 'イベント・レイド・新ポケモンの最新ニュース', 'gaming-hub' ); ?></p>
			</div>
			<a href="<?php echo esc_url( gaming_hub_pokemon_go_url() ); ?>" class="btn btn-outline pgo-view-all">
				<?php esc_html_e( 'すべて見る', 'gaming-hub' ); ?> →
			</a>
		</div>

		<div class="pgo-home-grid">
			<?php
			$pgo_news = gaming_hub_get_pokemon_go_news( 4 );
			if ( ! empty( $pgo_news ) ) :
				foreach ( $pgo_news as $item ) :
					get_template_part( 'template-parts/pokemon-go', 'card', array( 'item' => $item ) );
				endforeach;
			else :
				?>
				<div class="pgo-home-empty">
					<p><?php esc_html_e( '最新情報を読み込み中...', 'gaming-hub' ); ?></p>
					<a href="<?php echo esc_url( gaming_hub_pokemon_go_url() ); ?>" class="btn btn-primary">
						<?php esc_html_e( 'Pokémon GO ページへ', 'gaming-hub' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>

		<div class="pgo-home-youtube">
			<div class="pgo-home-yt-header">
				<h3><?php esc_html_e( 'YouTuber 最新動画', 'gaming-hub' ); ?></h3>
				<a href="<?php echo esc_url( gaming_hub_pokemon_go_url() . '#youtube' ); ?>"><?php esc_html_e( 'もっと見る', 'gaming-hub' ); ?> →</a>
			</div>
			<div class="pgo-yt-grid pgo-yt-grid-compact">
				<?php
				$yt_videos = gaming_hub_get_pokemon_go_youtube_videos( 3 );
				foreach ( $yt_videos as $video ) :
					?>
					<article class="pgo-yt-card">
						<a href="<?php echo esc_url( $video['link'] ); ?>" target="_blank" rel="noopener noreferrer" class="pgo-yt-link">
							<div class="pgo-yt-thumb">
								<?php if ( ! empty( $video['image'] ) ) : ?>
									<img src="<?php echo esc_url( $video['image'] ); ?>" alt="<?php echo esc_attr( $video['title'] ); ?>" loading="lazy" />
								<?php else : ?>
									<div class="pgo-yt-thumb-placeholder">▶</div>
								<?php endif; ?>
								<span class="pgo-yt-play" aria-hidden="true">▶</span>
							</div>
							<div class="pgo-yt-body">
								<span class="pgo-yt-channel"><?php echo esc_html( $video['channel'] ); ?></span>
								<h3 class="pgo-yt-title"><?php echo esc_html( $video['title'] ); ?></h3>
							</div>
						</a>
					</article>
				<?php endforeach; ?>
			</div>
		</div>
	</div>
</section>

<section id="looop" class="section looop-home-section">
	<div class="container">
		<div class="looop-home-header">
			<div>
				<span class="looop-section-badge">⚡ LOOOP</span>
				<h2 class="section-title"><?php esc_html_e( '中部エリア でんき予報', 'gaming-hub' ); ?></h2>
				<p class="section-desc"><?php esc_html_e( '時間別の電気代単価。安い時間帯に家事・充電をシフト', 'gaming-hub' ); ?></p>
			</div>
			<a href="<?php echo esc_url( gaming_hub_looop_url() ); ?>" class="btn btn-outline looop-home-view-all">
				<?php esc_html_e( '詳細を見る', 'gaming-hub' ); ?> →
			</a>
		</div>
		<?php gaming_hub_render_looop_home(); ?>
	</div>
</section>

<section id="powerwall" class="section powerwall-home-section">
	<div class="container">
		<div class="pw-home-top">
			<div class="pw-home-header">
				<div>
					<span class="pw-section-badge">🔋 Tesla Powerwall 3</span>
					<h2 class="section-title"><?php esc_html_e( 'Powerwall 3 最新情報', 'gaming-hub' ); ?></h2>
					<p class="section-desc"><?php esc_html_e( '家庭用蓄電池のニュース・仕様・公式リンク', 'gaming-hub' ); ?></p>
				</div>
				<a href="<?php echo esc_url( gaming_hub_powerwall_url() ); ?>" class="btn btn-outline pw-view-all">
					<?php esc_html_e( 'すべて見る', 'gaming-hub' ); ?> →
				</a>
			</div>
			<div class="pw-home-visual">
				<img
					src="<?php echo esc_url( gaming_hub_powerwall_product_image_url() ); ?>"
					alt="<?php esc_attr_e( 'Tesla Powerwall 3', 'gaming-hub' ); ?>"
					class="pw-home-product"
					width="480"
					height="560"
					loading="lazy"
				/>
			</div>
		</div>

		<div class="pw-home-grid">
			<?php
			$pw_news = gaming_hub_get_powerwall_news( 4 );
			if ( ! empty( $pw_news ) ) :
				foreach ( $pw_news as $item ) :
					get_template_part( 'template-parts/powerwall', 'card', array( 'item' => $item ) );
				endforeach;
			else :
				?>
				<div class="pw-home-empty">
					<p><?php esc_html_e( '最新情報を読み込み中...', 'gaming-hub' ); ?></p>
					<a href="<?php echo esc_url( gaming_hub_powerwall_url() ); ?>" class="btn btn-primary">
						<?php esc_html_e( 'Powerwall ページへ', 'gaming-hub' ); ?>
					</a>
				</div>
			<?php endif; ?>
		</div>
	</div>
</section>

<section class="hero">
	<div class="hero-bg"></div>
	<div class="container hero-content">
		<h1 class="hero-title"><?php echo esc_html( get_theme_mod( 'hero_title', __( 'Level Up Your Gaming Experience', 'gaming-hub' ) ) ); ?></h1>
		<p class="hero-subtitle"><?php echo esc_html( get_theme_mod( 'hero_subtitle', __( 'Latest reviews, news, and guides for gamers', 'gaming-hub' ) ) ); ?></p>
		<a href="<?php echo esc_url( get_theme_mod( 'hero_cta_url', '#reviews' ) ); ?>" class="btn btn-primary">
			<?php echo esc_html( get_theme_mod( 'hero_cta_text', __( 'Explore Reviews', 'gaming-hub' ) ) ); ?>
		</a>
	</div>
</section>

<section id="reviews" class="section featured-games">
	<div class="container">
		<div class="section-header">
			<h2 class="section-title"><?php esc_html_e( 'Featured Reviews', 'gaming-hub' ); ?></h2>
			<p class="section-desc"><?php esc_html_e( 'Our latest game reviews and ratings', 'gaming-hub' ); ?></p>
		</div>

		<div class="game-grid">
			<?php
			$reviews = new WP_Query( array(
				'posts_per_page' => 6,
				'category_name'  => 'reviews',
			) );

			if ( $reviews->have_posts() ) :
				while ( $reviews->have_posts() ) :
					$reviews->the_post();
					$meta = gaming_hub_get_game_meta();
					?>
					<article class="game-card">
						<a href="<?php the_permalink(); ?>" class="game-card-link">
							<div class="game-card-image">
								<?php if ( has_post_thumbnail() ) : ?>
									<?php the_post_thumbnail( 'game-card' ); ?>
								<?php else : ?>
									<div class="placeholder-image"></div>
								<?php endif; ?>
								<?php if ( $meta['rating'] ) : ?>
									<span class="game-rating"><?php echo esc_html( $meta['rating'] ); ?>/5</span>
								<?php endif; ?>
							</div>
							<div class="game-card-body">
								<?php if ( $meta['platform'] ) : ?>
									<span class="game-platform"><?php echo esc_html( $meta['platform'] ); ?></span>
								<?php endif; ?>
								<h3 class="game-card-title"><?php the_title(); ?></h3>
								<p class="game-card-excerpt"><?php echo esc_html( get_the_excerpt() ); ?></p>
							</div>
						</a>
					</article>
					<?php
				endwhile;
				wp_reset_postdata();
			else :
				gaming_hub_demo_cards();
			endif;
			?>
		</div>
	</div>
</section>

<section class="section latest-news">
	<div class="container">
		<div class="section-header">
			<h2 class="section-title"><?php esc_html_e( 'Latest News', 'gaming-hub' ); ?></h2>
			<p class="section-desc"><?php esc_html_e( 'Stay up to date with gaming industry news', 'gaming-hub' ); ?></p>
		</div>

		<div class="news-grid">
			<?php
			$news = new WP_Query( array(
				'posts_per_page' => 4,
				'category_name'  => 'news',
			) );

			if ( $news->have_posts() ) :
				while ( $news->have_posts() ) :
					$news->the_post();
					?>
					<article class="news-card">
						<a href="<?php the_permalink(); ?>">
							<div class="news-card-image">
								<?php if ( has_post_thumbnail() ) : ?>
									<?php the_post_thumbnail( 'medium' ); ?>
								<?php else : ?>
									<div class="placeholder-image"></div>
								<?php endif; ?>
							</div>
							<div class="news-card-body">
								<time datetime="<?php echo esc_attr( get_the_date( 'c' ) ); ?>"><?php echo esc_html( get_the_date() ); ?></time>
								<h3><?php the_title(); ?></h3>
								<p><?php echo esc_html( get_the_excerpt() ); ?></p>
							</div>
						</a>
					</article>
					<?php
				endwhile;
				wp_reset_postdata();
			else :
				gaming_hub_demo_news();
			endif;
			?>
		</div>
	</div>
</section>

<section class="section categories-cta">
	<div class="container">
		<div class="cta-grid">
			<a href="<?php echo esc_url( home_url( '/category/reviews/' ) ); ?>" class="cta-card cta-reviews">
				<span class="cta-icon">⭐</span>
				<h3><?php esc_html_e( 'Game Reviews', 'gaming-hub' ); ?></h3>
				<p><?php esc_html_e( 'In-depth reviews with honest ratings', 'gaming-hub' ); ?></p>
			</a>
			<a href="<?php echo esc_url( home_url( '/category/guides/' ) ); ?>" class="cta-card cta-guides">
				<span class="cta-icon">📖</span>
				<h3><?php esc_html_e( 'Guides & Tips', 'gaming-hub' ); ?></h3>
				<p><?php esc_html_e( 'Walkthroughs and strategy guides', 'gaming-hub' ); ?></p>
			</a>
			<a href="<?php echo esc_url( gaming_hub_pokemon_go_url() ); ?>" class="cta-card cta-pokemon-go">
				<span class="cta-icon">⚡</span>
				<h3><?php esc_html_e( 'Pokémon GO', 'gaming-hub' ); ?></h3>
				<p><?php esc_html_e( '最新イベント・レイド情報', 'gaming-hub' ); ?></p>
			</a>
			<a href="<?php echo esc_url( home_url( '/category/news/' ) ); ?>" class="cta-card cta-news">
				<span class="cta-icon">📰</span>
				<h3><?php esc_html_e( 'Gaming News', 'gaming-hub' ); ?></h3>
				<p><?php esc_html_e( 'Latest announcements and updates', 'gaming-hub' ); ?></p>
			</a>
			<a href="<?php echo esc_url( gaming_hub_ecoflow_url() ); ?>" class="cta-card cta-ecoflow">
				<span class="cta-icon">🔋</span>
				<h3>EcoFlow</h3>
				<p><?php esc_html_e( 'ポータブル電源・ソーラー・防災', 'gaming-hub' ); ?></p>
			</a>
			<a href="<?php echo esc_url( gaming_hub_looop_url() ); ?>" class="cta-card cta-looop">
				<span class="cta-icon">⚡</span>
				<h3>LOOOP</h3>
				<p><?php esc_html_e( '中部エリアの時間別でんき予報', 'gaming-hub' ); ?></p>
			</a>
			<a href="<?php echo esc_url( gaming_hub_powerwall_url() ); ?>" class="cta-card cta-powerwall">
				<span class="cta-icon">🔋</span>
				<h3>Powerwall 3</h3>
				<p><?php esc_html_e( 'Tesla 家庭用蓄電池の最新情報', 'gaming-hub' ); ?></p>
			</a>
		</div>
	</div>
</section>

<?php
get_footer();

function gaming_hub_demo_cards() {
	$demos = array(
		array( 'title' => 'Elden Ring: Shadow of the Erdtree', 'platform' => 'PS5 / PC', 'rating' => '4.8', 'excerpt' => 'An epic expansion that pushes the boundaries of open-world design.' ),
		array( 'title' => 'Final Fantasy VII Rebirth', 'platform' => 'PS5', 'rating' => '4.7', 'excerpt' => 'A stunning continuation of Cloud\'s journey across the world.' ),
		array( 'title' => 'Hades II', 'platform' => 'PC / Switch', 'rating' => '4.9', 'excerpt' => 'Supergiant returns with another masterpiece roguelike.' ),
		array( 'title' => 'Black Myth: Wukong', 'platform' => 'PS5 / PC', 'rating' => '4.5', 'excerpt' => 'A visually stunning action RPG rooted in Chinese mythology.' ),
		array( 'title' => 'Astro Bot', 'platform' => 'PS5', 'rating' => '4.9', 'excerpt' => 'Pure platforming joy with creative level design.' ),
		array( 'title' => 'Metaphor: ReFantazio', 'platform' => 'Multi', 'rating' => '4.6', 'excerpt' => 'Atlus delivers a fresh take on the JRPG formula.' ),
	);

	foreach ( $demos as $demo ) {
		?>
		<article class="game-card">
			<div class="game-card-link">
				<div class="game-card-image">
					<div class="placeholder-image"></div>
					<span class="game-rating"><?php echo esc_html( $demo['rating'] ); ?>/5</span>
				</div>
				<div class="game-card-body">
					<span class="game-platform"><?php echo esc_html( $demo['platform'] ); ?></span>
					<h3 class="game-card-title"><?php echo esc_html( $demo['title'] ); ?></h3>
					<p class="game-card-excerpt"><?php echo esc_html( $demo['excerpt'] ); ?></p>
				</div>
			</div>
		</article>
		<?php
	}
}

function gaming_hub_demo_news() {
	$demos = array(
		array( 'title' => 'Nintendo Direct Announced for September', 'date' => 'Aug 1, 2026', 'excerpt' => 'New games and hardware updates expected at the upcoming event.' ),
		array( 'title' => 'PlayStation State of Play Recap', 'date' => 'Jul 28, 2026', 'excerpt' => 'All the biggest reveals from Sony\'s latest showcase.' ),
		array( 'title' => 'Steam Summer Sale Ends This Week', 'date' => 'Jul 25, 2026', 'excerpt' => 'Don\'t miss these last-minute deals on top titles.' ),
		array( 'title' => 'Indie Game Spotlight: Rising Stars', 'date' => 'Jul 20, 2026', 'excerpt' => 'Five indie games you should keep on your radar.' ),
	);

	foreach ( $demos as $demo ) {
		?>
		<article class="news-card">
			<div>
				<div class="news-card-image">
					<div class="placeholder-image"></div>
				</div>
				<div class="news-card-body">
					<time><?php echo esc_html( $demo['date'] ); ?></time>
					<h3><?php echo esc_html( $demo['title'] ); ?></h3>
					<p><?php echo esc_html( $demo['excerpt'] ); ?></p>
				</div>
			</div>
		</article>
		<?php
	}
}
