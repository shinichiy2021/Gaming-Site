</main>

<footer class="site-footer">
	<div class="container">
		<div class="footer-grid">
			<div class="footer-brand">
				<a href="<?php echo esc_url( gaming_hub_default_entry_url() ); ?>" class="footer-logo">
					<?php gaming_hub_render_logo_mark(); ?>
					<?php bloginfo( 'name' ); ?>
				</a>
				<p class="footer-tagline"><?php bloginfo( 'description' ); ?></p>
			</div>

			<?php if ( is_active_sidebar( 'footer-1' ) ) : ?>
				<div class="footer-widgets">
					<?php dynamic_sidebar( 'footer-1' ); ?>
				</div>
			<?php endif; ?>

			<div class="footer-links">
				<h4><?php esc_html_e( 'Quick Links', 'gaming-hub' ); ?></h4>
				<?php
				wp_nav_menu( array(
					'theme_location' => 'footer',
					'menu_class'     => 'footer-menu',
					'container'      => false,
					'fallback_cb'    => false,
				) );
				?>
			</div>

			<div class="footer-lancers">
				<?php
				if ( function_exists( 'gaming_hub_render_lancers_promo' ) ) {
					gaming_hub_render_lancers_promo( 'footer' );
				} else {
					?>
					<h4><?php esc_html_e( 'Web制作・API実装', 'gaming-hub' ); ?></h4>
					<p class="footer-lancers-lead"><?php esc_html_e( 'ランサーズのパッケージでご相談いただけます。', 'gaming-hub' ); ?></p>
					<ul class="footer-lancers-plans">
						<li><?php esc_html_e( 'ベーシック 30,000円', 'gaming-hub' ); ?></li>
						<li><?php esc_html_e( 'スタンダード 80,000円', 'gaming-hub' ); ?></li>
						<li><?php esc_html_e( 'プレミアム 150,000円', 'gaming-hub' ); ?></li>
					</ul>
					<p class="footer-lancers-cta">
						<a href="<?php echo esc_url( function_exists( 'gaming_hub_lancers_url' ) ? gaming_hub_lancers_url() : 'https://www.lancers.jp/menu/detail/1338805' ); ?>" target="_blank" rel="noopener noreferrer">
							<?php esc_html_e( 'ランサーズで詳細・相談', 'gaming-hub' ); ?>
						</a>
					</p>
					<?php
				}
				?>
			</div>
		</div>

		<div class="footer-bottom">
			<p>&copy; <?php echo esc_html( date( 'Y' ) ); ?> <?php bloginfo( 'name' ); ?>. <?php esc_html_e( 'All rights reserved.', 'gaming-hub' ); ?></p>
		</div>
	</div>
</footer>

<?php wp_footer(); ?>
</body>
</html>
