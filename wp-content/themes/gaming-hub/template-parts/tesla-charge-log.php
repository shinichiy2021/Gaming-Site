<?php
/**
 * Tesla home AC charge session history.
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $log Month payload.
 */

$log       = isset( $args['log'] ) && is_array( $args['log'] ) ? $args['log'] : array();
$sessions  = is_array( $log['sessions'] ?? null ) ? $log['sessions'] : array();
$totals    = is_array( $log['totals'] ?? null ) ? $log['totals'] : array();
$current   = is_array( $log['current'] ?? null ) ? $log['current'] : null;

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

$format_rate = static function ( $value ) {
	if ( null === $value || '' === $value ) {
		return '—';
	}
	return number_format( (float) $value, 1 ) . ' 円/kWh';
};
?>

<section
	id="charge"
	class="ecoflow-plan ecoflow-cal tesla-cal tesla-charge-log"
	aria-label="<?php esc_attr_e( '充電セッション履歴', 'gaming-hub' ); ?>"
	data-tesla-charge
	data-month="<?php echo esc_attr( $log['month'] ?? '' ); ?>"
>
	<div class="ecoflow-plan-header ecoflow-plan-head">
		<div>
			<p class="ecoflow-plan-kicker"><?php esc_html_e( 'CHARGE LOG', 'gaming-hub' ); ?></p>
			<h3><?php esc_html_e( '充電セッション履歴', 'gaming-hub' ); ?></h3>
			<p class="ecoflow-plan-note">
				<?php esc_html_e( '自宅 200V 充電の開始〜終了を記録します。電力量は車の charge_energy_added、料金は LOOOP 単価で積算しています。', 'gaming-hub' ); ?>
			</p>
		</div>
		<p class="ecoflow-plan-limits">
			<span data-tesla-charge-label><?php echo esc_html( $log['label'] ?? '' ); ?></span>
			<span class="ecoflow-cal-nav">
				<button type="button" class="ecoflow-plan-cancel" data-tesla-charge-prev data-month="<?php echo esc_attr( $log['prev'] ?? '' ); ?>"><?php esc_html_e( '前月', 'gaming-hub' ); ?></button>
				<button type="button" class="ecoflow-plan-cancel" data-tesla-charge-next data-month="<?php echo esc_attr( $log['next'] ?? '' ); ?>"><?php esc_html_e( '翌月', 'gaming-hub' ); ?></button>
			</span>
		</p>
	</div>

	<div class="ecoflow-rates-hud ecoflow-plan-hud ecoflow-cal-hud">
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( 'NOW', 'gaming-hub' ); ?></span>
			<strong data-tesla-charge-now>
				<?php
				echo esc_html(
					$current
						? __( '充電中', 'gaming-hub' )
						: __( '待機', 'gaming-hub' )
				);
				?>
			</strong>
			<small data-tesla-charge-now-detail>
				<?php
				if ( $current ) {
					echo esc_html(
						trim(
							( $current['range_label'] ?? '' )
							. ( ! empty( $current['kwh'] ) ? ' · ' . number_format( (float) $current['kwh'], 2 ) . ' kWh' : '' )
						)
					);
				} else {
					echo '—';
				}
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( '今月回数', 'gaming-hub' ); ?></span>
			<strong data-tesla-charge-count><?php echo esc_html( number_format_i18n( (int) ( $totals['count'] ?? 0 ) ) ); ?></strong>
			<small><?php esc_html_e( 'セッション', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( '今月電力量', 'gaming-hub' ); ?></span>
			<strong data-tesla-charge-kwh><?php echo esc_html( $format_kwh( $totals['kwh'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( '自宅充電', 'gaming-hub' ); ?></small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-buy">
			<span><?php esc_html_e( '今月料金', 'gaming-hub' ); ?></span>
			<strong data-tesla-charge-yen><?php echo esc_html( $format_yen( $totals['yen'] ?? 0 ) ); ?></strong>
			<small><?php esc_html_e( 'LOOOP 積算', 'gaming-hub' ); ?></small>
		</div>
	</div>

	<ol class="tesla-charge-list" data-tesla-charge-list>
		<?php if ( $current ) : ?>
			<li class="tesla-charge-row is-active" data-tesla-charge-current>
				<div class="tesla-charge-when">
					<strong><?php echo esc_html( $current['when_label'] ?: __( '充電中', 'gaming-hub' ) ); ?></strong>
					<span class="tesla-charge-badge"><?php esc_html_e( '進行中', 'gaming-hub' ); ?></span>
				</div>
				<div class="tesla-charge-meta">
					<span><?php echo esc_html( $current['range_label'] ?: '—' ); ?></span>
					<span><?php echo esc_html( $current['duration_label'] ?: '—' ); ?></span>
					<span><?php echo esc_html( $format_kwh( $current['kwh'] ?? 0 ) ); ?></span>
					<span><?php echo esc_html( $format_yen( $current['yen'] ?? 0 ) ); ?></span>
					<span><?php echo esc_html( $format_rate( $current['yen_per_kwh'] ?? null ) ); ?></span>
				</div>
			</li>
		<?php endif; ?>

		<?php if ( empty( $sessions ) && ! $current ) : ?>
			<li class="tesla-charge-empty" data-tesla-charge-empty>
				<?php esc_html_e( 'この月の充電セッションはまだありません。次回の自宅充電から記録されます。', 'gaming-hub' ); ?>
			</li>
		<?php endif; ?>

		<?php foreach ( $sessions as $session ) : ?>
			<li class="tesla-charge-row">
				<div class="tesla-charge-when">
					<strong><?php echo esc_html( $session['when_label'] ?: '—' ); ?></strong>
					<?php if ( ! empty( $session['limit_soc'] ) ) : ?>
						<span class="tesla-charge-limit"><?php echo esc_html( sprintf( __( '上限 %s%%', 'gaming-hub' ), (string) (int) $session['limit_soc'] ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="tesla-charge-meta">
					<span><?php echo esc_html( $session['range_label'] ?: '—' ); ?></span>
					<span><?php echo esc_html( $session['duration_label'] ?: '—' ); ?></span>
					<span><?php echo esc_html( $format_kwh( $session['kwh'] ?? 0 ) ); ?></span>
					<span><?php echo esc_html( $format_yen( $session['yen'] ?? 0 ) ); ?></span>
					<span><?php echo esc_html( $format_rate( $session['yen_per_kwh'] ?? null ) ); ?></span>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
