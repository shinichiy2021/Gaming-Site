<?php
/**
 * Gaming Hub theme functions
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_VERSION', '1.14.3' );

/**
 * Browser origin when opening local WordPress via LAN IP (iPad).
 */
function gaming_hub_local_request_origin() {
	static $origin = null;

	if ( null !== $origin ) {
		return $origin;
	}

	$origin = '';
	if ( empty( $_SERVER['HTTP_HOST'] ) ) {
		return $origin;
	}

	$host = strtolower( (string) wp_unslash( $_SERVER['HTTP_HOST'] ) );
	if ( ! preg_match( '/^[a-z0-9.-]+(:\d+)?$/', $host ) ) {
		return $origin;
	}

	if ( 0 === strpos( $host, 'localhost' ) || 0 === strpos( $host, '127.0.0.1' ) ) {
		return $origin;
	}

	$https = ( ! empty( $_SERVER['HTTPS'] ) && 'off' !== $_SERVER['HTTPS'] )
		|| ( isset( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) && 'https' === $_SERVER['HTTP_X_FORWARDED_PROTO'] );

	$origin = ( $https ? 'https://' : 'http://' ) . $host;
	return $origin;
}

/**
 * Rewrite localhost URLs to the current request host.
 *
 * @param mixed $url URL.
 * @return mixed
 */
function gaming_hub_rewrite_local_url( $url ) {
	if ( ! is_string( $url ) || '' === $url ) {
		return $url;
	}

	$origin = gaming_hub_local_request_origin();
	if ( ! $origin ) {
		return $url;
	}

	return preg_replace( '#https?://(?:localhost|127\.0\.0\.1)(?::\d+)?#i', $origin, $url );
}

add_filter( 'option_home', 'gaming_hub_rewrite_local_url' );
add_filter( 'option_siteurl', 'gaming_hub_rewrite_local_url' );
add_filter( 'home_url', 'gaming_hub_rewrite_local_url' );
add_filter( 'site_url', 'gaming_hub_rewrite_local_url' );
add_filter( 'content_url', 'gaming_hub_rewrite_local_url' );
add_filter( 'plugins_url', 'gaming_hub_rewrite_local_url' );
add_filter( 'includes_url', 'gaming_hub_rewrite_local_url' );
add_filter( 'template_directory_uri', 'gaming_hub_rewrite_local_url' );
add_filter( 'stylesheet_directory_uri', 'gaming_hub_rewrite_local_url' );
add_filter( 'stylesheet_uri', 'gaming_hub_rewrite_local_url' );
add_filter( 'theme_root_uri', 'gaming_hub_rewrite_local_url' );
add_filter( 'script_loader_src', 'gaming_hub_rewrite_local_url' );
add_filter( 'style_loader_src', 'gaming_hub_rewrite_local_url' );
add_filter( 'wp_get_attachment_url', 'gaming_hub_rewrite_local_url' );

require get_template_directory() . '/inc/i18n.php';
require get_template_directory() . '/inc/pokemon-go.php';
require get_template_directory() . '/inc/pokemon-go-youtube.php';
require get_template_directory() . '/inc/pokemon-go-events.php';
require get_template_directory() . '/inc/pokemon-go-raids.php';
require get_template_directory() . '/inc/ecoflow.php';
require get_template_directory() . '/inc/affiliate.php';
require get_template_directory() . '/inc/hub-switcher.php';
require get_template_directory() . '/inc/rank-math-setup.php';
require get_template_directory() . '/inc/seed-posts.php';
require get_template_directory() . '/inc/switchbot.php';
require get_template_directory() . '/inc/looop.php';
require get_template_directory() . '/inc/powerwall.php';
require get_template_directory() . '/inc/powerwall-solar.php';
require get_template_directory() . '/inc/powerwall-flow.php';
require get_template_directory() . '/inc/powerwall-home.php';
require get_template_directory() . '/inc/powerwall-model3.php';
require get_template_directory() . '/inc/powerwall-cost.php';
require get_template_directory() . '/inc/tesla.php';
require get_template_directory() . '/inc/tajimi-gasoline.php';
require get_template_directory() . '/inc/tesla-flow.php';
require get_template_directory() . '/inc/tesla-gas-log.php';
require get_template_directory() . '/inc/tesla-charge-log.php';
require get_template_directory() . '/inc/tesla-plan.php';

/**
 * Tag archive URL (creates a stable /tag/{slug}/ fallback).
 *
 * @param string               $slug  Tag slug.
 * @param array<string, mixed> $query Optional query args.
 */
function gaming_hub_tag_url( $slug, $query = array() ) {
	$slug = sanitize_title( $slug );
	$term = get_term_by( 'slug', $slug, 'post_tag' );
	$url  = ( $term && ! is_wp_error( $term ) ) ? get_term_link( $term ) : home_url( '/tag/' . $slug . '/' );

	if ( is_wp_error( $url ) || ! is_string( $url ) || '' === $url ) {
		$url = home_url( '/tag/' . $slug . '/' );
	}

	return empty( $query ) ? $url : add_query_arg( $query, $url );
}

/**
 * Tesla OAuth flags that must follow the visitor to the Tesla tag.
 *
 * @return array<string, string>
 */
function gaming_hub_tesla_oauth_query() {
	$query = array();
	if ( ! empty( $_GET['tesla_connected'] ) ) {
		$query['tesla_connected'] = '1';
	}
	if ( ! empty( $_GET['tesla_revoked'] ) ) {
		$query['tesla_revoked'] = '1';
	}

	return $query;
}

/**
 * Default landing URL: Tesla while driving, otherwise EcoFlow.
 */
function gaming_hub_default_entry_url() {
	$query = gaming_hub_tesla_oauth_query();
	if ( $query ) {
		return function_exists( 'gaming_hub_tesla_url' )
			? gaming_hub_tesla_url( $query )
			: gaming_hub_tag_url( 'tesla', $query );
	}

	if ( function_exists( 'gaming_hub_tesla_is_driving_now' ) && gaming_hub_tesla_is_driving_now() ) {
		return function_exists( 'gaming_hub_tesla_url' )
			? gaming_hub_tesla_url()
			: gaming_hub_tag_url( 'tesla' );
	}

	return function_exists( 'gaming_hub_ecoflow_url' )
		? gaming_hub_ecoflow_url()
		: gaming_hub_tag_url( 'ecoflow' );
}

/**
 * Legacy hub section id → tag URL.
 *
 * @param string               $section Section id without #.
 * @param array<string, mixed> $query   Optional query args.
 */
function gaming_hub_hub_section_url( $section, $query = array() ) {
	$section = sanitize_title( $section );

	if ( 'energy' === $section ) {
		return ( function_exists( 'gaming_hub_energy_url' )
			? gaming_hub_energy_url( $query )
			: gaming_hub_tag_url( 'ecoflow', $query ) . '#energy' );
	}

	if ( 'powerwall' === $section || 'tesla' === $section ) {
		return function_exists( 'gaming_hub_tesla_url' )
			? gaming_hub_tesla_url( $query )
			: gaming_hub_tag_url( 'tesla', $query );
	}

	if ( 'pokemon-go' === $section ) {
		return function_exists( 'gaming_hub_pokemon_go_url' )
			? gaming_hub_pokemon_go_url( $query )
			: gaming_hub_tag_url( 'pokemon-go', $query );
	}

	return function_exists( 'gaming_hub_ecoflow_url' )
		? gaming_hub_ecoflow_url( $query )
		: gaming_hub_tag_url( 'ecoflow', $query );
}

/**
 * Map a legacy page/tag/hash URL to a section id.
 *
 * @param string $url Absolute or relative URL.
 */
function gaming_hub_url_hub_section( $url ) {
	$parts = wp_parse_url( (string) $url );
	$path  = untrailingslashit( (string) ( $parts['path'] ?? '' ) );
	$hash  = (string) ( $parts['fragment'] ?? '' );
	$map   = array(
		'/tag/ecoflow'     => 'ecoflow',
		'/tag/energy'      => 'energy',
		'/tag/tesla'       => 'tesla',
		'/tag/pokemon-go'  => 'pokemon-go',
		'/powerwall'       => 'tesla',
		'/pokemon-go'      => 'pokemon-go',
	);

	if ( isset( $map[ $path ] ) ) {
		return $map[ $path ];
	}

	if ( in_array( $hash, array( 'ecoflow', 'energy', 'powerwall', 'tesla', 'pokemon-go' ), true ) ) {
		return 'powerwall' === $hash ? 'tesla' : $hash;
	}

	return '';
}

/**
 * Home → EcoFlow (or Tesla while driving). Old pages/tags → the new tag screens.
 */
function gaming_hub_redirect_legacy_section_pages() {
	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || is_feed() || is_customize_preview() ) {
		return;
	}

	$query = gaming_hub_tesla_oauth_query();

	if ( is_front_page() ) {
		wp_safe_redirect( gaming_hub_default_entry_url(), 302 );
		exit;
	}

	if ( is_tag( 'energy' ) ) {
		wp_safe_redirect(
			function_exists( 'gaming_hub_ecoflow_url' ) ? gaming_hub_ecoflow_url() : gaming_hub_tag_url( 'ecoflow' ),
			301
		);
		exit;
	}

	if ( is_tag( 'powerwall' ) || is_page( 'powerwall' ) ) {
		wp_safe_redirect(
			function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url( $query ) : gaming_hub_tag_url( 'tesla', $query ),
			301
		);
		exit;
	}

	if ( is_page( 'pokemon-go' ) ) {
		wp_safe_redirect(
			function_exists( 'gaming_hub_pokemon_go_url' ) ? gaming_hub_pokemon_go_url() : gaming_hub_tag_url( 'pokemon-go' ),
			301
		);
		exit;
	}
}
add_action( 'template_redirect', 'gaming_hub_redirect_legacy_section_pages' );

/**
 * Serve Google Search Console HTML verification file at site root.
 */
function gaming_hub_serve_google_site_verification() {
	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	$path = (string) wp_parse_url( $uri, PHP_URL_PATH );
	if ( '/googlee038b6ecd4369c0c.html' !== $path && '/googlee038b6ecd4369c0c.html/' !== $path ) {
		return;
	}

	nocache_headers();
	header( 'Content-Type: text/html; charset=UTF-8' );
	status_header( 200 );
	echo 'google-site-verification: googlee038b6ecd4369c0c';
	exit;
}
add_action( 'template_redirect', 'gaming_hub_serve_google_site_verification', 0 );

/**
 * Keep dashboard tags visible even when they have no posts.
 *
 * @param bool     $preempt Whether to short-circuit 404 handling.
 * @param WP_Query $query   Main query.
 */
function gaming_hub_allow_empty_dashboard_tags( $preempt, $query ) {
	if ( $preempt || ! $query instanceof WP_Query ) {
		return $preempt;
	}

	if ( $query->is_tag( array( 'ecoflow', 'energy', 'tesla', 'pokemon-go' ) ) ) {
		return true;
	}

	return $preempt;
}
add_filter( 'pre_handle_404', 'gaming_hub_allow_empty_dashboard_tags', 10, 2 );

/**
 * EcoFlow tag archive also lists Energy-tagged posts.
 *
 * @param WP_Query $query Main query.
 */
function gaming_hub_ecoflow_tag_includes_energy( $query ) {
	if ( is_admin() || ! $query->is_main_query() || ! $query->is_tag( 'ecoflow' ) ) {
		return;
	}

	$ids  = array();
	$eco  = get_term_by( 'slug', 'ecoflow', 'post_tag' );
	$nrg  = get_term_by( 'slug', 'energy', 'post_tag' );
	if ( $eco && ! is_wp_error( $eco ) ) {
		$ids[] = (int) $eco->term_id;
	}
	if ( $nrg && ! is_wp_error( $nrg ) ) {
		$ids[] = (int) $nrg->term_id;
	}
	if ( ! $ids ) {
		return;
	}

	$query->set( 'tag', '' );
	$query->set( 'tag_id', 0 );
	$query->set(
		'tax_query',
		array(
			array(
				'taxonomy' => 'post_tag',
				'field'    => 'term_id',
				'terms'    => $ids,
				'operator' => 'IN',
			),
		)
	);
}
add_action( 'pre_get_posts', 'gaming_hub_ecoflow_tag_includes_energy' );

function gaming_hub_setup() {
	load_theme_textdomain( 'gaming-hub', get_template_directory() . '/languages' );

	add_theme_support( 'title-tag' );
	add_theme_support( 'post-thumbnails' );
	add_theme_support( 'html5', array( 'search-form', 'comment-form', 'comment-list', 'gallery', 'caption', 'style', 'script' ) );
	add_theme_support( 'custom-logo', array(
		'height'      => 60,
		'width'       => 200,
		'flex-height' => true,
		'flex-width'  => true,
	) );

	add_image_size( 'game-card', 400, 225, true );
	add_image_size( 'hero-banner', 1920, 600, true );

	register_nav_menus( array(
		'primary' => __( 'Primary Menu', 'gaming-hub' ),
		'footer'  => __( 'Footer Menu', 'gaming-hub' ),
	) );
}
add_action( 'after_setup_theme', 'gaming_hub_setup' );

/**
 * Pikachu-style lightning logo mark URL.
 */
function gaming_hub_logo_mark_url() {
	return get_template_directory_uri() . '/assets/images/lightning-logo.svg';
}

/**
 * Render the site logo mark.
 *
 * @param string $class CSS class for the image.
 */
function gaming_hub_render_logo_mark( $class = 'logo-icon' ) {
	?>
	<img
		src="<?php echo esc_url( gaming_hub_logo_mark_url() ); ?>"
		alt=""
		class="<?php echo esc_attr( $class ); ?>"
		width="28"
		height="28"
		decoding="async"
	/>
	<?php
}

/**
 * Customize wp-login.php logo.
 */
function gaming_hub_login_logo_styles() {
	$logo_url = esc_url( gaming_hub_logo_mark_url() );
	?>
	<style>
		body.login div#login h1 a {
			background-image: url('<?php echo $logo_url; ?>');
			background-size: contain;
			background-repeat: no-repeat;
			background-position: center;
			width: 84px;
			height: 84px;
		}
	</style>
	<?php
}
add_action( 'login_enqueue_scripts', 'gaming_hub_login_logo_styles' );

function gaming_hub_login_logo_url() {
	return gaming_hub_default_entry_url();
}
add_filter( 'login_headerurl', 'gaming_hub_login_logo_url' );

function gaming_hub_login_logo_title() {
	return get_bloginfo( 'name' );
}
add_filter( 'login_headertext', 'gaming_hub_login_logo_title' );

/**
 * Browser tab favicon (uses lightning mark when no Site Icon is set).
 */
function gaming_hub_favicon_tags() {
	if ( has_site_icon() ) {
		return;
	}

	$icon = esc_url( gaming_hub_logo_mark_url() );
	?>
	<link rel="icon" href="<?php echo $icon; ?>" type="image/svg+xml" sizes="any" />
	<link rel="shortcut icon" href="<?php echo $icon; ?>" type="image/svg+xml" />
	<link rel="apple-touch-icon" href="<?php echo $icon; ?>" />
	<?php
}
add_action( 'wp_head', 'gaming_hub_favicon_tags', 1 );
add_action( 'login_head', 'gaming_hub_favicon_tags', 1 );
add_action( 'admin_head', 'gaming_hub_favicon_tags', 1 );

function gaming_hub_scripts() {
	wp_enqueue_style(
		'gaming-hub-fonts',
		'https://fonts.googleapis.com/css2?family=Orbitron:wght@400;700;900&family=Inter:wght@400;500;600;700&display=swap',
		array(),
		null
	);

	wp_enqueue_style(
		'gaming-hub-style',
		get_stylesheet_uri(),
		array( 'gaming-hub-fonts' ),
		GAMING_HUB_VERSION
	);

	wp_enqueue_style(
		'gaming-hub-main',
		get_template_directory_uri() . '/assets/css/main.css',
		array( 'gaming-hub-style' ),
		GAMING_HUB_VERSION
	);

	wp_enqueue_script(
		'gaming-hub-active-refresh',
		get_template_directory_uri() . '/assets/js/active-refresh.js',
		array(),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-active-refresh',
		'gamingHubActiveRefreshConfig',
		array(
			'reloadAfterMs'  => MINUTE_IN_SECONDS * 1000,
			'reloadOnActive' => is_tag( array( 'ecoflow', 'tesla', 'pokemon-go' ) )
				|| is_page( array( 'pokemon-go-raid' ) ),
		)
	);

	wp_enqueue_script(
		'gaming-hub-main',
		get_template_directory_uri() . '/assets/js/main.js',
		array( 'gaming-hub-active-refresh', 'gaming-hub-i18n' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-main',
		'gamingHubNav',
		array(
			'hashMap' => array(
				'#ecoflow'    => function_exists( 'gaming_hub_ecoflow_url' ) ? gaming_hub_ecoflow_url() : gaming_hub_tag_url( 'ecoflow' ),
				'#energy'     => function_exists( 'gaming_hub_energy_url' ) ? gaming_hub_energy_url() : gaming_hub_tag_url( 'ecoflow' ) . '#energy',
				'#powerwall'  => function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : gaming_hub_tag_url( 'tesla' ),
				'#tesla'      => function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : gaming_hub_tag_url( 'tesla' ),
				'#pokemon-go' => function_exists( 'gaming_hub_pokemon_go_url' ) ? gaming_hub_pokemon_go_url() : gaming_hub_tag_url( 'pokemon-go' ),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_scripts' );

function gaming_hub_widgets_init() {
	register_sidebar( array(
		'name'          => __( 'Sidebar', 'gaming-hub' ),
		'id'            => 'sidebar-1',
		'description'   => __( 'Add widgets here.', 'gaming-hub' ),
		'before_widget' => '<section id="%1$s" class="widget %2$s">',
		'after_widget'  => '</section>',
		'before_title'  => '<h3 class="widget-title">',
		'after_title'   => '</h3>',
	) );

	register_sidebar( array(
		'name'          => __( 'Footer', 'gaming-hub' ),
		'id'            => 'footer-1',
		'description'   => __( 'Footer widget area.', 'gaming-hub' ),
		'before_widget' => '<div id="%1$s" class="footer-widget %2$s">',
		'after_widget'  => '</div>',
		'before_title'  => '<h4 class="footer-widget-title">',
		'after_title'   => '</h4>',
	) );
}
add_action( 'widgets_init', 'gaming_hub_widgets_init' );

function gaming_hub_excerpt_length( $length ) {
	return 20;
}
add_filter( 'excerpt_length', 'gaming_hub_excerpt_length' );

function gaming_hub_excerpt_more( $more ) {
	return '...';
}
add_filter( 'excerpt_more', 'gaming_hub_excerpt_more' );

function gaming_hub_get_rating_stars( $rating ) {
	$rating = max( 0, min( 5, (float) $rating ) );
	$full   = (int) floor( $rating );
	$html   = '<div class="rating-stars" aria-label="' . esc_attr( sprintf( __( '%s out of 5 stars', 'gaming-hub' ), $rating ) ) . '">';

	for ( $i = 1; $i <= 5; $i++ ) {
		$class = $i <= $full ? 'star filled' : 'star';
		$html .= '<span class="' . esc_attr( $class ) . '">★</span>';
	}

	$html .= '</div>';
	return $html;
}

function gaming_hub_get_game_meta( $post_id = null ) {
	$post_id = $post_id ?: get_the_ID();
	return array(
		'platform' => get_post_meta( $post_id, '_game_platform', true ),
		'genre'    => get_post_meta( $post_id, '_game_genre', true ),
		'rating'   => get_post_meta( $post_id, '_game_rating', true ),
	);
}

function gaming_hub_register_game_meta() {
	register_post_meta( 'post', '_game_platform', array(
		'show_in_rest' => true,
		'single'       => true,
		'type'         => 'string',
	) );
	register_post_meta( 'post', '_game_genre', array(
		'show_in_rest' => true,
		'single'       => true,
		'type'         => 'string',
	) );
	register_post_meta( 'post', '_game_rating', array(
		'show_in_rest' => true,
		'single'       => true,
		'type'         => 'number',
	) );
}
add_action( 'init', 'gaming_hub_register_game_meta' );

function gaming_hub_customize_register( $wp_customize ) {
	$wp_customize->add_section( 'gaming_hub_hero', array(
		'title'    => __( 'Hero Section', 'gaming-hub' ),
		'priority' => 30,
	) );

	$wp_customize->add_setting( 'hero_title', array(
		'default'           => __( '家庭の電力と、ゲームの最新情報をひとつに', 'gaming-hub' ),
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'hero_title', array(
		'label'   => __( 'Hero Title', 'gaming-hub' ),
		'section' => 'gaming_hub_hero',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'hero_subtitle', array(
		'default'           => __( 'Powerwall・EcoFlow の見える化と、Pokémon GO / ゲームレビュー。毎日の電気代から遊びまで、このサイトでチェック。', 'gaming-hub' ),
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'hero_subtitle', array(
		'label'   => __( 'Hero Subtitle', 'gaming-hub' ),
		'section' => 'gaming_hub_hero',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'hero_cta_text', array(
		'default'           => __( 'Powerwall を見る', 'gaming-hub' ),
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'hero_cta_text', array(
		'label'   => __( 'Hero CTA Text', 'gaming-hub' ),
		'section' => 'gaming_hub_hero',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'hero_cta_url', array(
		'default'           => '/powerwall/',
		'sanitize_callback' => 'esc_url_raw',
	) );
	$wp_customize->add_control( 'hero_cta_url', array(
		'label'   => __( 'Hero CTA URL', 'gaming-hub' ),
		'section' => 'gaming_hub_hero',
		'type'    => 'url',
	) );

	$wp_customize->add_section( 'gaming_hub_ecoflow_api', array(
		'title'    => __( 'EcoFlow API', 'gaming-hub' ),
		'priority' => 36,
	) );

	$wp_customize->add_setting( 'ecoflow_access_key', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'ecoflow_access_key', array(
		'label'   => __( 'Access Key', 'gaming-hub' ),
		'section' => 'gaming_hub_ecoflow_api',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'ecoflow_secret_key', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'ecoflow_secret_key', array(
		'label'   => __( 'Secret Key', 'gaming-hub' ),
		'section' => 'gaming_hub_ecoflow_api',
		'type'    => 'password',
	) );

	$wp_customize->add_setting( 'ecoflow_device_sn', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'ecoflow_device_sn', array(
		'label'       => __( 'Device Serial Number (Delta Pro 3)', 'gaming-hub' ),
		'description' => __( 'Example: MR51ZJ1APH6S0189', 'gaming-hub' ),
		'section'     => 'gaming_hub_ecoflow_api',
		'type'        => 'text',
	) );

	$wp_customize->add_setting( 'ecoflow_device_sn_2', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'ecoflow_device_sn_2', array(
		'label'       => __( 'Device Serial Number 2 (Delta 3 1500)', 'gaming-hub' ),
		'description' => __( 'AC 100V で Pro 3 に接続している 2 台目', 'gaming-hub' ),
		'section'     => 'gaming_hub_ecoflow_api',
		'type'        => 'text',
	) );

	$wp_customize->add_setting( 'ecoflow_app_email', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_email',
	) );
	$wp_customize->add_control( 'ecoflow_app_email', array(
		'label'       => __( 'App Login Email (Delta 3)', 'gaming-hub' ),
		'description' => __( 'Googleログインの場合は Google アカウントのメールアドレス。MQTT 用にアプリで別途「ログインパスワード」を設定してください。', 'gaming-hub' ),
		'section'     => 'gaming_hub_ecoflow_api',
		'type'        => 'email',
	) );

	$wp_customize->add_setting( 'ecoflow_app_password', array(
		'default'           => '',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'ecoflow_app_password', array(
		'label'       => __( 'App Login Password (Delta 3)', 'gaming-hub' ),
		'description' => __( 'EcoFlow アプリで設定したログインパスワード（Googleログインのみの場合は未設定のままでは使えません）', 'gaming-hub' ),
		'section'     => 'gaming_hub_ecoflow_api',
		'type'        => 'password',
	) );

	$wp_customize->add_setting( 'ecoflow_api_region', array(
		'default'           => 'us',
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'ecoflow_api_region', array(
		'label'   => __( 'API Region', 'gaming-hub' ),
		'section' => 'gaming_hub_ecoflow_api',
		'type'    => 'select',
		'choices' => array(
			'us' => 'US / Global (api.ecoflow.com)',
			'a'  => 'Asia / 日本 (api-a.ecoflow.com)',
			'eu' => 'Europe (api-e.ecoflow.com)',
		),
	) );
}
add_action( 'customize_register', 'gaming_hub_customize_register' );

function gaming_hub_customize_register_tesla( $wp_customize ) {
	$wp_customize->add_section(
		'gaming_hub_tesla_api',
		array(
			'title'    => __( 'Tesla API (Model 3)', 'gaming-hub' ),
			'priority' => 37,
		)
	);

	$wp_customize->add_setting(
		'tesla_client_id',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'tesla_client_id',
		array(
			'label'       => __( 'Client ID', 'gaming-hub' ),
			'description' => __( 'developer.tesla.com のアプリ Client ID（.env の TESLA_CLIENT_ID でも可）', 'gaming-hub' ),
			'section'     => 'gaming_hub_tesla_api',
			'type'        => 'text',
		)
	);

	$wp_customize->add_setting(
		'tesla_client_secret',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'tesla_client_secret',
		array(
			'label'   => __( 'Client Secret', 'gaming-hub' ),
			'section' => 'gaming_hub_tesla_api',
			'type'    => 'password',
		)
	);

	$wp_customize->add_setting(
		'tesla_vehicle_vin',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'tesla_vehicle_vin',
		array(
			'label'       => __( 'Model 3 VIN', 'gaming-hub' ),
			'description' => __( '車両識別番号（17桁）', 'gaming-hub' ),
			'section'     => 'gaming_hub_tesla_api',
			'type'        => 'text',
		)
	);

	$wp_customize->add_setting(
		'tesla_refresh_token',
		array(
			'default'           => '',
			'sanitize_callback' => 'sanitize_text_field',
		)
	);
	$wp_customize->add_control(
		'tesla_refresh_token',
		array(
			'label'       => __( 'Refresh Token', 'gaming-hub' ),
			'description' => __( 'Tesla タグの「Tesla で認証」後に自動保存。手動設定も可。', 'gaming-hub' ),
			'section'     => 'gaming_hub_tesla_api',
			'type'        => 'password',
		)
	);

	$wp_customize->add_setting(
		'tesla_home_lat',
		array(
			'default'           => 35.3409,
			'sanitize_callback' => static function ( $value ) {
				return is_numeric( $value ) ? (float) $value : 35.3409;
			},
		)
	);
	$wp_customize->add_control(
		'tesla_home_lat',
		array(
			'label'       => __( '自宅 緯度（AI PLAN ジオフェンス）', 'gaming-hub' ),
			'description' => __( 'デフォルト: 多治見市脇之島町6-47-4。vehicle_location スコープが必要です。', 'gaming-hub' ),
			'section'     => 'gaming_hub_tesla_api',
			'type'        => 'number',
			'input_attrs' => array(
				'step' => '0.000001',
			),
		)
	);

	$wp_customize->add_setting(
		'tesla_home_lon',
		array(
			'default'           => 137.1264,
			'sanitize_callback' => static function ( $value ) {
				return is_numeric( $value ) ? (float) $value : 137.1264;
			},
		)
	);
	$wp_customize->add_control(
		'tesla_home_lon',
		array(
			'label'   => __( '自宅 経度（AI PLAN ジオフェンス）', 'gaming-hub' ),
			'section' => 'gaming_hub_tesla_api',
			'type'    => 'number',
			'input_attrs' => array(
				'step' => '0.000001',
			),
		)
	);

	$wp_customize->add_setting(
		'tesla_home_radius_m',
		array(
			'default'           => 200,
			'sanitize_callback' => static function ( $value ) {
				return max( 50, (int) $value );
			},
		)
	);
	$wp_customize->add_control(
		'tesla_home_radius_m',
		array(
			'label'   => __( '自宅 半径（m）', 'gaming-hub' ),
			'section' => 'gaming_hub_tesla_api',
			'type'    => 'number',
			'input_attrs' => array(
				'min'  => 50,
				'max'  => 1000,
				'step' => 10,
			),
		)
	);
}
add_action( 'customize_register', 'gaming_hub_customize_register_tesla' );

function gaming_hub_fallback_menu() {
	$items = array(
		array( 'url' => gaming_hub_ecoflow_url(), 'label' => __( 'EcoFlow', 'gaming-hub' ), 'current' => is_tag( 'ecoflow' ) ),
		array( 'url' => gaming_hub_tesla_url(), 'label' => __( 'Tesla', 'gaming-hub' ), 'current' => is_tag( 'tesla' ) ),
		array( 'url' => gaming_hub_pokemon_go_url(), 'label' => __( 'Pokémon GO', 'gaming-hub' ), 'current' => is_tag( 'pokemon-go' ) ),
	);

	echo '<ul class="nav-menu">';
	foreach ( $items as $item ) {
		$li = $item['current'] ? ' class="current-menu-item"' : '';
		echo '<li' . $li . '><a href="' . esc_url( $item['url'] ) . '">' . esc_html( $item['label'] ) . '</a></li>';
	}
	echo '</ul>';
}

/**
 * Category slugs hidden from navigation (Reviews / News / Guides).
 *
 * @return array<int, string>
 */
function gaming_hub_hidden_nav_category_slugs() {
	return array( 'reviews', 'news', 'guides' );
}

/**
 * Remove Reviews / News / Guides items from WordPress menus.
 *
 * @param array<int, WP_Post> $items Menu items.
 * @return array<int, WP_Post>
 */
function gaming_hub_hide_nav_categories( $items ) {
	$hidden = gaming_hub_hidden_nav_category_slugs();

	return array_values(
		array_filter(
			$items,
			static function ( $item ) use ( $hidden ) {
				$slug = '';

				if ( 'category' === ( $item->object ?? '' ) && ! empty( $item->object_id ) ) {
					$term = get_term( (int) $item->object_id, 'category' );
					if ( $term && ! is_wp_error( $term ) ) {
						$slug = (string) $term->slug;
					}
				}

				$title = strtolower( trim( (string) ( $item->title ?? '' ) ) );
				$url   = strtolower( (string) ( $item->url ?? '' ) );

				if ( 'looop' === $title || false !== strpos( $url, '/tag/looop' ) ) {
					return false;
				}

				if ( in_array( $title, array( 'home', 'ホーム', 'energy', '発電ログ' ), true )
					|| false !== strpos( $url, '/tag/energy' ) ) {
					return false;
				}

				$path = untrailingslashit( (string) wp_parse_url( $url, PHP_URL_PATH ) );
				$hash = (string) wp_parse_url( $url, PHP_URL_FRAGMENT );
				if ( ( '' === $path || '/' === $path ) && '' === $hash && in_array( $title, array( 'home', 'ホーム', '' ), true ) ) {
					return false;
				}

				if ( in_array( $slug, $hidden, true ) || in_array( $title, $hidden, true ) ) {
					return false;
				}

				foreach ( $hidden as $hidden_slug ) {
					if ( false !== strpos( $url, '/category/' . $hidden_slug ) ) {
						return false;
					}
				}

				return true;
			}
		)
	);
}
add_filter( 'wp_nav_menu_objects', 'gaming_hub_hide_nav_categories' );

/**
 * Point menu items at the tag screens (EcoFlow / Tesla / Pokémon GO).
 *
 * @param array<int, WP_Post> $items Menu items.
 * @return array<int, WP_Post>
 */
function gaming_hub_nav_hub_section_urls( $items ) {
	foreach ( $items as $item ) {
		$url     = (string) ( $item->url ?? '' );
		$section = gaming_hub_url_hub_section( $url );
		$title   = strtolower( trim( (string) ( $item->title ?? '' ) ) );

		if ( 'energy' === $section ) {
			$item->url = function_exists( 'gaming_hub_energy_url' )
				? gaming_hub_energy_url()
				: gaming_hub_tag_url( 'ecoflow' ) . '#energy';
			continue;
		}

		if ( 'ecoflow' === $section || 'ecoflow' === $title ) {
			$item->url = function_exists( 'gaming_hub_ecoflow_url' )
				? gaming_hub_ecoflow_url()
				: gaming_hub_tag_url( 'ecoflow' );
			continue;
		}

		if ( in_array( $section, array( 'tesla', 'powerwall' ), true )
			|| false !== strpos( $url, '/powerwall' )
			|| false !== stripos( (string) ( $item->title ?? '' ), 'powerwall' )
			|| 'tesla' === $title ) {
			$item->url = function_exists( 'gaming_hub_tesla_url' )
				? gaming_hub_tesla_url()
				: gaming_hub_tag_url( 'tesla' );
			if ( false !== stripos( (string) ( $item->title ?? '' ), 'powerwall' ) ) {
				$item->title = 'Tesla';
			}
			continue;
		}

		if ( 'pokemon-go' === $section
			|| ( false !== strpos( $url, '/pokemon-go' )
				&& false === strpos( $url, 'raid' )
				&& false === strpos( $url, 'tokushuu' )
				&& false === strpos( $url, 'event' ) ) ) {
			$item->url = function_exists( 'gaming_hub_pokemon_go_url' )
				? gaming_hub_pokemon_go_url()
				: gaming_hub_tag_url( 'pokemon-go' );
		}
	}

	return $items;
}
add_filter( 'wp_nav_menu_objects', 'gaming_hub_nav_hub_section_urls' );

/**
 * Mark the active tag in the rewritten WordPress menu.
 *
 * @param array<int, string> $classes Menu item classes.
 * @param WP_Post            $item    Menu item.
 * @return array<int, string>
 */
function gaming_hub_nav_current_tag_class( $classes, $item ) {
	$url = (string) ( $item->url ?? '' );
	if ( ( is_tag( 'ecoflow' ) && false !== strpos( $url, '/tag/ecoflow' ) )
		|| ( is_tag( 'tesla' ) && false !== strpos( $url, '/tag/tesla' ) )
		|| ( is_tag( 'pokemon-go' ) && false !== strpos( $url, '/tag/pokemon-go' ) ) ) {
		$classes[] = 'current-menu-item';
	}

	return $classes;
}
add_filter( 'nav_menu_css_class', 'gaming_hub_nav_current_tag_class', 10, 2 );
