<?php
/**
 * Template Name: Tesla Powerwall 3
 * Description: Latest Tesla Powerwall 3 news and specifications
 *
 * @package Gaming_Hub
 */

get_header();
?>

<div class="powerwall-page">
	<section class="pw-hero">
		<div class="pw-hero-bg"></div>
		<div class="container pw-hero-inner">
			<div class="pw-hero-visual">
				<img
					src="<?php echo esc_url( gaming_hub_powerwall_product_image_url() ); ?>"
					alt="<?php esc_attr_e( 'Tesla Powerwall 3', 'gaming-hub' ); ?>"
					class="pw-hero-product"
					width="480"
					height="560"
					loading="eager"
				/>
			</div>
			<div class="pw-hero-content">
			<span class="pw-hero-badge">🔋 Tesla Powerwall 3</span>
			<h1 class="pw-hero-title"><?php esc_html_e( 'Powerwall 3 最新情報', 'gaming-hub' ); ?></h1>
			<p class="pw-hero-desc">
				<?php esc_html_e( '家庭用蓄電池 Powerwall 3 / 3P のニュース・仕様・公式リンク', 'gaming-hub' ); ?>
			</p>
			<div class="pw-hero-links">
				<a href="https://www.tesla.com/ja_jp/powerwall" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
					<?php esc_html_e( 'Tesla 公式（日本）', 'gaming-hub' ); ?>
				</a>
				<a href="https://www.tesla.com/support/energy/powerwall/own/powerwall-specifications" target="_blank" rel="noopener noreferrer" class="btn btn-outline">
					<?php esc_html_e( '技術仕様', 'gaming-hub' ); ?>
				</a>
			</div>
			</div>
		</div>
	</section>

	<section class="section pw-specs-section">
		<div class="container">
			<?php gaming_hub_render_powerwall_specs(); ?>
		</div>
	</section>

	<section class="section pw-news-section">
		<div class="container">
			<?php gaming_hub_render_powerwall_news( 15, true ); ?>
		</div>
	</section>

	<section class="section pw-quick-links">
		<div class="container">
			<h2 class="section-title"><?php esc_html_e( '便利リンク', 'gaming-hub' ); ?></h2>
			<div class="pw-links-grid">
				<a href="https://www.tesla.com/ja_jp/support/energy/powerwall" target="_blank" rel="noopener noreferrer" class="pw-link-card">
					<span class="pw-link-icon">📘</span>
					<h3><?php esc_html_e( 'サポート', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( '設置・運用・トラブルシューティング', 'gaming-hub' ); ?></p>
				</a>
				<a href="https://www.tesla.com/ja_jp/support/energy/powerwall/own/monitoring-powerwall" target="_blank" rel="noopener noreferrer" class="pw-link-card">
					<span class="pw-link-icon">📱</span>
					<h3><?php esc_html_e( 'Tesla アプリ', 'gaming-hub' ); ?></h3>
					<p><?php esc_html_e( '充放電・自家消費のモニタリング', 'gaming-hub' ); ?></p>
				</a>
				<a href="https://electrek.co/guides/tesla-powerwall/" target="_blank" rel="noopener noreferrer" class="pw-link-card">
					<span class="pw-link-icon">📰</span>
					<h3>Electrek</h3>
					<p><?php esc_html_e( 'Powerwall 関連ニュース（英語）', 'gaming-hub' ); ?></p>
				</a>
				<a href="https://www.ess-news.com/" target="_blank" rel="noopener noreferrer" class="pw-link-card">
					<span class="pw-link-icon">⚡</span>
					<h3>ESS News</h3>
					<p><?php esc_html_e( 'エネルギーストレージ業界ニュース', 'gaming-hub' ); ?></p>
				</a>
			</div>
		</div>
	</section>
</div>

<?php
get_footer();
