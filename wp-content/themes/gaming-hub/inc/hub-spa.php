<?php
/**
 * EcoFlow ↔ Tesla hub SPA (React shell + dual panels).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the current request is an EcoFlow/Tesla tag hub (SPA host).
 */
function gaming_hub_is_hub_spa_page() {
	return is_tag( array( 'ecoflow', 'tesla' ) );
}

/**
 * Active hub slug for SPA pages.
 *
 * @return string ecoflow|tesla|''
 */
function gaming_hub_hub_spa_active_slug() {
	if ( is_tag( 'tesla' ) ) {
		return 'tesla';
	}
	if ( is_tag( 'ecoflow' ) ) {
		return 'ecoflow';
	}
	return '';
}

/**
 * Hub switcher items for the React shell (always EcoFlow + Tesla).
 *
 * @return array<int, array{slug:string,label:string,url:string}>
 */
function gaming_hub_hub_spa_items() {
	$items = array(
		array(
			'slug'  => 'ecoflow',
			'label' => 'EcoFlow',
			'url'   => function_exists( 'gaming_hub_ecoflow_url' ) ? gaming_hub_ecoflow_url() : home_url( '/tag/ecoflow/' ),
		),
		array(
			'slug'  => 'tesla',
			'label' => 'Tesla',
			'url'   => function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ),
		),
	);

	/**
	 * Filter SPA hub items.
	 *
	 * @param array<int, array{slug:string,label:string,url:string}> $items Items.
	 */
	return apply_filters( 'gaming_hub_hub_spa_items', $items );
}

/**
 * Render posts grid for a tag slug (secondary query for inactive SPA panel).
 *
 * @param string $tag_slug Tag slug.
 */
function gaming_hub_render_hub_panel_posts( $tag_slug ) {
	$tag_slug = sanitize_title( (string) $tag_slug );
	if ( '' === $tag_slug ) {
		return;
	}

	$query = new WP_Query(
		array(
			'tag'            => $tag_slug,
			'post_status'    => 'publish',
			'posts_per_page' => (int) get_option( 'posts_per_page', 10 ),
			'no_found_rows'  => true,
		)
	);

	if ( $query->have_posts() ) {
		echo '<div class="container content-area content-area--hub-top">';
		echo '<div class="posts-grid">';
		while ( $query->have_posts() ) {
			$query->the_post();
			get_template_part( 'template-parts/content', get_post_type() );
		}
		echo '</div></div>';
		wp_reset_postdata();
		return;
	}

	if ( 'ecoflow' === $tag_slug ) {
		echo '<div class="container content-area content-area--hub-top">';
		echo '<div class="ecoflow-empty"><p>';
		esc_html_e( 'EcoFlow タグの記事はまだありません。下の実測構成・発電ログから機材を確認できます。', 'gaming-hub' );
		echo '</p></div></div>';
	}
}

/**
 * EcoFlow hub panel body (archive chrome + dashboard + energy + kit).
 */
function gaming_hub_render_hub_panel_ecoflow_body() {
	?>
	<div class="archive-header ecoflow-archive-header ecoflow-archive-header--below-posts">
		<div class="container">
			<span class="ecoflow-tag-badge ecoflow-tag-badge-lg">EcoFlow</span>
			<p class="ecoflow-archive-desc"><?php esc_html_e( 'ポータブル電源・ソーラーパネル・防災・キャンプ関連の記事', 'gaming-hub' ); ?></p>
			<div class="ecoflow-official-links">
				<a href="#energy" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '発電ログ', 'gaming-hub' ); ?></a>
				<a href="#kit" class="btn btn-outline ecoflow-btn-outline"><?php esc_html_e( '実測構成', 'gaming-hub' ); ?></a>
			</div>
		</div>
	</div>
	<div class="container ecoflow-dashboard-wrap">
		<?php gaming_hub_render_ecoflow_dashboard(); ?>
	</div>
	<div id="energy" class="container ecoflow-dashboard-wrap">
		<?php gaming_hub_render_ecoflow_energy_page(); ?>
	</div>
	<div class="container ecoflow-dashboard-wrap">
		<?php get_template_part( 'template-parts/ecoflow', 'kit' ); ?>
	</div>
	<?php
}

/**
 * Tesla hub panel body.
 */
function gaming_hub_render_hub_panel_tesla_body() {
	?>
	<section class="hub-section hub-tesla">
		<?php get_template_part( 'template-parts/powerwall', 'page' ); ?>
	</section>
	<?php
}

/**
 * Dual EcoFlow + Tesla panels for the React hub SPA.
 *
 * @param string $active Active slug (ecoflow|tesla).
 */
function gaming_hub_render_hub_spa_panels( $active ) {
	$active = in_array( $active, array( 'ecoflow', 'tesla' ), true ) ? $active : 'ecoflow';
	?>
	<div class="hub-spa-panels" data-hub-spa-panels="1">
		<div
			class="hub-panel hub-panel--ecoflow<?php echo 'ecoflow' === $active ? ' is-active' : ''; ?>"
			data-hub-panel="ecoflow"
			<?php echo 'ecoflow' === $active ? '' : ' hidden'; ?>
			aria-hidden="<?php echo 'ecoflow' === $active ? 'false' : 'true'; ?>"
		>
			<?php
			gaming_hub_render_hub_panel_posts( 'ecoflow' );
			gaming_hub_render_hub_panel_ecoflow_body();
			?>
		</div>
		<div
			class="hub-panel hub-panel--tesla<?php echo 'tesla' === $active ? ' is-active' : ''; ?>"
			data-hub-panel="tesla"
			<?php echo 'tesla' === $active ? '' : ' hidden'; ?>
			aria-hidden="<?php echo 'tesla' === $active ? 'false' : 'true'; ?>"
		>
			<?php
			gaming_hub_render_hub_panel_posts( 'tesla' );
			gaming_hub_render_hub_panel_tesla_body();
			?>
		</div>
	</div>
	<?php
}

/**
 * Enqueue React hub SPA shell on pages that show the mobile switcher.
 */
function gaming_hub_hub_spa_scripts() {
	if ( ! function_exists( 'gaming_hub_should_show_mobile_hub_switcher' ) || ! gaming_hub_should_show_mobile_hub_switcher() ) {
		return;
	}

	$items  = gaming_hub_hub_spa_items();
	$active = gaming_hub_hub_spa_active_slug();
	if ( '' === $active ) {
		if ( function_exists( 'gaming_hub_has_ecoflow_tag' ) && is_singular( 'post' ) && gaming_hub_has_ecoflow_tag() ) {
			$active = 'ecoflow';
		} elseif ( is_singular( 'post' ) && has_tag( 'tesla' ) ) {
			$active = 'tesla';
		} else {
			$active = 'ecoflow';
		}
	}

	wp_enqueue_script(
		'gaming-hub-hub-spa',
		get_template_directory_uri() . '/assets/js/hub-spa.js',
		array( 'gaming-hub-i18n' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-hub-spa',
		'gamingHubSpa',
		array(
			'active'     => $active,
			'spaEnabled' => gaming_hub_is_hub_spa_page(),
			'items'      => $items,
			'labels'     => array(
				'nav' => __( 'ダッシュボード切替', 'gaming-hub' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_hub_spa_scripts', 30 );
