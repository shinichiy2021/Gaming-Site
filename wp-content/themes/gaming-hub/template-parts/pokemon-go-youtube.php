<?php
/**
 * Pokémon GO YouTube videos partial
 *
 * @package Gaming_Hub
 *
 * @var array<int, array<string, mixed>> $videos
 * @var bool                              $show_header
 * @var int                               $limit
 */

$videos      = isset( $args['videos'] ) ? $args['videos'] : array();
$show_header = isset( $args['show_header'] ) ? (bool) $args['show_header'] : true;
?>

<section class="pgo-youtube-section" aria-label="<?php esc_attr_e( 'Pokémon GO YouTuber Videos', 'gaming-hub' ); ?>">
	<?php if ( $show_header ) : ?>
		<div class="section-header">
			<div class="pgo-yt-badge">▶ YouTube</div>
			<h2 class="section-title"><?php esc_html_e( 'YouTuber 最新動画', 'gaming-hub' ); ?></h2>
			<p class="section-desc"><?php esc_html_e( '人気 Pokémon GO 系 YouTuber の最新投稿', 'gaming-hub' ); ?></p>
		</div>
	<?php endif; ?>

	<?php if ( ! empty( $videos ) ) : ?>
		<div class="pgo-yt-grid">
			<?php foreach ( $videos as $video ) : ?>
				<article class="pgo-yt-card">
					<a href="<?php echo esc_url( $video['link'] ); ?>" target="_blank" rel="noopener noreferrer" class="pgo-yt-link">
						<div class="pgo-yt-thumb">
							<?php if ( ! empty( $video['image'] ) ) : ?>
								<img
									src="<?php echo esc_url( $video['image'] ); ?>"
									alt="<?php echo esc_attr( $video['title'] ); ?>"
									loading="lazy"
								/>
							<?php else : ?>
								<div class="pgo-yt-thumb-placeholder">▶</div>
							<?php endif; ?>
							<span class="pgo-yt-play" aria-hidden="true">▶</span>
						</div>
						<div class="pgo-yt-body">
							<?php if ( ! empty( $video['channel'] ) ) : ?>
								<span class="pgo-yt-channel"><?php echo esc_html( $video['channel'] ); ?></span>
							<?php endif; ?>
							<h3 class="pgo-yt-title"><?php echo esc_html( $video['title'] ); ?></h3>
							<?php if ( ! empty( $video['date_display'] ) ) : ?>
								<time datetime="<?php echo esc_attr( $video['date'] ); ?>"><?php echo esc_html( $video['date_display'] ); ?></time>
							<?php endif; ?>
						</div>
					</a>
				</article>
			<?php endforeach; ?>
		</div>

		<div class="pgo-yt-channels">
			<p><?php esc_html_e( '登録チャンネル:', 'gaming-hub' ); ?></p>
			<div class="pgo-yt-channel-links">
				<?php foreach ( gaming_hub_get_pokemon_go_youtube_channels() as $channel ) : ?>
					<a href="<?php echo esc_url( 'https://www.youtube.com/channel/' . $channel['id'] ); ?>" target="_blank" rel="noopener noreferrer">
						<?php echo esc_html( $channel['name'] ); ?>
					</a>
				<?php endforeach; ?>
			</div>
		</div>
	<?php else : ?>
		<div class="pgo-error">
			<p><?php esc_html_e( 'YouTube 動画を取得できませんでした。しばらくしてから再度お試しください。', 'gaming-hub' ); ?></p>
		</div>
	<?php endif; ?>
</section>
