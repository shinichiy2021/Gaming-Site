<?php
/**
 * JA / EN language switcher — WordPress standard locale + theme textdomain.
 *
 * English msgids in PHP; Japanese via languages/gaming-hub-ja.mo.
 * Browser JS uses inc/i18n-ja.php via gamingHubT().
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_LANG_COOKIE', 'gaming_hub_lang' );
define( 'GAMING_HUB_TEXT_DOMAIN', 'gaming-hub' );
define( 'GAMING_HUB_LOCALE_EN', 'en_US' );
define( 'GAMING_HUB_LOCALE_JA', 'ja' );

/**
 * Active public language code: ja or en.
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
 * Front-end locale follows the JA/EN switcher. wp-admin stays on the site language.
 *
 * @param string $locale Current locale.
 */
function gaming_hub_filter_locale( $locale ) {
	if ( is_admin() && ! wp_doing_ajax() ) {
		return $locale;
	}

	return 'en' === gaming_hub_lang() ? GAMING_HUB_LOCALE_EN : GAMING_HUB_LOCALE_JA;
}
add_filter( 'locale', 'gaming_hub_filter_locale', 1 );
add_filter( 'determine_locale', 'gaming_hub_filter_locale', 1 );

/**
 * Load theme translations after locale is resolved (WordPress standard).
 */
function gaming_hub_load_textdomain() {
	load_theme_textdomain( GAMING_HUB_TEXT_DOMAIN, get_template_directory() . '/languages' );
}
add_action( 'after_setup_theme', 'gaming_hub_load_textdomain' );

/**
 * Locale-aware yen amount: "1,234円" for ja, "¥1,234" for en.
 *
 * @param float|int $value    Amount in yen.
 * @param int       $decimals Decimal places.
 * @return string
 */
function gaming_hub_yen( $value, $decimals = 0 ) {
	$amount = number_format_i18n( (float) $value, $decimals );

	return 'en' === gaming_hub_lang() ? '¥' . $amount : $amount . '円';
}

/**
 * Force front-end date formats so JA/EN never inherit mixed WP Settings
 * (e.g. "September 2, 2026" + "5:52 pm" vs slash-style logs).
 *
 * JA: 2026年9月8日 (火)
 * EN: Sep 8, 2026 (Tue)
 *
 * @param string $format Stored format.
 * @return string
 */
function gaming_hub_ja_date_format( $format ) {
	if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $format;
	}

	return 'en' === gaming_hub_lang() ? 'M j, Y (D)' : 'Y年n月j日 (D)';
}
add_filter( 'option_date_format', 'gaming_hub_ja_date_format' );

/**
 * Front-end time format companion — always 24-hour on the public site.
 *
 * @param string $format Stored format.
 * @return string
 */
function gaming_hub_ja_time_format( $format ) {
	if ( is_admin() && ! wp_doing_ajax() && ! ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
		return $format;
	}

	return 'H:i';
}
add_filter( 'option_time_format', 'gaming_hub_ja_time_format' );

/**
 * Short date format for compact lists (charge log, week chips).
 *
 * @return string PHP date format.
 */
function gaming_hub_date_format_short() {
	return 'en' === gaming_hub_lang() ? 'M j (D)' : 'n月j日 (D)';
}

/**
 * Month label format (driving log header, calendars).
 *
 * @return string PHP date format.
 */
function gaming_hub_date_format_month() {
	return 'en' === gaming_hub_lang() ? 'M Y' : 'Y年n月';
}

/**
 * Format a timestamp for the front end.
 *
 * @param int|null $timestamp Unix timestamp (site timezone via wp_date). Null = now.
 * @param string   $style     full|short|month|time|datetime|datetime_short.
 * @return string
 */
function gaming_hub_format_date( $timestamp = null, $style = 'full' ) {
	$ts = null === $timestamp ? time() : (int) $timestamp;
	if ( $ts <= 0 ) {
		return '';
	}

	switch ( $style ) {
		case 'time':
			return wp_date( get_option( 'time_format' ), $ts );
		case 'short':
			return wp_date( gaming_hub_date_format_short(), $ts );
		case 'month':
			return wp_date( gaming_hub_date_format_month(), $ts );
		case 'datetime_short':
			return wp_date( gaming_hub_date_format_short() . ' ' . get_option( 'time_format' ), $ts );
		case 'datetime':
			return wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $ts );
		case 'full':
		default:
			return wp_date( get_option( 'date_format' ), $ts );
	}
}

/**
 * Format a start/end range for charge sessions and similar lists.
 *
 * @param int $start_ts Start timestamp.
 * @param int $end_ts   End timestamp (0 = open-ended).
 * @return string
 */
function gaming_hub_format_datetime_range( $start_ts, $end_ts = 0 ) {
	$start_ts = (int) $start_ts;
	$end_ts   = (int) $end_ts;
	if ( $start_ts <= 0 ) {
		return '';
	}

	$when = gaming_hub_format_date( $start_ts, 'datetime_short' );
	if ( $end_ts <= $start_ts ) {
		return $when;
	}

	$same_day = gaming_hub_format_date( $start_ts, 'full' ) === gaming_hub_format_date( $end_ts, 'full' );
	if ( $same_day ) {
		return $when . '–' . gaming_hub_format_date( $end_ts, 'time' );
	}

	return $when . '–' . gaming_hub_format_date( $end_ts, 'datetime_short' );
}

/**
 * Japanese DB strings (menus, site title) → English when lang=en.
 *
 * Built from i18n-en.php via scripts/build-i18n-standard.py.
 *
 * @return array<string, string>
 */
function gaming_hub_db_en_map() {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$file = get_template_directory() . '/inc/i18n-db-en.php';
	$map  = is_readable( $file ) ? include $file : array();
	if ( ! is_array( $map ) ) {
		$map = array();
	}

	return $map;
}

/**
 * English msgid → Japanese for client-side gamingHubT().
 *
 * @return array<string, string>
 */
function gaming_hub_japanese_map() {
	static $map = null;

	if ( null !== $map ) {
		return $map;
	}

	$file = get_template_directory() . '/inc/i18n-ja.php';
	$map  = is_readable( $file ) ? include $file : array();
	if ( ! is_array( $map ) ) {
		$map = array();
	}

	return $map;
}

/**
 * Fill JA gaps for theme msgids not yet compiled into ja.mo.
 *
 * @param string $translation Translated text from gettext.
 * @param string $text        Original msgid.
 * @param string $domain      Text domain.
 * @return string
 */
function gaming_hub_gettext_fill_ja( $translation, $text, $domain ) {
	if ( GAMING_HUB_TEXT_DOMAIN !== $domain || 'ja' !== gaming_hub_lang() || $translation !== $text ) {
		return $translation;
	}

	$map = gaming_hub_japanese_map();

	return isset( $map[ $text ] ) ? $map[ $text ] : $translation;
}
add_filter( 'gettext', 'gaming_hub_gettext_fill_ja', 10, 3 );

/**
 * Japanese strings stored in the DB → English when lang=en.
 *
 * @param string $text Stored value.
 */
function gaming_hub_translate_db_string( $text ) {
	if ( 'en' !== gaming_hub_lang() || '' === $text ) {
		return $text;
	}

	$map = gaming_hub_db_en_map();

	return isset( $map[ $text ] ) ? $map[ $text ] : $text;
}

/**
 * Translate nav labels stored in the database.
 *
 * @param string $title Menu title.
 */
function gaming_hub_translate_menu_title( $title ) {
	return gaming_hub_translate_db_string( $title );
}
add_filter( 'nav_menu_item_title', 'gaming_hub_translate_menu_title' );

/**
 * Translate site title / tagline when lang=en.
 *
 * @param string $output Bloginfo value.
 * @param string $show   Field name.
 */
function gaming_hub_translate_bloginfo( $output, $show ) {
	if ( ! in_array( $show, array( 'name', 'description' ), true ) ) {
		return $output;
	}

	return gaming_hub_translate_db_string( $output );
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
 * Enqueue the JS helper that reuses inc/i18n-ja.php.
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
			'ja'   => 'ja' === gaming_hub_lang() ? gaming_hub_japanese_map() : (object) array(),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_i18n_scripts', 5 );
