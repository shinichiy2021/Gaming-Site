<?php
/**
 * Gaming Hub theme functions
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_VERSION', '1.3.7' );

require get_template_directory() . '/inc/pokemon-go.php';
require get_template_directory() . '/inc/pokemon-go-youtube.php';
require get_template_directory() . '/inc/ecoflow.php';
require get_template_directory() . '/inc/looop.php';
require get_template_directory() . '/inc/powerwall.php';

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
	return home_url( '/' );
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
			'reloadOnActive' => is_front_page()
				|| is_tag( array( 'ecoflow', 'looop' ) )
				|| is_page( array( 'pokemon-go', 'powerwall' ) ),
		)
	);

	wp_enqueue_script(
		'gaming-hub-main',
		get_template_directory_uri() . '/assets/js/main.js',
		array( 'gaming-hub-active-refresh' ),
		GAMING_HUB_VERSION,
		true
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
		'default'           => __( 'Level Up Your Gaming Experience', 'gaming-hub' ),
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'hero_title', array(
		'label'   => __( 'Hero Title', 'gaming-hub' ),
		'section' => 'gaming_hub_hero',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'hero_subtitle', array(
		'default'           => __( 'Latest reviews, news, and guides for gamers', 'gaming-hub' ),
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'hero_subtitle', array(
		'label'   => __( 'Hero Subtitle', 'gaming-hub' ),
		'section' => 'gaming_hub_hero',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'hero_cta_text', array(
		'default'           => __( 'Explore Reviews', 'gaming-hub' ),
		'sanitize_callback' => 'sanitize_text_field',
	) );
	$wp_customize->add_control( 'hero_cta_text', array(
		'label'   => __( 'Hero CTA Text', 'gaming-hub' ),
		'section' => 'gaming_hub_hero',
		'type'    => 'text',
	) );

	$wp_customize->add_setting( 'hero_cta_url', array(
		'default'           => '#reviews',
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
			'a'  => 'Asia (api-a.ecoflow.com)',
			'eu' => 'Europe (api-e.ecoflow.com)',
		),
	) );
}
add_action( 'customize_register', 'gaming_hub_customize_register' );

function gaming_hub_fallback_menu() {
	echo '<ul class="nav-menu">';
	echo '<li><a href="' . esc_url( home_url( '/' ) ) . '">' . esc_html__( 'Home', 'gaming-hub' ) . '</a></li>';
	echo '<li><a href="' . esc_url( home_url( '/category/reviews/' ) ) . '">' . esc_html__( 'Reviews', 'gaming-hub' ) . '</a></li>';
	echo '<li><a href="' . esc_url( home_url( '/category/news/' ) ) . '">' . esc_html__( 'News', 'gaming-hub' ) . '</a></li>';
	echo '<li><a href="' . esc_url( gaming_hub_pokemon_go_url() ) . '">' . esc_html__( 'Pokémon GO', 'gaming-hub' ) . '</a></li>';
	echo '<li><a href="' . esc_url( gaming_hub_ecoflow_url() ) . '">' . esc_html__( 'EcoFlow', 'gaming-hub' ) . '</a></li>';
	echo '<li><a href="' . esc_url( gaming_hub_looop_url() ) . '">' . esc_html__( 'LOOOP', 'gaming-hub' ) . '</a></li>';
	echo '<li><a href="' . esc_url( gaming_hub_powerwall_url() ) . '">' . esc_html__( 'Powerwall', 'gaming-hub' ) . '</a></li>';
	echo '<li><a href="' . esc_url( home_url( '/category/guides/' ) ) . '">' . esc_html__( 'Guides', 'gaming-hub' ) . '</a></li>';
	echo '</ul>';
}
