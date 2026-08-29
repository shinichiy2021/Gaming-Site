<?php
/**
 * Powerwall news list partial
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

<section class="powerwall-news" aria-label="<?php esc_attr_e( 'Tesla Powerwall Latest News', 'gaming-hub' ); ?>">
	<?php if ( $show_header ) : ?>
		<div class="section-header">
			<div class="pw-section-badge">🔋 Powerwall 3</div>
			<h2 class="section-title"><?php esc_html_e( '最新ニュース', 'gaming-hub' ); ?></h2>
			<p class="section-desc"><?php esc_html_e( 'Powerwall 3 / 3P・家庭用蓄電池の最新情報', 'gaming-hub' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $news ) ) : ?>
		<div class="pw-news-list">
			<?php foreach ( $news as $item ) : ?>
				<article class="pw-news-item">
					<a href="<?php echo esc_url( $item['link'] ); ?>" target="_blank" rel="noopener noreferrer" class="pw-news-link">
						<?php gaming_hub_render_powerwall_image( $item, 'pw-news-image' ); ?>
						<div class="pw-news-content">
							<div class="pw-news-meta">
								<?php if ( ! empty( $item['source'] ) ) : ?>
									<span class="pw-badge pw-badge-source"><?php echo esc_html( $item['source'] ); ?></span>
								<?php endif; ?>
								<?php if ( ! empty( $item['categories'] ) ) : ?>
									<?php foreach ( array_slice( $item['categories'], 0, 1 ) as $category ) : ?>
										<span class="pw-badge <?php echo esc_attr( gaming_hub_powerwall_category_class( $category ) ); ?>">
											<?php echo esc_html( $category ); ?>
										</span>
									<?php endforeach; ?>
								<?php endif; ?>
								<?php if ( ! empty( $item['date_display'] ) ) : ?>
									<time datetime="<?php echo esc_attr( $item['date'] ); ?>"><?php echo esc_html( $item['date_display'] ); ?></time>
								<?php endif; ?>
							</div>
							<h3 class="pw-news-title"><?php echo esc_html( $item['title'] ); ?></h3>
							<?php if ( ! empty( $item['excerpt'] ) ) : ?>
								<p class="pw-news-excerpt"><?php echo esc_html( $item['excerpt'] ); ?></p>
							<?php endif; ?>
							<span class="pw-read-more"><?php esc_html_e( '詳しく見る', 'gaming-hub' ); ?> →</span>
						</div>
					</a>
				</article>
			<?php endforeach; ?>
		</div>

		<div class="pw-sources">
			<p class="pw-source-note">
				<?php esc_html_e( '情報源:', 'gaming-hub' ); ?>
				<a href="https://electrek.co/guides/tesla-powerwall/" target="_blank" rel="noopener noreferrer">Electrek</a>
				|
				<a href="https://www.ess-news.com/" target="_blank" rel="noopener noreferrer">ESS News</a>
				|
				<a href="<?php echo esc_url( gaming_hub_affiliate_url( 'tesla_powerwall' ) ); ?>" target="_blank" rel="<?php echo esc_attr( gaming_hub_affiliate_rel() ); ?>"><?php esc_html_e( 'Tesla 公式', 'gaming-hub' ); ?></a>
			</p>
			<p class="pw-updated-note">
				<?php esc_html_e( '30分ごとに自動更新', 'gaming-hub' ); ?>
			</p>
		</div>
	<?php else : ?>
		<div class="pw-error">
			<p><?php esc_html_e( '最新情報を取得できませんでした。しばらくしてから再度お試しください。', 'gaming-hub' ); ?></p>
			<p>
				<a href="<?php echo esc_url( gaming_hub_affiliate_url( 'tesla_powerwall' ) ); ?>" target="_blank" rel="<?php echo esc_attr( gaming_hub_affiliate_rel() ); ?>" class="btn btn-primary">
					<?php esc_html_e( 'Tesla 公式サイトで確認する', 'gaming-hub' ); ?>
				</a>
			</p>
		</div>
	<?php endif; ?>
</section>
