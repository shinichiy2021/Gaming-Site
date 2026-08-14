<?php
/**
 * EcoFlow generation calendar (input / output / now / saved yen).
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $calendar Month payload.
 */

$calendar = isset( $args['calendar'] ) && is_array( $args['calendar'] ) ? $args['calendar'] : array();
$days     = is_array( $calendar['days'] ?? null ) ? $calendar['days'] : array();
$weekdays = is_array( $calendar['weekdays'] ?? null ) ? $calendar['weekdays'] : array( '日', '月', '火', '水', '木', '金', '土' );
$totals   = is_array( $calendar['totals'] ?? null ) ? $calendar['totals'] : array();
$now      = is_array( $calendar['now'] ?? null ) ? $calendar['now'] : array();
$start_w  = (int) ( $calendar['start_wday'] ?? 0 );
$today    = (string) ( $calendar['today'] ?? '' );

$format_kwh = static function ( $value ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format( (float) $value, 2 );
};

$format_yen = static function ( $value ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format( (float) $value, 0 ) . ' 円';
};

$format_w = static function ( $value ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format_i18n( (int) $value ) . ' W';
};
?>

<section class="ecoflow-plan ecoflow-cal" aria-label="<?php esc_attr_e( '発電カレンダー', 'gaming-hub' ); ?>" data-ecoflow-cal data-month="<?php echo esc_attr( $calendar['month'] ?? '' ); ?>">
	<div class="ecoflow-plan-header">
		<h3><?php esc_html_e( '発電ログ', 'gaming-hub' ); ?></h3>
		<p class="ecoflow-plan-note"><?php esc_html_e( 'Pro 3 ハイボルト + Delta 1500 Low Volt の合算を積算しています。入力・出力も両機の合計です。節約額は発電量 × その時間の請求単価です。', 'gaming-hub' ); ?></p>
		<p class="ecoflow-plan-limits">
			<span data-ecoflow-cal-label><?php echo esc_html( $calendar['label'] ?? '' ); ?></span>
			<span class="ecoflow-cal-nav">
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-prev data-month="<?php echo esc_attr( $calendar['prev'] ?? '' ); ?>"><?php esc_html_e( '前月', 'gaming-hub' ); ?></button>
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-next data-month="<?php echo esc_attr( $calendar['next'] ?? '' ); ?>"><?php esc_html_e( '翌月', 'gaming-hub' ); ?></button>
			</span>
		</p>
	</div>

	<div class="ecoflow-plan-grid">
		<div class="ecoflow-plan-card">
			<span class="ecoflow-stat-label"><?php esc_html_e( 'いまの入力', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-now-in><?php echo esc_html( $format_w( $now['input'] ?? null ) ); ?></strong>
		</div>
		<div class="ecoflow-plan-card">
			<span class="ecoflow-stat-label"><?php esc_html_e( 'いまの出力', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-now-out><?php echo esc_html( $format_w( $now['output'] ?? null ) ); ?></strong>
		</div>
		<div class="ecoflow-plan-card">
			<span class="ecoflow-stat-label"><?php esc_html_e( 'いまの発電', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-now-pv><?php echo esc_html( $format_w( $now['solar'] ?? null ) ); ?></strong>
		</div>
		<div class="ecoflow-plan-card">
			<span class="ecoflow-stat-label"><?php esc_html_e( '今月の節約', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-month-save><?php echo esc_html( $format_yen( $totals['saved_yen'] ?? null ) ); ?></strong>
		</div>
		<div class="ecoflow-plan-card">
			<span class="ecoflow-stat-label"><?php esc_html_e( '月計 入力', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-month-in><?php echo esc_html( $format_kwh( $totals['input_kwh'] ?? 0 ) . ' kWh' ); ?></strong>
		</div>
		<div class="ecoflow-plan-card">
			<span class="ecoflow-stat-label"><?php esc_html_e( '月計 出力', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-month-out><?php echo esc_html( $format_kwh( $totals['output_kwh'] ?? 0 ) . ' kWh' ); ?></strong>
		</div>
		<div class="ecoflow-plan-card">
			<span class="ecoflow-stat-label"><?php esc_html_e( '月計 発電', 'gaming-hub' ); ?></span>
			<strong data-ecoflow-cal-month-pv><?php echo esc_html( $format_kwh( $totals['solar_kwh'] ?? 0 ) . ' kWh' ); ?></strong>
		</div>
	</div>

	<div class="ecoflow-cal-weekdays" aria-hidden="true">
		<?php foreach ( $weekdays as $label ) : ?>
			<span><?php echo esc_html( $label ); ?></span>
		<?php endforeach; ?>
	</div>

	<ol class="ecoflow-plan-slots ecoflow-cal-grid" data-ecoflow-cal-grid>
		<?php for ( $i = 0; $i < $start_w; $i++ ) : ?>
			<li class="ecoflow-plan-slot is-blank"></li>
		<?php endfor; ?>
		<?php foreach ( $days as $cell ) : ?>
			<?php
			$is_today = ! empty( $cell['is_today'] );
			$has_data = ! empty( $cell['has_data'] );
			$is_past  = ! $is_today && $today && ( (string) ( $cell['date'] ?? '' ) < $today );
			$classes  = array( 'ecoflow-plan-slot' );
			if ( $is_today ) {
				$classes[] = 'is-now';
			}
			if ( $has_data ) {
				$classes[] = 'is-solar';
			}
			if ( $is_past && ! $has_data ) {
				$classes[] = 'is-past';
			}
			if ( $has_data && (float) ( $cell['saved_yen'] ?? 0 ) > 0 ) {
				$classes[] = 'is-charge';
			}
			?>
			<li
				class="<?php echo esc_attr( implode( ' ', $classes ) ); ?>"
				data-ecoflow-cal-day="<?php echo esc_attr( $cell['date'] ); ?>"
			>
				<span class="ecoflow-plan-slot-hour"><?php echo esc_html( (string) ( $cell['day'] ?? '' ) ); ?></span>
				<span class="ecoflow-plan-slot-mode">IN <b data-k="in"><?php echo esc_html( $format_kwh( $cell['input_kwh'] ?? null ) ); ?></b></span>
				<span class="ecoflow-plan-slot-mode">OUT <b data-k="out"><?php echo esc_html( $format_kwh( $cell['output_kwh'] ?? null ) ); ?></b></span>
				<span class="ecoflow-plan-slot-watts">PV <b data-k="pv"><?php echo esc_html( $format_kwh( $cell['solar_kwh'] ?? null ) ); ?></b></span>
				<span class="ecoflow-plan-slot-yen">SAVE <b data-k="save"><?php echo esc_html( $format_yen( $cell['saved_yen'] ?? null ) ); ?></b></span>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
