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
		<h2 class="section-title"><?php esc_html_e( '主な仕様', 'gaming-hub' ); ?></h2>
		<p class="section-desc"><?php esc_html_e( 'Tesla 公開情報に基づく参考値（地域・モデルにより異なります）', 'gaming-hub' ); ?></p>
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
			<p class="pw-spec-note"><?php esc_html_e( '三相ネイティブ。2026年ドイツなど欧州で展開開始', 'gaming-hub' ); ?></p>
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
				<strong><?php esc_html_e( 'PW2 との併用', 'gaming-hub' ); ?></strong>
				<p><?php esc_html_e( 'ファームウェア 26.26 以降、Powerwall 2 / 3 / Expansion を同一システムで運用可能', 'gaming-hub' ); ?></p>
			</div>
		</div>
		<div class="pw-highlight-item">
			<span class="pw-highlight-icon">☀️</span>
			<div>
				<strong><?php esc_html_e( 'ソーラー一体型', 'gaming-hub' ); ?></strong>
				<p><?php esc_html_e( 'ハイブリッドインバータ内蔵。太陽光・EV充電・自家消費を Tesla アプリで管理', 'gaming-hub' ); ?></p>
			</div>
		</div>
		<div class="pw-highlight-item">
			<span class="pw-highlight-icon">🏠</span>
			<div>
				<strong><?php esc_html_e( '停電時バックアップ', 'gaming-hub' ); ?></strong>
				<p><?php esc_html_e( 'グリッド断時も重要負荷へ給電。容量は Expansion で拡張', 'gaming-hub' ); ?></p>
			</div>
		</div>
	</div>
</section>
