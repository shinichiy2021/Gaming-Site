<?php
/**
 * EcoFlow generation log (week IN/OUT chart + month calendar).
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $calendar Month payload.
 */

$calendar = isset( $args['calendar'] ) && is_array( $args['calendar'] ) ? $args['calendar'] : array();
$days     = is_array( $calendar['days'] ?? null ) ? $calendar['days'] : array();
$weeks    = is_array( $calendar['weeks'] ?? null ) ? $calendar['weeks'] : array();
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

$format_in = static function ( $input, $solar ) use ( $format_kwh ) {
	$in = $format_kwh( $input );
	if ( null === $input && null === $solar ) {
		return $in;
	}
	return $in . ' (PV ' . $format_kwh( $solar ) . ')';
};

$format_tick = static function ( $value ) {
	$n = (float) $value;
	if ( $n >= 10 ) {
		return number_format( $n, 0 );
	}
	return number_format( $n, $n >= 1 ? 1 : 2 );
};

$active_week = null;
foreach ( $weeks as $week ) {
	if ( ! empty( $week['is_active'] ) ) {
		$active_week = $week;
		break;
	}
}
if ( null === $active_week && $weeks ) {
	$active_week = $weeks[0];
}

$week_days  = is_array( $active_week['days'] ?? null ) ? $active_week['days'] : array();
$week_max   = max( 1, (float) ( $active_week['kwh_max'] ?? 1 ) );
$week_ticks = is_array( $active_week['kwh_ticks'] ?? null ) ? $active_week['kwh_ticks'] : array( $week_max, 0 );
$week_label = (string) ( $active_week['label'] ?? '' );
$week_index = (int) ( $active_week['index'] ?? 0 );
?>

<section class="ecoflow-plan ecoflow-cal" aria-label="<?php esc_attr_e('Generation log', 'gaming-hub'); ?>" data-ecoflow-cal data-month="<?php echo esc_attr( $calendar['month'] ?? '' ); ?>" data-week="<?php echo esc_attr( (string) $week_index ); ?>">
	<script type="application/json" data-ecoflow-cal-bootstrap><?php echo wp_json_encode( $calendar, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS ); ?></script>
	<div class="ecoflow-plan-header ecoflow-plan-head">
		<div>
			<p class="ecoflow-plan-kicker"><?php esc_html_e('GEN LOG', 'gaming-hub'); ?></p>
			<h3><?php esc_html_e('Generation log', 'gaming-hub'); ?></h3>
			<p class="ecoflow-plan-note"><?php esc_html_e('IN = OUT + STORE + LOSS. IN = site input (solar + grid). OUT = living + UPS + DC + standby. STORE = pack SOC Wh. LOSS = charge heat and remaining meter gap — not extra storage.', 'gaming-hub'); ?></p>
		</div>
		<p class="ecoflow-plan-limits">
			<span data-ecoflow-cal-label><?php echo esc_html( $calendar['label'] ?? '' ); ?></span>
			<span class="ecoflow-cal-nav">
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-prev data-month="<?php echo esc_attr( $calendar['prev'] ?? '' ); ?>"><?php esc_html_e('Prev month', 'gaming-hub'); ?></button>
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-next data-month="<?php echo esc_attr( $calendar['next'] ?? '' ); ?>"><?php esc_html_e('Next month', 'gaming-hub'); ?></button>
			</span>
		</p>
	</div>

	<div class="ecoflow-cal-week" data-ecoflow-cal-week>
		<div class="ecoflow-cal-week-head">
			<p class="ecoflow-cal-chart-label"><?php esc_html_e('Weekly IN / OUT', 'gaming-hub'); ?></p>
			<span class="ecoflow-cal-nav ecoflow-cal-week-nav">
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-week-prev><?php esc_html_e('Prev week', 'gaming-hub'); ?></button>
				<span data-ecoflow-cal-week-label><?php echo esc_html( $week_label ); ?></span>
				<button type="button" class="ecoflow-plan-cancel" data-ecoflow-cal-week-next><?php esc_html_e('Next week', 'gaming-hub'); ?></button>
			</span>
		</div>
		<div class="ecoflow-rate-chart ecoflow-cal-chart ecoflow-cal-week-chart">
			<div class="ecoflow-rate-y ecoflow-rate-y-kwh" aria-hidden="true">
				<span class="ecoflow-rate-y-unit"><?php esc_html_e('kWh', 'gaming-hub'); ?></span>
				<?php foreach ( $week_ticks as $tick ) : ?>
					<span data-ecoflow-cal-week-kwh-tick><?php echo esc_html( $format_tick( $tick ) ); ?></span>
				<?php endforeach; ?>
			</div>
			<div class="ecoflow-rate-plot">
				<div class="ecoflow-rate-track ecoflow-cal-week-track" data-ecoflow-cal-week-track role="img" aria-label="<?php esc_attr_e('Weekly input versus output', 'gaming-hub'); ?>">
					<?php foreach ( $week_days as $day ) : ?>
						<?php
						$in_v  = isset( $day['input_kwh'] ) && is_numeric( $day['input_kwh'] ) ? (float) $day['input_kwh'] : null;
						$out_v = isset( $day['output_kwh'] ) && is_numeric( $day['output_kwh'] ) ? (float) $day['output_kwh'] : null;
						$in_h  = null !== $in_v ? max( 0, min( 100, ( $in_v / $week_max ) * 100 ) ) : 0;
						$out_h = null !== $out_v ? max( 0, min( 100, ( $out_v / $week_max ) * 100 ) ) : 0;
						$col_class = 'ecoflow-rate-col ecoflow-cal-week-col';
						if ( ! empty( $day['is_today'] ) ) {
							$col_class .= ' is-now';
						}
						if ( empty( $day['has_data'] ) ) {
							$col_class .= ' is-empty';
						}
						?>
						<div class="<?php echo esc_attr( $col_class ); ?>" data-ecoflow-cal-week-col data-date="<?php echo esc_attr( (string) ( $day['date'] ?? '' ) ); ?>">
							<?php if ( ! empty( $day['is_today'] ) ) : ?>
								<span class="ecoflow-rate-now-pip"><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
							<?php endif; ?>
							<span class="ecoflow-cal-week-pair">
								<span class="ecoflow-rate-bar ecoflow-cal-in-bar" style="height: <?php echo esc_attr( (string) round( $in_h, 1 ) ); ?>%;" title="<?php echo esc_attr( 'IN ' . $format_kwh( $in_v ) ); ?>"></span>
								<span class="ecoflow-rate-bar ecoflow-cal-out-bar" style="height: <?php echo esc_attr( (string) round( $out_h, 1 ) ); ?>%;" title="<?php echo esc_attr( 'OUT ' . $format_kwh( $out_v ) ); ?>"></span>
							</span>
						</div>
					<?php endforeach; ?>
				</div>
				<div class="ecoflow-rate-hours" aria-hidden="true" data-ecoflow-cal-week-hours>
					<?php foreach ( $week_days as $i => $day ) : ?>
						<span class="ecoflow-rate-hour<?php echo ! empty( $day['is_today'] ) ? ' is-now' : ''; ?>">
							<?php
							$label = isset( $day['day'] ) ? (string) (int) $day['day'] : ( $weekdays[ $i ] ?? '' );
							echo esc_html( $label );
							?>
						</span>
					<?php endforeach; ?>
				</div>
			</div>
		</div>
		<p class="ecoflow-rate-legend"><?php esc_html_e('Cyan bars: IN (solar + grid) · Magenta bars: OUT (living + UPS)', 'gaming-hub'); ?></p>
		<p class="ecoflow-cal-week-totals" data-ecoflow-cal-week-totals>
			<?php
			$wt = is_array( $active_week['totals'] ?? null ) ? $active_week['totals'] : array();
			echo esc_html(
				__( 'Week', 'gaming-hub' )
				. ' · IN ' . $format_in( $wt['input_kwh'] ?? 0, $wt['solar_kwh'] ?? 0 )
				. ' · OUT ' . $format_kwh( $wt['output_kwh'] ?? 0 )
				. ' · STORE ' . $format_kwh( $wt['stored_kwh'] ?? 0 )
				. ' · LOSS ' . $format_kwh( $wt['loss_kwh'] ?? 0 )
				. ' kWh'
			);
			?>
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
				<span class="ecoflow-plan-slot-mode ecoflow-cal-in">IN <b data-k="in"><?php echo esc_html( $format_in( $cell['input_kwh'] ?? null, $cell['solar_kwh'] ?? null ) ); ?></b></span>
				<span class="ecoflow-plan-slot-mode">OUT <b data-k="out"><?php echo esc_html( $format_kwh( $cell['output_kwh'] ?? null ) ); ?></b></span>
				<span class="ecoflow-plan-slot-mode ecoflow-cal-store">STORE <b data-k="store"><?php echo esc_html( $format_kwh( $cell['stored_kwh'] ?? null ) ); ?></b></span>
				<span class="ecoflow-plan-slot-mode ecoflow-cal-loss">LOSS <b data-k="loss"><?php echo esc_html( $format_kwh( $cell['loss_kwh'] ?? null ) ); ?></b></span>
				<span class="ecoflow-plan-slot-yen">SAVE <b data-k="save"><?php echo esc_html( $format_yen( $cell['saved_yen'] ?? null ) ); ?></b></span>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
