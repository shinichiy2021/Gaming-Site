<?php
/**
 * JA / EN language switcher for the public site.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_LANG_COOKIE', 'gaming_hub_lang' );

/**
 * Active public language: ja or en.
 */
function gaming_hub_lang() {
	static $lang = null;

	if ( null !== $lang ) {
		return $lang;
	}

	$requested = '';
	if ( isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$requested = sanitize_key( wp_unslash( $_GET['lang'] ) );
	} elseif ( ! empty( $_COOKIE[ GAMING_HUB_LANG_COOKIE ] ) ) {
		$requested = sanitize_key( wp_unslash( $_COOKIE[ GAMING_HUB_LANG_COOKIE ] ) );
	}

	$lang = in_array( $requested, array( 'en', 'en_us', 'en-us' ), true ) ? 'en' : 'ja';

	return $lang;
}

/**
 * Persist ?lang= and drop it from the URL on HTML requests.
 */
function gaming_hub_persist_lang() {
	if ( ! isset( $_GET['lang'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		return;
	}

	$lang   = gaming_hub_lang();
	$path   = defined( 'COOKIEPATH' ) && COOKIEPATH ? COOKIEPATH : '/';
	$domain = defined( 'COOKIE_DOMAIN' ) ? COOKIE_DOMAIN : '';
	setcookie( GAMING_HUB_LANG_COOKIE, $lang, time() + YEAR_IN_SECONDS, $path, $domain, is_ssl(), true );
	$_COOKIE[ GAMING_HUB_LANG_COOKIE ] = $lang;

	if ( is_admin() || wp_doing_ajax() || wp_doing_cron() ) {
		return;
	}

	$uri = isset( $_SERVER['REQUEST_URI'] ) ? (string) wp_unslash( $_SERVER['REQUEST_URI'] ) : '';
	if ( false !== strpos( $uri, '/wp-json/' ) ) {
		return;
	}

	wp_safe_redirect( remove_query_arg( 'lang' ) );
	exit;
}
add_action( 'template_redirect', 'gaming_hub_persist_lang', 0 );

/**
 * Front-end locale follows the switcher. wp-admin stays on the site language.
 *
 * @param string $locale Current locale.
 */
function gaming_hub_filter_locale( $locale ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $locale;
	}

	return 'en' === gaming_hub_lang() ? 'en_US' : 'ja';
}
add_filter( 'locale', 'gaming_hub_filter_locale', 1 );
add_filter( 'determine_locale', 'gaming_hub_filter_locale', 1 );

/**
 * English map for Japanese source strings.
 *
 * @return array<string, string>
 */
function gaming_hub_english_map() {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$file = get_template_directory() . '/inc/i18n-en.php';
	$map  = is_readable( $file ) ? include $file : array();
	if ( ! is_array( $map ) ) {
		$map = array();
	}

	return $map;
}

/**
 * Translate gaming-hub strings when English is selected.
 *
 * @param string $translation Translated text.
 * @param string $text        Source text.
 * @param string $domain      Text domain.
 */
function gaming_hub_filter_gettext( $translation, $text, $domain ) {
	if ( 'gaming-hub' !== $domain || 'en' !== gaming_hub_lang() ) {
		return $translation;
	}

	$map = gaming_hub_english_map();
	return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
}
add_filter( 'gettext', 'gaming_hub_filter_gettext', 10, 3 );

/**
 * Context-aware gettext (same English map).
 *
 * @param string $translation Translated text.
 * @param string $text        Source text.
 * @param string $context     Context.
 * @param string $domain      Text domain.
 */
function gaming_hub_filter_gettext_with_context( $translation, $text, $context, $domain ) {
	return gaming_hub_filter_gettext( $translation, $text, $domain );
}
add_filter( 'gettext_with_context', 'gaming_hub_filter_gettext_with_context', 10, 4 );

/**
 * Translate nav labels stored in the database.
 *
 * @param string $title Menu title.
 */
function gaming_hub_translate_menu_title( $title ) {
	return __( $title, 'gaming-hub' );
}
add_filter( 'nav_menu_item_title', 'gaming_hub_translate_menu_title' );

/**
 * Translate site title / tagline when English is on.
 *
 * @param string $output Bloginfo value.
 * @param string $show   Field name.
 */
function gaming_hub_translate_bloginfo( $output, $show ) {
	if ( ! in_array( $show, array( 'name', 'description' ), true ) ) {
		return $output;
	}

	return __( $output, 'gaming-hub' );
}
add_filter( 'bloginfo', 'gaming_hub_translate_bloginfo', 10, 2 );

/**
 * Body class for the active language.
 *
 * @param array<int, string> $classes Body classes.
 * @return array<int, string>
 */
function gaming_hub_lang_body_class( $classes ) {
	$classes[] = 'lang-' . gaming_hub_lang();
	return $classes;
}
add_filter( 'body_class', 'gaming_hub_lang_body_class' );

/**
 * JA / EN toggle markup.
 */
function gaming_hub_language_switcher() {
	$lang = gaming_hub_lang();
	$ja   = esc_url( add_query_arg( 'lang', 'ja' ) );
	$en   = esc_url( add_query_arg( 'lang', 'en' ) );
	?>
	<nav class="lang-switch" aria-label="<?php esc_attr_e( 'Language', 'gaming-hub' ); ?>">
		<a href="<?php echo $ja; ?>" class="<?php echo 'ja' === $lang ? 'is-active' : ''; ?>" lang="ja" hreflang="ja">JA</a>
		<span class="lang-switch-sep" aria-hidden="true">/</span>
		<a href="<?php echo $en; ?>" class="<?php echo 'en' === $lang ? 'is-active' : ''; ?>" lang="en" hreflang="en">EN</a>
	</nav>
	<?php
}

/**
 * Enqueue the JS helper that reuses the same English map.
 */
function gaming_hub_i18n_scripts() {
	wp_enqueue_script(
		'gaming-hub-i18n',
		get_template_directory_uri() . '/assets/js/i18n.js',
		array(),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-i18n',
		'gamingHubI18n',
		array(
			'lang' => gaming_hub_lang(),
			'en'   => 'en' === gaming_hub_lang() ? gaming_hub_english_map() : (object) array(),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_i18n_scripts', 5 );
