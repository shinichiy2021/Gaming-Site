<?php
/**
 * LOOOP-style electricity forecast data (Chubu / 中部電力エリア)
 *
 * Fetches JEPX spot prices from japanesepower.org and converts to
 * LOOOP 電源料金 using Chubu loss rate (7.1%) + consumption tax.
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JEPX / LOOOP forecast timezone (Japan standard time).
 */
function gaming_hub_looop_timezone() {
	return new DateTimeZone( 'Asia/Tokyo' );
}

/**
 * Current time in Japan.
 */
function gaming_hub_looop_now() {
	return new DateTimeImmutable( 'now', gaming_hub_looop_timezone() );
}

/**
 * Format a JST datetime string for display.
 *
 * @param string $datetime_string Slot datetime (Y-m-d H:i:s).
 * @param string $format          PHP date format.
 */
function gaming_hub_looop_format_datetime( $datetime_string, $format = 'n/j H:i' ) {
	$datetime = new DateTimeImmutable( $datetime_string, gaming_hub_looop_timezone() );

	return wp_date( $format, $datetime->getTimestamp(), gaming_hub_looop_timezone() );
}

/**
 * JEPX data fetcher and LOOOP-style price calculator.
 */
class Gaming_Hub_Looop_Api {

	const LOSS_RATE       = 0.071;
	const TAX_RATE        = 1.1;
	const CHUBU_COLUMN    = 'Chuubu Yen/kWh';
	const DATETIME_COLUMN = 'datetime';
	const CSV_URL         = 'https://japanesepower.org/spot_%d.csv';
	const LOOKBACK_DAYS   = 8;

	/** @var float サービス料（税込・円/kWh） */
	const CHUBU_SERVICE_FEE = 7.0;

	/** @var float 託送従量料金相当（税込・円/kWh） */
	const CHUBU_TRANSMISSION_VOLUMETRIC = 7.91;

	/** @var float 再エネ賦課金（2026年度・税込・円/kWh） */
	const RENEWABLE_SURCHARGE = 4.18;

	/** @var float 託送基本料金相当（税込・円/kW） */
	const CHUBU_TRANSMISSION_BASIC_PER_KW = 214.5;

	/** @var float 容量拠出金相当（2026/4〜・税込・円/kW） */
	const CHUBU_CAPACITY_PER_KW = 88.04;

	/** @var float 基本料金按分の前提契約電力（kW） */
	const DEFAULT_CONTRACT_KW = 6.0;

	/** @var float 基本料金按分の前提月間使用量（kWh） */
	const DEFAULT_MONTHLY_KWH = 350.0;

	/**
	 * Fetch forecast payload for Chubu area.
	 *
	 * @param bool $force_refresh Skip transient cache.
	 * @return array<string, mixed>|WP_Error
	 */
	public function get_forecast( $force_refresh = false ) {
		$cache_key = 'gaming_hub_looop_forecast_v4';
		$cache_ttl = HOUR_IN_SECONDS;

		if ( ! $force_refresh ) {
			$cached = get_transient( $cache_key );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$slots = $this->fetch_chubu_slots();
		if ( is_wp_error( $slots ) ) {
			return $slots;
		}

		if ( empty( $slots ) ) {
			return new WP_Error(
				'looop_no_data',
				__( 'JEPX 価格データを取得できませんでした。', 'gaming-hub' )
			);
		}

		$payload = $this->build_forecast_payload( $slots );
		set_transient( $cache_key, $payload, $cache_ttl );

		return $payload;
	}

	/**
	 * Download and parse recent Chubu JEPX slots.
	 *
	 * @return array<int, array<string, mixed>>|WP_Error
	 */
	private function fetch_chubu_slots() {
		$timezone = gaming_hub_looop_timezone();
		$now      = gaming_hub_looop_now();
		$years    = array( (int) $now->format( 'Y' ) );

		if ( '01' === $now->format( 'm' ) && (int) $now->format( 'd' ) <= self::LOOKBACK_DAYS ) {
			$years[] = (int) $now->format( 'Y' ) - 1;
		}

		$cutoff = $now->modify( '-' . self::LOOKBACK_DAYS . ' days' )->setTime( 0, 0, 0 );
		$slots  = array();

		foreach ( array_unique( $years ) as $year ) {
			$url  = sprintf( self::CSV_URL, $year );
			$body = $this->download_csv( $url );

			if ( is_wp_error( $body ) ) {
				if ( empty( $slots ) ) {
					return $body;
				}
				continue;
			}

			$parsed = $this->parse_csv_slots( $body, $cutoff );
			$slots  = array_merge( $slots, $parsed );
		}

		usort(
			$slots,
			static function ( $a, $b ) {
				return strcmp( $a['datetime'], $b['datetime'] );
			}
		);

		return $slots;
	}

	/**
	 * @param string $url Remote CSV URL.
	 * @return string|WP_Error
	 */
	private function download_csv( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout' => 30,
				'headers' => array(
					'Accept' => 'text/csv,text/plain,*/*',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		if ( 200 !== $code ) {
			return new WP_Error(
				'looop_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'JEPX データの取得に失敗しました (HTTP %d)。', 'gaming-hub' ),
					$code
				)
			);
		}

		$body = wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return new WP_Error(
				'looop_empty_body',
				__( 'JEPX データが空でした。', 'gaming-hub' )
			);
		}

		return $body;
	}

	/**
	 * @param string               $body   CSV body.
	 * @param DateTimeImmutable    $cutoff Earliest datetime to keep.
	 * @return array<int, array<string, mixed>>
	 */
	private function parse_csv_slots( $body, $cutoff ) {
		$timezone = gaming_hub_looop_timezone();
		$lines    = preg_split( '/\r\n|\n|\r/', trim( $body ) );
		if ( empty( $lines ) ) {
			return array();
		}

		$header = str_getcsv( array_shift( $lines ) );
		$dt_idx = array_search( self::DATETIME_COLUMN, $header, true );
		$px_idx = array_search( self::CHUBU_COLUMN, $header, true );

		if ( false === $dt_idx || false === $px_idx ) {
			return array();
		}

		$slots = array();

		foreach ( $lines as $line ) {
			if ( '' === trim( $line ) ) {
				continue;
			}

			$row = str_getcsv( $line );
			if ( ! isset( $row[ $dt_idx ], $row[ $px_idx ] ) ) {
				continue;
			}

			$datetime = DateTimeImmutable::createFromFormat( 'Y-m-d H:i:s', $row[ $dt_idx ], $timezone );
			if ( ! $datetime || $datetime < $cutoff ) {
				continue;
			}

			$jepx = (float) $row[ $px_idx ];
			if ( $jepx <= 0 ) {
				continue;
			}

			$power_price = $this->to_power_price( $jepx );
			$total_price = $this->to_total_price( $power_price );

			$slots[] = array(
				'datetime'      => $datetime->format( 'Y-m-d H:i:s' ),
				'date'          => $datetime->format( 'Y-m-d' ),
				'hour'          => (int) $datetime->format( 'G' ),
				'minute'        => (int) $datetime->format( 'i' ),
				'period'        => ( (int) $datetime->format( 'G' ) * 2 ) + ( (int) $datetime->format( 'i' ) >= 30 ? 2 : 1 ),
				'jepx'          => $jepx,
				'power_price'   => $power_price,
				'total_price'   => $total_price,
				'forecast_mark' => 'normal',
			);
		}

		return $slots;
	}

	/**
	 * Convert JEPX area price to LOOOP 電源料金 (tax included, 2 decimals).
	 *
	 * @param float $jepx JEPX area price (tax excluded).
	 */
	public function to_power_price( $jepx ) {
		$adjusted = $jepx / ( 1 - self::LOSS_RATE );
		$with_tax = $adjusted * self::TAX_RATE;

		return round( $with_tax, 2 );
	}

	/**
	 * Fixed per-kWh surcharges for Chubu (tax included).
	 *
	 * @return array<string, float>
	 */
	public function get_fixed_cost_breakdown() {
		$monthly_basic = ( self::CHUBU_TRANSMISSION_BASIC_PER_KW + self::CHUBU_CAPACITY_PER_KW ) * self::DEFAULT_CONTRACT_KW;
		$basic_per_kwh = $monthly_basic / self::DEFAULT_MONTHLY_KWH;
		$volumetric    = self::CHUBU_SERVICE_FEE + self::CHUBU_TRANSMISSION_VOLUMETRIC + self::RENEWABLE_SURCHARGE;

		return array(
			'service_fee'            => self::CHUBU_SERVICE_FEE,
			'transmission_volumetric'=> self::CHUBU_TRANSMISSION_VOLUMETRIC,
			'renewable_surcharge'    => self::RENEWABLE_SURCHARGE,
			'basic_amortized'        => round( $basic_per_kwh, 2 ),
			'volumetric_total'       => round( $volumetric, 2 ),
			'total'                  => round( $volumetric, 2 ),
		);
	}

	/**
	 * LOOOP 請求単価 (¥/kWh): 電源料金 + サービス料 + 託送従量 + 再エネ賦課金.
	 * kW 課金の託送基本・容量拠出金は含まない.
	 *
	 * @param float $power_price Tax-included 電源料金.
	 */
	public function to_total_price( $power_price ) {
		$fixed = $this->get_fixed_cost_breakdown();

		return round( $power_price + (float) $fixed['volumetric_total'], 2 );
	}

	/**
	 * @param array<int, array<string, mixed>> $slots Parsed half-hour slots.
	 * @return array<string, mixed>
	 */
	private function build_forecast_payload( array $slots ) {
		$timezone = gaming_hub_looop_timezone();
		$now      = gaming_hub_looop_now();
		$today    = $now->format( 'Y-m-d' );
		$tomorrow = $now->modify( '+1 day' )->format( 'Y-m-d' );
		$yesterday = $now->modify( '-1 day' )->format( 'Y-m-d' );

		$by_date = array();
		foreach ( $slots as $slot ) {
			$by_date[ $slot['date'] ][] = $slot;
		}

		$days = array(
			'yesterday' => $this->build_day_data( $yesterday, $by_date, $slots, false ),
			'today'     => $this->build_day_data( $today, $by_date, $slots, $this->is_weekend_or_holiday( $today ) ),
			'tomorrow'  => $this->build_day_data( $tomorrow, $by_date, $slots, $this->is_weekend_or_holiday( $tomorrow ) ),
		);

		$current_slot = $this->find_current_slot( $days['today']['slots'] ?? $slots, $now );
		$hourly_today = $days['today']['hourly'] ?? array();
		$fixed_costs  = $this->get_fixed_cost_breakdown();

		return array(
			'area'          => 'chubu',
			'area_label'    => __( '中部電力エリア', 'gaming-hub' ),
			'updated_at'    => $now->format( 'Y-m-d H:i' ),
			'current'       => $current_slot,
			'days'          => $days,
			'hourly_today'  => $hourly_today,
			'has_tomorrow'  => ! empty( $days['tomorrow']['slots'] ),
			'cheapest_hour' => $this->find_cheapest_hour( $hourly_today ),
			'fixed_costs'   => $fixed_costs,
			'pricing_note'  => __( '請求単価 = 電源料金 + サービス料 7.00 + 託送従量 7.91 + 再エネ 4.18。託送基本・容量拠出金は月額のため含みません。', 'gaming-hub' ),
			'source'        => 'JEPX / japanesepower.org',
			'disclaimer'    => __( 'LOOOP スマートタイムONE（電灯）の請求単価です。JEPX中部エリアから電源料金を算出し、サービス料・託送従量料金・再エネ賦課金を加算しています。月額の制度対応費（託送基本・容量拠出金）は含みません。', 'gaming-hub' ),
		);
	}

	/**
	 * @param string                           $date      Target date (Y-m-d).
	 * @param array<string, array<int, mixed>> $by_date   Slots grouped by date.
	 * @param array<int, array<string, mixed>> $all_slots All slots for history.
	 * @param bool                             $is_holiday Weekend/holiday flag.
	 * @return array<string, mixed>
	 */
	private function build_day_data( $date, array $by_date, array $all_slots, $is_holiday ) {
		$day_slots = $by_date[ $date ] ?? array();
		if ( empty( $day_slots ) ) {
			return array(
				'date'       => $date,
				'date_label' => gaming_hub_looop_format_datetime( $date . ' 12:00:00', 'n/j (D)' ),
				'slots'      => array(),
				'hourly'     => array(),
			);
		}

		$history_days = $is_holiday ? 3 : 5;
		$history      = $this->get_history_before( $date, $all_slots, $history_days );
		$avg_jepx     = $this->average_jepx( $history );

		$marked = $this->apply_forecast_marks( $day_slots, $avg_jepx );
		$hourly = $this->aggregate_hourly( $marked );

		return array(
			'date'       => $date,
			'date_label' => gaming_hub_looop_format_datetime( $date . ' 12:00:00', 'n/j (D)' ),
			'avg_jepx'   => round( $avg_jepx, 2 ),
			'slots'      => $marked,
			'hourly'     => $hourly,
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $history Historical slots.
	 */
	private function average_jepx( array $history ) {
		if ( empty( $history ) ) {
			return 0.0;
		}

		$sum = 0.0;
		foreach ( $history as $slot ) {
			$sum += (float) $slot['jepx'];
		}

		return $sum / count( $history );
	}

	/**
	 * @param string                           $target_date Target day.
	 * @param array<int, array<string, mixed>> $all_slots   All slots.
	 * @param int                              $days        Number of prior days.
	 * @return array<int, array<string, mixed>>
	 */
	private function get_history_before( $target_date, array $all_slots, $days ) {
		$timezone  = gaming_hub_looop_timezone();
		$target_dt = new DateTimeImmutable( $target_date, $timezone );
		$start     = $target_dt->modify( '-' . $days . ' days' );
		$history   = array();

		foreach ( $all_slots as $slot ) {
			$slot_dt = new DateTimeImmutable( $slot['datetime'], $timezone );
			if ( $slot_dt >= $start && $slot_dt < $target_dt ) {
				$history[] = $slot;
			}
		}

		return $history;
	}

	/**
	 * Apply LOOOP-style forecast marks to half-hour slots.
	 *
	 * @param array<int, array<string, mixed>> $day_slots Day slots.
	 * @param float                            $avg_jepx  Historical average JEPX.
	 * @return array<int, array<string, mixed>>
	 */
	private function apply_forecast_marks( array $day_slots, $avg_jepx ) {
		foreach ( $day_slots as $index => $slot ) {
			$day_slots[ $index ]['forecast_mark'] = 'normal';

			if ( (float) $slot['jepx'] > 100 ) {
				$day_slots[ $index ]['forecast_mark'] = 'alert';
			}
		}

		$sunny_candidates   = array();
		$caution_candidates = array();

		foreach ( $day_slots as $index => $slot ) {
			if ( 'alert' === $day_slots[ $index ]['forecast_mark'] ) {
				continue;
			}

			$jepx = (float) $slot['jepx'];
			if ( $jepx <= $avg_jepx - 5 ) {
				$sunny_candidates[ $index ] = $jepx;
			} elseif ( $jepx >= $avg_jepx + 5 ) {
				$caution_candidates[ $index ] = $jepx;
			}
		}

		asort( $sunny_candidates );
		$sunny_indexes = array_slice( array_keys( $sunny_candidates ), 0, 10, true );
		foreach ( $sunny_indexes as $index ) {
			$day_slots[ $index ]['forecast_mark'] = 'sunny';
		}

		arsort( $caution_candidates );
		$caution_indexes = array_slice( array_keys( $caution_candidates ), 0, 10, true );
		foreach ( $caution_indexes as $index ) {
			if ( 'sunny' !== $day_slots[ $index ]['forecast_mark'] ) {
				$day_slots[ $index ]['forecast_mark'] = 'caution';
			}
		}

		return $day_slots;
	}

	/**
	 * Aggregate 30-min slots into hourly bars.
	 *
	 * @param array<int, array<string, mixed>> $slots Half-hour slots.
	 * @return array<int, array<string, mixed>>
	 */
	private function aggregate_hourly( array $slots ) {
		$groups = array();

		foreach ( $slots as $slot ) {
			$key = $slot['date'] . '-' . $slot['hour'];
			if ( ! isset( $groups[ $key ] ) ) {
				$groups[ $key ] = array(
					'date'           => $slot['date'],
					'hour'           => $slot['hour'],
					'label'          => sprintf( '%02d:00', $slot['hour'] ),
					'jepx_values'    => array(),
					'power_prices'   => array(),
					'total_prices'   => array(),
					'forecast_marks' => array(),
				);
			}

			$groups[ $key ]['jepx_values'][]    = (float) $slot['jepx'];
			$groups[ $key ]['power_prices'][]   = (float) $slot['power_price'];
			$groups[ $key ]['total_prices'][]   = (float) $slot['total_price'];
			$groups[ $key ]['forecast_marks'][] = $slot['forecast_mark'];
		}

		$hourly = array();
		foreach ( $groups as $group ) {
			$mark         = $this->dominant_mark( $group['forecast_marks'] );
			$power_price  = round( array_sum( $group['power_prices'] ) / count( $group['power_prices'] ), 2 );
			$total_price  = round( array_sum( $group['total_prices'] ) / count( $group['total_prices'] ), 2 );
			$hourly[]     = array(
				'date'          => $group['date'],
				'hour'          => $group['hour'],
				'label'         => $group['label'],
				'jepx'          => round( array_sum( $group['jepx_values'] ) / count( $group['jepx_values'] ), 2 ),
				'power_price'   => $power_price,
				'total_price'   => $total_price,
				'forecast_mark' => $mark,
			);
		}

		usort(
			$hourly,
			static function ( $a, $b ) {
				if ( $a['date'] === $b['date'] ) {
					return $a['hour'] <=> $b['hour'];
				}
				return strcmp( $a['date'], $b['date'] );
			}
		);

		return $hourly;
	}

	/**
	 * Pick the strongest forecast mark for an hour.
	 *
	 * @param array<int, string> $marks Slot marks within the hour.
	 */
	private function dominant_mark( array $marks ) {
		if ( in_array( 'alert', $marks, true ) ) {
			return 'alert';
		}
		if ( in_array( 'caution', $marks, true ) ) {
			return 'caution';
		}
		if ( in_array( 'sunny', $marks, true ) ) {
			return 'sunny';
		}

		return 'normal';
	}

	/**
	 * @param array<int, array<string, mixed>> $slots All slots.
	 * @param DateTimeImmutable                $now   Current time.
	 * @return array<string, mixed>|null
	 */
	private function find_current_slot( array $slots, DateTimeImmutable $now ) {
		$minute  = (int) $now->format( 'i' ) >= 30 ? 30 : 0;
		$current = $now->setTime( (int) $now->format( 'G' ), $minute, 0 )->format( 'Y-m-d H:i:s' );

		foreach ( $slots as $slot ) {
			if ( $slot['datetime'] === $current ) {
				return array(
					'datetime'      => $slot['datetime'],
					'label'         => gaming_hub_looop_format_datetime( $slot['datetime'] ),
					'jepx'          => $slot['jepx'],
					'power_price'   => $slot['power_price'],
					'total_price'   => $slot['total_price'],
					'forecast_mark' => $slot['forecast_mark'],
				);
			}
		}

		// Fallback: nearest past slot.
		$past = array_filter(
			$slots,
			static function ( $slot ) use ( $now ) {
				return $slot['datetime'] <= $now->format( 'Y-m-d H:i:s' );
			}
		);

		if ( empty( $past ) ) {
			return null;
		}

		$slot = end( $past );
		return array(
			'datetime'      => $slot['datetime'],
			'label'         => gaming_hub_looop_format_datetime( $slot['datetime'] ),
			'jepx'          => $slot['jepx'],
			'power_price'   => $slot['power_price'],
			'total_price'   => $slot['total_price'],
			'forecast_mark' => $slot['forecast_mark'],
		);
	}

	/**
	 * @param array<int, array<string, mixed>> $hourly Hourly rows.
	 * @return array<string, mixed>|null
	 */
	private function find_cheapest_hour( array $hourly ) {
		if ( empty( $hourly ) ) {
			return null;
		}

		$cheapest = null;
		foreach ( $hourly as $row ) {
			if ( null === $cheapest || $row['total_price'] < $cheapest['total_price'] ) {
				$cheapest = $row;
			}
		}

		return $cheapest;
	}

	/**
	 * @param string $date Date string (Y-m-d).
	 */
	private function is_weekend_or_holiday( $date ) {
		$date_obj  = new DateTimeImmutable( $date . ' 12:00:00', gaming_hub_looop_timezone() );
		$timestamp = $date_obj->getTimestamp();
		$dow       = (int) wp_date( 'w', $timestamp, gaming_hub_looop_timezone() );

		if ( 0 === $dow || 6 === $dow ) {
			return true;
		}

		$holidays = $this->japanese_holidays( (int) wp_date( 'Y', $timestamp, gaming_hub_looop_timezone() ) );
		return in_array( wp_date( 'Y-m-d', $timestamp, gaming_hub_looop_timezone() ), $holidays, true );
	}

	/**
	 * Minimal Japanese public holiday list (fixed + common movable).
	 *
	 * @param int $year Year.
	 * @return array<int, string>
	 */
	private function japanese_holidays( $year ) {
		$fixed = array(
			sprintf( '%d-01-01', $year ),
			sprintf( '%d-02-11', $year ),
			sprintf( '%d-02-23', $year ),
			sprintf( '%d-04-29', $year ),
			sprintf( '%d-05-03', $year ),
			sprintf( '%d-05-04', $year ),
			sprintf( '%d-05-05', $year ),
			sprintf( '%d-08-11', $year ),
			sprintf( '%d-11-03', $year ),
			sprintf( '%d-11-23', $year ),
		);

		// Golden Week / New Year observed dates vary; good enough for forecast window logic.
		return $fixed;
	}
}
