<?php
/**
 * Powerwall 3 / 3P specification cards
 *
 * @package Gaming_Hub
 */

$specs = gaming_hub_get_powerwall_specs();
?>

<section class="powerwall-specs" aria-label="<?php esc_attr_e( 'Powerwall Specifications', 'gaming-hub' ); ?>">
	<div class="section-header">
		<h2 class="section-title"><?php esc_html_e('Key specs', 'gaming-hub'); ?></h2>
		<p class="section-desc"><?php esc_html_e('Reference values from Tesla public info (varies by region and model)', 'gaming-hub'); ?></p>
	</div>

	<div class="pw-specs-grid">
		<div class="pw-spec-card">
			<h3 class="pw-spec-title">Powerwall 3</h3>
			<dl class="pw-spec-list">
				<?php foreach ( $specs['pw3'] as $row ) : ?>
					<div class="pw-spec-row">
						<dt><?php echo esc_html( $row['label'] ); ?></dt>
						<dd><?php echo esc_html( $row['value'] ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</div>

		<div class="pw-spec-card pw-spec-card-highlight">
			<h3 class="pw-spec-title">Powerwall 3P</h3>
			<p class="pw-spec-note"><?php esc_html_e('Native 3-phase. European rollout from 2026, including Germany', 'gaming-hub'); ?></p>
			<dl class="pw-spec-list">
				<?php foreach ( $specs['pw3p'] as $row ) : ?>
					<div class="pw-spec-row">
						<dt><?php echo esc_html( $row['label'] ); ?></dt>
						<dd><?php echo esc_html( $row['value'] ); ?></dd>
					</div>
				<?php endforeach; ?>
			</dl>
		</div>
	</div>

	<div class="pw-highlights">
		<div class="pw-highlight-item">
			<span class="pw-highlight-icon">🔗</span>
			<div>
				<strong><?php esc_html_e('Use with PW2', 'gaming-hub'); ?></strong>
				<p><?php esc_html_e('From firmware 26.26, Powerwall 2 / 3 / Expansion can run on the same system', 'gaming-hub'); ?></p>
			</div>
		</div>
		<div class="pw-highlight-item">
			<span class="pw-highlight-icon">☀️</span>
			<div>
				<strong><?php esc_html_e('Solar-integrated', 'gaming-hub'); ?></strong>
				<p><?php esc_html_e('Built-in hybrid inverter. Manage solar, EV charging, and self-consumption in the Tesla app', 'gaming-hub'); ?></p>
			</div>
		</div>
		<div class="pw-highlight-item">
			<span class="pw-highlight-icon">🏠</span>
			<div>
				<strong><?php esc_html_e('Backup during outages', 'gaming-hub'); ?></strong>
				<p><?php esc_html_e('Keeps critical loads powered if the grid drops. Expand capacity with Expansion', 'gaming-hub'); ?></p>
			</div>
		</div>
	</div>
</section>
