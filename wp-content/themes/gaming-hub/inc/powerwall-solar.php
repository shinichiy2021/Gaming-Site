<?php
/**
 * Tajimi (Gifu) solar generation simulation with live weather.
 *
 * Assumes a 1.5 kW rooftop array (30° south, PR 0.85). Uses JMA 1991–2020
 * monthly sunshine normals for 多治見 and Open-Meteo for cloud / irradiance.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'GAMING_HUB_TAJIMI_SOLAR_CACHE_PREFIX', 'gaming_hub_tajimi_solar_v2_' );
define( 'GAMING_HUB_TAJIMI_SOLAR_CACHE_TTL', HOUR_IN_SECONDS );

/** Installed rooftop solar array (watts). Max rated output 1.5 kW. */
define( 'GAMING_HUB_POWERWALL_SOLAR_CAPACITY_W', 1500 );

/**
 * Installed solar capacity in watts (1.5 kW panels).
 */
function gaming_hub_powerwall_solar_capacity_w() {
	return (int) GAMING_HUB_POWERWALL_SOLAR_CAPACITY_W;
}

/**
 * Human-readable panel label for dashboards.
 */
function gaming_hub_powerwall_solar_panel_label() {
	return sprintf(
		/* translators: %s: panel capacity in kW */
		__( '%s kW パネル', 'gaming-hub' ),
		number_format_i18n( gaming_hub_powerwall_solar_capacity_w() / 1000, 1 )
	);
}

/**
 * Tajimi city center (Open-Meteo / JMA 多治見).
 *
 * @return array{lat: float, lon: float, name: string}
 */
function gaming_hub_tajimi_solar_location() {
	return array(
		'lat'  => 35.332,
		'lon'  => 137.134,
		'name' => __( '岐阜県多治見市', 'gaming-hub' ),
	);
}

/**
 * JMA monthly sunshine hours at Tajimi (1991–2020 normals).
 *
 * @return array<int, float>
 */
function gaming_hub_tajimi_monthly_sunshine_hours() {
	return array(
		1  => 164.8,
		2  => 167.3,
		3  => 198.1,
		4  => 202.5,
		5  => 206.0,
		6  => 147.4,
		7  => 170.8,
		8  => 209.4,
		9  => 163.9,
		10 => 166.5,
		11 => 158.2,
		12 => 158.2,
	);
}

/**
 * Human-readable weather label from WMO code.
 *
 * @param int $code WMO weather code.
 */
function gaming_hub_tajimi_weather_label( $code ) {
	$map = array(
		0  => __( '快晴', 'gaming-hub' ),
		1  => __( '晴れ', 'gaming-hub' ),
		2  => __( '一部曇り', 'gaming-hub' ),
		3  => __( '曇り', 'gaming-hub' ),
		45 => __( '霧', 'gaming-hub' ),
		48 => __( '霧氷', 'gaming-hub' ),
		51 => __( '弱い霧雨', 'gaming-hub' ),
		53 => __( '霧雨', 'gaming-hub' ),
		55 => __( '強い霧雨', 'gaming-hub' ),
		61 => __( '弱い雨', 'gaming-hub' ),
		63 => __( '雨', 'gaming-hub' ),
		65 => __( '強い雨', 'gaming-hub' ),
		71 => __( '弱い雪', 'gaming-hub' ),
		73 => __( '雪', 'gaming-hub' ),
		75 => __( '強い雪', 'gaming-hub' ),
		80 => __( 'にわか雨', 'gaming-hub' ),
		81 => __( 'にわか雨', 'gaming-hub' ),
		82 => __( '激しいにわか雨', 'gaming-hub' ),
		95 => __( '雷雨', 'gaming-hub' ),
	);

	$code = (int) $code;

	return $map[ $code ] ?? __( '不明', 'gaming-hub' );
}

/**
 * Today's daily weather label from an Open-Meteo payload.
 *
 * @param array<string, mixed> $payload Open-Meteo JSON.
 */
function gaming_hub_tajimi_today_weather_from_payload( array $payload ) {
	$daily = is_array( $payload['daily'] ?? null ) ? $payload['daily'] : array();
	if ( isset( $daily['weather_code'][0] ) ) {
		return gaming_hub_tajimi_weather_label( (int) $daily['weather_code'][0] );
	}

	$parsed = gaming_hub_tajimi_parse_open_meteo_hour( $payload );

	return gaming_hub_tajimi_weather_label( (int) ( $parsed['weather_code'] ?? 0 ) );
}

/**
 * Clear-sky hourly shape for Tajimi (0–1), weighted by month sunshine.
 *
 * @param int $hour   Local hour 0–23.
 * @param int $month  Month 1–12.
 * @return float
 */
function gaming_hub_tajimi_clear_sky_hour_factor( $hour, $month ) {
	if ( $hour < 5 || $hour > 19 ) {
		return 0.0;
	}

	$monthly = gaming_hub_tajimi_monthly_sunshine_hours();
	$norm    = $monthly[ $month ] ?? 180.0;
	$year_avg = array_sum( $monthly ) / 12;
	$season  = max( 0.55, min( 1.15, $norm / $year_avg ) );

	$daylight_center = 12.0;
	$daylight_width  = 11.0;
	$angle           = ( $hour - $daylight_center ) / ( $daylight_width / 2 );
	$shape           = max( 0.0, cos( min( 1.0, abs( $angle ) ) * ( M_PI / 2 ) ) );

	return $shape * $season;
}

/**
 * Fetch Open-Meteo forecast for Tajimi (current clock hour).
 *
 * @return array<string, mixed>|WP_Error
 */
function gaming_hub_tajimi_fetch_open_meteo() {
	$loc = gaming_hub_tajimi_solar_location();

	$url = add_query_arg(
		array(
			'latitude'       => $loc['lat'],
			'longitude'      => $loc['lon'],
			'timezone'       => 'Asia/Tokyo',
			'forecast_days'  => 1,
			'current'        => 'cloud_cover,is_day,weather_code,global_tilted_irradiance,temperature_2m',
			'hourly'         => 'global_tilted_irradiance,cloud_cover,is_day,weather_code,temperature_2m',
			'daily'          => 'weather_code',
			'tilt'           => 30,
			'azimuth'        => 0,
		),
		'https://api.open-meteo.com/v1/forecast'
	);

	$response = wp_remote_get(
		$url,
		array(
			'timeout' => 12,
		)
	);

	if ( is_wp_error( $response ) ) {
		return $response;
	}

	$body = json_decode( wp_remote_retrieve_body( $response ), true );

	if ( ! is_array( $body ) ) {
		return new WP_Error( 'open_meteo_invalid', __( 'Open-Meteo response invalid.', 'gaming-hub' ) );
	}

	return $body;
}

/**
 * Pick irradiance + weather for the current local clock hour.
 *
 * @param array<string, mixed> $payload Open-Meteo JSON.
 * @return array<string, mixed>
 */
function gaming_hub_tajimi_parse_open_meteo_hour( array $payload ) {
	$hour_key = wp_date( 'Y-m-d\TH:00' );
	$current  = is_array( $payload['current'] ?? null ) ? $payload['current'] : array();
	$hourly   = is_array( $payload['hourly'] ?? null ) ? $payload['hourly'] : array();
	$times    = is_array( $hourly['time'] ?? null ) ? $hourly['time'] : array();

	$index = array_search( $hour_key, $times, true );
	if ( false === $index ) {
		$index = 0;
		foreach ( $times as $i => $time ) {
			if ( 0 === strpos( (string) $time, wp_date( 'Y-m-d' ) ) ) {
				$index = $i;
			}
		}
	}

	$gti = null;
	if ( false !== $index && isset( $hourly['global_tilted_irradiance'][ $index ] ) ) {
		$gti = (float) $hourly['global_tilted_irradiance'][ $index ];
	} elseif ( isset( $current['global_tilted_irradiance'] ) ) {
		$gti = (float) $current['global_tilted_irradiance'];
	}

	$cloud = null;
	if ( false !== $index && isset( $hourly['cloud_cover'][ $index ] ) ) {
		$cloud = (int) $hourly['cloud_cover'][ $index ];
	} elseif ( isset( $current['cloud_cover'] ) ) {
		$cloud = (int) $current['cloud_cover'];
	}

	$is_day = 1;
	if ( false !== $index && isset( $hourly['is_day'][ $index ] ) ) {
		$is_day = (int) $hourly['is_day'][ $index ];
	} elseif ( isset( $current['is_day'] ) ) {
		$is_day = (int) $current['is_day'];
	}

	$weather_code = 0;
	if ( false !== $index && isset( $hourly['weather_code'][ $index ] ) ) {
		$weather_code = (int) $hourly['weather_code'][ $index ];
	} elseif ( isset( $current['weather_code'] ) ) {
		$weather_code = (int) $current['weather_code'];
	}

	return array(
		'hour_slot'                 => $hour_key,
		'global_tilted_irradiance'  => $gti,
		'cloud_cover'               => $cloud,
		'is_day'                    => $is_day,
		'weather_code'              => $weather_code,
	);
}

/**
 * Estimate watts from irradiance (1.5 kW array, 30° south, PR 0.85).
 *
 * @param float|null $gti W/m² global tilted irradiance.
 * @param int        $capacity_w Panel capacity watts.
 * @return int
 */
function gaming_hub_tajimi_watts_from_irradiance( $gti, $capacity_w ) {
	if ( null === $gti || $gti <= 0 ) {
		return 0;
	}

	$performance_ratio = 0.85;
	$watts             = ( $gti / 1000.0 ) * $capacity_w * $performance_ratio;

	return (int) max( 0, min( $capacity_w, round( $watts ) ) );
}

/**
 * Fallback generation from Tajimi sunshine normals + cloud cover.
 *
 * @param int      $hour        Local hour.
 * @param int      $month       Local month.
 * @param int|null $cloud_cover Cloud cover percent.
 * @param int      $capacity_w  Panel capacity.
 * @return int
 */
function gaming_hub_tajimi_fallback_solar_w( $hour, $month, $cloud_cover, $capacity_w ) {
	$factor = gaming_hub_tajimi_clear_sky_hour_factor( $hour, $month );
	if ( $factor <= 0 ) {
		return 0;
	}

	$monthly      = gaming_hub_tajimi_monthly_sunshine_hours();
	$days         = (int) wp_date( 't' );
	$daily_hours  = ( $monthly[ $month ] ?? 180 ) / max( 1, $days );
	$peak_fraction = min( 1.0, $daily_hours / 6.5 );
	$clear_w       = $capacity_w * $factor * $peak_fraction;

	$cloud_factor = 1.0;
	if ( null !== $cloud_cover ) {
		$cloud_factor = max( 0.08, 1.0 - ( (int) $cloud_cover / 100.0 ) * 0.88 );
	}

	return (int) max( 0, min( $capacity_w, round( $clear_w * $cloud_factor ) ) );
}

/**
 * Current-hour solar generation for Tajimi (cached 1 hour).
 *
 * @param bool $force_refresh Skip cache.
 * @return array<string, mixed>
 */
function gaming_hub_powerwall_get_solar_generation( $force_refresh = false ) {
	$hour_slot  = wp_date( 'Y-m-d-H' );
	$cache_key  = GAMING_HUB_TAJIMI_SOLAR_CACHE_PREFIX . $hour_slot;
	$capacity_w = gaming_hub_powerwall_solar_capacity_w();
	$month      = (int) wp_date( 'n' );
	$hour       = (int) wp_date( 'G' );
	$loc        = gaming_hub_tajimi_solar_location();

	if ( ! $force_refresh ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$payload = gaming_hub_tajimi_fetch_open_meteo();
	$source  = 'tajimi-normal';
	$watts   = 0;
	$cloud   = null;
	$weather = __( '不明', 'gaming-hub' );
	$gti     = null;
	$slot    = wp_date( 'Y-m-d H:00' );

	if ( ! is_wp_error( $payload ) ) {
		$parsed = gaming_hub_tajimi_parse_open_meteo_hour( $payload );
		$source = 'open-meteo';
		$cloud  = $parsed['cloud_cover'];
		$gti    = $parsed['global_tilted_irradiance'];
		$slot   = str_replace( 'T', ' ', $parsed['hour_slot'] );
		$weather = gaming_hub_tajimi_today_weather_from_payload( $payload );

		if ( empty( $parsed['is_day'] ) ) {
			$watts = 0;
		} elseif ( null !== $gti ) {
			$watts = gaming_hub_tajimi_watts_from_irradiance( $gti, $capacity_w );
		} else {
			$watts = gaming_hub_tajimi_fallback_solar_w( $hour, $month, $cloud, $capacity_w );
		}
	} else {
		$watts   = gaming_hub_tajimi_fallback_solar_w( $hour, $month, null, $capacity_w );
		$weather = __( '天気取得不可', 'gaming-hub' );
	}

	$result = array(
		'watts'                => $watts,
		'solar_capacity_w'     => $capacity_w,
		'panel_capacity_w'     => $capacity_w,
		'panel_capacity_kw'    => round( $capacity_w / 1000, 1 ),
		'panel_label'          => gaming_hub_powerwall_solar_panel_label(),
		'location'             => $loc['name'],
		'source'               => $source,
		'weather'              => $weather,
		'cloud_cover'          => $cloud,
		'gti_wm2'              => $gti,
		'hour_slot'            => $slot,
		'monthly_sunshine_h'   => gaming_hub_tajimi_monthly_sunshine_hours()[ $month ] ?? null,
		'cache_expires'        => wp_date( 'H:i', time() + GAMING_HUB_TAJIMI_SOLAR_CACHE_TTL ),
		'fetch_error'          => is_wp_error( $payload ) ? $payload->get_error_message() : '',
	);

	set_transient( $cache_key, $result, GAMING_HUB_TAJIMI_SOLAR_CACHE_TTL );

	return $result;
}

/**
 * 24-hour solar profile for today (Open-Meteo hourly or Tajimi normals).
 *
 * @param bool $force_refresh Skip day cache.
 * @return array{hours: array<int, int>, date: string, source: string}
 */
function gaming_hub_powerwall_solar_hourly_profile( $force_refresh = false ) {
	$date       = wp_date( 'Y-m-d' );
	$cache_key  = GAMING_HUB_TAJIMI_SOLAR_CACHE_PREFIX . 'dayv4_' . $date;
	$capacity_w = gaming_hub_powerwall_solar_capacity_w();
	$month      = (int) wp_date( 'n' );

	if ( ! $force_refresh ) {
		$cached = get_transient( $cache_key );
		if ( is_array( $cached ) ) {
			return $cached;
		}
	}

	$profile       = array_fill( 0, 24, 0 );
	$temps         = array_fill( 0, 24, null );
	$clouds        = array_fill( 0, 24, null );
	$weather_codes = array_fill( 0, 24, null );
	$payload       = gaming_hub_tajimi_fetch_open_meteo();
	$source        = 'tajimi-normal';
	$weather_code  = null;

	if ( ! is_wp_error( $payload ) ) {
		$source  = 'open-meteo';
		$hourly  = is_array( $payload['hourly'] ?? null ) ? $payload['hourly'] : array();
		$times   = is_array( $hourly['time'] ?? null ) ? $hourly['time'] : array();
		$daily   = is_array( $payload['daily'] ?? null ) ? $payload['daily'] : array();
		if ( isset( $daily['weather_code'][0] ) ) {
			$weather_code = (int) $daily['weather_code'][0];
		}

		foreach ( $times as $index => $time ) {
			if ( 0 !== strpos( (string) $time, $date ) ) {
				continue;
			}

			$hour   = (int) substr( (string) $time, 11, 2 );
			$is_day = (int) ( $hourly['is_day'][ $index ] ?? 0 );
			$gti    = isset( $hourly['global_tilted_irradiance'][ $index ] )
				? (float) $hourly['global_tilted_irradiance'][ $index ]
				: null;
			$cloud  = isset( $hourly['cloud_cover'][ $index ] )
				? (int) $hourly['cloud_cover'][ $index ]
				: null;

			if ( isset( $hourly['temperature_2m'][ $index ] ) ) {
				$temps[ $hour ] = (float) $hourly['temperature_2m'][ $index ];
			}
			$clouds[ $hour ] = $cloud;
			if ( isset( $hourly['weather_code'][ $index ] ) ) {
				$weather_codes[ $hour ] = (int) $hourly['weather_code'][ $index ];
			}

			if ( ! $is_day ) {
				$profile[ $hour ] = 0;
			} elseif ( null !== $gti ) {
				$profile[ $hour ] = gaming_hub_tajimi_watts_from_irradiance( $gti, $capacity_w );
			} else {
				$profile[ $hour ] = gaming_hub_tajimi_fallback_solar_w( $hour, $month, $cloud, $capacity_w );
			}
		}
	} else {
		for ( $hour = 0; $hour < 24; $hour++ ) {
			$profile[ $hour ] = gaming_hub_tajimi_fallback_solar_w( $hour, $month, null, $capacity_w );
		}
	}

	$weather = __( '不明', 'gaming-hub' );
	if ( ! is_wp_error( $payload ) ) {
		$weather = gaming_hub_tajimi_today_weather_from_payload( $payload );
	} else {
		$weather = __( '天気取得不可', 'gaming-hub' );
	}

	$loc = gaming_hub_tajimi_solar_location();

	$temp_now = null;
	$temp_max = null;
	$temp_min = null;
	$numeric_temps = array_values( array_filter( $temps, 'is_numeric' ) );
	if ( $numeric_temps ) {
		$temp_max = max( $numeric_temps );
		$temp_min = min( $numeric_temps );
		$now_h    = (int) wp_date( 'G' );
		$temp_now = isset( $temps[ $now_h ] ) && is_numeric( $temps[ $now_h ] )
			? (float) $temps[ $now_h ]
			: (float) $numeric_temps[0];
	}
	if ( ! is_wp_error( $payload ) && isset( $payload['current']['temperature_2m'] ) ) {
		$temp_now = (float) $payload['current']['temperature_2m'];
	}

	$result = array(
		'hours'         => $profile,
		'temps'         => $temps,
		'clouds'        => $clouds,
		'weather_codes' => $weather_codes,
		'weather_code'  => $weather_code,
		'temp_now'      => $temp_now,
		'temp_max'      => $temp_max,
		'temp_min'      => $temp_min,
		'date'          => $date,
		'source'        => $source,
		'today_kwh'     => round( array_sum( $profile ) / 1000, 2 ),
		'weather'       => $weather,
		'location'      => $loc['name'],
	);

	set_transient( $cache_key, $result, GAMING_HUB_TAJIMI_SOLAR_CACHE_TTL );

	return $result;
}
