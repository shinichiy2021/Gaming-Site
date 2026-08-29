<?php
/**
 * Model 3 demo simulation (30 km/day average driving).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/** Average daily driving distance (km). */
define( 'GAMING_HUB_MODEL3_DAILY_KM', 30 );

/** Typical Model 3 consumption (Wh/km, mixed driving). */
define( 'GAMING_HUB_MODEL3_WH_PER_KM', 150 );

/** Assumed usable battery capacity for SOC demo (kWh). */
define( 'GAMING_HUB_MODEL3_BATTERY_KWH', 60 );

/** Evening home-charging window start (fractional hour). */
define( 'GAMING_HUB_MODEL3_CHARGE_START', 17.0 );

/** Evening home-charging window end (fractional hour). */
define( 'GAMING_HUB_MODEL3_CHARGE_END', 22.5 );

/** Stored odometer snapshots for the daily driving quest. */
define( 'GAMING_HUB_MODEL3_ODO_OPTION', 'gaming_hub_model3_odometer_v1' );

/** Parked cabin watt-hours and yen accumulated for today. */
define( 'GAMING_HUB_TESLA_CABIN_ENERGY_OPTION', 'gaming_hub_tesla_cabin_energy_v1' );

/** Widest poll gap still treated as continuous load: one missed 5-minute sample. */
define( 'GAMING_HUB_TESLA_CABIN_INTEGRATE_MAX', 12 * MINUTE_IN_SECONDS );

/** Drive / regen watt-hours for efficiency badges (Wh/km, regen %). */
define( 'GAMING_HUB_TESLA_DRIVE_EFF_OPTION', 'gaming_hub_tesla_drive_eff_v1' );

/** Widest poll gap for drive/regen integration (covers 15-minute sampler). */
define( 'GAMING_HUB_TESLA_DRIVE_EFF_INTEGRATE_MAX', 22 * MINUTE_IN_SECONDS );

/** Five-minute sampler that keeps the energy counters advancing. */
define( 'GAMING_HUB_TESLA_SAMPLER_CRON', 'gaming_hub_tesla_sampler' );

/** Home AC (普通充電) watt-hours and yen accumulated for today. */
define( 'GAMING_HUB_TESLA_WALL_ENERGY_OPTION', 'gaming_hub_tesla_wall_energy_v1' );

/** Supercharger session watt-hours (no LOOOP pricing). */
define( 'GAMING_HUB_TESLA_SUPER_ENERGY_OPTION', 'gaming_hub_tesla_super_energy_v1' );

/**
 * Daily energy to replenish after 30 km driving.
 */
function gaming_hub_powerwall_model3_daily_kwh() {
	return ( GAMING_HUB_MODEL3_DAILY_KM * GAMING_HUB_MODEL3_WH_PER_KM ) / 1000.0;
}

/**
 * Hours in the evening charging window.
 */
function gaming_hub_powerwall_model3_charge_duration_hours() {
	return GAMING_HUB_MODEL3_CHARGE_END - GAMING_HUB_MODEL3_CHARGE_START;
}

/**
 * Constant charge power to deliver daily_kwh within the evening window.
 */
function gaming_hub_powerwall_model3_charge_watts() {
	$duration = gaming_hub_powerwall_model3_charge_duration_hours();
	if ( $duration <= 0 ) {
		return 0;
	}

	return (int) round( ( gaming_hub_powerwall_model3_daily_kwh() * 1000 ) / $duration );
}

/**
 * Whether fractional local time is within the home-charging window.
 *
 * @param float $time Hour + minute/60 (0–24).
 */
function gaming_hub_powerwall_model3_is_charging_time( $time ) {
	return $time >= GAMING_HUB_MODEL3_CHARGE_START && $time <= GAMING_HUB_MODEL3_CHARGE_END;
}

/**
 * Model 3 charging watts for hourly cost simulation.
 *
 * @param int $hour Hour 0–23.
 */
function gaming_hub_powerwall_model3_watts_for_hour( $hour ) {
	$charge_w = gaming_hub_powerwall_model3_charge_watts();

	if ( $hour >= 17 && $hour <= 21 ) {
		return $charge_w;
	}

	if ( 22 === $hour ) {
		return (int) round( $charge_w * 0.5 );
	}

	return 0;
}

/**
 * Live charge watts at fractional local time.
 *
 * @param float $time Hour + minute/60.
 */
function gaming_hub_powerwall_model3_watts_at_time( $time ) {
	if ( ! gaming_hub_powerwall_model3_is_charging_time( $time ) ) {
		return 0;
	}

	return (float) gaming_hub_powerwall_model3_charge_watts();
}

/**
 * Demo SOC after daily 30 km drive, rising during evening charge.
 *
 * @param float $time Hour + minute/60.
 */
function gaming_hub_powerwall_model3_soc_at_time( $time ) {
	$base_soc = 52;
	$gain     = ( gaming_hub_powerwall_model3_daily_kwh() / GAMING_HUB_MODEL3_BATTERY_KWH ) * 100;

	if ( ! gaming_hub_powerwall_model3_is_charging_time( $time ) ) {
		return (int) round( $base_soc );
	}

	$duration = gaming_hub_powerwall_model3_charge_duration_hours();
	$progress = ( $time - GAMING_HUB_MODEL3_CHARGE_START ) / $duration;

	return (int) round( min( 95, $base_soc + ( $progress * $gain ) ) );
}

/**
 * Metadata for dashboard display.
 *
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_model3_demo_meta() {
	return array(
		'daily_km'      => GAMING_HUB_MODEL3_DAILY_KM,
		'daily_kwh'     => round( gaming_hub_powerwall_model3_daily_kwh(), 1 ),
		'wh_per_km'     => GAMING_HUB_MODEL3_WH_PER_KM,
		'charge_window' => '17:00–22:30',
		'charge_watts'  => gaming_hub_powerwall_model3_charge_watts(),
		'profile'       => sprintf(
			/* translators: 1: daily km, 2: daily kWh */
			__( '1日平均 %1$s km', 'gaming-hub' ),
			number_format_i18n( GAMING_HUB_MODEL3_DAILY_KM )
		),
	);
}

/**
 * Simulated daily quest km (ramps through the day toward the 30 km target).
 *
 * @param float $time Hour + minute/60.
 */
function gaming_hub_powerwall_model3_demo_today_km( $time ) {
	$target = (float) GAMING_HUB_MODEL3_DAILY_KM;

	if ( $time < 7 ) {
		return 0.0;
	}

	if ( $time >= 22 ) {
		return $target;
	}

	return round( $target * ( ( $time - 7 ) / ( 22 - 7 ) ), 1 );
}

/**
 * Demo Model 3 payload with the same HUD fields as live Tesla data.
 *
 * @param int   $soc     Battery percent.
 * @param bool  $charging Whether charging.
 * @param float $watts   Charge watts.
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_model3_demo_status( $soc, $charging, $watts ) {
	$soc        = max( 0, min( 100, (int) $soc ) );
	$range_full = 450;
	$range_km   = (int) round( $range_full * ( $soc / 100 ) );
	$time       = (int) wp_date( 'G' ) + ( (int) wp_date( 'i' ) / 60 );
	$today_km   = gaming_hub_powerwall_model3_demo_today_km( $time );
	$drop_kwh   = 0.0;
	$raid_ts    = 0;

	if ( $charging ) {
		$duration = gaming_hub_powerwall_model3_charge_duration_hours();
		$progress = $duration > 0
			? max( 0, min( 1, ( $time - GAMING_HUB_MODEL3_CHARGE_START ) / $duration ) )
			: 0;
		$drop_kwh = round( gaming_hub_powerwall_model3_daily_kwh() * $progress, 1 );
	} else {
		$raid_at = date_create( wp_date( 'Y-m-d' ) . ' 17:00:00', wp_timezone() );
		if ( $raid_at && $raid_at->getTimestamp() > time() ) {
			$raid_ts = $raid_at->getTimestamp();
		}
	}

	return array(
		'battery_percent'      => $soc,
		'is_charging'          => $charging,
		'charge_state'         => $charging ? __( 'チャージレイド', 'gaming-hub' ) : __( '待機', 'gaming-hub' ),
		'watts'                => round( $watts ),
		'charge_limit_percent' => 80,
		'range_km'             => $range_km,
		'range_full_km'        => $range_full,
		'vehicle_name'         => 'Model 3',
		'charge_energy_added'  => $drop_kwh,
		'supply_kind'          => $charging ? 'home' : 'none',
		'supply_label'         => $charging ? __( '拠点補給', 'gaming-hub' ) : __( '未接続', 'gaming-hub' ),
		'plugged'              => $charging,
		'scheduled_charging_ts' => $raid_ts,
		'odometer_km'          => null,
		'today_km'             => $today_km,
		'today_target_km'      => GAMING_HUB_MODEL3_DAILY_KM,
		'car_version'          => '',
		'sentry_mode'          => false,
		'locked'               => true,
		'live'                 => false,
	);
}

/**
 * Format minutes as a short Japanese duration label.
 *
 * @param int $minutes Duration in minutes.
 */
function gaming_hub_format_duration_minutes( $minutes ) {
	$minutes = max( 0, (int) $minutes );

	if ( $minutes <= 0 ) {
		return '—';
	}

	$hours = intdiv( $minutes, 60 );
	$mins  = $minutes % 60;

	if ( $hours > 0 && $mins > 0 ) {
		return sprintf(
			/* translators: 1: hours, 2: minutes */
			__( '約 %1$s時間%2$s分', 'gaming-hub' ),
			number_format_i18n( $hours ),
			number_format_i18n( $mins )
		);
	}

	if ( $hours > 0 ) {
		return sprintf(
			/* translators: %s: hours */
			__( '約 %1$s時間', 'gaming-hub' ),
			number_format_i18n( $hours )
		);
	}

	return sprintf(
		/* translators: %s: minutes */
		__( '約 %1$s分', 'gaming-hub' ),
		number_format_i18n( $mins )
	);
}

/**
 * Enrich Model 3 payload with gauge labels and charging ETA fields.
 *
 * @param array<string, mixed> $model3 Raw model3 slice.
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_model3_present( array $model3 ) {
	$soc           = max( 0, min( 100, (int) ( $model3['battery_percent'] ?? 0 ) ) );
	$is_charging   = ! empty( $model3['is_charging'] );
	$watts         = $is_charging ? max( 0, (int) ( $model3['watts'] ?? 0 ) ) : 0;
	$charge_limit  = max( $soc, min( 100, (int) ( $model3['charge_limit_percent'] ?? 100 ) ) );
	$battery_kwh   = (float) ( $model3['battery_kwh_nominal'] ?? GAMING_HUB_MODEL3_BATTERY_KWH );
	$charge_rate_kw = $is_charging && $watts > 0
		? round( $watts / 1000, 1 )
		: 0;

	$minutes_to_full = null;

	if ( isset( $model3['minutes_to_full'] ) && is_numeric( $model3['minutes_to_full'] ) ) {
		$minutes_to_full = max( 0, (int) round( (float) $model3['minutes_to_full'] ) );
	} elseif ( isset( $model3['time_to_full_charge_hours'] ) && is_numeric( $model3['time_to_full_charge_hours'] ) ) {
		$hours = (float) $model3['time_to_full_charge_hours'];
		if ( $hours > 0 ) {
			$minutes_to_full = max( 0, (int) round( $hours * 60 ) );
		}
	} elseif ( $is_charging && $watts > 0 && $charge_limit > $soc ) {
		$remaining_kwh   = ( ( $charge_limit - $soc ) / 100 ) * $battery_kwh;
		$minutes_to_full = max( 0, (int) round( ( $remaining_kwh / ( $watts / 1000 ) ) * 60 ) );
	}

	$charge_eta_label      = null;
	$charge_complete_label = null;

	if ( $is_charging && null !== $minutes_to_full && $minutes_to_full > 0 ) {
		$charge_eta_label      = gaming_hub_format_duration_minutes( $minutes_to_full );
		$charge_complete_label = wp_date( 'H:i', time() + ( $minutes_to_full * MINUTE_IN_SECONDS ) ) . ' ' . __( '頃完了', 'gaming-hub' );
	}

	$usable_kwh = round( $battery_kwh * $soc / 100, 1 );
	if ( isset( $model3['battery_kwh_estimate'] ) && is_numeric( $model3['battery_kwh_estimate'] ) ) {
		$from_api = (float) $model3['battery_kwh_estimate'];
		if ( $from_api > 0 && $from_api <= ( $battery_kwh * 1.25 ) ) {
			$usable_kwh = round( $from_api, 1 );
		}
	}
	$range_km   = isset( $model3['range_km'] ) && is_numeric( $model3['range_km'] )
		? max( 0, (int) round( (float) $model3['range_km'] ) )
		: null;
	$range_full = isset( $model3['range_full_km'] ) && is_numeric( $model3['range_full_km'] )
		? max( 1, (int) round( (float) $model3['range_full_km'] ) )
		: 450;
	$mp_percent = null !== $range_km
		? max( 0, min( 100, (int) round( 100 * $range_km / $range_full ) ) )
		: $soc;

	$energy_added = isset( $model3['charge_energy_added'] ) && is_numeric( $model3['charge_energy_added'] )
		? max( 0, (float) $model3['charge_energy_added'] )
		: 0;
	$supply_kind  = (string) ( $model3['supply_kind'] ?? ( $is_charging ? 'home' : 'none' ) );
	$supply_label = (string) ( $model3['supply_label'] ?? (
		'home' === $supply_kind
			? __( '拠点補給', 'gaming-hub' )
			: ( 'supercharger' === $supply_kind ? __( 'フィールド補給', 'gaming-hub' ) : __( '未接続', 'gaming-hub' ) )
	) );

	$today_km     = isset( $model3['today_km'] ) && is_numeric( $model3['today_km'] )
		? max( 0, (float) $model3['today_km'] )
		: null;
	$today_target = isset( $model3['today_target_km'] ) && is_numeric( $model3['today_target_km'] )
		? max( 1, (float) $model3['today_target_km'] )
		: (float) GAMING_HUB_MODEL3_DAILY_KM;
	$quest_percent = null !== $today_km
		? max( 0, min( 100, (int) round( 100 * $today_km / $today_target ) ) )
		: 0;

	$scheduled_ts = isset( $model3['scheduled_charging_ts'] ) ? (int) $model3['scheduled_charging_ts'] : 0;
	$next_raid    = '';
	if ( ! $is_charging && $scheduled_ts > time() ) {
		$next_raid = sprintf(
			/* translators: %s: scheduled charge time */
			__( '次レイド %s', 'gaming-hub' ),
			wp_date( 'H:i', $scheduled_ts )
		);
	}

	$odometer_km = isset( $model3['odometer_km'] ) && is_numeric( $model3['odometer_km'] )
		? max( 0, (float) $model3['odometer_km'] )
		: null;

	$inside_c = isset( $model3['inside_temp_c'] ) && is_numeric( $model3['inside_temp_c'] )
		? (float) $model3['inside_temp_c']
		: null;
	$cabin_temp_label = null !== $inside_c
		? sprintf(
			/* translators: %s: cabin temperature Celsius */
			__( '室内 %s℃', 'gaming-hub' ),
			number_format_i18n( $inside_c, 1 )
		)
		: __( '室内 —', 'gaming-hub' );

	$tires = isset( $model3['tire_pressure'] ) && is_array( $model3['tire_pressure'] )
		? $model3['tire_pressure']
		: array();
	$avg_bar = isset( $tires['avg_bar'] ) && is_numeric( $tires['avg_bar'] )
		? (float) $tires['avg_bar']
		: null;
	$tire_pressure_label = null !== $avg_bar
		? sprintf(
			/* translators: %s: average tire pressure in bar */
			__( '空気圧 %s bar', 'gaming-hub' ),
			number_format_i18n( $avg_bar, 1 )
		)
		: __( '空気圧 —', 'gaming-hub' );

	$odometer_plain_label = null !== $odometer_km
		? sprintf(
			/* translators: %s: lifetime odometer km */
			__( 'オドメーター %s km', 'gaming-hub' ),
			number_format_i18n( (int) round( $odometer_km ) )
		)
		: __( 'オドメーター —', 'gaming-hub' );
	$patch       = trim( (string) ( $model3['car_version'] ?? '' ) );
	$vehicle     = (string) ( $model3['vehicle_name'] ?? 'Model 3' );
	if ( '' === $vehicle ) {
		$vehicle = 'Model 3';
	}

	$status_key = $is_charging ? 'raid' : 'idle';
	if ( 'レイドクリア' === (string) ( $model3['charge_state'] ?? '' ) ) {
		$status_key = 'clear';
	}

	$badge_status = $is_charging
		? __( 'チャージレイド', 'gaming-hub' )
		: (string) ( $model3['charge_state'] ?? __( '待機', 'gaming-hub' ) );

	$combo_label = (string) ( $model3['combo_label'] ?? '' );

	return array_merge(
		$model3,
		array(
			'battery_percent'       => $soc,
			'is_charging'           => $is_charging,
			'watts'                 => $watts,
			'charge_limit_percent'  => $charge_limit,
			'charge_rate_kw'        => $charge_rate_kw,
			'charge_rate_label'     => $charge_rate_kw > 0
				? number_format_i18n( $charge_rate_kw, 1 ) . ' kW'
				: '—',
			'minutes_to_full'       => $minutes_to_full,
			'charge_eta_label'      => $charge_eta_label,
			'charge_complete_label' => $charge_complete_label,
			'battery_kwh_nominal'   => $battery_kwh,
			'battery_kwh_estimate'  => $usable_kwh,
			'battery_kwh_label'     => number_format_i18n( $usable_kwh, 1 ) . ' kWh',
			'range_km'              => $range_km,
			'range_full_km'         => $range_full,
			'range_label'           => null !== $range_km
				? sprintf(
					/* translators: %s: estimated range km */
					__( '残MP %s km', 'gaming-hub' ),
					number_format_i18n( $range_km )
				)
				: '—',
			'mp_percent'            => $mp_percent,
			'hp_label'              => sprintf(
				/* translators: %s: SOC percent */
				__( 'HP %s%%', 'gaming-hub' ),
				number_format_i18n( $soc )
			),
			'cap_label'             => sprintf(
				/* translators: %s: charge limit percent */
				__( 'チャージキャップ %s%%', 'gaming-hub' ),
				number_format_i18n( $charge_limit )
			),
			'drop_kwh'              => $energy_added,
			'drop_label'            => $energy_added > 0
				? number_format_i18n( $energy_added, 1 ) . ' kWh'
				: '—',
			'supply_kind'           => $supply_kind,
			'supply_label'          => $supply_label,
			'plugged'               => ! empty( $model3['plugged'] ) || $is_charging,
			'today_km'              => $today_km,
			'today_target_km'       => $today_target,
			'quest_percent'         => $quest_percent,
			'quest_label'           => null !== $today_km
				? sprintf(
					/* translators: 1: km driven today, 2: daily target km */
					__( '%1$s / %2$s km', 'gaming-hub' ),
					number_format_i18n( $today_km, 1 ),
					number_format_i18n( $today_target )
				)
				: '—',
			'odometer_label'        => null !== $odometer_km
				? sprintf(
					/* translators: %s: lifetime odometer km */
					__( '累計EXP %s km', 'gaming-hub' ),
					number_format_i18n( (int) round( $odometer_km ) )
				)
				: __( '累計EXP —', 'gaming-hub' ),
			'odometer_plain_label'  => $odometer_plain_label,
			'cabin_temp_label'      => $cabin_temp_label,
			'tire_pressure_label'   => $tire_pressure_label,
			'inside_temp_c'         => $inside_c,
			'outside_temp_c'        => isset( $model3['outside_temp_c'] ) && is_numeric( $model3['outside_temp_c'] )
				? (float) $model3['outside_temp_c']
				: null,
			'tire_pressure'         => $tires,
			'patch_label'           => '' !== $patch
				? sprintf(
					/* translators: %s: vehicle software version */
					__( 'パッチ %s', 'gaming-hub' ),
					$patch
				)
				: __( 'パッチ —', 'gaming-hub' ),
			'next_raid_label'       => $next_raid,
			'vehicle_name'          => $vehicle,
			'status_key'            => $status_key,
			'badge_status'          => $badge_status,
			'sentry_label'          => ! empty( $model3['sentry_mode'] ) ? __( 'Sentry', 'gaming-hub' ) : '',
			'lock_label'            => array_key_exists( 'locked', $model3 )
				? ( ! empty( $model3['locked'] ) ? __( 'ロック', 'gaming-hub' ) : __( 'アンロック', 'gaming-hub' ) )
				: '',
			'combo_label'           => (string) ( $model3['combo_label'] ?? '' ),
		)
	);
}

/**
 * Party combo label from solar / Powerwall while Model 3 is charging.
 *
 * @param array<string, mixed> $model3 Model 3 slice.
 * @param array<string, mixed> $status Full flow status.
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_model3_with_combo( array $model3, array $status ) {
	if ( empty( $model3['is_charging'] ) ) {
		$model3['combo_label'] = '';
		return $model3;
	}

	$solar     = (float) ( $status['solar_w'] ?? 0 );
	$powerwall = is_array( $status['powerwall'] ?? null ) ? $status['powerwall'] : array();

	if ( $solar >= 80 ) {
		$model3['combo_label'] = __( 'ソーラーコンボ', 'gaming-hub' );
	} elseif ( ! empty( $powerwall['is_discharging'] ) ) {
		$model3['combo_label'] = __( 'Powerwallコンボ', 'gaming-hub' );
	} elseif ( 'supercharger' === ( $model3['supply_kind'] ?? '' ) ) {
		$model3['combo_label'] = __( 'フィールド補給', 'gaming-hub' );
	} else {
		$model3['combo_label'] = __( 'グリッド補給', 'gaming-hub' );
	}

	return $model3;
}
