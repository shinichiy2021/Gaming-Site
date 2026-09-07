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
		if ( 'ecoflow' === $tag_slug ) {
			echo '<section id="ecoflow-posts" class="ecoflow-hub-section">';
		}
		echo '<div class="container content-area content-area--hub-top">';
		if ( 'ecoflow' === $tag_slug && function_exists( 'gaming_hub_render_ecoflow_section_head' ) ) {
			gaming_hub_render_ecoflow_section_head(
				__( '記事', 'gaming-hub' ),
				__( '実測レビュー・運用メモ', 'gaming-hub' )
			);
		}
		echo '<div class="posts-grid">';
		while ( $query->have_posts() ) {
			$query->the_post();
			get_template_part( 'template-parts/content', get_post_type() );
		}
		echo '</div></div>';
		if ( 'ecoflow' === $tag_slug ) {
			echo '</section>';
		}
		wp_reset_postdata();
		return;
	}

	if ( 'ecoflow' === $tag_slug ) {
		echo '<section id="ecoflow-posts" class="ecoflow-hub-section">';
		echo '<div class="container content-area content-area--hub-top">';
		if ( function_exists( 'gaming_hub_render_ecoflow_section_head' ) ) {
			gaming_hub_render_ecoflow_section_head(
				__( '記事', 'gaming-hub' ),
				__( '実測レビュー・運用メモ', 'gaming-hub' )
			);
		}
		echo '<div class="ecoflow-empty"><p>';
		esc_html_e( 'EcoFlow タグの記事はまだありません。下の実測構成・発電ログから機材を確認できます。', 'gaming-hub' );
		echo '</p></div></div></section>';
	}
}

/**
 * EcoFlow hub panel body (archive chrome + dashboard + energy + kit).
 */
function gaming_hub_render_hub_panel_ecoflow_body() {
	gaming_hub_render_ecoflow_hub_dashboard_sections();
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
			gaming_hub_render_ecoflow_hub_intro();
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
