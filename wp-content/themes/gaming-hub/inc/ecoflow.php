<?php
/**
 * EcoFlow tag integration
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require get_template_directory() . '/inc/ecoflow-api.php';
require get_template_directory() . '/inc/ecoflow-app.php';

define( 'GAMING_HUB_ECOFLOW_TAG_SLUG', 'ecoflow' );
define( 'GAMING_HUB_ECOFLOW_STATUS_CACHE_KEY', 'gaming_hub_ecoflow_status_v4' );
define( 'GAMING_HUB_ECOFLOW_STATUS_CACHE_TTL', 5 );

/**
 * Register EcoFlow post tag on theme setup.
 */
function gaming_hub_setup_ecoflow_tag() {
	if ( get_option( 'gaming_hub_ecoflow_tag_created' ) ) {
		return;
	}

	if ( ! term_exists( GAMING_HUB_ECOFLOW_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'EcoFlow',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_ECOFLOW_TAG_SLUG,
				'description' => __( 'EcoFlow portable power and solar energy content', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_ecoflow_tag_created', 1 );
}
add_action( 'init', 'gaming_hub_setup_ecoflow_tag' );

/**
 * Get EcoFlow API credentials from env or theme mods.
 *
 * @return array<string, string>
 */
function gaming_hub_get_ecoflow_config() {
	return array(
		'access_key'  => getenv( 'ECOFLOW_ACCESS_KEY' ) ?: get_theme_mod( 'ecoflow_access_key', '' ),
		'secret_key'  => getenv( 'ECOFLOW_SECRET_KEY' ) ?: get_theme_mod( 'ecoflow_secret_key', '' ),
		'device_sn'   => getenv( 'ECOFLOW_DEVICE_SN' ) ?: get_theme_mod( 'ecoflow_device_sn', '' ),
		'device_sn_2' => getenv( 'ECOFLOW_DEVICE_SN_2' ) ?: get_theme_mod( 'ecoflow_device_sn_2', '' ),
		'region'      => getenv( 'ECOFLOW_API_REGION' ) ?: get_theme_mod( 'ecoflow_api_region', 'us' ),
	);
}

/**
 * Check if EcoFlow API is configured.
 */
function gaming_hub_ecoflow_is_configured() {
	$config = gaming_hub_get_ecoflow_config();
	return ! empty( $config['access_key'] ) && ! empty( $config['secret_key'] ) && ! empty( $config['device_sn'] );
}

/**
 * Get EcoFlow tag archive URL.
 */
function gaming_hub_ecoflow_url() {
	$link = get_tag_link( get_term_by( 'slug', GAMING_HUB_ECOFLOW_TAG_SLUG, 'post_tag' ) );
	return $link && ! is_wp_error( $link ) ? $link : home_url( '/tag/ecoflow/' );
}

/**
 * Fetch and normalize EcoFlow device status.
 *
 * @param bool $force_refresh Skip cache.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_get_ecoflow_status( $force_refresh = false ) {
	if ( ! gaming_hub_ecoflow_is_configured() ) {
		return new WP_Error(
			'ecoflow_not_configured',
			__( 'EcoFlow API が未設定です。', 'gaming-hub' )
		);
	}

	gaming_hub_ecoflow_sync_bridge_config();

	if ( ! $force_refresh ) {
		$cached = get_transient( GAMING_HUB_ECOFLOW_STATUS_CACHE_KEY );
		if ( false !== $cached && is_array( $cached ) ) {
			return $cached;
		}
	}

	$config = gaming_hub_get_ecoflow_config();
	$api    = new Gaming_Hub_Ecoflow_Api( $config['access_key'], $config['secret_key'], $config['region'] );

	$devices = $api->get_device_list();
	if ( is_wp_error( $devices ) ) {
		return $devices;
	}

	$primary = gaming_hub_fetch_ecoflow_device_status( $api, $devices, $config['device_sn'] );
	if ( is_wp_error( $primary ) ) {
		return $primary;
	}

	$status = $primary;

	if ( ! empty( $config['device_sn_2'] ) ) {
		$secondary = gaming_hub_fetch_ecoflow_device_status( $api, $devices, $config['device_sn_2'], $primary );
		if ( is_wp_error( $secondary ) ) {
			$status['secondary_error'] = $secondary->get_error_message();
		} else {
			$status['secondary'] = $secondary;
		}
	}

	set_transient( GAMING_HUB_ECOFLOW_STATUS_CACHE_KEY, $status, GAMING_HUB_ECOFLOW_STATUS_CACHE_TTL );

	return $status;
}

/**
 * Fetch one device quota and normalize it.
 *
 * @param Gaming_Hub_Ecoflow_Api     $api         API client.
 * @param array<int, mixed>          $devices     Device list from API.
 * @param string                     $device_sn   Device serial.
 * @param array<string, mixed>|null  $primary     Primary device status for inference fallback.
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_fetch_ecoflow_device_status( $api, $devices, $device_sn, $primary = null ) {
	$device_name = $device_sn;
	$online      = false;

	foreach ( $devices as $device ) {
		if ( isset( $device['sn'] ) && $device['sn'] === $device_sn ) {
			$device_name = isset( $device['productName'] ) ? $device['productName'] : $device_name;
			if ( empty( $device_name ) || $device_name === $device_sn ) {
				$device_name = isset( $device['deviceName'] ) ? $device['deviceName'] : $device_name;
			}
			$online = ! empty( $device['online'] );
			break;
		}
	}

	$bridge_quota = gaming_hub_ecoflow_read_bridge_quota( $device_sn );
	if ( is_array( $bridge_quota ) && ! empty( $bridge_quota ) ) {
		$parsed = gaming_hub_parse_ecoflow_quota( $bridge_quota, $device_sn, $device_name, $online );
		$parsed['source'] = 'mqtt';
		return $parsed;
	}

	$quota = $api->get_device_quota( $device_sn );
	if ( is_wp_error( $quota ) ) {
		if ( null !== $primary && gaming_hub_ecoflow_is_app_only_device( $device_sn ) ) {
			return gaming_hub_ecoflow_infer_secondary_from_primary(
				$primary,
				$device_sn,
				$device_name,
				$online,
				$quota->get_error_message()
			);
		}

		return $quota;
	}

	if ( empty( $quota ) && gaming_hub_ecoflow_is_app_only_device( $device_sn ) ) {
		if ( null !== $primary ) {
			return gaming_hub_ecoflow_infer_secondary_from_primary(
				$primary,
				$device_sn,
				$device_name,
				$online,
				__( 'Delta 3 系は Developer API 非対応', 'gaming-hub' )
			);
		}

		return new WP_Error(
			'ecoflow_app_only',
			__( 'Delta 3 系デバイスは Developer API に未対応です。ECOFLOW_APP_EMAIL / ECOFLOW_APP_PASSWORD を設定するか、Pro 3 と連携してください。', 'gaming-hub' )
		);
	}

	return gaming_hub_parse_ecoflow_quota( $quota, $device_sn, $device_name, $online );
}

/**
 * Parse quota map into dashboard metrics.
 *
 * @param array<string, mixed> $quota       Raw quota data.
 * @param string               $device_sn   Device serial.
 * @param string               $device_name Device name.
 * @param bool                 $online      Online flag.
 * @return array<string, mixed>
 */
function gaming_hub_parse_ecoflow_quota( $quota, $device_sn, $device_name, $online ) {
	$battery = gaming_hub_ecoflow_quota_value( $quota, array( 'cmsBattSoc', 'bmsBattSoc', 'pd.soc', 'bms_bmsStatus.soc', 'bms_emsStatus.lcdShowSoc' ) );
	$solar   = gaming_hub_ecoflow_sum_quota(
		$quota,
		array( 'powGetPvH', 'powGetPvL', 'mppt.inWatts', 'mppt.inWattsHV', 'mppt.inWattsLV', 'powGet.solar' ),
		true
	);
	$input   = gaming_hub_ecoflow_quota_value( $quota, array( 'powInSumW', 'pd.wattsInSum', 'inv.inputWatts', 'bms_bmsStatus.inputWatts' ) );
	$output  = gaming_hub_ecoflow_quota_value( $quota, array( 'powOutSumW', 'pd.wattsOutSum', 'inv.outputWatts', 'bms_bmsStatus.outputWatts' ) );
	$ac_in   = gaming_hub_ecoflow_abs_watts(
		gaming_hub_ecoflow_quota_value( $quota, array( 'powGetAcIn', 'inv.inputWatts', 'pd.acInWatts' ) )
	);
	$ac_out  = gaming_hub_ecoflow_sum_quota(
		$quota,
		array( 'powGetAcLvOut', 'powGetAcHvOut', 'powGetAcLvTt30Out', 'inv.outputWatts', 'pd.acOutWatts' ),
		true
	);
	$dc_out  = gaming_hub_ecoflow_sum_quota(
		$quota,
		array(
			'powGet12v',
			'powGet24v',
			'powGetTypec1',
			'powGetTypec2',
			'powGetQcusb1',
			'powGetQcusb2',
			'mppt.outWatts',
			'pd.dcOutWatts',
			'pd.carOutWatts',
		),
		true
	);
	$temp    = gaming_hub_ecoflow_quota_value(
		$quota,
		array( 'bmsMaxCellTemp', 'bmsMaxMosTemp', 'bms_bmsStatus.temp', 'bmsBattTemp', 'mppt.mpptTemp', 'inv.outTemp' )
	);
	$capacity = gaming_hub_ecoflow_quota_value( $quota, array( 'cmsBattFullEnergy', 'bmsDesignCap', 'bms_bmsStatus.designCap', 'pd.designCap' ) );
	$remain_cap = gaming_hub_ecoflow_quota_value( $quota, array( 'bms_bmsStatus.vol', 'pd.remainCap' ) );

	$chg_dsg_state = gaming_hub_ecoflow_chg_dsg_state( $quota );
	$is_charging   = gaming_hub_ecoflow_is_charging( $quota, $input, $output, $chg_dsg_state );
	$is_discharging = gaming_hub_ecoflow_is_discharging( $quota, $input, $output, $chg_dsg_state );
	$remain        = gaming_hub_ecoflow_remain_time( $quota, $is_charging, $is_discharging );

	if ( null === $input && null !== $solar ) {
		$input = $solar;
	}

	if ( null === $remain_cap && null !== $capacity && null !== $battery ) {
		$remain_cap = $capacity * ( $battery / 100 );
	}

	return array(
		'device_sn'       => $device_sn,
		'device_name'     => $device_name,
		'online'          => $online,
		'battery_percent' => null !== $battery ? max( 0, min( 100, (int) round( $battery ) ) ) : null,
		'solar_in'        => $solar,
		'input_total'     => $input,
		'output_total'    => $output,
		'ac_in'           => $ac_in,
		'ac_out'          => $ac_out,
		'dc_out'          => $dc_out,
		'battery_temp'    => $temp,
		'remain_time'     => null !== $remain ? (int) $remain : null,
		'remain_capacity' => $remain_cap ?: $capacity,
		'is_charging'     => $is_charging,
		'is_discharging'  => $is_discharging,
		'charge_state'    => gaming_hub_ecoflow_charge_state_label( $quota, $is_charging, $is_discharging, $input, $output, $chg_dsg_state ),
		'updated_at'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
	);
}

/**
 * Build initial payload for the React energy-flow diagram.
 *
 * @param array<string, mixed> $status Normalized device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_device_flow_slice( array $device ) {
	$remain_time = isset( $device['remain_time'] ) ? (int) $device['remain_time'] : 0;

	return array(
		'device_name'         => $device['device_name'] ?? '',
		'device_sn'           => $device['device_sn'] ?? '',
		'online'              => ! empty( $device['online'] ),
		'solar_in'            => (int) ( $device['solar_in'] ?? 0 ),
		'ac_in'               => (int) ( $device['ac_in'] ?? 0 ),
		'ac_out'              => (int) ( $device['ac_out'] ?? 0 ),
		'input_total'         => (int) ( $device['input_total'] ?? 0 ),
		'output_total'        => (int) ( $device['output_total'] ?? 0 ),
		'battery_percent'     => isset( $device['battery_percent'] ) && null !== $device['battery_percent']
			? (int) $device['battery_percent']
			: null,
		'is_charging'         => ! empty( $device['is_charging'] ),
		'is_discharging'      => ! empty( $device['is_discharging'] ),
		'charge_state'        => $device['charge_state'] ?? '',
		'remain_time'         => $remain_time,
		'remain_time_label'   => ! empty( $device['is_charging'] )
			? __( '満充電まで', 'gaming-hub' )
			: __( '残り使用時間', 'gaming-hub' ),
		'remain_time_display' => $remain_time > 0 ? gaming_hub_format_ecoflow_minutes( $remain_time ) : '—',
	);
}

/**
 * Estimate AC link watts between Delta Pro 3 output and Delta 3 1500 input.
 *
 * @param array<string, mixed> $primary   Primary device status.
 * @param array<string, mixed> $secondary Secondary device status.
 */
function gaming_hub_ecoflow_link_watts( array $primary, array $secondary ) {
	$pro_ac_out    = (int) ( $primary['ac_out'] ?? 0 );
	$delta_ac_in   = (int) ( $secondary['ac_in'] ?? 0 );
	$delta_charging = ! empty( $secondary['is_charging'] );

	if ( $pro_ac_out >= 8 && $delta_ac_in >= 8 ) {
		return min( $pro_ac_out, $delta_ac_in );
	}

	if ( $delta_ac_in >= 8 ) {
		return $delta_ac_in;
	}

	if ( $pro_ac_out >= 8 && $delta_charging ) {
		return $pro_ac_out;
	}

	return 0;
}

/**
 * Build initial payload for the React energy-flow diagram.
 *
 * @param array<string, mixed> $status Normalized device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_flow_payload( array $status ) {
	$pro = gaming_hub_ecoflow_device_flow_slice( $status );

	$payload = array(
		'dual'        => ! empty( $status['secondary'] ) && is_array( $status['secondary'] ),
		'solar_in'    => $pro['solar_in'],
		'grid_in'     => $pro['ac_in'],
		'ac_in'       => $pro['ac_in'],
		'pro'         => $pro,
		'battery_percent' => $pro['battery_percent'],
		'is_charging' => $pro['is_charging'],
		'charge_state' => $pro['charge_state'],
		'input_total' => $pro['input_total'],
		'output_total' => $pro['output_total'],
		'remain_time' => $pro['remain_time'],
		'remain_time_label' => $pro['remain_time_label'],
		'remain_time_display' => $pro['remain_time_display'],
	);

	if ( ! $payload['dual'] ) {
		return $payload;
	}

	$delta = gaming_hub_ecoflow_device_flow_slice( $status['secondary'] );
	$link  = gaming_hub_ecoflow_link_watts( $status, $status['secondary'] );

	$home_out = (int) ( $delta['output_total'] ?? 0 );
	if ( $home_out <= 0 ) {
		$home_out = max( 0, (int) ( $delta['ac_out'] ?? 0 ) + (int) ( $status['secondary']['dc_out'] ?? 0 ) );
	}

	$payload['delta']      = $delta;
	$payload['link_watts'] = $link;
	$payload['home_out']   = $home_out;

	return $payload;
}

/**
 * Sum numeric quota keys.
 *
 * @param array<string, mixed> $quota Quota map.
 * @param array<int, string>   $keys  Candidate keys.
 * @param bool                 $abs   Use absolute values.
 * @return float|null
 */
function gaming_hub_ecoflow_sum_quota( $quota, $keys, $abs = false ) {
	$sum   = 0.0;
	$found = false;

	foreach ( $keys as $key ) {
		if ( ! isset( $quota[ $key ] ) || ! is_numeric( $quota[ $key ] ) ) {
			continue;
		}

		$value = (float) $quota[ $key ];
		$sum  += $abs ? abs( $value ) : $value;
		$found = true;
	}

	return $found ? $sum : null;
}

/**
 * Normalize signed watt readings from Delta Pro 3 powGet fields.
 *
 * @param float|null $value Signed watts.
 * @return float|null
 */
function gaming_hub_ecoflow_abs_watts( $value ) {
	if ( null === $value ) {
		return null;
	}

	return abs( (float) $value );
}

/**
 * Get Delta Pro 3 charge/discharge state code.
 *
 * 0 = idle, 1 = discharging, 2 = charging.
 *
 * @param array<string, mixed> $quota Quota map.
 * @return int|null
 */
function gaming_hub_ecoflow_chg_dsg_state( $quota ) {
	$value = gaming_hub_ecoflow_quota_value(
		$quota,
		array( 'cmsChgDsgState', 'bmsChgDsgState', 'pd.chgDsgState', 'bms_emsStatus.sysChgDsgState', 'bms_bmsStatus.chgState' )
	);

	return null !== $value ? (int) $value : null;
}

/**
 * Resolve remaining time based on current mode.
 *
 * @param array<string, mixed> $quota Quota map.
 * @param bool                 $is_charging Whether the device is charging.
 * @param bool                 $is_discharging Whether the device is discharging.
 * @return float|null
 */
function gaming_hub_ecoflow_remain_time( $quota, $is_charging, $is_discharging ) {
	if ( $is_charging ) {
		return gaming_hub_ecoflow_quota_value( $quota, array( 'cmsChgRemTime', 'bmsChgRemTime', 'pd.remainTime', 'cms.chgRemainTime' ) );
	}

	if ( $is_discharging ) {
		return gaming_hub_ecoflow_quota_value( $quota, array( 'cmsDsgRemTime', 'bmsDsgRemTime', 'pd.remainTime', 'cms.dsgRemainTime' ) );
	}

	return gaming_hub_ecoflow_quota_value( $quota, array( 'pd.remainTime', 'cms.chgRemainTime', 'cms.dsgRemainTime', 'bms_bmsStatus.remainTime' ) );
}

/**
 * Get first available quota value.
 *
 * @param array<string, mixed> $quota Quota map.
 * @param array<int, string>   $keys  Candidate keys.
 * @return float|null
 */
function gaming_hub_ecoflow_quota_value( $quota, $keys ) {
	foreach ( $keys as $key ) {
		if ( isset( $quota[ $key ] ) && is_numeric( $quota[ $key ] ) ) {
			return (float) $quota[ $key ];
		}
	}

	return null;
}

/**
 * Detect charging state from quota.
 *
 * @param array<string, mixed> $quota Quota map.
 * @param float|null           $input  Input watts.
 * @param float|null           $output Output watts.
 * @param int|null             $chg_dsg_state Delta Pro 3 state code.
 */
function gaming_hub_ecoflow_is_charging( $quota, $input, $output, $chg_dsg_state = null ) {
	if ( null === $chg_dsg_state ) {
		$chg_dsg_state = gaming_hub_ecoflow_chg_dsg_state( $quota );
	}

	if ( null !== $chg_dsg_state ) {
		return 2 === (int) $chg_dsg_state;
	}

	$flags = array( 'pd.chgState', 'bms_bmsStatus.chgState', 'cms.chgState' );
	foreach ( $flags as $flag ) {
		if ( isset( $quota[ $flag ] ) ) {
			return (int) $quota[ $flag ] === 1;
		}
	}

	if ( null !== $input && null !== $output ) {
		return $input > $output && $input > 0;
	}

	return null !== $input && $input > 0 && ( null === $output || $output <= 0 );
}

/**
 * Detect discharging state from quota.
 *
 * @param array<string, mixed> $quota Quota map.
 * @param float|null           $input  Input watts.
 * @param float|null           $output Output watts.
 * @param int|null             $chg_dsg_state Delta Pro 3 state code.
 */
function gaming_hub_ecoflow_is_discharging( $quota, $input, $output, $chg_dsg_state = null ) {
	if ( null === $chg_dsg_state ) {
		$chg_dsg_state = gaming_hub_ecoflow_chg_dsg_state( $quota );
	}

	if ( null !== $chg_dsg_state ) {
		return 1 === (int) $chg_dsg_state;
	}

	return null !== $output && $output > 0 && ( null === $input || $input <= $output );
}

/**
 * Human-readable charge state.
 *
 * @param array<string, mixed> $quota Quota map.
 * @param bool                 $is_charging Charging flag.
 * @param bool                 $is_discharging Discharging flag.
 * @param float|null           $input       Input watts.
 * @param float|null           $output      Output watts.
 * @param int|null             $chg_dsg_state Delta Pro 3 state code.
 */
function gaming_hub_ecoflow_charge_state_label( $quota, $is_charging, $is_discharging, $input, $output, $chg_dsg_state = null ) {
	if ( null === $chg_dsg_state ) {
		$chg_dsg_state = gaming_hub_ecoflow_chg_dsg_state( $quota );
	}

	if ( null !== $chg_dsg_state ) {
		switch ( (int) $chg_dsg_state ) {
			case 2:
				return __( '充電中', 'gaming-hub' );
			case 1:
				return __( '放電中', 'gaming-hub' );
			case 0:
				return __( '待機中', 'gaming-hub' );
		}
	}

	if ( $is_charging ) {
		return __( '充電中', 'gaming-hub' );
	}

	if ( $is_discharging || ( null !== $output && $output > 0 ) ) {
		return __( '放電中', 'gaming-hub' );
	}

	if ( null !== $input && $input > 0 ) {
		return __( '入力中', 'gaming-hub' );
	}

	return __( '待機中', 'gaming-hub' );
}

/**
 * Format watts.
 *
 * @param float|null $value Watts.
 */
function gaming_hub_format_ecoflow_watts( $value ) {
	if ( null === $value ) {
		return '—';
	}

	return number_format_i18n( $value, 0 ) . ' W';
}

/**
 * Format Wh.
 *
 * @param float|null $value Wh.
 */
function gaming_hub_format_ecoflow_wh( $value ) {
	if ( null === $value ) {
		return '—';
	}

	if ( $value > 1000 ) {
		return number_format_i18n( $value / 1000, 1 ) . ' kWh';
	}

	return number_format_i18n( $value, 0 ) . ' Wh';
}

/**
 * Format temperature.
 *
 * @param float|null $value Celsius.
 */
function gaming_hub_format_ecoflow_temp( $value ) {
	if ( null === $value ) {
		return '—';
	}

	return number_format_i18n( $value, 1 ) . ' ℃';
}

/**
 * Format minutes to hours/minutes.
 *
 * @param int $minutes Minutes.
 */
function gaming_hub_format_ecoflow_minutes( $minutes ) {
	if ( $minutes <= 0 ) {
		return '—';
	}

	$hours = (int) floor( $minutes / 60 );
	$mins  = $minutes % 60;

	if ( $hours > 0 ) {
		return sprintf(
			/* translators: 1: hours, 2: minutes */
			__( '%1$d時間%2$d分', 'gaming-hub' ),
			$hours,
			$mins
		);
	}

	return sprintf(
		/* translators: %d: minutes */
		__( '%d分', 'gaming-hub' ),
		$mins
	);
}

/**
 * Render setup instructions when API is not configured.
 */
function gaming_hub_render_ecoflow_setup_instructions() {
	?>
	<div class="ecoflow-setup-steps">
		<ol>
			<li><?php esc_html_e( 'EcoFlow Developer Platform で Access Key / Secret Key を取得', 'gaming-hub' ); ?></li>
			<li><?php esc_html_e( 'デバイスのシリアル番号 (SN) を確認', 'gaming-hub' ); ?></li>
			<li><?php esc_html_e( '.env または 外観 → カスタマイズ → EcoFlow API に設定', 'gaming-hub' ); ?></li>
			<li><?php esc_html_e( 'Delta 3 1500 を連携する場合は ECOFLOW_DEVICE_SN_2 も設定', 'gaming-hub' ); ?></li>
			<li><?php esc_html_e( 'Delta 3 の MQTT 取得: Googleログインのみの場合は EcoFlow アプリで「ログインパスワード」を先に設定', 'gaming-hub' ); ?></li>
		</ol>
		<p>
			<a href="https://developer.ecoflow.com/us/" target="_blank" rel="noopener noreferrer">
				<?php esc_html_e( 'EcoFlow Developer Platform', 'gaming-hub' ); ?> →
			</a>
		</p>
	</div>
	<?php
}

/**
 * Render EcoFlow dashboard.
 */
function gaming_hub_render_ecoflow_dashboard() {
	get_template_part(
		'template-parts/ecoflow',
		'dashboard',
		array(
			'status' => gaming_hub_get_ecoflow_status(),
		)
	);
}

/**
 * Query posts with EcoFlow tag.
 *
 * @param int $limit Number of posts.
 * @return WP_Query
 */
function gaming_hub_get_ecoflow_posts( $limit = 6 ) {
	return new WP_Query(
		array(
			'posts_per_page' => $limit,
			'tag'            => GAMING_HUB_ECOFLOW_TAG_SLUG,
			'post_status'    => 'publish',
		)
	);
}

/**
 * Render EcoFlow tag badge.
 */
function gaming_hub_render_ecoflow_tag_badge() {
	echo '<a href="' . esc_url( gaming_hub_ecoflow_url() ) . '" class="ecoflow-tag-badge">EcoFlow</a>';
}

/**
 * Check if post has EcoFlow tag.
 *
 * @param int|null $post_id Post ID.
 */
function gaming_hub_has_ecoflow_tag( $post_id = null ) {
	return has_tag( GAMING_HUB_ECOFLOW_TAG_SLUG, $post_id );
}

/**
 * REST endpoint for dashboard refresh.
 */
function gaming_hub_register_ecoflow_rest_route() {
	register_rest_route(
		'gaming-hub/v1',
		'/ecoflow/status',
		array(
			'methods'             => 'GET',
			'callback'            => 'gaming_hub_rest_ecoflow_status',
			'permission_callback' => '__return_true',
		)
	);
}
add_action( 'rest_api_init', 'gaming_hub_register_ecoflow_rest_route' );

/**
 * REST callback for EcoFlow status.
 */
function gaming_hub_rest_ecoflow_status() {
	$status = gaming_hub_get_ecoflow_status( true );

	if ( is_wp_error( $status ) ) {
		return new WP_REST_Response(
			array(
				'success' => false,
				'message' => $status->get_error_message(),
			),
			200
		);
	}

	return new WP_REST_Response(
		array(
			'success' => true,
			'data'    => $status,
		),
		200
	);
}

/**
 * Enqueue EcoFlow dashboard script on EcoFlow pages.
 */
function gaming_hub_ecoflow_scripts() {
	if ( ! is_tag( 'ecoflow' ) ) {
		return;
	}

	wp_enqueue_script(
		'gaming-hub-ecoflow-flow',
		get_template_directory_uri() . '/assets/js/ecoflow-flow.js',
		array(),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-ecoflow-flow',
		'gamingHubEcoflowFlow',
		array(
			'labels' => array(
				'solar'       => __( 'ソーラー', 'gaming-hub' ),
				'grid'        => __( 'グリッド', 'gaming-hub' ),
				'home'        => __( 'ホーム', 'gaming-hub' ),
				'battery'     => __( 'バッテリー', 'gaming-hub' ),
				'pro'         => __( 'Delta Pro 3', 'gaming-hub' ),
				'delta'       => __( 'Delta 3 1500', 'gaming-hub' ),
				'acLink'      => __( 'AC 100V', 'gaming-hub' ),
				'flow'        => __( '電力フロー', 'gaming-hub' ),
				'inputTotal'  => __( '入力合計', 'gaming-hub' ),
				'outputTotal' => __( '出力合計', 'gaming-hub' ),
			),
		)
	);

	wp_enqueue_script(
		'gaming-hub-ecoflow',
		get_template_directory_uri() . '/assets/js/ecoflow-dashboard.js',
		array( 'gaming-hub-active-refresh', 'gaming-hub-ecoflow-flow' ),
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-ecoflow',
		'gamingHubEcoflow',
		array(
			'refreshUrl' => rest_url( 'gaming-hub/v1/ecoflow/status' ),
			'interval'   => GAMING_HUB_ECOFLOW_STATUS_CACHE_TTL * 1000,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_ecoflow_scripts' );
