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
require get_template_directory() . '/inc/ecoflow-plan.php';
require get_template_directory() . '/inc/ecoflow-schedule.php';
require get_template_directory() . '/inc/ecoflow-energy.php';
require get_template_directory() . '/inc/ecoflow-delta1500.php';

define( 'GAMING_HUB_ECOFLOW_TAG_SLUG', 'ecoflow' );
define( 'GAMING_HUB_ENERGY_TAG_SLUG', 'energy' );
define( 'GAMING_HUB_ECOFLOW_STATUS_CACHE_KEY', 'gaming_hub_ecoflow_status_v10' );
define( 'GAMING_HUB_ECOFLOW_STATUS_CACHE_TTL', 5 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH', 2500 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH', 1000 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_BASELINE_SOC', 6 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_LV_RATIO', 0.5 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_SOC_OPTION', 'gaming_hub_delta1500_soc_v2' );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_SOC_LOCK', 'gaming_hub_delta1500_soc_lock' );

/**
 * Register EcoFlow post tag on theme setup.
 */
function gaming_hub_setup_ecoflow_tag() {
	if ( ! get_option( 'gaming_hub_ecoflow_tag_created' ) ) {
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

	if ( get_option( 'gaming_hub_energy_tag_created' ) ) {
		return;
	}

	if ( ! term_exists( GAMING_HUB_ENERGY_TAG_SLUG, 'post_tag' ) ) {
		wp_insert_term(
			'発電ログ',
			'post_tag',
			array(
				'slug'        => GAMING_HUB_ENERGY_TAG_SLUG,
				'description' => __( 'EcoFlow 発電量・入出力の実測ログ', 'gaming-hub' ),
			)
		);
	}

	update_option( 'gaming_hub_energy_tag_created', 1 );
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
 * Get Energy tag archive URL.
 */
function gaming_hub_energy_url() {
	$link = get_tag_link( get_term_by( 'slug', GAMING_HUB_ENERGY_TAG_SLUG, 'post_tag' ) );
	return $link && ! is_wp_error( $link ) ? $link : home_url( '/tag/energy/' );
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
			return gaming_hub_ecoflow_attach_live_addons( $cached );
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

	$secondary = null;
	if ( ! empty( $config['device_sn_2'] ) ) {
		$fetched = gaming_hub_fetch_ecoflow_device_status( $api, $devices, $config['device_sn_2'], $primary );
		if ( ! is_wp_error( $fetched ) ) {
			$secondary = $fetched;
		}
	}

	if ( ! is_array( $secondary ) ) {
		$secondary = gaming_hub_ecoflow_independent_delta1500( $config['device_sn_2'] ?? '' );
	}

	$status              = $primary;
	$status['secondary'] = $secondary;
	$delta_solar         = max( 0, (int) ( $secondary['solar_in'] ?? 0 ) );
	$status['solar_delta'] = $delta_solar;
	$status['hv_in']       = max( 0, (int) ( $primary['hv_in'] ?? 0 ) );
	$status['solar_in']    = $delta_solar;

	set_transient( GAMING_HUB_ECOFLOW_STATUS_CACHE_KEY, $status, GAMING_HUB_ECOFLOW_STATUS_CACHE_TTL );

	return gaming_hub_ecoflow_attach_live_addons( $status );
}

/**
 * Overlay plan, energy log, and SwitchBot UPS watts onto a status snapshot.
 *
 * @param array<string, mixed> $status Cached or fresh EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_attach_live_addons( array $status ) {
	if ( function_exists( 'gaming_hub_switchbot_attach_ups' ) ) {
		$status = gaming_hub_switchbot_attach_ups( $status );
	}

	$status = gaming_hub_ecoflow_apply_theoretical_lv( $status );
	$status = gaming_hub_ecoflow_apply_delta1500_soc_model( $status );

	if ( function_exists( 'gaming_hub_ecoflow_apply_delta1500_grid_rescue' ) ) {
		$status = gaming_hub_ecoflow_apply_delta1500_grid_rescue( $status );
	}

	$status['charge_plan'] = gaming_hub_ecoflow_get_charge_plan( $status );

	if ( function_exists( 'gaming_hub_ecoflow_energy_sample' ) ) {
		gaming_hub_ecoflow_energy_sample( $status );
		$status = gaming_hub_ecoflow_energy_attach( $status );
	}

	if ( function_exists( 'gaming_hub_ecoflow_apply_approved_schedule' ) ) {
		gaming_hub_ecoflow_apply_approved_schedule( false );
	}

	if ( ! empty( $status['charge_plan'] ) && is_array( $status['charge_plan'] ) && function_exists( 'gaming_hub_ecoflow_attach_schedule_state' ) ) {
		$status['charge_plan'] = gaming_hub_ecoflow_attach_schedule_state( $status['charge_plan'] );
	}

	if ( ! empty( $status['charge_plan'] ) && is_array( $status['charge_plan'] ) && function_exists( 'gaming_hub_ecoflow_pro_grid_charge_view' ) ) {
		$status['pro_grid_charge'] = gaming_hub_ecoflow_pro_grid_charge_view( $status['charge_plan'] );
	}

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
	$hv_in = gaming_hub_ecoflow_sum_quota(
		$quota,
		array( 'powGetPvH', 'mppt.inWattsHV' ),
		true
	);
	$lv_in = gaming_hub_ecoflow_sum_quota(
		$quota,
		array( 'powGetPvL', 'mppt.inWattsLV' ),
		true
	);
	if ( null !== $hv_in || null !== $lv_in ) {
		$solar = $lv_in;
	} else {
		$solar = gaming_hub_ecoflow_sum_quota(
			$quota,
			array( 'mppt.inWatts', 'powGet.solar' ),
			true
		);
	}
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
	$capacity = gaming_hub_ecoflow_quota_value( $quota, array( 'cmsBattFullEnergy', 'bmsDesignCap', 'bms_bmsStatus.designCap', 'pd.designCap', 'bmsFullCap' ) );
	$remain_cap = gaming_hub_ecoflow_quota_value( $quota, array( 'cmsBattRemainEnergy', 'pd.remainCap', 'remainCap', 'bmsRemainCap', 'bms_bmsStatus.remainCap' ) );

	if ( null === $remain_cap && is_array( $quota ) && ! empty( $quota ) ) {
		$remain_mah = gaming_hub_ecoflow_quota_value( $quota, array( 'bmsRemainCap', 'remainCap', 'pd.remainCap' ) );
		$full_mah   = gaming_hub_ecoflow_quota_value( $quota, array( 'bmsFullCap', 'fullCap', 'pd.fullCap' ) );
		$full_wh    = gaming_hub_ecoflow_quota_value( $quota, array( 'cmsBattFullEnergy' ) );
		if ( null !== $remain_mah && null !== $full_mah && $full_mah > 0 && null !== $full_wh && $full_wh > 0 ) {
			$remain_cap = $full_wh * ( $remain_mah / $full_mah );
		}
	}

	$chg_dsg_state = gaming_hub_ecoflow_chg_dsg_state( $quota );
	$is_charging   = gaming_hub_ecoflow_is_charging( $quota, $input, $output, $chg_dsg_state );
	$is_discharging = gaming_hub_ecoflow_is_discharging( $quota, $input, $output, $chg_dsg_state );
	$remain        = gaming_hub_ecoflow_remain_time( $quota, $is_charging, $is_discharging );

	if ( null === $input ) {
		$parts = array_filter( array( $solar, $hv_in ), 'is_numeric' );
		if ( ! empty( $parts ) ) {
			$input = array_sum( $parts );
		}
	}

	if ( null === $remain_cap && null !== $capacity && null !== $battery ) {
		$remain_cap = $capacity * ( $battery / 100 );
	}

	$parsed = array(
		'device_sn'       => $device_sn,
		'device_name'     => $device_name,
		'online'          => $online,
		'battery_percent' => null !== $battery ? max( 0, min( 100, (int) round( $battery ) ) ) : null,
		'solar_in'        => $solar,
		'hv_in'           => $hv_in,
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
		'charge_state'    => gaming_hub_ecoflow_charge_state_label( $quota, $is_charging, $is_discharging, $input, $output, $chg_dsg_state, $solar, $ac_in, $hv_in ),
		'updated_at'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
	);

	if ( gaming_hub_ecoflow_is_app_only_device( $device_sn ) ) {
		$energy                    = gaming_hub_ecoflow_delta1500_energy_from_parsed( $parsed, $quota );
		$parsed['capacity_wh']     = $energy['capacity_wh'];
		$parsed['remain_capacity'] = $energy['remain_capacity'];
		if ( null !== $energy['battery_percent'] ) {
			$parsed['battery_percent'] = $energy['battery_percent'];
		}
		$parsed['extra'] = gaming_hub_ecoflow_parse_extra_battery( $quota, $parsed['battery_percent'] );
	}

	return $parsed;
}

/**
 * Combined Delta 3 1500 + Extra Battery pack (2.5 kWh).
 *
 * @param int|float|null $soc Battery percent.
 * @return array{capacity_wh: int, remain_capacity: int|null}
 */
function gaming_hub_ecoflow_delta1500_pack_energy( $soc = null ) {
	$full   = (int) GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH;
	$remain = null;

	if ( null !== $soc && is_numeric( $soc ) ) {
		$remain = (int) round( $full * ( max( 0, min( 100, (float) $soc ) ) / 100 ) );
	}

	return array(
		'capacity_wh'     => $full,
		'remain_capacity' => $remain,
	);
}

/**
 * Low Volt input as 50% of High Volt (theoretical).
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_theoretical_lv( array $status ) {
	$hv = max( 0, (float) ( $status['hv_in'] ?? 0 ) );
	if ( $hv <= 0 && ! empty( $status['pro']['hv_in'] ) ) {
		$hv = max( 0, (float) $status['pro']['hv_in'] );
	}

	$lv = (int) round( $hv * GAMING_HUB_ECOFLOW_DELTA1500_LV_RATIO );

	$status['hv_in']           = (int) round( $hv );
	$status['solar_in']        = $lv;
	$status['solar_delta']     = $lv;
	$status['solar_in_source'] = 'theoretical_lv';

	if ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) ) {
		$status['secondary']['solar_in']        = $lv;
		$status['secondary']['solar_in_source'] = 'theoretical_lv';
	}

	return $status;
}

/**
 * Delta 3 1500 pack energy from parsed quota (Wh + SOC).
 *
 * @param array<string, mixed>      $parsed Parsed device metrics.
 * @param array<string, mixed>|null $quota  Raw quota map.
 * @return array{capacity_wh: int, remain_capacity: int|null, battery_percent: int|null}
 */
function gaming_hub_ecoflow_delta1500_energy_from_parsed( array $parsed, $quota = null ) {
	$quota = is_array( $quota ) ? $quota : array();

	$full_wh = gaming_hub_ecoflow_quota_value( $quota, array( 'cmsBattFullEnergy' ) );
	if ( null === $full_wh || $full_wh <= 0 ) {
		$full_wh = (int) GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH;
	} else {
		$full_wh = (int) round( $full_wh );
	}

	$soc = isset( $parsed['battery_percent'] ) && is_numeric( $parsed['battery_percent'] )
		? max( 0, min( 100, (int) round( (float) $parsed['battery_percent'] ) ) )
		: null;

	$remain_wh = gaming_hub_ecoflow_quota_value( $quota, array( 'cmsBattRemainEnergy' ) );
	if ( null === $remain_wh && isset( $parsed['remain_capacity'] ) && is_numeric( $parsed['remain_capacity'] ) ) {
		$candidate = (float) $parsed['remain_capacity'];
		if ( $candidate > 0 && ( $full_wh <= 0 || $candidate <= $full_wh * 1.05 ) ) {
			$remain_wh = $candidate;
		}
	}

	if ( null === $remain_wh ) {
		$remain_mah = gaming_hub_ecoflow_quota_value( $quota, array( 'bmsRemainCap', 'remainCap', 'pd.remainCap' ) );
		$full_mah   = gaming_hub_ecoflow_quota_value( $quota, array( 'bmsFullCap', 'fullCap', 'pd.fullCap' ) );
		if ( null !== $remain_mah && null !== $full_mah && $full_mah > 0 && $full_wh > 0 ) {
			$remain_wh = $full_wh * ( $remain_mah / $full_mah );
		}
	}

	if ( null === $remain_wh && null !== $soc && $full_wh > 0 ) {
		$remain_wh = $full_wh * ( $soc / 100.0 );
	}

	if ( null !== $remain_wh && null === $soc && $full_wh > 0 ) {
		$soc = (int) round( 100 * $remain_wh / $full_wh );
	}

	return array(
		'capacity_wh'     => $full_wh,
		'remain_capacity' => null !== $remain_wh ? (int) round( $remain_wh ) : null,
		'battery_percent' => null !== $soc ? max( 0, min( 100, (int) $soc ) ) : null,
	);
}

/**
 * Whether Delta 1500 has live SOC from MQTT or Developer API.
 *
 * @param array<string, mixed> $delta Secondary device status.
 */
function gaming_hub_ecoflow_delta1500_has_live_soc( array $delta ) {
	if ( ! empty( $delta['inferred'] ) ) {
		return false;
	}

	if ( ! isset( $delta['battery_percent'] ) || ! is_numeric( $delta['battery_percent'] ) ) {
		return false;
	}

	return true;
}

/**
 * Normalize live Delta 1500 pack metrics for dashboard + flow.
 *
 * @param array<string, mixed> $delta Secondary device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_delta1500_live_energy( array $delta ) {
	$energy = gaming_hub_ecoflow_delta1500_energy_from_parsed( $delta );

	$delta['capacity_wh']     = $energy['capacity_wh'];
	$delta['battery_percent'] = $energy['battery_percent'];
	$delta['remain_capacity'] = $energy['remain_capacity'];
	$delta['soc_source']     = ! empty( $delta['source'] ) ? (string) $delta['source'] : 'device';

	if ( empty( $delta['extra'] ) || ! is_array( $delta['extra'] ) ) {
		$delta['extra'] = gaming_hub_ecoflow_extra_battery_slice( $delta['battery_percent'] );
	}

	return $delta;
}

/**
 * Delta 3 remaining: start at 6%, subtract UPS Wh, add grid AC in + Low Volt solar in.
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_delta1500_soc_model( array $status ) {
	$delta = isset( $status['secondary'] ) && is_array( $status['secondary'] )
		? $status['secondary']
		: array();

	if ( gaming_hub_ecoflow_delta1500_has_live_soc( $delta ) ) {
		$status['secondary'] = gaming_hub_ecoflow_apply_delta1500_live_energy( $delta );
		return $status;
	}

	$full         = (int) GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH;
	$baseline_soc = (int) GAMING_HUB_ECOFLOW_DELTA1500_BASELINE_SOC;
	$baseline_wh  = $full * ( $baseline_soc / 100.0 );
	$ups_w        = 0.0;
	$ac_in_w      = 0.0;
	$lv_w         = 0.0;

	if ( isset( $status['ups_plug']['watts'] ) && is_numeric( $status['ups_plug']['watts'] ) ) {
		$ups_w = max( 0, (float) $status['ups_plug']['watts'] );
	} elseif ( isset( $status['secondary']['ac_out'] ) && is_numeric( $status['secondary']['ac_out'] ) ) {
		$ups_w = max( 0, (float) $status['secondary']['ac_out'] );
	}

	if ( isset( $status['secondary']['ac_in'] ) && is_numeric( $status['secondary']['ac_in'] ) ) {
		$ac_in_w = max( 0, (float) $status['secondary']['ac_in'] );
	}

	if ( isset( $status['secondary']['solar_in'] ) && is_numeric( $status['secondary']['solar_in'] ) ) {
		$lv_w = max( 0, (float) $status['secondary']['solar_in'] );
	} elseif ( isset( $status['solar_delta'] ) && is_numeric( $status['solar_delta'] ) ) {
		$lv_w = max( 0, (float) $status['solar_delta'] );
	} elseif ( isset( $status['solar_in'] ) && is_numeric( $status['solar_in'] ) ) {
		$lv_w = max( 0, (float) $status['solar_in'] );
	}

	$state = get_option( GAMING_HUB_ECOFLOW_DELTA1500_SOC_OPTION, array() );
	$state = is_array( $state ) ? $state : array();
	$now   = time();

	$needs_reset = empty( $state['started_at'] )
		|| (int) ( $state['baseline_soc'] ?? 0 ) !== $baseline_soc
		|| (int) ( $state['full_wh'] ?? 0 ) !== $full
		|| ! array_key_exists( 'lv_in_wh', $state );

	if ( $needs_reset ) {
		$state = array(
			'started_at'     => $now,
			'baseline_soc'   => $baseline_soc,
			'baseline_wh'    => $baseline_wh,
			'full_wh'        => $full,
			'ups_wh'         => 0.0,
			'ac_in_wh'       => 0.0,
			'lv_in_wh'       => 0.0,
			'last_ts'        => $now,
			'last_ups_w'     => $ups_w,
			'last_ac_in_w'   => $ac_in_w,
			'last_lv_w'      => $lv_w,
		);
		update_option( GAMING_HUB_ECOFLOW_DELTA1500_SOC_OPTION, $state, false );
	} elseif ( ! get_transient( GAMING_HUB_ECOFLOW_DELTA1500_SOC_LOCK ) ) {
		set_transient( GAMING_HUB_ECOFLOW_DELTA1500_SOC_LOCK, 1, 2 );

		$dt = $now - (int) ( $state['last_ts'] ?? $now );
		$dt = max( 0, min( 15 * MINUTE_IN_SECONDS, $dt ) );

		if ( $dt > 0 ) {
			$avg_ups             = ( (float) ( $state['last_ups_w'] ?? $ups_w ) + $ups_w ) / 2.0;
			$avg_ac              = ( (float) ( $state['last_ac_in_w'] ?? $ac_in_w ) + $ac_in_w ) / 2.0;
			$avg_lv              = ( (float) ( $state['last_lv_w'] ?? $lv_w ) + $lv_w ) / 2.0;
			$state['ups_wh']     = (float) ( $state['ups_wh'] ?? 0 ) + ( $avg_ups * ( $dt / 3600.0 ) );
			$state['ac_in_wh']   = (float) ( $state['ac_in_wh'] ?? 0 ) + ( $avg_ac * ( $dt / 3600.0 ) );
			$state['lv_in_wh']   = (float) ( $state['lv_in_wh'] ?? 0 ) + ( $avg_lv * ( $dt / 3600.0 ) );
		}

		$state['last_ts']      = $now;
		$state['last_ups_w']   = $ups_w;
		$state['last_ac_in_w'] = $ac_in_w;
		$state['last_lv_w']    = $lv_w;
		update_option( GAMING_HUB_ECOFLOW_DELTA1500_SOC_OPTION, $state, false );
	}

	$remain = max(
		0,
		$baseline_wh
		- (float) ( $state['ups_wh'] ?? 0 )
		+ (float) ( $state['ac_in_wh'] ?? 0 )
		+ (float) ( $state['lv_in_wh'] ?? 0 )
	);
	$floor_wh = (int) round( $full * ( GAMING_HUB_ECOFLOW_DELTA1500_RESCUE_FLOOR_SOC / 100.0 ) );
	if ( $ups_w > 0 && $remain < $floor_wh ) {
		$remain = $floor_wh;
	}
	$remain = min( $full, $remain );
	$soc    = $full > 0 ? (int) round( 100 * $remain / $full ) : 0;
	$soc    = max( 0, min( 100, $soc ) );
	$remain = (int) round( $remain );

	$delta = isset( $status['secondary'] ) && is_array( $status['secondary'] )
		? $status['secondary']
		: array();

	$delta['battery_percent'] = $soc;
	$delta['capacity_wh']     = $full;
	$delta['remain_capacity'] = $remain;
	$delta['soc_source']      = 'baseline_minus_ups_plus_ac_lv';
	$delta['ups_wh_used']     = round( (float) ( $state['ups_wh'] ?? 0 ), 1 );
	$delta['ac_in_wh']        = round( (float) ( $state['ac_in_wh'] ?? 0 ), 1 );
	$delta['lv_in_wh']        = round( (float) ( $state['lv_in_wh'] ?? 0 ), 1 );
	$delta['extra']           = gaming_hub_ecoflow_extra_battery_slice( $soc );

	$status['secondary'] = $delta;

	return $status;
}

/**
 * Extra Battery 1kW attached to Delta 3 1500.
 *
 * @param array<string, mixed> $quota        Raw quota map.
 * @param int|null             $combined_soc Combined / main SOC fallback.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_parse_extra_battery( $quota, $combined_soc = null ) {
	$soc = gaming_hub_ecoflow_quota_value(
		$quota,
		array(
			'bmsSlaveSoc',
			'bp2Soc',
			'bms_kitInfo.soc',
			'bms_bmsKitStatus.soc',
			'kitBattSoc',
			'extraBattSoc',
			'bms_slave.soc',
		)
	);

	if ( null === $soc && null !== $combined_soc ) {
		$soc = $combined_soc;
	}

	return gaming_hub_ecoflow_extra_battery_slice( $soc );
}

/**
 * Normalized Extra Battery payload for the 1500.
 *
 * @param int|float|null $soc Battery percent.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_extra_battery_slice( $soc = null ) {
	$percent = null !== $soc && is_numeric( $soc )
		? max( 0, min( 100, (int) round( $soc ) ) )
		: null;

	return array(
		'connected'       => true,
		'battery_percent' => $percent,
		'capacity_wh'     => GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH,
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
		'hv_in'               => (int) ( $device['hv_in'] ?? 0 ),
		'ac_in'               => (int) ( $device['ac_in'] ?? 0 ),
		'ac_out'              => (int) ( $device['ac_out'] ?? 0 ),
		'dc_out'              => (int) ( $device['dc_out'] ?? 0 ),
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
		'capacity_wh'         => isset( $device['capacity_wh'] ) ? (int) $device['capacity_wh'] : null,
		'remain_capacity'     => isset( $device['remain_capacity'] ) && null !== $device['remain_capacity']
			? (int) round( (float) $device['remain_capacity'] )
			: null,
		'extra'               => isset( $device['extra'] ) && is_array( $device['extra'] )
			? gaming_hub_ecoflow_extra_battery_slice( $device['extra']['battery_percent'] ?? null )
			: null,
	);
}

/**
 * Independent Delta 3 1500 placeholder when live telemetry is unavailable.
 *
 * @param string $device_sn Secondary serial.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_independent_delta1500( $device_sn = '' ) {
	$delta = array(
		'device_sn'       => $device_sn,
		'device_name'     => __( 'Delta 3 1500', 'gaming-hub' ),
		'online'          => false,
		'battery_percent' => null,
		'solar_in'        => 0,
		'hv_in'           => 0,
		'input_total'     => 0,
		'output_total'    => 0,
		'ac_in'           => 0,
		'ac_out'          => 0,
		'dc_out'          => 0,
		'battery_temp'    => null,
		'remain_time'     => null,
		'is_charging'     => false,
		'is_discharging'  => false,
		'charge_state'    => __( '独立運転', 'gaming-hub' ),
		'inferred'        => true,
		'inferred_note'   => __( 'Pro とは独立。Low Volt ソーラーは 1500 へ入力。Extra Battery 1kW 接続。合算 2.5 kWh。ライブ計測は MQTT ブリッジ待ち。', 'gaming-hub' ),
		'updated_at'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		'extra'           => gaming_hub_ecoflow_extra_battery_slice(),
	);

	return array_merge( $delta, gaming_hub_ecoflow_delta1500_pack_energy( $delta['battery_percent'] ) );
}

/**
 * Theme-relative EcoFlow diagram image URL.
 *
 * @param string $file Filename in assets/images.
 */
function gaming_hub_ecoflow_image_url( $file ) {
	return get_template_directory_uri() . '/assets/images/' . ltrim( $file, '/' );
}

/**
 * Build initial payload for the React energy-flow diagram.
 *
 * @param array<string, mixed> $status Normalized device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_flow_payload( array $status ) {
	$pro = gaming_hub_ecoflow_device_flow_slice( $status );

	$delta = ! empty( $status['secondary'] ) && is_array( $status['secondary'] )
		? $status['secondary']
		: gaming_hub_ecoflow_independent_delta1500();

	$delta_slice = gaming_hub_ecoflow_device_flow_slice( $delta );

	$payload = array(
		'dual'                => true,
		'independent'         => true,
		'solar_in'            => (int) ( $delta_slice['solar_in'] ?? 0 ),
		'hv_in'               => (int) ( $status['hv_in'] ?? $pro['hv_in'] ?? 0 ),
		'grid_in'             => $pro['ac_in'],
		'ac_in'               => $pro['ac_in'],
		'pro_grid_charge'     => isset( $status['pro_grid_charge'] ) && is_array( $status['pro_grid_charge'] )
			? $status['pro_grid_charge']
			: array(),
		'pro'                 => $pro,
		'battery_percent'     => $pro['battery_percent'],
		'is_charging'         => $pro['is_charging'],
		'charge_state'        => $pro['charge_state'],
		'input_total'         => $pro['input_total'],
		'output_total'        => $pro['output_total'],
		'remain_time'         => $pro['remain_time'],
		'remain_time_label'   => $pro['remain_time_label'],
		'remain_time_display' => $pro['remain_time_display'],
		'delta'               => $delta_slice,
		'link_watts'          => 0,
		'home_out'            => (int) ( $status['ac_out'] ?? 0 ),
		'ups_out'             => gaming_hub_ecoflow_ups_watts( $status, (int) ( $delta_slice['ac_out'] ?? 0 ) ),
		'ups_source'          => gaming_hub_ecoflow_ups_source( $status ),
		'solar_in_source'     => $status['solar_in_source'] ?? '',
		'extra'               => isset( $delta_slice['extra'] ) && is_array( $delta_slice['extra'] )
			? $delta_slice['extra']
			: gaming_hub_ecoflow_extra_battery_slice(),
	);

	return $payload;
}

/**
 * UPS AC watts: SwitchBot Plug Mini when available, else 1500 ac_out.
 *
 * @param array<string, mixed> $status   EcoFlow status.
 * @param int                  $fallback EcoFlow AC out fallback.
 */
function gaming_hub_ecoflow_ups_watts( array $status, $fallback = 0 ) {
	if ( isset( $status['ups_plug']['watts'] ) && is_numeric( $status['ups_plug']['watts'] ) ) {
		return (int) round( (float) $status['ups_plug']['watts'] );
	}

	return (int) $fallback;
}

/**
 * Which source is driving the UPS watt display.
 *
 * @param array<string, mixed> $status EcoFlow status.
 */
function gaming_hub_ecoflow_ups_source( array $status ) {
	if ( isset( $status['ups_plug']['watts'] ) && is_numeric( $status['ups_plug']['watts'] ) ) {
		return 'switchbot';
	}

	return 'ecoflow';
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
 * @param float|null           $solar         PV watts.
 * @param float|null           $hv_in         High-volt DC in watts.
 */
function gaming_hub_ecoflow_charge_state_label( $quota, $is_charging, $is_discharging, $input, $output, $chg_dsg_state = null, $solar = null, $ac_in = null, $hv_in = null ) {
	if ( null === $chg_dsg_state ) {
		$chg_dsg_state = gaming_hub_ecoflow_chg_dsg_state( $quota );
	}

	$charging = $is_charging || 2 === (int) $chg_dsg_state;
	if ( $charging ) {
		$grid_w  = (float) ( $ac_in ?? 0 );
		$hv_w    = (float) ( $hv_in ?? 0 );
		$solar_w = (float) ( $solar ?? 0 );
		if ( $grid_w >= 50 ) {
			return __( 'グリッド充電中', 'gaming-hub' );
		}
		if ( $hv_w >= 50 ) {
			return __( 'ハイボルト充電中', 'gaming-hub' );
		}
		if ( $solar_w >= 50 ) {
			return __( 'ソーラー充電中', 'gaming-hub' );
		}
		return __( '充電中', 'gaming-hub' );
	}

	if ( null !== $chg_dsg_state ) {
		switch ( (int) $chg_dsg_state ) {
			case 1:
				return __( '放電中', 'gaming-hub' );
			case 0:
				return __( '待機中', 'gaming-hub' );
		}
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
 * Remaining / full pack energy, e.g. 800 Wh / 2.5 kWh.
 *
 * @param float|null $remain Remaining Wh.
 * @param float|null $full   Full pack Wh.
 */
function gaming_hub_format_ecoflow_pack( $remain, $full ) {
	if ( null === $full || ! is_numeric( $full ) || (float) $full <= 0 ) {
		return gaming_hub_format_ecoflow_wh( $remain );
	}

	return gaming_hub_format_ecoflow_wh( $remain ) . ' / ' . gaming_hub_format_ecoflow_wh( $full );
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
			<li><?php esc_html_e( 'Delta 3 の MQTT: 日本のアカウントは API Region を Asia にする。Googleログインのみならアプリで「ログインパスワード」を設定', 'gaming-hub' ); ?></li>
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
 * Render generation log on the Energy tag page.
 */
function gaming_hub_render_ecoflow_energy_page() {
	$status = gaming_hub_get_ecoflow_status();
	$energy = ( ! is_wp_error( $status ) && is_array( $status['energy'] ?? null ) )
		? $status['energy']
		: null;

	echo '<section class="ecoflow-dashboard ecoflow-energy-page" aria-label="' . esc_attr__( '発電ログ', 'gaming-hub' ) . '">';
	gaming_hub_render_ecoflow_calendar(
		array(
			'status' => is_wp_error( $status ) ? null : $status,
			'energy' => $energy,
		)
	);
	echo '</section>';
}

/**
 * Render Smart Time ONE rate HUD on the EcoFlow dashboard.
 */
function gaming_hub_render_ecoflow_rates( $extra = array() ) {
	if ( ! function_exists( 'gaming_hub_get_looop_forecast' ) ) {
		return;
	}

	get_template_part(
		'template-parts/ecoflow',
		'rates',
		array_merge(
			array(
				'forecast' => gaming_hub_get_looop_forecast(),
			),
			is_array( $extra ) ? $extra : array()
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
	$is_ecoflow = is_tag( 'ecoflow' );
	$is_energy  = is_tag( 'energy' );

	if ( ! $is_ecoflow && ! $is_energy ) {
		return;
	}

	if ( $is_ecoflow ) {
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
					'solar'       => __( 'Low Volt', 'gaming-hub' ),
					'hv'          => __( 'ハイボルト', 'gaming-hub' ),
					'grid'        => __( 'グリッド', 'gaming-hub' ),
					'gridCharge'  => __( 'グリッド補充電', 'gaming-hub' ),
					'gridIdle'    => __( '待機', 'gaming-hub' ),
					'home'        => __( '慎一の部屋', 'gaming-hub' ),
					'ups'         => __( '常時稼働エリア (UPS)', 'gaming-hub' ),
					'battery'     => __( 'バッテリー', 'gaming-hub' ),
					'pro'         => __( 'Delta Pro 3', 'gaming-hub' ),
					'delta'       => __( 'Delta 3 1500', 'gaming-hub' ),
					'extra'       => __( 'Extra Battery 1kW', 'gaming-hub' ),
					'dcLink'      => __( 'DC 12V', 'gaming-hub' ),
					'acLink'      => __( 'DC 12V', 'gaming-hub' ),
					'acOut'       => __( 'AC 出力', 'gaming-hub' ),
					'upsPlug'     => __( 'SwitchBot Plug', 'gaming-hub' ),
					'lvTheory'    => __( '理論 HV×50%', 'gaming-hub' ),
					'flow'        => __( '電力フロー', 'gaming-hub' ),
					'inputTotal'  => __( '入力合計', 'gaming-hub' ),
					'outputTotal' => __( '出力合計', 'gaming-hub' ),
				),
				'images' => array(
					'solar' => gaming_hub_ecoflow_image_url( 'ecoflow-solar-gaming.jpg' ),
					'pro'   => gaming_hub_ecoflow_image_url( 'ecoflow-pro-gaming.jpg' ),
					'dc12v' => gaming_hub_ecoflow_image_url( 'ecoflow-dc12v-gaming.jpg' ),
					'delta' => gaming_hub_ecoflow_image_url( 'ecoflow-delta1500-gaming.jpg' ),
					'room'  => gaming_hub_ecoflow_image_url( 'ecoflow-room-gaming.jpg' ),
				),
			)
		);
	}

	$deps = array( 'gaming-hub-active-refresh' );
	if ( $is_ecoflow ) {
		$deps[] = 'gaming-hub-ecoflow-flow';
	}

	wp_enqueue_script(
		'gaming-hub-ecoflow',
		get_template_directory_uri() . '/assets/js/ecoflow-dashboard.js',
		$deps,
		GAMING_HUB_VERSION,
		true
	);

	wp_localize_script(
		'gaming-hub-ecoflow',
		'gamingHubEcoflow',
		array(
			'refreshUrl' => rest_url( 'gaming-hub/v1/ecoflow/status' ),
			'ratesUrl'   => rest_url( 'gaming-hub/v1/looop/forecast' ),
			'energyUrl'  => rest_url( 'gaming-hub/v1/ecoflow/energy' ),
			'approveUrl' => rest_url( 'gaming-hub/v1/ecoflow/plan/approve' ),
			'cancelUrl'  => rest_url( 'gaming-hub/v1/ecoflow/plan/cancel' ),
			'restNonce'  => wp_create_nonce( 'wp_rest' ),
			'canApprove' => false,
			'interval'   => GAMING_HUB_ECOFLOW_STATUS_CACHE_TTL * 1000,
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_ecoflow_scripts' );
