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
define( 'GAMING_HUB_ECOFLOW_STATUS_CACHE_KEY', 'gaming_hub_ecoflow_status_v19' );
define( 'GAMING_HUB_ECOFLOW_STATUS_CACHE_TTL', 5 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH', 2500 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH', 1000 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_BASELINE_SOC', 6 );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_LV_RATIO', GAMING_HUB_ECOFLOW_SOLAR_DELTA1500_W / GAMING_HUB_ECOFLOW_SOLAR_PRO_W );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_SOC_OPTION', 'gaming_hub_delta1500_soc_v2' );
define( 'GAMING_HUB_ECOFLOW_DELTA1500_SOC_LOCK', 'gaming_hub_delta1500_soc_lock' );
define( 'GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W', 8 );

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
	return gaming_hub_hub_section_url( 'ecoflow' );
}

/**
 * Get Energy tag archive URL.
 */
function gaming_hub_energy_url() {
	return gaming_hub_hub_section_url( 'energy' );
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

	set_transient(
		GAMING_HUB_ECOFLOW_STATUS_CACHE_KEY,
		gaming_hub_ecoflow_strip_internal_fields( $status ),
		GAMING_HUB_ECOFLOW_STATUS_CACHE_TTL
	);

	return gaming_hub_ecoflow_attach_live_addons( $status );
}

/**
 * Overlay plan, energy log, and SwitchBot UPS watts onto a status snapshot.
 *
 * @param array<string, mixed> $status Cached or fresh EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_attach_live_addons( array $status ) {
	if ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) ) {
		$secondary = $status['secondary'];
		if ( ! empty( $secondary['inferred'] ) || empty( $secondary['_quota'] ) ) {
			$status['secondary'] = gaming_hub_ecoflow_merge_bridge_quota( $secondary );
		}
	}

	$status = gaming_hub_ecoflow_attach_ups_ac_out( $status );

	if ( function_exists( 'gaming_hub_switchbot_attach_ups' ) && function_exists( 'gaming_hub_switchbot_is_enabled' ) && gaming_hub_switchbot_is_enabled() ) {
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

	$plan = ! empty( $status['charge_plan'] ) && is_array( $status['charge_plan'] )
		? $status['charge_plan']
		: array();
	if ( function_exists( 'gaming_hub_ecoflow_pro_grid_charge_view' ) ) {
		$status['pro_grid_charge'] = gaming_hub_ecoflow_pro_grid_charge_view( $plan, $status );
	}

	return gaming_hub_ecoflow_apply_mqtt_display_policy( $status );
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
		$parsed            = gaming_hub_parse_ecoflow_quota( $bridge_quota, $device_sn, $device_name, $online );
		$parsed['source']  = 'mqtt';
		$parsed['_quota']  = $bridge_quota;
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
	$battery = gaming_hub_ecoflow_quota_value(
		$quota,
		array(
			'cmsBattSoc',
			'bmsBattSoc',
			'pd.soc',
			'bms_bmsStatus.soc',
			'bms_emsStatus.lcdShowSoc',
			'bms.soc',
			'bms.f32ShowSoc',
			'bms.actSoc',
			'ems.lcdShowSoc',
			'ems.f32ShowSoc',
		)
	);
	$hv_in = gaming_hub_ecoflow_sum_quota(
		$quota,
		array( 'powGetPvH', 'mppt.inWattsHV', 'mpptStatus.inWattsHV' ),
		true
	);
	$lv_in           = gaming_hub_ecoflow_extract_lv_watts( $quota );
	$solar_in_source = '';

	if ( null !== $lv_in ) {
		$solar             = $lv_in;
		$solar_in_source   = 'device';
	} else {
		$solar = gaming_hub_ecoflow_sum_quota(
			$quota,
			array( 'mppt.inWatts', 'powGet.solar' ),
			true
		);
		if ( null !== $solar && $solar > 0 ) {
			$solar_in_source = 'device';
		}
	}
	$input   = gaming_hub_ecoflow_quota_value(
		$quota,
		array(
			'powInSumW',
			'pd.wattsInSum',
			'inv.inputWatts',
			'bms_bmsStatus.inputWatts',
			'bms.inputWatts',
		)
	);
	$output  = gaming_hub_ecoflow_quota_value(
		$quota,
		array(
			'powOutSumW',
			'pd.wattsOutSum',
			'inv.outputWatts',
			'bms_bmsStatus.outputWatts',
			'bms.outputWatts',
			'bms_slave.outputWatts',
		)
	);
	$ac_in   = gaming_hub_ecoflow_ac_input_watts( $quota );
	$ac_out  = gaming_hub_ecoflow_ac_output_watts( $quota );
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
		'device_sn'         => $device_sn,
		'device_name'       => $device_name,
		'online'            => $online,
		'battery_percent'   => null !== $battery ? max( 0, min( 100, (int) round( $battery ) ) ) : null,
		'solar_in'          => $solar,
		'solar_in_source'   => $solar_in_source,
		'hv_in'             => $hv_in,
		'input_total'       => $input,
		'output_total'      => $output,
		'ac_in'             => $ac_in,
		'ac_out'            => $ac_out,
		'dc_out'            => $dc_out,
		'battery_temp'      => $temp,
		'remain_time'       => null !== $remain ? (int) $remain : null,
		'remain_capacity'   => $remain_cap ?: $capacity,
		'is_charging'       => $is_charging,
		'is_discharging'    => $is_discharging,
		'charge_state'      => gaming_hub_ecoflow_charge_state_label( $quota, $is_charging, $is_discharging, $input, $output, $chg_dsg_state, $solar, $ac_in, $hv_in ),
		'updated_at'        => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
	);

	if ( gaming_hub_ecoflow_is_app_only_device( $device_sn ) ) {
		$main                      = gaming_hub_ecoflow_parse_main_pack( $quota, $parsed );
		$parsed['battery_percent'] = $main['battery_percent'];
		$parsed['capacity_wh']     = $main['capacity_wh'];
		$parsed['remain_capacity'] = $main['remain_capacity'];
		$parsed['capacity_source'] = $main['capacity_source'];
		$parsed['extra']           = gaming_hub_ecoflow_parse_extra_battery( $quota );

		$lv_in = gaming_hub_ecoflow_delta1500_solar_from_quota( $quota );
		if ( gaming_hub_ecoflow_delta1500_quota_has_solar( $quota ) ) {
			$parsed['solar_in']        = max( 0, (int) round( (float) $lv_in ) );
			$parsed['solar_in_source'] = 'mqtt';
			$parsed['input_total']     = max( (float) ( $parsed['input_total'] ?? 0 ), (float) $parsed['solar_in'] );
		} else {
			$parsed['solar_in']        = null;
			$parsed['solar_in_source'] = '';
		}
	} elseif ( null !== $capacity && $capacity >= 500 ) {
		$parsed['capacity_wh']     = (int) round( $capacity );
		$parsed['capacity_source'] = 'device';
	}

	return gaming_hub_ecoflow_attach_device_pack_eta( $parsed );
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
 * Low Volt PV watts from live quota (MQTT / Developer API).
 *
 * @param array<string, mixed> $quota Raw quota map.
 * @return float|null
 */
function gaming_hub_ecoflow_extract_lv_watts( $quota ) {
	return gaming_hub_ecoflow_quota_value_live(
		$quota,
		array(
			'powGetPvL',
			'mppt.inWattsLV',
			'mpptStatus.inWattsLV',
			'mppt.pvLowWatts',
			'mppt.lvInputWatts',
		)
	);
}

/**
 * Whether Delta 1500 AC inlet voltage is present (UPS passthrough or grid charge).
 *
 * @param array<string, mixed> $quota Raw quota map.
 */
function gaming_hub_ecoflow_delta1500_ac_connected( $quota ) {
	$quota = is_array( $quota ) ? $quota : array();
	$vol   = gaming_hub_ecoflow_quota_value_live(
		$quota,
		array( 'inv.acInVol', 'inv.acInWatts', 'pd.acInVol' )
	);

	if ( null !== $vol ) {
		$volts = abs( (float) $vol );
		if ( $volts > 1000 ) {
			$volts /= 1000;
		}
		if ( $volts >= 80 ) {
			return true;
		}
	}

	return false;
}

/**
 * Delta 1500 Low Volt solar watts from MQTT / bridge quota.
 *
 * Show MPPT / PV input whenever it is producing. Do not hide it because AC
 * is plugged in for UPS or the charger type looks like grid.
 *
 * @param array<string, mixed> $quota Raw quota map.
 * @return float|null
 */
function gaming_hub_ecoflow_delta1500_solar_from_quota( $quota ) {
	$quota = is_array( $quota ) ? $quota : array();
	$lv_in = gaming_hub_ecoflow_extract_lv_watts( $quota );

	if ( null !== $lv_in ) {
		return abs( (float) $lv_in );
	}

	$mppt = gaming_hub_ecoflow_quota_value_live( $quota, array( 'mppt.inWatts', 'pd.inWatts' ) );

	return null !== $mppt ? abs( (float) $mppt ) : null;
}

/**
 * Whether Delta 1500 MQTT quota includes a solar input reading.
 *
 * @param array<string, mixed> $quota Raw quota map.
 */
function gaming_hub_ecoflow_delta1500_quota_has_solar( $quota ) {
	$quota = is_array( $quota ) ? $quota : array();

	$lv = gaming_hub_ecoflow_quota_value_live(
		$quota,
		array(
			'powGetPvL',
			'mppt.inWattsLV',
			'mpptStatus.inWattsLV',
			'mppt.pvLowWatts',
			'mppt.lvInputWatts',
		)
	);
	if ( null !== $lv ) {
		return true;
	}

	return null !== gaming_hub_ecoflow_quota_value_live( $quota, array( 'mppt.inWatts', 'pd.inWatts' ) );
}

/**
 * Resolve Delta 1500 bridge quota for live-field checks.
 *
 * @param array<string, mixed> $delta Secondary device slice.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_delta1500_quota( array $delta ) {
	$quota = isset( $delta['_quota'] ) && is_array( $delta['_quota'] ) ? $delta['_quota'] : array();

	if ( ! empty( $quota ) ) {
		return $quota;
	}

	$sn = (string) ( $delta['device_sn'] ?? '' );
	if ( '' === $sn ) {
		return array();
	}

	$bridge = gaming_hub_ecoflow_read_bridge_quota( $sn );

	return is_array( $bridge ) ? $bridge : array();
}

/**
 * Whether Low Volt input is from a live measurement (not HV×50% theory).
 *
 * @param array<string, mixed> $status EcoFlow status.
 */
function gaming_hub_ecoflow_has_live_lv( array $status ) {
	$secondary = isset( $status['secondary'] ) && is_array( $status['secondary'] )
		? $status['secondary']
		: array();

	if ( gaming_hub_ecoflow_delta1500_has_live_solar( $secondary ) ) {
		return true;
	}

	$source = (string) ( $secondary['solar_in_source'] ?? '' );

	return '' !== $source && 'theoretical_lv' !== $source && 'unavailable' !== $source;
}

/**
 * Dashboard label for Low Volt input source.
 *
 * @param string $source solar_in_source value.
 */
function gaming_hub_ecoflow_solar_delta_label( $source ) {
	if ( 'unavailable' === $source || 'theoretical_lv' === $source || '' === $source ) {
		return __( 'Low Volt 入力 (未取得)', 'gaming-hub' );
	}

	if ( 'mqtt' === $source || 'device' === $source ) {
		return __( 'Low Volt 入力 (実測)', 'gaming-hub' );
	}

	return __( 'Low Volt 入力 (実測)', 'gaming-hub' );
}

/**
 * Dashboard label for combined Delta 1500 pack capacity.
 *
 * @param array<string, mixed> $device Secondary device slice.
 */
function gaming_hub_ecoflow_pack_capacity_label( array $device ) {
	if ( 'unavailable' === (string) ( $device['soc_source'] ?? '' ) ) {
		return __( '残容量 (1500 · 未取得)', 'gaming-hub' );
	}

	if ( ! empty( $device['capacity_source'] ) && 'default' !== $device['capacity_source'] ) {
		return __( '残容量 (1500 · 実測)', 'gaming-hub' );
	}

	return __( '残容量 (1500)', 'gaming-hub' );
}

/**
 * Dashboard label for Extra Battery pack capacity.
 *
 * @param array<string, mixed> $extra Extra battery slice.
 */
function gaming_hub_ecoflow_extra_capacity_label( array $extra ) {
	if ( ! gaming_hub_ecoflow_extra_has_mqtt_soc( $extra ) ) {
		return __( '残容量 (Extra · 未取得)', 'gaming-hub' );
	}

	if ( 'stale' === ( $extra['capacity_source'] ?? '' ) ) {
		return __( '残容量 (Extra · 最終値)', 'gaming-hub' );
	}

	if ( 'mqtt' === ( $extra['capacity_source'] ?? '' ) ) {
		return __( '残容量 (Extra · MQTT)', 'gaming-hub' );
	}

	return __( '残容量 (Extra · 実測)', 'gaming-hub' );
}

/**
 * Normalize a quota cap field to watt-hours when possible.
 *
 * @param float|null $value Raw cap value.
 * @return float|null
 */
function gaming_hub_ecoflow_normalize_cap_to_wh( $value ) {
	if ( null === $value || ! is_numeric( $value ) || $value <= 0 ) {
		return null;
	}

	$value = (float) $value;

	// Device energy fields (Wh): Extra ~1024, Delta 1500, Pro 4096.
	if ( $value >= 400 && $value <= 8000 ) {
		return $value;
	}

	// Pack mAh on Delta 3 (16S LiFePO4 ≈ 51.2 V). Extra ~20 Ah, 1500 ~30 Ah.
	if ( $value >= 8000 && $value <= 80000 ) {
		return $value * 51.2 / 1000.0;
	}

	return null;
}

/**
 * Parse combined pack capacity from quota keys.
 *
 * @param array<string, mixed> $quota      Raw quota map.
 * @param int|null             $default_wh Fallback Wh.
 * @return array{capacity_wh: int, capacity_source: string}
 */
function gaming_hub_ecoflow_parse_pack_capacity_wh( $quota, $default_wh = null ) {
	$default_wh = null !== $default_wh ? (int) $default_wh : (int) GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH;

	$full = gaming_hub_ecoflow_quota_value( $quota, array( 'cmsBattFullEnergy' ) );
	if ( null !== $full && $full >= 500 && $full <= 20000 ) {
		return array(
			'capacity_wh'     => (int) round( $full ),
			'capacity_source' => 'device',
		);
	}

	$main_wh = gaming_hub_ecoflow_normalize_cap_to_wh(
		gaming_hub_ecoflow_quota_value(
			$quota,
			array( 'bms.fullCap', 'bmsFullCap', 'bmsDesignCap', 'bms.designCap', 'pd.fullCap', 'bms_bmsStatus.designCap', 'pd.designCap' )
		)
	);
	$slave_wh = gaming_hub_ecoflow_normalize_cap_to_wh(
		gaming_hub_ecoflow_quota_value(
			$quota,
			array( 'bms_slave.fullCap', 'bms_slave.designCap' )
		)
	);

	if ( null !== $main_wh && null !== $slave_wh ) {
		return array(
			'capacity_wh'     => (int) round( $main_wh + $slave_wh ),
			'capacity_source' => 'device',
		);
	}

	if ( null !== $main_wh && $main_wh >= 800 ) {
		$extra = null !== $slave_wh ? $slave_wh : (float) GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH;

		return array(
			'capacity_wh'     => (int) round( $main_wh + $extra ),
			'capacity_source' => 'device',
		);
	}

	return array(
		'capacity_wh'     => $default_wh,
		'capacity_source' => 'default',
	);
}

/**
 * Remove internal-only fields before caching or REST output.
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_strip_internal_fields( array $status ) {
	unset( $status['_quota'], $status['_model_lv'] );

	if ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) ) {
		unset( $status['secondary']['_quota'] );
	}

	return $status;
}

/**
 * Quota keys for Delta 1500 main pack SOC (excludes bms_slave / Extra).
 *
 * @return array<int, string>
 */
function gaming_hub_ecoflow_delta1500_main_soc_keys() {
	return array(
		'bms_bmsStatus.f32ShowSoc',
		'bms_bmsStatus.soc',
		'pd.soc',
		'bms.f32ShowSoc',
		'bms.soc',
	);
}

/**
 * Clamp a live MQTT SOC to one decimal for display.
 *
 * @param mixed $soc Raw percent.
 * @return float|null
 */
function gaming_hub_ecoflow_round_soc( $soc ) {
	if ( null === $soc || ! is_numeric( $soc ) ) {
		return null;
	}

	return round( max( 0, min( 100, (float) $soc ) ), 1 );
}

/**
 * Low Volt input as 50% of High Volt (theoretical).
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_theoretical_lv( array $status ) {
	if ( gaming_hub_ecoflow_has_live_lv( $status ) ) {
		$live_lv = max( 0, (int) ( $status['secondary']['solar_in'] ?? $status['solar_delta'] ?? 0 ) );
		$source  = (string) ( $status['secondary']['solar_in_source'] ?? 'mqtt' );

		$status['hv_in']           = (int) round( max( 0, (float) ( $status['hv_in'] ?? 0 ) ) );
		$status['solar_in']        = $live_lv;
		$status['solar_delta']     = $live_lv;
		$status['solar_in_source'] = $source;

		if ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) ) {
			$status['secondary']['solar_in']        = $live_lv;
			$status['secondary']['solar_in_source'] = $source;
		}

		return $status;
	}

	$hv = max( 0, (float) ( $status['hv_in'] ?? 0 ) );
	if ( $hv <= 0 && ! empty( $status['pro']['hv_in'] ) ) {
		$hv = max( 0, (float) $status['pro']['hv_in'] );
	}

	$status['hv_in']           = (int) round( $hv );
	$status['_model_lv']       = (int) round( $hv * GAMING_HUB_ECOFLOW_DELTA1500_LV_RATIO );
	$status['solar_in']        = null;
	$status['solar_delta']     = null;
	$status['solar_in_source'] = '';

	if ( isset( $status['secondary'] ) && is_array( $status['secondary'] ) ) {
		if ( ! gaming_hub_ecoflow_delta1500_has_live_solar( $status['secondary'] ) ) {
			$status['secondary']['solar_in']        = null;
			$status['secondary']['solar_in_source'] = '';
		}
	}

	return $status;
}

/**
 * Delta 3 1500 pack energy from parsed quota (Wh + SOC).
 *
 * @param array<string, mixed>      $parsed Parsed device metrics.
 * @param array<string, mixed>|null $quota  Raw quota map.
 * @return array{capacity_wh: int, remain_capacity: int|null, battery_percent: int|null, capacity_source: string}
 */
function gaming_hub_ecoflow_delta1500_energy_from_parsed( array $parsed, $quota = null ) {
	$quota = is_array( $quota ) ? $quota : array();

	$pack                  = gaming_hub_ecoflow_parse_pack_capacity_wh( $quota );
	$full_wh               = (int) $pack['capacity_wh'];
	$capacity_source       = (string) $pack['capacity_source'];

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
		'capacity_source' => $capacity_source,
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
	$quota = gaming_hub_ecoflow_delta1500_quota( $delta );

	if ( empty( $quota ) ) {
		return false;
	}

	$main_soc = gaming_hub_ecoflow_quota_value_live(
		$quota,
		gaming_hub_ecoflow_delta1500_main_soc_keys()
	);

	return null !== $main_soc;
}

/**
 * Whether Delta 1500 has live solar input from MQTT.
 *
 * @param array<string, mixed> $delta Secondary device status.
 */
function gaming_hub_ecoflow_delta1500_has_live_solar( array $delta ) {
	if ( ! empty( $delta['inferred'] ) ) {
		return false;
	}

	return gaming_hub_ecoflow_delta1500_quota_has_solar( gaming_hub_ecoflow_delta1500_quota( $delta ) );
}

/**
 * Attach MQTT bridge quota to inferred secondary (Extra SOC, AC out).
 *
 * @param array<string, mixed> $delta Secondary device slice.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_merge_bridge_quota( array $delta ) {
	$sn = (string) ( $delta['device_sn'] ?? '' );
	if ( '' === $sn ) {
		return $delta;
	}

	$quota = gaming_hub_ecoflow_read_bridge_quota( $sn );
	if ( ! is_array( $quota ) || empty( $quota ) ) {
		unset( $delta['_quota'] );
		return $delta;
	}

	$delta['_quota'] = $quota;
	$delta['extra']  = gaming_hub_ecoflow_parse_extra_battery( $quota );

	$main = gaming_hub_ecoflow_parse_main_pack( $quota, $delta );
	if ( null !== $main['battery_percent'] ) {
		$delta['battery_percent'] = $main['battery_percent'];
		$delta['capacity_wh']     = $main['capacity_wh'];
		$delta['remain_capacity'] = $main['remain_capacity'];
		$delta['capacity_source'] = $main['capacity_source'];
		$delta['soc_source']      = 'mqtt';
	}

	if ( gaming_hub_ecoflow_delta1500_quota_has_solar( $quota ) ) {
		$solar = gaming_hub_ecoflow_delta1500_solar_from_quota( $quota );
		if ( null !== $solar ) {
			$delta['solar_in']        = max( 0, (int) round( $solar ) );
			$delta['solar_in_source'] = 'mqtt';
		}
	}

	$ac_out = gaming_hub_ecoflow_ac_output_watts( $quota );
	if ( null !== $ac_out && $ac_out >= 0 ) {
		$delta['ac_out'] = (int) round( $ac_out );
	}

	$ac_in = gaming_hub_ecoflow_ac_input_watts( $quota );
	if ( null !== $ac_in && $ac_in >= 0 ) {
		$delta['ac_in'] = (int) round( $ac_in );
	}

	return gaming_hub_ecoflow_attach_device_pack_eta( gaming_hub_ecoflow_sync_device_activity( $delta ) );
}

/**
 * Default main-pack energy fields for Delta 3 1500 (excluding Extra).
 *
 * @param int|float|null $soc Battery percent.
 * @return array{capacity_wh: int, remain_capacity: int|null, capacity_source: string}
 */
function gaming_hub_ecoflow_main_pack_defaults( $soc = null ) {
	$full   = gaming_hub_ecoflow_main_pack_default_wh();
	$remain = null;

	if ( null !== $soc && is_numeric( $soc ) ) {
		$remain = (int) round( $full * ( max( 0, min( 100, (float) $soc ) ) / 100 ) );
	}

	return array(
		'capacity_wh'     => $full,
		'remain_capacity' => $remain,
		'capacity_source' => 'default',
	);
}

/**
 * Normalize live Delta 1500 pack metrics for dashboard + flow.
 *
 * @param array<string, mixed> $delta Secondary device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_delta1500_live_energy( array $delta ) {
	$quota = isset( $delta['_quota'] ) && is_array( $delta['_quota'] ) ? $delta['_quota'] : array();
	$main  = gaming_hub_ecoflow_parse_main_pack( $quota, $delta );
	$extra = gaming_hub_ecoflow_parse_extra_battery( $quota );

	$delta['battery_percent'] = $main['battery_percent'];
	$delta['capacity_wh']     = $main['capacity_wh'];
	$delta['remain_capacity'] = $main['remain_capacity'];
	$delta['capacity_source'] = $main['capacity_source'];
	$delta['extra']           = $extra;
	$delta['soc_source']      = ! empty( $delta['source'] ) ? (string) $delta['source'] : 'device';

	return gaming_hub_ecoflow_attach_device_pack_eta( $delta );
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
		$full                = (int) ( $status['secondary']['capacity_wh'] ?? GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH );
		return $status;
	}

	$full         = gaming_hub_ecoflow_main_pack_default_wh();
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
	} elseif ( isset( $status['_model_lv'] ) && is_numeric( $status['_model_lv'] ) ) {
		$lv_w = max( 0, (float) $status['_model_lv'] );
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

	$delta['battery_percent'] = null;
	$delta['capacity_wh']     = $full;
	$delta['capacity_source'] = ! empty( $delta['capacity_source'] ) ? (string) $delta['capacity_source'] : 'default';
	$delta['remain_capacity'] = null;
	$delta['_model_soc']      = $soc;
	$delta['_model_remain']   = $remain;
	$delta['soc_source']      = 'unavailable';
	$delta['ups_wh_used']     = round( (float) ( $state['ups_wh'] ?? 0 ), 1 );
	$delta['ac_in_wh']        = round( (float) ( $state['ac_in_wh'] ?? 0 ), 1 );
	$delta['lv_in_wh']        = round( (float) ( $state['lv_in_wh'] ?? 0 ), 1 );
	$delta                    = gaming_hub_ecoflow_merge_bridge_quota( $delta );

	$status['secondary'] = $delta;

	return $status;
}

/**
 * Default Delta 3 1500 main pack capacity (excluding Extra Battery).
 */
function gaming_hub_ecoflow_main_pack_default_wh() {
	return max(
		500,
		(int) GAMING_HUB_ECOFLOW_DELTA1500_CAPACITY_WH - (int) GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH
	);
}

/**
 * Parse Delta 3 1500 main unit SOC / capacity / remain from quota.
 *
 * @param array<string, mixed> $quota  Raw quota map.
 * @param array<string, mixed> $parsed Optional parsed metrics fallback.
 * @return array{battery_percent: int|null, capacity_wh: int, remain_capacity: int|null, capacity_source: string}
 */
function gaming_hub_ecoflow_parse_main_pack( $quota, $parsed = array() ) {
	$quota       = is_array( $quota ) ? $quota : array();
	$parsed      = is_array( $parsed ) ? $parsed : array();
	$default_cap = gaming_hub_ecoflow_main_pack_default_wh();

	$soc = gaming_hub_ecoflow_quota_value_live(
		$quota,
		gaming_hub_ecoflow_delta1500_main_soc_keys()
	);

	if ( null === $soc && isset( $parsed['battery_percent'] ) && is_numeric( $parsed['battery_percent'] ) ) {
		$soc = (float) $parsed['battery_percent'];
	}

	$cap_wh = gaming_hub_ecoflow_normalize_cap_to_wh(
		gaming_hub_ecoflow_quota_value_live(
			$quota,
			array(
				'bms_bmsStatus.fullCap',
				'bms.fullCap',
				'bms_bmsStatus.designCap',
				'bms.designCap',
				'pd.fullCap',
				'bmsFullCap',
				'bmsDesignCap',
			)
		)
	);
	if ( null === $cap_wh || $cap_wh < 400 ) {
		$cap_wh = (float) $default_cap;
		$source = 'default';
	} else {
		$source = 'device';
	}

	$remain_wh = gaming_hub_ecoflow_quota_value( $quota, array( 'cmsBattRemainEnergy' ) );
	if ( null === $remain_wh && isset( $parsed['remain_capacity'] ) && is_numeric( $parsed['remain_capacity'] ) ) {
		$candidate = (float) $parsed['remain_capacity'];
		if ( $candidate > 0 && $candidate <= $cap_wh * 1.05 ) {
			$remain_wh = $candidate;
		}
	}

	if ( null === $remain_wh ) {
		$remain_mah = gaming_hub_ecoflow_quota_value( $quota, array( 'bmsRemainCap', 'remainCap', 'pd.remainCap' ) );
		$full_mah   = gaming_hub_ecoflow_quota_value( $quota, array( 'bmsFullCap', 'fullCap', 'pd.fullCap' ) );
		if ( null !== $remain_mah && null !== $full_mah && $full_mah > 0 && $cap_wh > 0 ) {
			$remain_wh = $cap_wh * ( $remain_mah / $full_mah );
		}
	}

	if ( null === $remain_wh && null !== $soc && $cap_wh > 0 ) {
		$remain_wh = $cap_wh * ( max( 0, min( 100, $soc ) ) / 100.0 );
	}

	if ( null !== $remain_wh && null === $soc && $cap_wh > 0 ) {
		$soc = 100 * $remain_wh / $cap_wh;
	}

	return array(
		'battery_percent' => gaming_hub_ecoflow_round_soc( $soc ),
		'capacity_wh'     => (int) round( $cap_wh ),
		'remain_capacity' => null !== $remain_wh ? (int) round( $remain_wh ) : null,
		'capacity_source' => $source,
	);
}

/**
 * Extra Battery 1kW attached to Delta 3 1500.
 *
 * @param array<string, mixed> $quota        Raw quota map.
 * @param int|null             $combined_soc Combined / main SOC fallback.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_parse_extra_battery( $quota ) {
	$quota = is_array( $quota ) ? $quota : array();

	$soc = gaming_hub_ecoflow_quota_value_live(
		$quota,
		array(
			'bms_slave.f32ShowSoc',
			'bms_slave.soc',
			'bmsSlaveSoc',
			'bp2Soc',
			'bms_kitInfo.soc',
			'bms_bmsKitStatus.soc',
			'kitBattSoc',
			'extraBattSoc',
		)
	);

	$extra_cap = gaming_hub_ecoflow_normalize_cap_to_wh(
		gaming_hub_ecoflow_quota_value_live(
			$quota,
			array( 'bms_slave.fullCap', 'bms_slave.designCap' )
		)
	);

	$capacity_wh = ( null !== $extra_cap && $extra_cap >= 200 )
		? (int) round( $extra_cap )
		: (int) GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH;

	$source = ( null !== $extra_cap && $extra_cap >= 200 ) ? 'device' : 'default';
	if ( 'default' === $source && null !== $soc ) {
		$source = 'mqtt';
	}

	$remain_wh = null;
	if ( null !== $soc && $capacity_wh > 0 ) {
		$remain_wh = $capacity_wh * ( max( 0, min( 100, (float) $soc ) ) / 100.0 );
	}

	$remain_mah = gaming_hub_ecoflow_quota_value( $quota, array( 'bms_slave.remainCap', 'bms_slave.remainCap' ) );
	$full_mah   = gaming_hub_ecoflow_quota_value( $quota, array( 'bms_slave.fullCap' ) );
	if ( null === $remain_wh && null !== $remain_mah && null !== $full_mah && $full_mah > 0 && $capacity_wh > 0 ) {
		$remain_wh = $capacity_wh * ( $remain_mah / $full_mah );
	}

	$in_w  = gaming_hub_ecoflow_quota_value_live( $quota, array( 'bms_slave.inputWatts' ) );
	$out_w = gaming_hub_ecoflow_quota_value_live( $quota, array( 'bms_slave.outputWatts' ) );
	$amp   = gaming_hub_ecoflow_quota_value_live( $quota, array( 'bms_slave.amp' ) );
	$vol   = gaming_hub_ecoflow_quota_value_live( $quota, array( 'bms_slave.vol' ) );
	if ( ( null === $out_w || $out_w < 1 ) && ( null === $in_w || $in_w < 1 ) && null !== $amp && abs( $amp ) > 200 && null !== $vol ) {
		$vol_v = $vol > 1000 ? $vol / 1000.0 : (float) $vol;
		$amp_a = abs( $amp ) > 100 ? abs( $amp ) / 1000.0 : abs( $amp );
		$watts = $amp_a * $vol_v;
		if ( $amp < 0 ) {
			$out_w = $watts;
		} else {
			$in_w = $watts;
		}
	}

	$extra = array(
		'connected'        => true,
		'battery_percent'  => gaming_hub_ecoflow_round_soc( $soc ),
		'capacity_wh'      => $capacity_wh,
		'remain_capacity'  => null !== $remain_wh ? (int) round( $remain_wh ) : null,
		'capacity_source'  => $source,
		'input_watts'      => null !== $in_w ? max( 0, (int) round( $in_w ) ) : 0,
		'output_watts'     => null !== $out_w ? max( 0, (int) round( $out_w ) ) : 0,
		'reported_remain'  => gaming_hub_ecoflow_quota_value_live( $quota, array( 'bms_slave.remainTime' ) ),
	);

	return gaming_hub_ecoflow_apply_pack_eta( $extra, $extra['reported_remain'], true );
}

/**
 * Normalized Extra Battery payload for the 1500.
 *
 * @param int|float|null $soc Battery percent.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_extra_battery_slice( $soc = null, $capacity_wh = null ) {
	$percent = gaming_hub_ecoflow_round_soc( $soc );

	$capacity_wh = null !== $capacity_wh && is_numeric( $capacity_wh ) && $capacity_wh > 0
		? (int) round( $capacity_wh )
		: (int) GAMING_HUB_ECOFLOW_DELTA1500_EXTRA_WH;

	$remain = null;
	if ( null !== $percent && $capacity_wh > 0 ) {
		$remain = (int) round( $capacity_wh * ( $percent / 100.0 ) );
	}

	return array(
		'connected'            => true,
		'battery_percent'      => $percent,
		'capacity_wh'          => $capacity_wh,
		'remain_capacity'      => $remain,
		'capacity_source'      => 'default',
		'input_watts'          => 0,
		'output_watts'         => 0,
		'remain_time'          => null,
		'remain_time_label'    => '',
		'remain_time_display'  => '—',
		'eta_mode'             => 'idle',
		'is_charging'          => false,
		'is_discharging'       => false,
	);
}

/**
 * Device remain-time in minutes, ignoring stale / signed EcoFlow sentinels.
 *
 * @param mixed $value Raw remain minutes.
 * @return int|null
 */
function gaming_hub_ecoflow_sane_remain_minutes( $value ) {
	if ( null === $value || ! is_numeric( $value ) ) {
		return null;
	}

	$minutes = (float) $value;
	if ( $minutes <= 0 || $minutes > 2880 ) {
		return null;
	}

	return max( 1, (int) round( $minutes ) );
}

/**
 * Pack input and output watts (dedicated fields, else device totals).
 *
 * @param array<string, mixed> $pack Device or Extra slice.
 * @return array{0: float, 1: float}
 */
function gaming_hub_ecoflow_pack_net_in_out( array $pack ) {
	if ( isset( $pack['input_watts'] ) && is_numeric( $pack['input_watts'] ) ) {
		$in = max( 0, (float) $pack['input_watts'] );
	} else {
		$in = max( 0, (float) ( $pack['input_total'] ?? 0 ) );
	}

	if ( isset( $pack['output_watts'] ) && is_numeric( $pack['output_watts'] ) ) {
		$out = max( 0, (float) $pack['output_watts'] );
	} else {
		$out = max( 0, (float) ( $pack['output_total'] ?? $pack['ac_out'] ?? 0 ) );
	}

	return array( $in, $out );
}

/**
 * Charge watts for a pack (net input).
 *
 * @param array<string, mixed> $pack Device or Extra slice.
 */
function gaming_hub_ecoflow_pack_charge_watts( array $pack ) {
	list( $in, $out ) = gaming_hub_ecoflow_pack_net_in_out( $pack );

	return max( 0, $in - $out );
}

/**
 * Discharge watts for a pack (net output).
 *
 * @param array<string, mixed> $pack Device or Extra slice.
 */
function gaming_hub_ecoflow_pack_discharge_watts( array $pack ) {
	list( $in, $out ) = gaming_hub_ecoflow_pack_net_in_out( $pack );

	return max( 0, $out - $in );
}

/**
 * Minutes to 0% (discharge) or full (charge) from SOC, capacity, and watts.
 *
 * @param array<string, mixed> $pack         Device or Extra slice.
 * @param mixed                $reported_min Optional device remain minutes.
 * @return array{mode: string, minutes: int|null, label: string, display: string}
 */
function gaming_hub_ecoflow_estimate_pack_eta( array $pack, $reported_min = null ) {
	$idle = array(
		'mode'    => 'idle',
		'minutes' => null,
		'label'   => '',
		'display' => '—',
	);

	$soc = $pack['battery_percent'] ?? null;
	if ( null === $soc || ! is_numeric( $soc ) ) {
		return $idle;
	}

	$soc = max( 0.0, min( 100.0, (float) $soc ) );
	$cap = isset( $pack['capacity_wh'] ) && is_numeric( $pack['capacity_wh'] ) && $pack['capacity_wh'] > 0
		? (float) $pack['capacity_wh']
		: 0.0;

	$charge_w = gaming_hub_ecoflow_pack_charge_watts( $pack );
	$dsg_w    = gaming_hub_ecoflow_pack_discharge_watts( $pack );
	$thr      = defined( 'GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W' ) ? (int) GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W : 8;

	$charging    = $charge_w >= $thr && $charge_w >= $dsg_w;
	$discharging = $dsg_w >= $thr && $dsg_w > $charge_w;

	if ( ! $charging && ! $discharging ) {
		return $idle;
	}

	$remain_wh = isset( $pack['remain_capacity'] ) && is_numeric( $pack['remain_capacity'] )
		? max( 0.0, (float) $pack['remain_capacity'] )
		: ( $cap * $soc / 100.0 );
	if ( $cap > 0 ) {
		$remain_wh = min( $remain_wh, $cap );
	}
	$to_full_wh = $cap > 0 ? max( 0.0, $cap - $remain_wh ) : 0.0;

	$est  = null;
	$mode = $charging ? 'charge' : 'discharge';
	if ( $charging ) {
		$label = __( '満タンまで', 'gaming-hub' );
		if ( $soc >= 99.5 ) {
			return array(
				'mode'    => $mode,
				'minutes' => 0,
				'label'   => $label,
				'display' => __( '満タン', 'gaming-hub' ),
			);
		}
		if ( $charge_w > 0 && $to_full_wh > 0 ) {
			$est = (int) round( $to_full_wh / $charge_w * 60 );
		}
	} else {
		$label = __( '0%まで', 'gaming-hub' );
		if ( $soc <= 0.5 ) {
			return array(
				'mode'    => $mode,
				'minutes' => 0,
				'label'   => $label,
				'display' => '0%',
			);
		}
		if ( $dsg_w > 0 && $remain_wh > 0 ) {
			$est = (int) round( $remain_wh / $dsg_w * 60 );
		}
	}

	$reported = gaming_hub_ecoflow_sane_remain_minutes( $reported_min );
	$minutes  = $est;
	if ( null !== $reported && ( null === $est || ( $reported >= $est * 0.4 && $reported <= max( $est * 2.5, $est + 30 ) ) ) ) {
		$minutes = $reported;
	}

	if ( null === $minutes || $minutes < 0 ) {
		return array(
			'mode'    => $mode,
			'minutes' => null,
			'label'   => $label,
			'display' => '—',
		);
	}

	$minutes = max( 1, min( 2880, $minutes ) );

	return array(
		'mode'    => $mode,
		'minutes' => $minutes,
		'label'   => $label,
		'display' => gaming_hub_format_ecoflow_minutes( $minutes ),
	);
}

/**
 * Attach ETA fields to a pack slice.
 *
 * @param array<string, mixed> $pack         Device or Extra slice.
 * @param mixed                $reported_min Optional remain minutes.
 * @param bool                 $sync_flags   Also set is_charging / is_discharging from watts.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_pack_eta( array $pack, $reported_min = null, $sync_flags = false ) {
	$eta                           = gaming_hub_ecoflow_estimate_pack_eta( $pack, $reported_min );
	$pack['remain_time']           = $eta['minutes'];
	$pack['remain_time_label']     = $eta['label'];
	$pack['remain_time_display']   = $eta['display'];
	$pack['eta_mode']              = $eta['mode'];

	if ( $sync_flags ) {
		$pack['is_charging']    = 'charge' === $eta['mode'];
		$pack['is_discharging'] = 'discharge' === $eta['mode'];
	}

	return $pack;
}

/**
 * Split Extra watts out of a 1500/Pro slice and attach SOC remaining time.
 *
 * @param array<string, mixed> $parsed Normalized device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_attach_device_pack_eta( array $parsed ) {
	$extra     = isset( $parsed['extra'] ) && is_array( $parsed['extra'] ) ? $parsed['extra'] : null;
	$extra_in  = $extra ? max( 0, (float) ( $extra['input_watts'] ?? 0 ) ) : 0;
	$extra_out = $extra ? max( 0, (float) ( $extra['output_watts'] ?? 0 ) ) : 0;
	$extra_soc = $extra ? ( $extra['battery_percent'] ?? null ) : null;

	$in_tot = max(
		0,
		(float) ( $parsed['input_total'] ?? 0 ),
		(float) ( $parsed['solar_in'] ?? 0 ) + (float) ( $parsed['ac_in'] ?? 0 )
	);
	$out_tot = max(
		0,
		(float) ( $parsed['output_total'] ?? 0 ),
		(float) ( $parsed['ac_out'] ?? 0 )
	);

	$parsed['input_watts']  = max( 0, $in_tot - $extra_in );
	$parsed['output_watts'] = max( 0, $out_tot - $extra_out );

	if (
		( ! isset( $parsed['capacity_wh'] ) || ! is_numeric( $parsed['capacity_wh'] ) || $parsed['capacity_wh'] <= 0 )
		&& null === $extra_soc
		&& defined( 'GAMING_HUB_ECOFLOW_PRO_CAPACITY_WH' )
	) {
		$parsed['capacity_wh'] = (int) GAMING_HUB_ECOFLOW_PRO_CAPACITY_WH;
	}

	$reported = $parsed['remain_time'] ?? null;
	if ( null !== $extra_soc ) {
		$reported = null;
	}

	return gaming_hub_ecoflow_apply_pack_eta( $parsed, $reported, false );
}

/**
 * Cast a watt value to int, or null when missing.
 *
 * @param mixed $value Raw watts.
 * @return int|null
 */
function gaming_hub_ecoflow_nullable_watts( $value ) {
	if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
		return null;
	}

	return (int) round( (float) $value );
}

/**
 * Build initial payload for the React energy-flow diagram.
 *
 * @param array<string, mixed> $status Normalized device status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_device_flow_slice( array $device ) {
	$extra = isset( $device['extra'] ) && is_array( $device['extra'] )
		? $device['extra']
		: gaming_hub_ecoflow_extra_battery_slice();
	$extra = gaming_hub_ecoflow_apply_pack_eta(
		$extra,
		$extra['reported_remain'] ?? ( $extra['remain_time'] ?? null ),
		true
	);

	$extra_in  = max( 0, (float) ( $extra['input_watts'] ?? 0 ) );
	$extra_out = max( 0, (float) ( $extra['output_watts'] ?? 0 ) );
	$in_tot    = max(
		0,
		(float) ( $device['input_total'] ?? 0 ),
		(float) ( $device['solar_in'] ?? 0 ) + (float) ( $device['ac_in'] ?? 0 )
	);
	$out_tot = max(
		0,
		(float) ( $device['output_total'] ?? 0 ),
		(float) ( $device['ac_out'] ?? 0 )
	);

	if ( ! isset( $device['input_watts'] ) || ! is_numeric( $device['input_watts'] ) ) {
		$device['input_watts'] = max( 0, $in_tot - $extra_in );
	}
	if ( ! isset( $device['output_watts'] ) || ! is_numeric( $device['output_watts'] ) ) {
		$device['output_watts'] = max( 0, $out_tot - $extra_out );
	}

	$reported = $device['remain_time'] ?? null;
	if ( null !== ( $extra['battery_percent'] ?? null ) ) {
		$reported = null;
	}
	$device = gaming_hub_ecoflow_apply_pack_eta( $device, $reported, false );

	$mqtt_live = ! empty( $device['mqtt_live'] );

	return array(
		'device_name'         => $device['device_name'] ?? '',
		'device_sn'           => $device['device_sn'] ?? '',
		'online'              => ! empty( $device['online'] ),
		'mqtt_live'           => $mqtt_live,
		'soc_source'          => isset( $device['soc_source'] ) ? (string) $device['soc_source'] : '',
		'solar_in'            => gaming_hub_ecoflow_nullable_watts( $device['solar_in'] ?? null ),
		'hv_in'               => gaming_hub_ecoflow_nullable_watts( $device['hv_in'] ?? null ),
		'ac_in'               => gaming_hub_ecoflow_nullable_watts( $device['ac_in'] ?? null ),
		'ac_out'              => gaming_hub_ecoflow_nullable_watts( $device['ac_out'] ?? null ),
		'dc_out'              => gaming_hub_ecoflow_nullable_watts( $device['dc_out'] ?? null ),
		'input_total'         => gaming_hub_ecoflow_nullable_watts( $device['input_total'] ?? null ),
		'output_total'        => gaming_hub_ecoflow_nullable_watts( $device['output_total'] ?? null ),
		'input_watts'         => gaming_hub_ecoflow_nullable_watts( $device['input_watts'] ?? null ),
		'output_watts'        => gaming_hub_ecoflow_nullable_watts( $device['output_watts'] ?? null ),
		'battery_percent'     => gaming_hub_ecoflow_round_soc( $device['battery_percent'] ?? null ),
		'is_charging'         => ! empty( $device['is_charging'] ),
		'is_discharging'      => ! empty( $device['is_discharging'] ),
		'charge_state'        => $device['charge_state'] ?? '',
		'remain_time'         => $device['remain_time'] ?? null,
		'remain_time_label'   => $device['remain_time_label'] ?? '',
		'remain_time_display' => $device['remain_time_display'] ?? '—',
		'eta_mode'            => $device['eta_mode'] ?? 'idle',
		'capacity_wh'         => isset( $device['capacity_wh'] ) ? (int) $device['capacity_wh'] : null,
		'capacity_source'     => isset( $device['capacity_source'] ) ? (string) $device['capacity_source'] : '',
		'solar_in_source'     => isset( $device['solar_in_source'] ) ? (string) $device['solar_in_source'] : '',
		'remain_capacity'     => isset( $device['remain_capacity'] ) && null !== $device['remain_capacity']
			? (int) round( (float) $device['remain_capacity'] )
			: null,
		'extra'               => $extra,
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
		'solar_in'        => null,
		'hv_in'           => 0,
		'input_total'     => null,
		'output_total'    => null,
		'ac_in'           => null,
		'ac_out'          => null,
		'dc_out'          => null,
		'battery_temp'    => null,
		'remain_time'     => null,
		'is_charging'     => false,
		'is_discharging'  => false,
		'mqtt_live'       => false,
		'soc_source'      => 'unavailable',
		'solar_in_source' => 'unavailable',
		'charge_state'    => __( '未取得', 'gaming-hub' ),
		'inferred'        => true,
		'inferred_note'   => __( 'Pro とは独立。Low Volt ソーラーは 1500 へ入力。Extra Battery 1kW 接続。合算 2.5 kWh。ライブ計測は MQTT ブリッジ待ち。', 'gaming-hub' ),
		'updated_at'      => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		'extra'           => gaming_hub_ecoflow_extra_battery_slice(),
	);

	return gaming_hub_ecoflow_merge_bridge_quota(
		array_merge( $delta, gaming_hub_ecoflow_main_pack_defaults( $delta['battery_percent'] ) )
	);
}

/**
 * Theme-relative EcoFlow diagram image URL.
 *
 * @param string $file Filename in assets/images.
 */
function gaming_hub_ecoflow_image_url( $file ) {
	return add_query_arg(
		'v',
		GAMING_HUB_VERSION,
		get_template_directory_uri() . '/assets/images/' . ltrim( $file, '/' )
	);
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
		'solar_in'            => $delta_slice['solar_in'],
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
		'ups_out'             => 'ecoflow' === gaming_hub_ecoflow_ups_source( $status )
			? gaming_hub_ecoflow_ups_watts( $status, 0 )
			: null,
		'ups_source'          => gaming_hub_ecoflow_ups_source( $status ),
		'solar_in_source'     => $status['solar_in_source'] ?? '',
		'extra'               => isset( $delta_slice['extra'] ) && is_array( $delta_slice['extra'] )
			? $delta_slice['extra']
			: gaming_hub_ecoflow_extra_battery_slice(),
		'today_yen'           => function_exists( 'gaming_hub_ecoflow_energy_today_yen' )
			? gaming_hub_ecoflow_energy_today_yen( $status )
			: array(),
		'today_solar'         => function_exists( 'gaming_hub_ecoflow_energy_today_solar' )
			? gaming_hub_ecoflow_energy_today_solar( $status )
			: array(),
		'today_usage'         => function_exists( 'gaming_hub_ecoflow_energy_today_usage' )
			? gaming_hub_ecoflow_energy_today_usage( $status )
			: array(),
	);

	return $payload;
}

/**
 * Use Delta 1500 live MQTT AC output for UPS when SwitchBot is unavailable.
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_attach_ups_ac_out( array $status ) {
	$delta = isset( $status['secondary'] ) && is_array( $status['secondary'] )
		? $status['secondary']
		: array();

	$ac_out = gaming_hub_ecoflow_ac_output_watts( gaming_hub_ecoflow_delta1500_quota( $delta ) );
	if ( null === $ac_out && isset( $delta['ac_out'] ) && is_numeric( $delta['ac_out'] ) ) {
		$ac_out = (float) $delta['ac_out'];
	}

	if ( null === $ac_out ) {
		return $status;
	}

	$watts = (int) round( max( 0, (float) $ac_out ) );
	$status['secondary']['ac_out'] = $watts;
	$status['secondary']           = gaming_hub_ecoflow_sync_device_activity( $status['secondary'] );
	$status['ups_plug']            = array(
		'watts'      => $watts,
		'source'     => 'ecoflow',
		'online'     => ! empty( $delta['online'] ),
		'updated_at' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
	);

	return $status;
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
	if ( isset( $status['ups_plug']['source'] ) && '' !== (string) $status['ups_plug']['source'] ) {
		return (string) $status['ups_plug']['source'];
	}

	if ( isset( $status['ups_plug']['watts'] ) && is_numeric( $status['ups_plug']['watts'] ) ) {
		return 'switchbot';
	}

	return 'unavailable';
}

/**
 * Label for values that require live MQTT.
 */
function gaming_hub_ecoflow_unavailable_label() {
	return __( '未取得', 'gaming-hub' );
}

/**
 * Format battery percent for dashboard display.
 *
 * @param float|int|null $value Percent.
 */
function gaming_hub_format_ecoflow_percent( $value ) {
	if ( null === $value || ! is_numeric( $value ) ) {
		return gaming_hub_ecoflow_unavailable_label();
	}

	$soc = gaming_hub_ecoflow_round_soc( $value );
	if ( null === $soc ) {
		return gaming_hub_ecoflow_unavailable_label();
	}

	if ( abs( $soc - round( $soc ) ) < 0.05 ) {
		return (int) round( $soc ) . '%';
	}

	return number_format_i18n( $soc, 1 ) . '%';
}

/**
 * Format UPS AC watts (MQTT only while SwitchBot is disabled).
 *
 * @param array<string, mixed> $status EcoFlow status.
 */
function gaming_hub_format_ecoflow_ups( array $status ) {
	if ( 'ecoflow' !== gaming_hub_ecoflow_ups_source( $status ) ) {
		return gaming_hub_ecoflow_unavailable_label();
	}

	return gaming_hub_format_ecoflow_watts( gaming_hub_ecoflow_ups_watts( $status, 0 ) );
}

/**
 * Format Delta 1500 Low Volt solar for dashboard display.
 *
 * @param array<string, mixed> $status EcoFlow status.
 */
function gaming_hub_format_ecoflow_delta_solar( array $status ) {
	$secondary = isset( $status['secondary'] ) && is_array( $status['secondary'] )
		? $status['secondary']
		: array();
	$watts     = $secondary['solar_in'] ?? $status['solar_delta'] ?? $status['solar_in'] ?? null;

	if ( null !== $watts && is_numeric( $watts ) ) {
		return gaming_hub_format_ecoflow_watts( $watts );
	}

	$source = (string) ( $secondary['solar_in_source'] ?? $status['solar_in_source'] ?? '' );
	if ( 'unavailable' === $source ) {
		return gaming_hub_ecoflow_unavailable_label();
	}

	return gaming_hub_ecoflow_unavailable_label();
}

/**
 * Label for Delta 1500 SOC card.
 *
 * @param array<string, mixed> $device Secondary device slice.
 */
function gaming_hub_ecoflow_pack_soc_label( array $device ) {
	if ( 'unavailable' === (string) ( $device['soc_source'] ?? '' ) ) {
		return __( '残量 (1500 · 未取得)', 'gaming-hub' );
	}

	return __( '残量 (1500 · 実測)', 'gaming-hub' );
}

/**
 * Whether Extra Battery SOC is from live MQTT quota.
 *
 * @param array<string, mixed> $extra Extra battery slice.
 */
function gaming_hub_ecoflow_extra_has_mqtt_soc( array $extra ) {
	return isset( $extra['battery_percent'] ) && is_numeric( $extra['battery_percent'] );
}

/**
 * Extra Battery SOC: live MQTT, last known within 10 minutes, or n/a.
 *
 * Extra BMS publishes less often than the Delta 1500 pack. Keep the last
 * Extra reading after the 1500 live window closes.
 *
 * @param array<string, mixed> $delta Secondary device slice.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_resolve_extra_battery( array $delta ) {
	$empty = gaming_hub_ecoflow_extra_battery_slice();
	$sn    = (string) ( $delta['device_sn'] ?? '' );
	$quota = isset( $delta['_quota'] ) && is_array( $delta['_quota'] ) ? $delta['_quota'] : array();

	if ( empty( $quota ) && '' !== $sn && function_exists( 'gaming_hub_ecoflow_read_bridge_quota_file' ) ) {
		$file = gaming_hub_ecoflow_read_bridge_quota_file( $sn );
		if ( is_array( $file ) && ! empty( $file ) ) {
			$quota = $file;
		}
	}

	$has_ts      = function_exists( 'gaming_hub_ecoflow_bridge_extra_updated_ts' ) && gaming_hub_ecoflow_bridge_extra_updated_ts() > 0;
	$fresh       = function_exists( 'gaming_hub_ecoflow_extra_is_fresh' ) && gaming_hub_ecoflow_extra_is_fresh();
	$live_extra  = function_exists( 'gaming_hub_ecoflow_extra_is_live' ) && gaming_hub_ecoflow_extra_is_live();
	$bridge_live = function_exists( 'gaming_hub_ecoflow_bridge_is_live' ) && gaming_hub_ecoflow_bridge_is_live( $sn );

	if ( $has_ts && ! $fresh ) {
		return $empty;
	}

	if ( ! $has_ts && ! $bridge_live ) {
		return $empty;
	}

	$extra = gaming_hub_ecoflow_parse_extra_battery( $quota );
	if ( ! gaming_hub_ecoflow_extra_has_mqtt_soc( $extra ) ) {
		return $empty;
	}

	if ( $has_ts && ! $live_extra ) {
		$extra['capacity_source'] = 'stale';
	}

	return $extra;
}

/**
 * Hide inferred / baseline Delta 1500 values when MQTT data is missing.
 *
 * @param array<string, mixed> $status EcoFlow status.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_apply_mqtt_display_policy( array $status ) {
	$delta = isset( $status['secondary'] ) && is_array( $status['secondary'] )
		? $status['secondary']
		: array();

	$sn   = (string) ( $delta['device_sn'] ?? '' );
	$live = function_exists( 'gaming_hub_ecoflow_bridge_is_live' )
		&& gaming_hub_ecoflow_bridge_is_live( $sn );

	if ( $live && empty( $delta['_quota'] ) && function_exists( 'gaming_hub_ecoflow_read_bridge_quota' ) ) {
		$fresh = gaming_hub_ecoflow_read_bridge_quota( $sn );
		if ( is_array( $fresh ) && ! empty( $fresh ) ) {
			$delta['_quota'] = $fresh;
		}
	}

	if ( ! $live ) {
		foreach ( array( 'solar_in', 'ac_in', 'ac_out', 'dc_out', 'input_total', 'output_total' ) as $key ) {
			$delta[ $key ] = null;
		}
		$delta['mqtt_live']         = false;
		$delta['battery_percent']   = null;
		$delta['remain_capacity']   = null;
		$delta['soc_source']        = 'unavailable';
		$delta['solar_in_source']   = 'unavailable';
		$delta['capacity_source']   = 'default';
		$delta['is_charging']       = false;
		$delta['is_discharging']    = false;
		$delta['charge_state']      = __( '未取得', 'gaming-hub' );
		$delta['extra']             = gaming_hub_ecoflow_resolve_extra_battery( $delta );
		if ( 'mqtt' === ( $delta['source'] ?? '' ) ) {
			$delta['source'] = '';
		}

		$status['secondary']       = $delta;
		$status['solar_in']        = null;
		$status['solar_delta']     = null;
		$status['solar_in_source'] = 'unavailable';

		if ( 'ecoflow' === gaming_hub_ecoflow_ups_source( $status ) ) {
			unset( $status['ups_plug'] );
		}

		return $status;
	}

	$delta['mqtt_live'] = true;

	if ( ! gaming_hub_ecoflow_delta1500_has_live_soc( $delta ) ) {
		$delta['battery_percent'] = null;
		$delta['remain_capacity'] = null;
		if ( empty( $delta['soc_source'] ) || 'mqtt' !== $delta['soc_source'] ) {
			$delta['soc_source'] = 'unavailable';
		}
	} else {
		$main = gaming_hub_ecoflow_parse_main_pack( gaming_hub_ecoflow_delta1500_quota( $delta ), $delta );
		$delta['battery_percent'] = $main['battery_percent'];
		$delta['capacity_wh']     = $main['capacity_wh'];
		$delta['remain_capacity'] = $main['remain_capacity'];
		$delta['capacity_source'] = 'mqtt' === ( $delta['source'] ?? '' ) ? 'mqtt' : $main['capacity_source'];
		$delta['soc_source']      = 'mqtt';
	}

	$delta['extra'] = gaming_hub_ecoflow_resolve_extra_battery( $delta );

	$status['secondary'] = $delta;

	if ( ! gaming_hub_ecoflow_delta1500_has_live_solar( $delta ) ) {
		$status['secondary']['solar_in']        = null;
		$status['secondary']['solar_in_source'] = 'unavailable';
		$status['solar_in']                    = null;
		$status['solar_delta']                  = null;
		$status['solar_in_source']              = 'unavailable';
	} else {
		$solar = gaming_hub_ecoflow_delta1500_solar_from_quota( gaming_hub_ecoflow_delta1500_quota( $delta ) );
		if ( null !== $solar ) {
			$solar_watts                             = max( 0, (int) round( $solar ) );
			$status['secondary']['solar_in']        = $solar_watts;
			$status['secondary']['solar_in_source'] = 'mqtt';
			$status['solar_in']                     = $solar_watts;
			$status['solar_delta']                  = $solar_watts;
			$status['solar_in_source']              = 'mqtt';
		}
	}

	$ac_out = gaming_hub_ecoflow_ac_output_watts( gaming_hub_ecoflow_delta1500_quota( $status['secondary'] ) );
	if ( null !== $ac_out ) {
		$watts                                 = (int) round( max( 0, (float) $ac_out ) );
		$status['secondary']['ac_out']        = $watts;
		$status['secondary']                  = gaming_hub_ecoflow_sync_device_activity( $status['secondary'] );
		$status['ups_plug']                    = array(
			'watts'      => $watts,
			'source'     => 'ecoflow',
			'online'     => ! empty( $status['secondary']['online'] ),
			'updated_at' => wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ) ),
		);
	} else {
		$status['secondary']['ac_out'] = null;
		if ( 'ecoflow' === gaming_hub_ecoflow_ups_source( $status ) ) {
			unset( $status['ups_plug'] );
		}
	}

	$ac_in = gaming_hub_ecoflow_ac_input_watts( gaming_hub_ecoflow_delta1500_quota( $status['secondary'] ) );
	$status['secondary']['ac_in'] = null !== $ac_in ? (int) round( max( 0, (float) $ac_in ) ) : null;

	return $status;
}

/**
 * Keep charge/discharge flags in sync with live watts.
 *
 * Delta 1500 often reports pd.chgDsgState = 0 (idle) while AC out is active.
 *
 * @param array<string, mixed> $device Device status slice.
 * @return array<string, mixed>
 */
function gaming_hub_ecoflow_sync_device_activity( array $device ) {
	if ( ! empty( $device['is_charging'] ) ) {
		return $device;
	}

	$out = 0.0;
	foreach ( array( 'ac_out', 'output_total', 'dc_out' ) as $key ) {
		if ( isset( $device[ $key ] ) && is_numeric( $device[ $key ] ) ) {
			$out = max( $out, (float) $device[ $key ] );
		}
	}

	if ( $out < GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W ) {
		return $device;
	}

	$device['is_discharging'] = true;
	$state                    = (string) ( $device['charge_state'] ?? '' );
	if ( '' === $state || false !== strpos( $state, '待機' ) || 0 === strcasecmp( $state, 'Idle' ) || 0 === strcasecmp( $state, 'Standby' ) ) {
		$device['charge_state'] = __( '放電中', 'gaming-hub' );
	}

	return $device;
}

/**
 * Grid AC input watts from live MQTT (not a stale quotaMap snapshot).
 *
 * Delta 3 1500: EcoFlow app AC input is pd.inputWatts. pd.chgPowerAC stays 0
 * on this firmware; inv.inputWatts tracks inverter throughput, not the app.
 *
 * @param array<string, mixed> $quota Quota map.
 * @return float|null
 */
function gaming_hub_ecoflow_ac_input_watts( $quota ) {
	$named = gaming_hub_ecoflow_quota_value_live(
		$quota,
		array( 'powGetAcIn', 'powGetAc', 'pd.acInWatts', 'inv.acInWatts' )
	);
	if ( null !== $named && (float) $named > 0 ) {
		return abs( (float) $named );
	}

	$pd_in = gaming_hub_ecoflow_quota_value_live( $quota, array( 'pd.inputWatts' ) );
	if ( null !== $pd_in ) {
		return abs( (float) $pd_in );
	}

	$chg_ac = gaming_hub_ecoflow_quota_value_live( $quota, array( 'pd.chgPowerAC' ) );
	if ( null !== $chg_ac && (float) $chg_ac > 0 ) {
		return abs( (float) $chg_ac );
	}

	$inv_in = gaming_hub_ecoflow_quota_value_live( $quota, array( 'inv.inputWatts' ) );
	if ( null !== $inv_in ) {
		return abs( (float) $inv_in );
	}

	return null;
}

/**
 * Live Pro 3 grid AC watts for the flow diagram.
 *
 * Prefer measured AC in. If that key is 0/missing while powInSumW exceeds HV solar,
 * treat the remainder as grid input.
 *
 * @param array<string, mixed> $status EcoFlow status (primary / Pro 3).
 * @return int
 */
function gaming_hub_ecoflow_pro_grid_live_watts( array $status ) {
	$threshold = defined( 'GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W' )
		? (int) GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W
		: 8;
	$ac        = isset( $status['ac_in'] ) && is_numeric( $status['ac_in'] )
		? (float) $status['ac_in']
		: null;

	if ( null !== $ac && $ac >= $threshold ) {
		return (int) round( $ac );
	}

	$input = isset( $status['input_total'] ) && is_numeric( $status['input_total'] )
		? (float) $status['input_total']
		: 0.0;
	$hv    = isset( $status['hv_in'] ) && is_numeric( $status['hv_in'] )
		? (float) $status['hv_in']
		: 0.0;
	$grid  = max( 0, $input - $hv );

	if ( $grid >= $threshold ) {
		return (int) round( $grid );
	}

	return (int) round( $ac ?? 0 );
}

/**
 * First numeric quota value, preferring live params over quotaMap snapshots.
 *
 * @param array<string, mixed> $quota Quota map.
 * @param array<int, string>   $keys  Canonical keys without quotaMap. prefix.
 * @return float|null
 */
function gaming_hub_ecoflow_quota_value_live( $quota, $keys ) {
	$live = array();
	$stale = array();

	foreach ( $keys as $key ) {
		$key = (string) $key;
		$live[]  = 'params.' . $key;
		$live[]  = $key;
		$stale[] = 'quotaMap.' . $key;
		$stale[] = 'data.quotaMap.' . $key;
	}

	foreach ( array_merge( $live, $stale ) as $alias ) {
		if ( isset( $quota[ $alias ] ) && is_numeric( $quota[ $alias ] ) ) {
			return (float) $quota[ $alias ];
		}
	}

	return null;
}

/**
 * AC output watts from live MQTT (not a stale quotaMap snapshot).
 *
 * Delta 3 1500: EcoFlow app AC output is pd.outputWatts. Do not sum
 * inv.outputWatts + pd.outputWatts — they are the same AC load.
 *
 * @param array<string, mixed> $quota Quota map.
 * @return float|null
 */
function gaming_hub_ecoflow_ac_output_watts( $quota ) {
	$sum   = 0.0;
	$found = false;

	foreach ( array( 'powGetAcLvOut', 'powGetAcHvOut', 'powGetAcLvTt30Out' ) as $key ) {
		$value = gaming_hub_ecoflow_quota_value_live( $quota, array( $key ) );
		if ( null === $value ) {
			continue;
		}

		$sum  += abs( (float) $value );
		$found = true;
	}

	if ( $found && $sum > 0 ) {
		return $sum;
	}

	$pd_out = gaming_hub_ecoflow_quota_value_live( $quota, array( 'pd.outputWatts', 'pd.acOutWatts' ) );
	if ( null !== $pd_out ) {
		return abs( (float) $pd_out );
	}

	$inv_out = gaming_hub_ecoflow_quota_value_live( $quota, array( 'inv.outputWatts', 'inv.outWatts' ) );
	if ( null !== $inv_out ) {
		return abs( (float) $inv_out );
	}

	$out_sum = gaming_hub_ecoflow_quota_value_live( $quota, array( 'pd.wattsOutSum' ) );
	if ( null !== $out_sum ) {
		return abs( (float) $out_sum );
	}

	return $found ? $sum : null;
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
		foreach ( gaming_hub_ecoflow_quota_key_aliases( $key ) as $alias ) {
			if ( isset( $quota[ $alias ] ) && is_numeric( $quota[ $alias ] ) ) {
				return (float) $quota[ $alias ];
			}
		}
	}

	return null;
}

/**
 * Lookup aliases for a flattened MQTT / latestQuotas key.
 *
 * @param string $key Canonical quota key.
 * @return array<int, string>
 */
function gaming_hub_ecoflow_quota_key_aliases( $key ) {
	$key = (string) $key;
	$aliases = array( $key );

	if ( 0 !== strpos( $key, 'quotaMap.' ) && 0 !== strpos( $key, 'data.quotaMap.' ) && 0 !== strpos( $key, 'params.' ) ) {
		array_unshift( $aliases, 'quotaMap.' . $key, 'data.quotaMap.' . $key, 'params.' . $key );
	}

	return $aliases;
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

	if ( 2 === (int) $chg_dsg_state ) {
		return true;
	}

	if ( null === $chg_dsg_state ) {
		$flags = array( 'pd.chgState', 'bms_bmsStatus.chgState', 'cms.chgState' );
		foreach ( $flags as $flag ) {
			if ( isset( $quota[ $flag ] ) ) {
				return (int) $quota[ $flag ] === 1;
			}
		}
	}

	if ( null !== $input && null !== $output ) {
		return $input > $output && $input >= GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W;
	}

	return null !== $input && $input >= GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W && ( null === $output || $output <= 0 );
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

	if ( 1 === (int) $chg_dsg_state ) {
		return true;
	}

	$out = 0.0;
	if ( null !== $output && is_numeric( $output ) ) {
		$out = max( $out, (float) $output );
	}

	$ac_out = gaming_hub_ecoflow_ac_output_watts( $quota );
	if ( null !== $ac_out ) {
		$out = max( $out, (float) $ac_out );
	}

	return $out >= GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W;
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

	$out_w = ( null !== $output && is_numeric( $output ) ) ? (float) $output : 0.0;
	if ( 1 === (int) $chg_dsg_state || $is_discharging || $out_w >= GAMING_HUB_ECOFLOW_FLOW_THRESHOLD_W ) {
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
	if ( null === $value || '' === $value || ! is_numeric( $value ) ) {
		return gaming_hub_ecoflow_unavailable_label();
	}

	$watts = (int) round( (float) $value );
	if ( 0 === $watts ) {
		return __( '待機', 'gaming-hub' );
	}

	return number_format_i18n( $watts, 0 ) . ' W';
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
	if ( null === $remain || ! is_numeric( $remain ) ) {
		return gaming_hub_ecoflow_unavailable_label();
	}

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
			'data'    => gaming_hub_ecoflow_strip_internal_fields( $status ),
		),
		200
	);
}

/**
 * Enqueue EcoFlow dashboard script on EcoFlow pages.
 */
function gaming_hub_ecoflow_scripts() {
	$is_ecoflow = is_front_page() || is_tag( 'ecoflow' );
	$is_energy  = is_front_page() || is_tag( 'energy' );

	if ( ! $is_ecoflow && ! $is_energy ) {
		return;
	}

	if ( $is_ecoflow ) {
		wp_enqueue_script(
			'gaming-hub-ecoflow-flow',
			get_template_directory_uri() . '/assets/js/ecoflow-flow.js',
			array( 'gaming-hub-i18n' ),
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
					'deltaGrid'    => __( 'グリッド AC 入力', 'gaming-hub' ),
					'acInMeasured' => __( '実測 · MQTT', 'gaming-hub' ),
					'home'        => __( '慎一の部屋', 'gaming-hub' ),
					'ups'         => __( '常時稼働エリア (UPS)', 'gaming-hub' ),
					'battery'     => __( 'バッテリー', 'gaming-hub' ),
					'pro'         => __( 'Delta Pro 3', 'gaming-hub' ),
					'delta'       => __( 'Delta 3 1500', 'gaming-hub' ),
					'extra'       => __( 'Extra Battery 1kW', 'gaming-hub' ),
					'dcLink'      => __( 'DC 12V', 'gaming-hub' ),
					'acLink'      => __( 'DC 12V', 'gaming-hub' ),
					'acOut'       => __( 'AC 出力', 'gaming-hub' ),
					'acOutMeasured' => __( '実測 · MQTT', 'gaming-hub' ),
					'upsPlug'     => __( 'SwitchBot Plug', 'gaming-hub' ),
					'lvMeasured'  => __( '実測 · MQTT', 'gaming-hub' ),
					'flow'        => __( '電力フロー', 'gaming-hub' ),
					'inputTotal'  => __( '入力合計', 'gaming-hub' ),
					'outputTotal' => __( '出力合計', 'gaming-hub' ),
					'todaySave'   => __( '今日 節約', 'gaming-hub' ),
					'todayBuy'    => __( '今日 買電', 'gaming-hub' ),
					'todayGen'    => __( '今日 発電', 'gaming-hub' ),
					'todayUse'    => __( '今日 使用', 'gaming-hub' ),
				),
				'images' => array(
					'solar' => gaming_hub_ecoflow_image_url( 'ecoflow-solar-gaming.jpg' ),
					'pro'   => gaming_hub_ecoflow_image_url( 'ecoflow-pro-gaming.jpg' ),
					'dc12v' => gaming_hub_ecoflow_image_url( 'ecoflow-dc12v-gaming.jpg' ),
					'delta' => gaming_hub_ecoflow_image_url( 'ecoflow-delta1500-gaming.jpg' ),
					'extra' => gaming_hub_ecoflow_image_url( 'ecoflow-extra-gaming.jpg' ),
					'room'  => gaming_hub_ecoflow_image_url( 'ecoflow-room-ac.jpg' ),
					'ups'   => gaming_hub_ecoflow_image_url( 'ecoflow-ups-ac.jpg' ),
					'grid'  => gaming_hub_ecoflow_image_url( 'ecoflow-grid-pole.jpg' ),
				),
			)
		);
	}

	$deps = array( 'gaming-hub-active-refresh', 'gaming-hub-i18n' );
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
			'canApprove' => $is_ecoflow && gaming_hub_ecoflow_can_control(),
			'interval'   => GAMING_HUB_ECOFLOW_STATUS_CACHE_TTL * 1000,
			'labels'     => array(
				'unavailable' => gaming_hub_ecoflow_unavailable_label(),
			),
		)
	);
}
add_action( 'wp_enqueue_scripts', 'gaming_hub_ecoflow_scripts' );
