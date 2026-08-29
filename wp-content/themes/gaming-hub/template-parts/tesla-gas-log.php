<?php
/**
 * Tesla driving log (month list, charge-log style).
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $calendar Month payload.
 */

$calendar = isset( $args['calendar'] ) && is_array( $args['calendar'] ) ? $args['calendar'] : array();
$days     = is_array( $calendar['days'] ?? null ) ? $calendar['days'] : array();
$totals   = is_array( $calendar['totals'] ?? null ) ? $calendar['totals'] : array();
$now      = is_array( $calendar['now'] ?? null ) ? $calendar['now'] : array();
$today_st = is_array( $calendar['today_stats'] ?? null ) ? $calendar['today_stats'] : array();
$summary  = is_array( $calendar['summary'] ?? null ) ? $calendar['summary'] : array();
$today    = (string) ( $calendar['today'] ?? '' );
$sum_day  = is_array( $summary['day'] ?? null ) ? $summary['day'] : array();
$sum_view = $sum_day;

$format_km = static function ( $value, $digits = 1 ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}
	return number_format( (float) $value, $digits ) . ' km';
};

$format_l = static function ( $value ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}
	return number_format( (float) $value, 2 ) . ' L';
};

$format_kwh = static function ( $value ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}
	return number_format( (float) $value, 2 ) . ' kWh';
};

$format_yen = static function ( $value ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}
	return number_format( (float) $value, 0 ) . ' 円';
};

$format_avg = static function ( $value ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}
	return number_format( (float) $value, 1 ) . ' 円/km';
};

$weekday_labels = array(
	0 => __( '日', 'gaming-hub' ),
	1 => __( '月', 'gaming-hub' ),
	2 => __( '火', 'gaming-hub' ),
	3 => __( '水', 'gaming-hub' ),
	4 => __( '木', 'gaming-hub' ),
	5 => __( '金', 'gaming-hub' ),
	6 => __( '土', 'gaming-hub' ),
);

$format_when = static function ( $ymd ) use ( $weekday_labels ) {
	$ts = strtotime( (string) $ymd . ' 12:00:00' );
	if ( ! $ts ) {
		return (string) $ymd;
	}
	$w = (int) wp_date( 'w', $ts );
	return wp_date( 'n/j', $ts ) . '（' . ( $weekday_labels[ $w ] ?? '' ) . '）';
};

$rows = array();
foreach ( $days as $cell ) {
	if ( empty( $cell['has_data'] ) ) {
		continue;
	}
	$rows[] = $cell;
}
$rows = array_reverse( $rows );

$now_yen_h = (int) ( $now['saved_yen_per_h'] ?? 0 );
$now_idle  = ! empty( $now['asleep'] ) || $now_yen_h <= 0;
$now_text  = $now_idle ? __( '待機', 'gaming-hub' ) : ( number_format( $now_yen_h ) . ' 円/時' );
$price_label = (string) ( $now['price_label'] ?? '' );
?>

<span id="gas" hidden></span>
<section
	id="drive"
	class="ecoflow-plan ecoflow-cal tesla-cal tesla-gas-log"
	aria-label="<?php esc_attr_e( '走行ログ', 'gaming-hub' ); ?>"
	data-tesla-gas
	data-month="<?php echo esc_attr( $calendar['month'] ?? '' ); ?>"
	data-summary="<?php echo esc_attr( wp_json_encode( $summary ) ); ?>"
>
	<div class="ecoflow-plan-header ecoflow-plan-head">
		<div>
			<p class="ecoflow-plan-kicker"><?php esc_html_e( 'DRIVING LOG', 'gaming-hub' ); ?></p>
			<h3><?php esc_html_e( '走行ログ', 'gaming-hub' ); ?></h3>
			<p class="ecoflow-plan-note">
				<?php esc_html_e( '走行距離と消費電力を記録し、普通車 15 km/L 換算との差を節約額にしています。', 'gaming-hub' ); ?>
				<?php if ( $price_label ) : ?>
					<span data-tesla-gas-price><?php echo esc_html( $price_label ); ?></span>
				<?php endif; ?>
			</p>
		</div>
		<p class="ecoflow-plan-limits">
			<span data-tesla-gas-label><?php echo esc_html( $calendar['label'] ?? '' ); ?></span>
			<span class="ecoflow-cal-nav">
				<button type="button" class="ecoflow-plan-cancel" data-tesla-gas-prev data-month="<?php echo esc_attr( $calendar['prev'] ?? '' ); ?>"><?php esc_html_e( '前月', 'gaming-hub' ); ?></button>
				<button type="button" class="ecoflow-plan-cancel" data-tesla-gas-next data-month="<?php echo esc_attr( $calendar['next'] ?? '' ); ?>"><?php esc_html_e( '翌月', 'gaming-hub' ); ?></button>
			</span>
		</p>
	</div>

	<div class="tesla-gas-summary" data-tesla-gas-summary>
		<div class="tesla-gas-summary-head">
			<div>
				<p class="tesla-gas-summary-kicker"><?php esc_html_e( 'SUMMARY', 'gaming-hub' ); ?></p>
				<strong data-tesla-gas-summary-label><?php echo esc_html( (string) ( $sum_view['label'] ?? __( '今日', 'gaming-hub' ) ) ); ?></strong>
			</div>
			<div class="tesla-gas-summary-tabs" role="tablist" aria-label="<?php esc_attr_e( '日次／週次サマリー', 'gaming-hub' ); ?>">
				<button
					type="button"
					class="tesla-gas-summary-tab is-active"
					role="tab"
					aria-selected="true"
					data-tesla-gas-summary-tab="day"
				><?php esc_html_e( '日次', 'gaming-hub' ); ?></button>
				<button
					type="button"
					class="tesla-gas-summary-tab"
					role="tab"
					aria-selected="false"
					data-tesla-gas-summary-tab="week"
				><?php esc_html_e( '週次', 'gaming-hub' ); ?></button>
			</div>
		</div>
		<?php
		$eff = function_exists( 'gaming_hub_tesla_drive_efficiency_snapshot' )
			? gaming_hub_tesla_drive_efficiency_snapshot(
				isset( $sum_day['km'] ) ? (float) $sum_day['km'] : null
			)
			: array();
		?>
		<?php if ( ! empty( $eff['badge_wh'] ) || ! empty( $eff['badge_regen'] ) ) : ?>
			<div class="tesla-eff-badges tesla-eff-badges-inline" aria-label="<?php esc_attr_e( '効率バッジ', 'gaming-hub' ); ?>">
				<?php if ( ! empty( $eff['badge_wh'] ) ) : ?>
					<span class="tesla-eff-badge is-<?php echo esc_attr( (string) ( $eff['tier_wh'] ?? 'idle' ) ); ?>"><?php echo esc_html( (string) $eff['badge_wh'] ); ?></span>
				<?php endif; ?>
				<?php if ( ! empty( $eff['badge_regen'] ) ) : ?>
					<span class="tesla-eff-badge is-<?php echo esc_attr( (string) ( $eff['tier_regen'] ?? 'idle' ) ); ?>"><?php echo esc_html( (string) $eff['badge_regen'] ); ?></span>
				<?php endif; ?>
			</div>
		<?php endif; ?>
		<div class="tesla-gas-summary-grid" role="tabpanel">
			<div class="tesla-gas-summary-stat">
				<span><?php esc_html_e( '走行', 'gaming-hub' ); ?></span>
				<strong data-tesla-gas-summary-km><?php echo esc_html( $format_km( $sum_view['km'] ?? 0 ) ); ?></strong>
			</div>
			<div class="tesla-gas-summary-stat">
				<span><?php esc_html_e( '充電円', 'gaming-hub' ); ?></span>
				<strong data-tesla-gas-summary-ev><?php echo esc_html( $format_yen( $sum_view['ev_yen'] ?? 0 ) ); ?></strong>
			</div>
			<div class="tesla-gas-summary-stat tesla-gas-summary-save">
				<span><?php esc_html_e( '節約円', 'gaming-hub' ); ?></span>
				<strong data-tesla-gas-summary-save><?php echo esc_html( $format_yen( $sum_view['saved_yen'] ?? 0 ) ); ?></strong>
			</div>
			<div class="tesla-gas-summary-stat">
				<span><?php esc_html_e( '平均単価', 'gaming-hub' ); ?></span>
				<strong data-tesla-gas-summary-avg><?php echo esc_html( $format_avg( $sum_view['avg_yen_per_km'] ?? null ) ); ?></strong>
			</div>
		</div>
	</div>

	<div class="ecoflow-rates-hud ecoflow-plan-hud ecoflow-cal-hud">
		<div class="ecoflow-rates-stat ecoflow-rates-stat-pv">
			<span><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-now><?php echo esc_html( $now_text ); ?></strong>
			<small data-tesla-gas-now-speed>
				<?php
				printf(
					/* translators: %s: speed km/h */
					esc_html__( '%s km/h', 'gaming-hub' ),
					esc_html( (string) (int) ( $now['speed_km'] ?? 0 ) )
				);
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( '今月走行', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-month-km><?php echo esc_html( $format_km( $totals['km'] ?? 0 ) ); ?></strong>
			<small data-tesla-gas-today-km>
				<?php
				printf(
					/* translators: %s: today's km */
					esc_html__( '今日 %s', 'gaming-hub' ),
					esc_html( $format_km( $today_st['km'] ?? 0 ) )
				);
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-save">
			<span><?php esc_html_e( '今月節約', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-month-save><?php echo esc_html( $format_yen( $totals['saved_yen'] ?? null ) ); ?></strong>
			<small data-tesla-gas-today-save>
				<?php
				printf(
					/* translators: %s: today's saved yen */
					esc_html__( '今日 %s', 'gaming-hub' ),
					esc_html( $format_yen( $today_st['saved_yen'] ?? 0 ) )
				);
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( '節約ガソリン', 'gaming-hub' ); ?></span>
			<strong data-tesla-gas-month-l><?php echo esc_html( $format_l( $totals['gas_l'] ?? 0 ) ); ?></strong>
			<small data-tesla-gas-today-l>
				<?php
				printf(
					/* translators: %s: today's gas liters */
					esc_html__( '今日 %s', 'gaming-hub' ),
					esc_html( $format_l( $today_st['gas_l'] ?? 0 ) )
				);
				?>
			</small>
		</div>
	</div>

	<ol class="tesla-charge-list tesla-gas-list" data-tesla-gas-list>
		<?php if ( empty( $rows ) ) : ?>
			<li class="tesla-charge-empty" data-tesla-gas-empty>
				<?php esc_html_e( 'この月の走行ログはまだありません。', 'gaming-hub' ); ?>
			</li>
		<?php endif; ?>

		<?php foreach ( $rows as $cell ) : ?>
			<?php
			$is_today = ! empty( $cell['is_today'] ) || ( (string) ( $cell['date'] ?? '' ) === $today );
			?>
			<li class="tesla-charge-row<?php echo $is_today ? ' is-active' : ''; ?>" data-tesla-gas-day="<?php echo esc_attr( (string) ( $cell['date'] ?? '' ) ); ?>">
				<div class="tesla-charge-when">
					<strong><?php echo esc_html( $format_when( $cell['date'] ?? '' ) ); ?></strong>
					<?php if ( $is_today ) : ?>
						<span class="tesla-charge-badge"><?php esc_html_e( '今日', 'gaming-hub' ); ?></span>
					<?php endif; ?>
				</div>
				<div class="tesla-charge-meta">
					<span><?php echo esc_html( $format_km( $cell['km'] ?? null ) ); ?></span>
					<span><?php echo esc_html( $format_kwh( $cell['ev_kwh'] ?? null ) ); ?></span>
					<span><?php echo esc_html( $format_yen( $cell['ev_yen'] ?? null ) ); ?></span>
					<span><?php echo esc_html( $format_yen( $cell['saved_yen'] ?? null ) ); ?></span>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
