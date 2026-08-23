<?php
/**
 * Full Pokémon GO section used on the one-page hub.
 *
 * @package Gaming_Hub
 */
?>
<div class="pokemon-go-page">
	<section class="pgo-hero">
		<div class="pgo-hero-bg"></div>
		<div class="container pgo-hero-content">
			<span class="pgo-hero-badge">⚡ Pokémon GO</span>
			<h2 class="pgo-hero-title"><?php esc_html_e( 'Pokémon GO 最新情報', 'gaming-hub' ); ?></h2>
			<p class="pgo-hero-desc">
				<?php esc_html_e( 'イベント、Community Day、レイド、新ポケモンなど最新ニュースをチェック', 'gaming-hub' ); ?>
			</p>
			<div class="pgo-hero-links">
				<?php
				$raid_open = function_exists( 'gaming_hub_pgo_raid_open_count' ) ? gaming_hub_pgo_raid_open_count() : 0;
				if ( function_exists( 'gaming_hub_pgo_raid_url' ) ) :
					?>
					<a href="<?php echo esc_url( gaming_hub_pgo_raid_url() ); ?>" class="btn btn-primary">
						<?php esc_html_e( 'レイド招待', 'gaming-hub' ); ?>
						<?php if ( $raid_open ) : ?>
							<span class="pgo-raid-hero-count"><?php echo esc_html( (string) $raid_open ); ?></span>
						<?php endif; ?>
					</a>
				<?php endif; ?>
				<?php if ( function_exists( 'gaming_hub_pgo_hub_events' ) && gaming_hub_pgo_hub_events() ) : ?>
					<a href="<?php echo esc_url( gaming_hub_pgo_tokushuu_url() ); ?>" class="btn btn-primary">
						<?php esc_html_e( '大型イベント特集', 'gaming-hub' ); ?>
					</a>
				<?php endif; ?>
				<a href="https://pokemongolive.com/ja/news/" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
					<?php esc_html_e( '公式ニュース', 'gaming-hub' ); ?>
				</a>
				<a href="https://pokemongolive.com/ja/events/" target="_blank" rel="noopener noreferrer" class="btn btn-outline">
					<?php esc_html_e( 'イベント一覧', 'gaming-hub' ); ?>
				</a>
			</div>
		</div>
	</section>

	<?php get_template_part( 'template-parts/pokemon-go', 'tokushuu-banner' ); ?>

	<section class="section pgo-news-section">
		<div class="container">
			<?php gaming_hub_render_pokemon_go_news( 15, true ); ?>
		</div>
	</section>

	<section id="youtube" class="section pgo-youtube-page-section">
		<div class="container">
			<?php gaming_hub_render_pokemon_go_youtube( 12, true ); ?>
		</div>
	</section>

	<section class="section pgo-quick-links">
		<div class="container">
			<h2 class="section-title"><?php esc_html_e( '便利リンク', 'gaming-hub' ); ?></h2>
			<div class="pgo-links-grid">
				<?php if ( function_exists( 'gaming_hub_pgo_raid_url' ) ) : ?>
					<a href="<?php echo esc_url( gaming_hub_pgo_raid_url() ); ?>" class="pgo-link-card">
						<span class="pgo-link-icon">📣</span>
						<h3><?php esc_html_e( 'レイド招待掲示板', 'gaming-hub' ); ?></h3>
						<p>
							<?php
							$count = function_exists( 'gaming_hub_pgo_raid_open_count' ) ? gaming_hub_pgo_raid_open_count() : 0;
							echo esc_html( $count ? sprintf( __( '募集中 %s 件', 'gaming-hub' ), (string) $count ) : __( 'トレーナー同士の招待募集', 'gaming-hub' ) );
							?>
						</p>
					</a>
				<?php endif; ?>
				<a href="https://pokemongolive.com/ja/post/community-day/" target="_blank" rel="noopener noreferrer" class="pgo-link-card">
					<span class="pgo-link-icon">📅</span>
					<h3><?php esc_html_e( 'Community Day', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( '次回開催日とボーナス内容', 'gaming-hub' ); ?></p>
				</a>
				<a href="https://pokemongolive.com/ja/post/spotlight-hour/" target="_blank" rel="noopener noreferrer" class="pgo-link-card">
					<span class="pgo-link-icon">✨</span>
					<h3><?php esc_html_e( 'スポライ', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( 'スポライトアワーのスケジュール', 'gaming-hub' ); ?></p>
				</a>
				<a href="<?php echo esc_url( gaming_hub_pgo_tokushuu_url() ); ?>" class="pgo-link-card">
					<span class="pgo-link-icon">🏆</span>
					<h3><?php esc_html_e( '大型イベント特集', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( '世界大会・ウルトラアンロック・レイドデイ', 'gaming-hub' ); ?></p>
				</a>
				<a href="https://pokemongohub.net/post/category/events/" target="_blank" rel="noopener noreferrer" class="pgo-link-card">
					<span class="pgo-link-icon">🎉</span>
					<h3><?php esc_html_e( 'イベント攻略', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( 'イベントの攻略ガイド', 'gaming-hub' ); ?></p>
				</a>
				<a href="https://pokemongohub.net/post/category/guides/" target="_blank" rel="noopener noreferrer" class="pgo-link-card">
					<span class="pgo-link-icon">📖</span>
					<h3><?php esc_html_e( 'レイド攻略', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( 'レイドボス別おすすめポケモン', 'gaming-hub' ); ?></p>
				</a>
			</div>
		</div>
	</section>
</div>
