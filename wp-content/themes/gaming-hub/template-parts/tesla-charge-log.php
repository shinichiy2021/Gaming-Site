<?php
/**
 * Tesla charge session history (home AC + Supercharger).
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

$format_yen = static function ( $value, $known = true ) {
	if ( ! $known || null === $value || '' === $value ) {
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

$now_label = __( '待機', 'gaming-hub' );
if ( $current ) {
	$now_label = ( 'supercharger' === ( $current['supply'] ?? '' ) )
		? __( '急速充電中', 'gaming-hub' )
		: __( '充電中', 'gaming-hub' );
}
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
				<?php esc_html_e( '自宅 200V は LOOOP 単価、急速充電（Supercharger）は Tesla Fleet の充電履歴 API の請求額を使います。履歴に出るまで急速の料金は空欄のままです。', 'gaming-hub' ); ?>
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
			<strong data-tesla-charge-now><?php echo esc_html( $now_label ); ?></strong>
			<small data-tesla-charge-now-detail>
				<?php
				if ( $current ) {
					$bits = array_filter(
						array(
							(string) ( $current['supply_label'] ?? '' ),
							(string) ( $current['range_label'] ?? '' ),
							! empty( $current['kwh'] ) ? number_format( (float) $current['kwh'], 2 ) . ' kWh' : '',
						)
					);
					echo esc_html( $bits ? implode( ' · ', $bits ) : '—' );
				} else {
					echo '—';
				}
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( '今月回数', 'gaming-hub' ); ?></span>
			<strong data-tesla-charge-count><?php echo esc_html( number_format_i18n( (int) ( $totals['count'] ?? 0 ) ) ); ?></strong>
			<small data-tesla-charge-count-detail>
				<?php
				printf(
					/* translators: 1: home sessions, 2: supercharger sessions */
					esc_html__( '自宅 %1$s · 急速 %2$s', 'gaming-hub' ),
					esc_html( number_format_i18n( (int) ( $totals['home_count'] ?? 0 ) ) ),
					esc_html( number_format_i18n( (int) ( $totals['super_count'] ?? 0 ) ) )
				);
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat">
			<span><?php esc_html_e( '今月電力量', 'gaming-hub' ); ?></span>
			<strong data-tesla-charge-kwh><?php echo esc_html( $format_kwh( $totals['kwh'] ?? 0 ) ); ?></strong>
			<small data-tesla-charge-kwh-detail>
				<?php
				printf(
					/* translators: 1: home kWh, 2: supercharger kWh */
					esc_html__( '自宅 %1$s · 急速 %2$s', 'gaming-hub' ),
					esc_html( number_format( (float) ( $totals['home_kwh'] ?? 0 ), 2 ) ),
					esc_html( number_format( (float) ( $totals['super_kwh'] ?? 0 ), 2 ) )
				);
				?>
			</small>
		</div>
		<div class="ecoflow-rates-stat ecoflow-cal-stat-buy">
			<span><?php esc_html_e( '今月料金', 'gaming-hub' ); ?></span>
			<strong data-tesla-charge-yen><?php echo esc_html( $format_yen( $totals['yen'] ?? 0 ) ); ?></strong>
			<small data-tesla-charge-yen-detail>
				<?php
				printf(
					/* translators: 1: home yen, 2: supercharger yen */
					esc_html__( '自宅 %1$s · 急速 %2$s', 'gaming-hub' ),
					esc_html( number_format_i18n( (int) ( $totals['home_yen'] ?? 0 ) ) ),
					esc_html( number_format_i18n( (int) ( $totals['super_yen'] ?? 0 ) ) )
				);
				?>
			</small>
		</div>
	</div>

	<ol class="tesla-charge-list" data-tesla-charge-list>
		<?php if ( $current ) : ?>
			<li class="tesla-charge-row is-active<?php echo 'supercharger' === ( $current['supply'] ?? '' ) ? ' is-super' : ''; ?>" data-tesla-charge-current>
				<div class="tesla-charge-when">
					<strong><?php echo esc_html( $current['when_label'] ?: __( '充電中', 'gaming-hub' ) ); ?></strong>
					<span class="tesla-charge-badge"><?php esc_html_e( '進行中', 'gaming-hub' ); ?></span>
					<span class="tesla-charge-supply"><?php echo esc_html( $current['supply_label'] ?? __( '自宅充電', 'gaming-hub' ) ); ?></span>
					<?php if ( ! empty( $current['site_name'] ) ) : ?>
						<span class="tesla-charge-site"><?php echo esc_html( $current['site_name'] ); ?></span>
					<?php endif; ?>
				</div>
				<div class="tesla-charge-meta">
					<span><?php echo esc_html( $current['range_label'] ?: '—' ); ?></span>
					<span><?php echo esc_html( $current['duration_label'] ?: '—' ); ?></span>
					<span><?php echo esc_html( $format_kwh( $current['kwh'] ?? 0 ) ); ?></span>
					<span><?php echo esc_html( $format_yen( $current['yen'] ?? null, ! empty( $current['yen_known'] ) ) ); ?></span>
					<span>
						<?php
						if ( ! empty( $current['yen_known'] ) ) {
							echo esc_html( $format_rate( $current['yen_per_kwh'] ?? null ) );
						} elseif ( ! empty( $current['peak_w'] ) && 'supercharger' === ( $current['supply'] ?? '' ) ) {
							echo esc_html( number_format_i18n( (int) $current['peak_w'] ) . ' W' );
						} else {
							echo esc_html( $format_rate( $current['yen_per_kwh'] ?? null ) );
						}
						?>
					</span>
				</div>
			</li>
		<?php endif; ?>

		<?php if ( empty( $sessions ) && ! $current ) : ?>
			<li class="tesla-charge-empty" data-tesla-charge-empty>
				<?php esc_html_e( 'この月の充電セッションはまだありません。次回の自宅／急速充電、または Fleet 履歴の同期から記録されます。', 'gaming-hub' ); ?>
			</li>
		<?php endif; ?>

		<?php foreach ( $sessions as $session ) : ?>
			<li class="tesla-charge-row<?php echo 'supercharger' === ( $session['supply'] ?? '' ) ? ' is-super' : ''; ?>">
				<div class="tesla-charge-when">
					<strong><?php echo esc_html( $session['when_label'] ?: '—' ); ?></strong>
					<span class="tesla-charge-supply"><?php echo esc_html( $session['supply_label'] ?? __( '自宅充電', 'gaming-hub' ) ); ?></span>
					<?php if ( ! empty( $session['site_name'] ) ) : ?>
						<span class="tesla-charge-site"><?php echo esc_html( $session['site_name'] ); ?></span>
					<?php endif; ?>
					<?php if ( ! empty( $session['limit_soc'] ) ) : ?>
						<span class="tesla-charge-limit"><?php echo esc_html( sprintf( __( '上限 %s%%', 'gaming-hub' ), (string) (int) $session['limit_soc'] ) ); ?></span>
					<?php endif; ?>
				</div>
				<div class="tesla-charge-meta">
					<span><?php echo esc_html( $session['range_label'] ?: '—' ); ?></span>
					<span><?php echo esc_html( $session['duration_label'] ?: '—' ); ?></span>
					<span><?php echo esc_html( $format_kwh( $session['kwh'] ?? 0 ) ); ?></span>
					<span><?php echo esc_html( $format_yen( $session['yen'] ?? null, ! empty( $session['yen_known'] ) ) ); ?></span>
					<span>
						<?php
						if ( ! empty( $session['yen_known'] ) ) {
							echo esc_html( $format_rate( $session['yen_per_kwh'] ?? null ) );
						} elseif ( ! empty( $session['peak_w'] ) && 'supercharger' === ( $session['supply'] ?? '' ) ) {
							echo esc_html( number_format_i18n( (int) $session['peak_w'] ) . ' W' );
						} else {
							echo esc_html( $format_rate( $session['yen_per_kwh'] ?? null ) );
						}
						?>
					</span>
				</div>
			</li>
		<?php endforeach; ?>
	</ol>
</section>
