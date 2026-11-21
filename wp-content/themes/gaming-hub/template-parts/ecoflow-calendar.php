<?php
/**
 * EcoFlow generation log calendar (month grid).
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $calendar Month payload.
 */

$calendar = isset( $args['calendar'] ) && is_array( $args['calendar'] ) ? $args['calendar'] : array();
$days     = is_array( $calendar['days'] ?? null ) ? $calendar['days'] : array();
$weekdays = is_array( $calendar['weekdays'] ?? null ) ? $calendar['weekdays'] : array( '日', '月', '火', '水', '木', '金', '土' );
$start_w  = (int) ( $calendar['start_wday'] ?? 0 );
$today    = (string) ( $calendar['today'] ?? '' );

$format_kwh = static function ( $value, $digits = 2 ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format( (float) $value, $digits );
};

$format_yen = static function ( $value ) {
	if ( null === $value ) {
		return '—';
	}
	return number_format( (float) $value, 0 ) . ' 円';
};
?>

<section class="ecoflow-plan ecoflow-cal" aria-label="<?php esc_attr_e('Generation log', 'gaming-hub'); ?>" data-ecoflow-cal data-month="<?php echo esc_attr( $calendar['month'] ?? '' ); ?>">
	<div class="ecoflow-plan-header ecoflow-plan-head">
		<div>
			<p class="ecoflow-plan-kicker"><?php esc_html_e('GEN LOG', 'gaming-hub'); ?></p>
			<h3><?php esc_html_e('Generation log', 'gaming-hub'); ?></h3>
			<p class="ecoflow-plan-note"><?php esc_html_e('Totals Pro 3 high-volt plus Delta 1500 Low Volt. Input and output are both units combined. Savings = (Living AC & others Pro AC out + UPS 1500 AC out) × that hour’s import rate, minus 1500 and Pro grid AC in × the same rate.', 'gaming-hub'); ?></p>
		</div>
		<p class="ecoflow-plan-limits">
			<span data-ecoflow-cal-label><?php echo esc_html( $calendar['label'] ?? '' ); ?></span>
			<span class="ecoflow-cal-nav">
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-prev data-month="<?php echo esc_attr( $calendar['prev'] ?? '' ); ?>"><?php esc_html_e('Prev month', 'gaming-hub'); ?></button>
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-next data-month="<?php echo esc_attr( $calendar['next'] ?? '' ); ?>"><?php esc_html_e('Next month', 'gaming-hub'); ?></button>
			</span>
		</p>
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
