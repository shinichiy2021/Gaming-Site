<?php
/**
 * Affiliate links + EcoFlow kit (real metrics + purchase CTAs).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Default destination URLs (official EcoFlow JP). Replace via Customizer with affiliate URLs.
 *
 * @return array<string, string>
 */
function gaming_hub_affiliate_defaults() {
	return array(
		'ecoflow_home'        => 'https://jp.ecoflow.com/',
		'ecoflow_blog'        => 'https://jp.ecoflow.com/pages/blog',
		'ecoflow_delta_pro3'  => 'https://jp.ecoflow.com/products/delta-pro-3',
		'ecoflow_delta_1500'  => 'https://jp.ecoflow.com/products/delta-3-1500-portable-power-station',
		'ecoflow_solar'       => 'https://jp.ecoflow.com/collections/solar-panels',
		'amazon_delta_pro3'   => '',
		'amazon_delta_1500'   => '',
		'tesla_home'          => 'https://ts.la/shinichi831753',
		'tesla_model3'        => 'https://ts.la/shinichi831753',
		'tesla_charging'      => 'https://www.tesla.com/ja_jp/charging',
		'tesla_powerwall'     => 'https://www.tesla.com/ja_jp/powerwall',
		'amazon_tesla_model3' => '',
		'amazon_tesla_charge' => '',
	);
}

/**
 * Resolve an affiliate / outbound URL by key.
 *
 * @param string $key Link key.
 * @return string
 */
function gaming_hub_affiliate_url( $key ) {
	$key      = sanitize_key( (string) $key );
	$defaults = gaming_hub_affiliate_defaults();
	if ( ! isset( $defaults[ $key ] ) ) {
		return '';
	}

	$stored = trim( (string) get_theme_mod( 'affiliate_' . $key, $defaults[ $key ] ) );
	if ( '' === $stored ) {
		return (string) $defaults[ $key ];
	}

	return esc_url_raw( $stored );
}

/**
 * Rel attribute for outbound affiliate / commercial links.
 *
 * @return string
 */
function gaming_hub_affiliate_rel() {
	return 'sponsored noopener noreferrer';
}

/**
 * Whether EcoFlow A8 banner (Dabson etc.) should render.
 */
function gaming_hub_affiliate_ecoflow_a8_banner_enabled() {
	return (bool) get_theme_mod( 'affiliate_ecoflow_a8_banner_enabled', true );
}

/**
 * Render EcoFlow A8 300x250 banner markup.
 */
function gaming_hub_render_ecoflow_a8_banner() {
	if ( ! gaming_hub_affiliate_ecoflow_a8_banner_enabled() ) {
		return;
	}

	$href = 'https://px.a8.net/svt/ejp?a8mat=4BAH9O+4M3YK2+5MSA+5YZ75';
	$img  = 'https://www25.a8.net/svt/bgt?aid=260829420279&wid=001&eno=01&mid=s00000026281001003000&mc=1';
	$pixel = 'https://www17.a8.net/0.gif?a8mat=4BAH9O+4M3YK2+5MSA+5YZ75';
	?>
	<div class="ecoflow-a8-banner" aria-label="<?php esc_attr_e( '広告', 'gaming-hub' ); ?>">
		<p class="ecoflow-a8-banner-label"><?php esc_html_e( 'PR', 'gaming-hub' ); ?></p>
		<a href="<?php echo esc_url( $href ); ?>" target="_blank" rel="sponsored nofollow noopener noreferrer">
			<img src="<?php echo esc_url( $img ); ?>" width="300" height="250" alt="<?php esc_attr_e( 'A8.net 広告バナー', 'gaming-hub' ); ?>" loading="lazy" decoding="async" />
		</a>
		<img src="<?php echo esc_url( $pixel ); ?>" width="1" height="1" alt="" loading="lazy" decoding="async" />
	</div>
	<?php
}

/**
 * Whether the EcoFlow kit block should render.
 */
function gaming_hub_affiliate_kit_enabled() {
	return (bool) get_theme_mod( 'affiliate_kit_enabled', true );
}

/**
 * Whether the Tesla kit block should render.
 */
function gaming_hub_affiliate_tesla_kit_enabled() {
	return (bool) get_theme_mod( 'affiliate_tesla_kit_enabled', true );
}

/**
 * Kit products tied to live EcoFlow setup.
 *
 * @return array<int, array<string, string>>
 */
function gaming_hub_affiliate_ecoflow_kit_items() {
	$items = array(
		array(
			'name'    => 'DELTA Pro 3',
			'role'    => __( 'ハイボルト発電・リビングエアコン他の主電源', 'gaming-hub' ),
			'primary' => 'ecoflow_delta_pro3',
			'amazon'  => 'amazon_delta_pro3',
		),
		array(
			'name'    => 'DELTA 3 1500',
			'role'    => __( 'UPS・補充電・Low Volt 連携', 'gaming-hub' ),
			'primary' => 'ecoflow_delta_1500',
			'amazon'  => 'amazon_delta_1500',
		),
		array(
			'name'    => __( 'ソーラーパネル', 'gaming-hub' ),
			'role'    => __( '発電ログの入力源', 'gaming-hub' ),
			'primary' => 'ecoflow_solar',
			'amazon'  => '',
		),
	);

	/**
	 * Filter EcoFlow kit rows.
	 *
	 * @param array<int, array<string, string>> $items Kit items.
	 */
	return apply_filters( 'gaming_hub_affiliate_ecoflow_kit_items', $items );
}

/**
 * Kit products tied to live Tesla / Model 3 setup.
 *
 * @return array<int, array<string, string>>
 */
function gaming_hub_affiliate_tesla_kit_items() {
	$items = array(
		array(
			'name'    => 'Model 3',
			'role'    => __( '走行ログ・充電ログで実測中の車両', 'gaming-hub' ),
			'primary' => 'tesla_model3',
			'amazon'  => 'amazon_tesla_model3',
		),
		array(
			'name'    => __( '充電（Wall Connector など）', 'gaming-hub' ),
			'role'    => __( '自宅・外出先の充電まわり', 'gaming-hub' ),
			'primary' => 'tesla_charging',
			'amazon'  => 'amazon_tesla_charge',
		),
		array(
			'name'    => 'Powerwall',
			'role'    => __( '家庭用蓄電（関連ダッシュボードあり）', 'gaming-hub' ),
			'primary' => 'tesla_powerwall',
			'amazon'  => '',
		),
	);

	/**
	 * Filter Tesla kit rows.
	 *
	 * @param array<int, array<string, string>> $items Kit items.
	 */
	return apply_filters( 'gaming_hub_affiliate_tesla_kit_items', $items );
}

/**
 * This month's PV / savings for the kit headline.
 *
 * @return array{month:string,label:string,solar_kwh:float,saved_yen:float}
 */
function gaming_hub_affiliate_ecoflow_month_stats() {
	$ym    = wp_date( 'Y-m' );
	$label = wp_date( 'Y年n月' );
	$solar = 0.0;
	$yen   = 0.0;

	if ( function_exists( 'gaming_hub_ecoflow_energy_month_payload' ) ) {
		$payload = gaming_hub_ecoflow_energy_month_payload( $ym );
		$totals  = is_array( $payload['totals'] ?? null ) ? $payload['totals'] : array();
		$solar   = (float) ( $totals['solar_kwh'] ?? 0 );
		$yen     = (float) ( $totals['saved_yen'] ?? 0 );
		if ( ! empty( $payload['label'] ) ) {
			$label = (string) $payload['label'];
		}
	}

	return array(
		'month'      => $ym,
		'label'      => $label,
		'solar_kwh'  => $solar,
		'saved_yen'  => $yen,
	);
}

/**
 * This month's driving km / gasoline-vs-EV savings for the Tesla kit.
 *
 * @return array{month:string,label:string,km:float,saved_yen:float}
 */
function gaming_hub_affiliate_tesla_month_stats() {
	$ym    = wp_date( 'Y-m' );
	$label = wp_date( 'Y年n月' );
	$km    = 0.0;
	$yen   = 0.0;

	if ( function_exists( 'gaming_hub_tesla_gas_month_payload' ) ) {
		$payload = gaming_hub_tesla_gas_month_payload( $ym );
		$totals  = is_array( $payload['totals'] ?? null ) ? $payload['totals'] : array();
		$km      = (float) ( $totals['km'] ?? 0 );
		$yen     = (float) ( $totals['saved_yen'] ?? 0 );
		if ( ! empty( $payload['label'] ) ) {
			$label = (string) $payload['label'];
		} elseif ( ! empty( $payload['month'] ) ) {
			$ts = strtotime( (string) $payload['month'] . '-01 00:00:00' );
			if ( $ts ) {
				$label = wp_date( 'Y年n月', $ts );
			}
		}
	}

	return array(
		'month'     => $ym,
		'label'     => $label,
		'km'        => $km,
		'saved_yen' => $yen,
	);
}

/**
 * Customizer: affiliate destinations.
 *
 * @param WP_Customize_Manager $wp_customize Manager.
 */
function gaming_hub_customize_register_affiliate( $wp_customize ) {
	$wp_customize->add_section(
		'gaming_hub_affiliate',
		array(
			'title'       => __( 'Affiliate / 実測キット', 'gaming-hub' ),
			'description' => __( 'A8・Amazon・メーカー公式アフィのURLを貼ると、EcoFlow / Tesla の「うちの実測構成」と公式ボタンに反映されます。空欄は公式直リンクのままです。', 'gaming-hub' ),
			'priority'    => 38,
		)
	);

	$wp_customize->add_setting(
		'affiliate_kit_enabled',
		array(
			'default'           => true,
			'sanitize_callback' => static function ( $value ) {
				return (bool) $value;
			},
		)
	);
	$wp_customize->add_control(
		'affiliate_kit_enabled',
		array(
			'label'   => __( 'EcoFlow「うちの実測構成」を表示', 'gaming-hub' ),
			'section' => 'gaming_hub_affiliate',
			'type'    => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'affiliate_ecoflow_a8_banner_enabled',
		array(
			'default'           => true,
			'sanitize_callback' => static function ( $value ) {
				return (bool) $value;
			},
		)
	);
	$wp_customize->add_control(
		'affiliate_ecoflow_a8_banner_enabled',
		array(
			'label'   => __( 'EcoFlow A8バナー（300×250）を表示', 'gaming-hub' ),
			'section' => 'gaming_hub_affiliate',
			'type'    => 'checkbox',
		)
	);

	$wp_customize->add_setting(
		'affiliate_tesla_kit_enabled',
		array(
			'default'           => true,
			'sanitize_callback' => static function ( $value ) {
				return (bool) $value;
			},
		)
	);
	$wp_customize->add_control(
		'affiliate_tesla_kit_enabled',
		array(
			'label'   => __( 'Tesla「うちの実測構成」を表示', 'gaming-hub' ),
			'section' => 'gaming_hub_affiliate',
			'type'    => 'checkbox',
		)
	);

	$fields = array(
		'ecoflow_home'        => __( 'EcoFlow 公式トップ URL', 'gaming-hub' ),
		'ecoflow_blog'        => __( 'EcoFlow 公式ブログ URL', 'gaming-hub' ),
		'ecoflow_delta_pro3'  => __( 'DELTA Pro 3 URL（公式 or アフィ）', 'gaming-hub' ),
		'ecoflow_delta_1500'  => __( 'DELTA 3 1500 URL（公式 or アフィ）', 'gaming-hub' ),
		'ecoflow_solar'       => __( 'ソーラーパネル URL', 'gaming-hub' ),
		'amazon_delta_pro3'   => __( 'DELTA Pro 3 Amazonアフィ URL（任意）', 'gaming-hub' ),
		'amazon_delta_1500'   => __( 'DELTA 3 1500 Amazonアフィ URL（任意）', 'gaming-hub' ),
		'tesla_home'          => __( 'Tesla 公式トップ URL', 'gaming-hub' ),
		'tesla_model3'        => __( 'Model 3 URL（公式 or A8）', 'gaming-hub' ),
		'tesla_charging'      => __( 'Tesla 充電ページ URL', 'gaming-hub' ),
		'tesla_powerwall'     => __( 'Powerwall URL', 'gaming-hub' ),
		'amazon_tesla_model3' => __( 'Model 3 関連 Amazonアフィ URL（任意）', 'gaming-hub' ),
		'amazon_tesla_charge' => __( '充電関連 Amazonアフィ URL（任意）', 'gaming-hub' ),
	);

	$defaults = gaming_hub_affiliate_defaults();
	foreach ( $fields as $key => $label ) {
		$setting = 'affiliate_' . $key;
		$wp_customize->add_setting(
			$setting,
			array(
				'default'           => $defaults[ $key ],
				'sanitize_callback' => 'esc_url_raw',
			)
		);
		$wp_customize->add_control(
			$setting,
			array(
				'label'   => $label,
				'section' => 'gaming_hub_affiliate',
				'type'    => 'url',
			)
		);
	}
}
add_action( 'customize_register', 'gaming_hub_customize_register_affiliate' );

/**
 * Persist Tesla referral link into theme mods (Customizer + front-end).
 */
function gaming_hub_ensure_tesla_referral_link() {
	if ( get_option( 'gaming_hub_tesla_referral_v1' ) ) {
		return;
	}

	$url = 'https://ts.la/shinichi831753';
	set_theme_mod( 'affiliate_tesla_home', $url );
	set_theme_mod( 'affiliate_tesla_model3', $url );
	update_option( 'gaming_hub_tesla_referral_v1', 1 );
}
add_action( 'init', 'gaming_hub_ensure_tesla_referral_link', 3 );

/**
 * Shortcode for posts: [ecoflow_kit]
 *
 * @return string
 */
function gaming_hub_affiliate_ecoflow_kit_shortcode() {
	ob_start();
	get_template_part( 'template-parts/ecoflow', 'kit' );
	return (string) ob_get_clean();
}
add_shortcode( 'ecoflow_kit', 'gaming_hub_affiliate_ecoflow_kit_shortcode' );

/**
 * Shortcode for posts: [tesla_kit]
 *
 * @return string
 */
function gaming_hub_affiliate_tesla_kit_shortcode() {
	ob_start();
	get_template_part( 'template-parts/tesla', 'kit' );
	return (string) ob_get_clean();
}
add_shortcode( 'tesla_kit', 'gaming_hub_affiliate_tesla_kit_shortcode' );
