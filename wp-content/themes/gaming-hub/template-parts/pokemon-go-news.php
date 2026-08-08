<?php
/**
 * Pokémon GO news list partial
 *
 * @package Gaming_Hub
 *
 * @var array<int, array<string, mixed>> $news
 * @var bool                              $show_header
 * @var int                               $limit
 */

$news        = isset( $args['news'] ) ? $args['news'] : array();
$show_header = isset( $args['show_header'] ) ? (bool) $args['show_header'] : true;
$limit       = isset( $args['limit'] ) ? (int) $args['limit'] : 10;
?>

<section class="pokemon-go-news" aria-label="<?php esc_attr_e( 'Pokémon GO Latest News', 'gaming-hub' ); ?>">
	<?php if ( $show_header ) : ?>
		<div class="section-header">
			<div class="pgo-section-badge">⚡ Pokémon GO</div>
			<h2 class="section-title"><?php esc_html_e( '最新情報', 'gaming-hub' ); ?></h2>
			<p class="section-desc"><?php esc_html_e( 'イベント・レイド・攻略など Pokémon GO の最新ニュース', 'gaming-hub' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $news ) ) : ?>
		<div class="pgo-news-list">
			<?php foreach ( $news as $item ) : ?>
				<article class="pgo-news-item">
					<a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener noreferrer" class="pgo-news-link">
						<?php gaming_hub_render_pokemon_go_image( $item, 'pgo-news-image' ); ?>
						<div class="pgo-news-content">
							<div class="pgo-news-meta">
							<?php if ( ! empty( $item['categories'] ) ) : ?>
								<?php foreach ( array_slice( $item['categories'], 0, 2 ) as $category ) : ?>
									<span class="pgo-badge <?php echo esc_attr( gaming_hub_pokemon_go_category_class( $category ) ); ?>">
										<?php echo esc_html( $category ); ?>
									</span>
								<?php endforeach; ?>
							<?php endif; ?>
							<?php if ( ! empty( $item['date_display'] ) ) : ?>
								<time datetime="<?php echo esc_attr( $item['date'] ); ?>"><?php echo esc_html( $item['date_display'] ); ?></time>
							<?php endif; ?>
						</div>
						<h3 class="pgo-news-title"><?php echo esc_html( $item['title'] ); ?></h3>
						<?php if ( ! empty( $item['excerpt'] ) ) : ?>
							<p class="pgo-news-excerpt"><?php echo esc_html( $item['excerpt'] ); ?></p>
						<?php endif; ?>
						<span class="pgo-read-more"><?php esc_html_e( '詳しく見る', 'gaming-hub' ); ?> →</span>
						</div>
					</a>
				</article>
			<?php endforeach; ?>
		</div>

		<div class="pgo-sources">
			<p class="pgo-source-note">
				<?php esc_html_e( '情報源:', 'gaming-hub' ); ?>
				<a href="https://pokemongohub.net/" target="_blank" rel="noopener noreferrer">Pokémon GO Hub</a>
				|
				<a href="https://pokemongolive.com/ja/news/" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '公式サイト（日本語）', 'gaming-hub' ); ?></a>
			</p>
			<p class="pgo-updated-note">
				<?php esc_html_e( '30分ごとに自動更新', 'gaming-hub' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="pgo-error">
			<p><?php esc_html_e( '最新情報を取得できませんでした。しばらくしてから再度お試しください。', 'gaming-hub' ); ?></p>
			<p>
				<a href="https://pokemongolive.com/ja/news/" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
					<?php esc_html_e( '公式サイトで確認する', 'gaming-hub' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>
</section>
