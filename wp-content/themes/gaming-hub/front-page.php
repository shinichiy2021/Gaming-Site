<?php
/**
 * Front page template — landing page layout
 *
 * @package Gaming_Hub
 */

get_header();
?>

<section class="lp-hero" aria-label="<?php esc_attr_e( 'メインビジュアル', 'gaming-hub' ); ?>">
	<div class="lp-hero-bg" aria-hidden="true"></div>
	<div class="container lp-hero-inner">
		<p class="lp-hero-badge"><?php esc_html_e( 'エネルギー × ゲーム', 'gaming-hub' ); ?></p>
		<h1 class="lp-hero-title">
			<?php echo esc_html( get_theme_mod( 'hero_title', __( '家庭の電力と、ゲームの最新情報をひとつに', 'gaming-hub' ) ) ); ?>
		</h1>
		<p class="lp-hero-lead">
			<?php echo esc_html( get_theme_mod( 'hero_subtitle', __( 'Powerwall・LOOOP・EcoFlow の見える化と、Pokémon GO / ゲームレビュー。毎日の電気代から遊びまで、このサイトでチェック。', 'gaming-hub' ) ) ); ?>
		</p>
		<div class="lp-hero-actions">
			<a href="<?php echo esc_url( gaming_hub_powerwall_url() ); ?>" class="btn btn-primary">
				<?php esc_html_e( 'Powerwall を見る', 'gaming-hub' ); ?>
			</a>
			<a href="#lp-features" class="btn btn-outline">
				<?php esc_html_e( 'できることを見る', 'gaming-hub' ); ?>
			</a>
		</div>
		<dl class="lp-hero-stats">
			<div>
				<dt><?php esc_html_e( 'ソーラー想定', 'gaming-hub' ); ?></dt>
				<dd>2 kW</dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'LOOOP 契約', 'gaming-hub' ); ?></dt>
				<dd>6 kW</dd>
			</div>
			<div>
				<dt><?php esc_html_e( 'Model 3', 'gaming-hub' ); ?></dt>
				<dd><?php esc_html_e( '30 km/日', 'gaming-hub' ); ?></dd>
			</div>
		</dl>
		<a href="#lp-features" class="lp-hero-scroll">
			<?php esc_html_e( 'スクロール', 'gaming-hub' ); ?>
		</a>
	</div>
</section>

<section id="lp-features" class="section lp-features">
	<div class="container">
		<div class="section-header">
			<p class="lp-kicker"><?php esc_html_e( 'FEATURES', 'gaming-hub' ); ?></p>
			<h2 class="section-title"><?php esc_html_e( 'このサイトでできること', 'gaming-hub' ); ?></h2>
			<p class="section-desc"><?php esc_html_e( '家庭の電力フローから、Pokémon GO の最新情報まで。', 'gaming-hub' ); ?></p>
		</div>
		<div class="lp-feature-grid">
			<a href="<?php echo esc_url( gaming_hub_powerwall_url() ); ?>" class="lp-feature-card lp-feature-powerwall">
				<span class="lp-feature-icon" aria-hidden="true">🔋</span>
				<h3><?php esc_html_e( 'Powerwall 3', 'gaming-hub' ); ?></h3>
				<p><?php esc_html_e( '2kW ソーラー・蓄電池・Model 3 の電力フローと、LOOOP 料金での節約額をシミュレーション。', 'gaming-hub' ); ?></p>
				<span class="lp-feature-link"><?php esc_html_e( 'ダッシュボードへ', 'gaming-hub' ); ?></span>
			</a>
			<a href="<?php echo esc_url( gaming_hub_looop_url() ); ?>" class="lp-feature-card lp-feature-looop">
				<span class="lp-feature-icon" aria-hidden="true">⚡</span>
				<h3><?php esc_html_e( 'LOOOP でんき予報', 'gaming-hub' ); ?></h3>
				<p><?php esc_html_e( '中部エリアの時間別単価。安い時間帯に家事や充電をシフト。', 'gaming-hub' ); ?></p>
				<span class="lp-feature-link"><?php esc_html_e( '予報を見る', 'gaming-hub' ); ?></span>
			</a>
			<a href="<?php echo esc_url( gaming_hub_ecoflow_url() ); ?>" class="lp-feature-card lp-feature-ecoflow">
				<span class="lp-feature-icon" aria-hidden="true">☀️</span>
				<h3><?php esc_html_e( 'EcoFlow', 'gaming-hub' ); ?></h3>
				<p><?php esc_html_e( 'ポータブル電源の入出力・SOC をリアルタイムで可視化。', 'gaming-hub' ); ?></p>
				<span class="lp-feature-link"><?php esc_html_e( 'ステータスへ', 'gaming-hub' ); ?></span>
			</a>
			<a href="<?php echo esc_url( gaming_hub_pokemon_go_url() ); ?>" class="lp-feature-card lp-feature-pgo">
				<span class="lp-feature-icon" aria-hidden="true">⚡</span>
				<h3><?php esc_html_e( 'Pokémon GO', 'gaming-hub' ); ?></h3>
				<p><?php esc_html_e( 'イベント・レイド情報と、注目 YouTuber の最新動画。', 'gaming-hub' ); ?></p>
				<span class="lp-feature-link"><?php esc_html_e( '最新情報へ', 'gaming-hub' ); ?></span>
			</a>
		</div>
	</div>
</section>

<section id="powerwall" class="section powerwall-home-section">
	<div class="container">
		<div class="pw-home-top">
			<div class="pw-home-header">
				<div>
					<span class="pw-section-badge">🔋 Tesla Powerwall 3</span>
					<h2 class="section-title"><?php esc_html_e( '家庭の電力を、見える化する', 'gaming-hub' ); ?></h2>
					<p class="section-desc"><?php esc_html_e( 'ソーラー・蓄電池・EV 充電の流れと、1日の電気代見込み。', 'gaming-hub' ); ?></p>
				</div>
				<a href="<?php echo esc_url( gaming_hub_powerwall_url() ); ?>" class="btn btn-outline pw-view-all">
					<?php esc_html_e( 'ダッシュボードへ', 'gaming-hub' ); ?> →
				</a>
			</div>
			<div class="pw-home-visual">
				<img
					src="<?php echo esc_url( gaming_hub_powerwall_house_image_url() ); ?>"
					alt="<?php esc_attr_e( 'Tesla Powerwall 3 とソーラー・EV の全体イメージ', 'gaming-hub' ); ?>"
					class="pw-home-house"
					width="1024"
					height="558"
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

<section class="lp-final-cta">
	<div class="container lp-final-cta-inner">
		<h2><?php esc_html_e( 'まずは電力フローから', 'gaming-hub' ); ?></h2>
		<p><?php esc_html_e( 'Powerwall ダッシュボードで、今日の発電・買電・節約額を確認できます。', 'gaming-hub' ); ?></p>
		<div class="lp-hero-actions">
			<a href="<?php echo esc_url( gaming_hub_powerwall_url() ); ?>" class="btn btn-primary">
				<?php esc_html_e( 'Powerwall を開く', 'gaming-hub' ); ?>
			</a>
			<a href="<?php echo esc_url( gaming_hub_looop_url() ); ?>" class="btn btn-outline">
				<?php esc_html_e( 'でんき予報', 'gaming-hub' ); ?>
			</a>
		</div>
	</div>
</section>

<?php
get_footer();

