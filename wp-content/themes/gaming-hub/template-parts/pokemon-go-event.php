<?php
/**
 * Single Pokémon GO large-event tokushuu page.
 *
 * @package Gaming_Hub
 *
 * @var array<string, mixed> $event
 */

$event = isset( $args['event'] ) && is_array( $args['event'] ) ? $args['event'] : array();
if ( empty( $event ) ) {
	return;
}

$theme    = (string) ( $event['theme'] ?? 'worlds' );
$status   = (string) ( $event['status'] ?? 'upcoming' );
$related  = gaming_hub_pgo_event_related_news( $event, 6 );
$official = (string) ( $event['official'] ?? 'https://pokemongolive.com/ja/events/' );
$art      = gaming_hub_pgo_artwork_url( (int) ( $event['featured_dex'] ?? 0 ) );
$today    = is_array( $event['today'] ?? null ) ? $event['today'] : array();
$line_of  = static function ( $item ) {
	if ( is_array( $item ) ) {
		return array(
			'icon'  => (string) ( $item['icon'] ?? 'spark' ),
			'text'  => (string) ( $item['text'] ?? '' ),
			'label' => (string) ( $item['label'] ?? $item['title'] ?? '' ),
		);
	}

	return array(
		'icon'  => 'spark',
		'text'  => (string) $item,
		'label' => '',
	);
};
?>
<div class="pokemon-go-page pgo-event-page theme-<?php echo esc_attr( $theme ); ?>">
	<section class="pgo-hero pgo-event-hero">
		<div class="pgo-hero-bg"></div>
		<div class="container pgo-event-hero-grid">
			<div class="pgo-hero-content pgo-event-hero-copy">
				<span class="pgo-hero-badge"><?php echo esc_html( $event['kicker'] ?? __( '大型イベント特集', 'gaming-hub' ) ); ?></span>
				<p class="pgo-event-kicker">
					<span class="pgo-badge pgo-status-<?php echo esc_attr( $status ); ?>">
						<?php echo esc_html( gaming_hub_pgo_event_status_label( $status ) ); ?>
					</span>
					<span class="pgo-event-when"><?php echo esc_html( gaming_hub_pgo_format_range( $event['start_dt'] ?? null, $event['end_dt'] ?? null ) ); ?></span>
				</p>
				<h1 class="pgo-hero-title"><?php echo esc_html( $event['title'] ); ?></h1>
				<p class="pgo-hero-desc"><?php echo esc_html( $event['lead'] ); ?></p>
				<div class="pgo-hero-links">
					<a href="<?php echo esc_url( $official ); ?>" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
						<?php esc_html_e( '公式を見る', 'gaming-hub' ); ?>
					</a>
					<a href="<?php echo esc_url( gaming_hub_pgo_tokushuu_url() ); ?>" class="btn btn-outline">
						<?php esc_html_e( '特集一覧', 'gaming-hub' ); ?>
					</a>
					<?php if ( function_exists( 'gaming_hub_pgo_raid_url' ) ) : ?>
						<a href="<?php echo esc_url( gaming_hub_pgo_raid_url() ); ?>" class="btn btn-outline">
							<?php esc_html_e( 'レイド招待', 'gaming-hub' ); ?>
						</a>
					<?php endif; ?>
				</div>
			</div>
			<?php if ( $art ) : ?>
				<div class="pgo-event-hero-art" aria-hidden="true">
					<img src="<?php echo esc_url( $art ); ?>" alt="" width="320" height="320" decoding="async" />
				</div>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( ! empty( $today ) ) : ?>
		<section class="section pgo-event-block pgo-today-block">
			<div class="container">
				<h2 class="pgo-event-heading">
					<?php gaming_hub_pgo_icon( 'check' ); ?>
					<?php esc_html_e( '今日やること', 'gaming-hub' ); ?>
				</h2>
				<ol class="pgo-today-steps">
					<?php foreach ( $today as $i => $step ) : ?>
						<li class="pgo-today-step">
							<span class="pgo-today-num"><?php echo esc_html( (string) ( $i + 1 ) ); ?></span>
							<span class="pgo-today-ico"><?php gaming_hub_pgo_icon( (string) ( $step['icon'] ?? 'check' ) ); ?></span>
							<div>
								<strong><?php echo esc_html( $step['title'] ?? '' ); ?></strong>
								<span><?php echo esc_html( $step['text'] ?? '' ); ?></span>
							</div>
						</li>
					<?php endforeach; ?>
				</ol>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $event['phases'] ) ) : ?>
		<section class="section pgo-event-block">
			<div class="container">
				<h2 class="pgo-event-heading">
					<?php gaming_hub_pgo_icon( 'clock' ); ?>
					<?php esc_html_e( 'スケジュール', 'gaming-hub' ); ?>
				</h2>
				<div class="pgo-event-phases">
					<?php foreach ( $event['phases'] as $phase ) : ?>
						<div class="pgo-event-phase">
							<?php gaming_hub_pgo_icon( (string) ( $phase['icon'] ?? 'clock' ), 'pgo-ico pgo-ico-lg' ); ?>
							<h3><?php echo esc_html( $phase['title'] ?? '' ); ?></h3>
							<p class="pgo-tokushuu-when"><?php echo esc_html( $phase['when'] ?? '' ); ?></p>
							<p><?php echo esc_html( $phase['note'] ?? '' ); ?></p>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<section class="section pgo-event-block">
		<div class="container pgo-event-split">
			<?php if ( ! empty( $event['highlights'] ) ) : ?>
				<div>
					<h2 class="pgo-event-heading">
						<?php gaming_hub_pgo_icon( 'spark' ); ?>
						<?php esc_html_e( '見どころ', 'gaming-hub' ); ?>
					</h2>
					<ul class="pgo-icon-list">
						<?php foreach ( $event['highlights'] as $line ) : ?>
							<?php $row = $line_of( $line ); ?>
							<li>
								<?php gaming_hub_pgo_icon( $row['icon'] ); ?>
								<span><?php echo esc_html( $row['text'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
			<?php if ( ! empty( $event['bonuses'] ) ) : ?>
				<div>
					<h2 class="pgo-event-heading">
						<?php gaming_hub_pgo_icon( 'star' ); ?>
						<?php esc_html_e( 'ボーナス', 'gaming-hub' ); ?>
					</h2>
					<ul class="pgo-icon-list">
						<?php foreach ( $event['bonuses'] as $line ) : ?>
							<?php $row = $line_of( $line ); ?>
							<li>
								<?php gaming_hub_pgo_icon( $row['icon'] ); ?>
								<span><?php echo esc_html( $row['text'] ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				</div>
			<?php endif; ?>
		</div>
	</section>

	<?php if ( ! empty( $event['debuts'] ) ) : ?>
		<section class="section pgo-event-block">
			<div class="container">
				<h2 class="pgo-event-heading">
					<?php gaming_hub_pgo_icon( 'spark' ); ?>
					<?php esc_html_e( '初登場・注目ポケモン', 'gaming-hub' ); ?>
				</h2>
				<?php gaming_hub_render_pgo_mon_row( $event['debuts'], 'pgo-mon-row pgo-mon-row-lg' ); ?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $event['wild'] ) || ! empty( $event['raids'] ) ) : ?>
		<section class="section pgo-event-block">
			<div class="container pgo-event-split">
				<?php if ( ! empty( $event['wild'] ) ) : ?>
					<div>
						<h2 class="pgo-event-heading">
							<?php gaming_hub_pgo_icon( 'ball' ); ?>
							<?php esc_html_e( '野生', 'gaming-hub' ); ?>
						</h2>
						<?php foreach ( $event['wild'] as $row ) : ?>
							<h3 class="pgo-event-sub"><?php echo esc_html( $row['phase'] ?? '' ); ?></h3>
							<?php gaming_hub_render_pgo_mon_row( $row['pokemon'] ?? array() ); ?>
							<?php if ( ! empty( $row['rare'] ) ) : ?>
								<p class="pgo-event-rare"><?php esc_html_e( 'まれ', 'gaming-hub' ); ?></p>
								<?php gaming_hub_render_pgo_mon_row( $row['rare'], 'pgo-mon-row pgo-mon-row-rare' ); ?>
							<?php endif; ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $event['raids'] ) ) : ?>
					<div>
						<h2 class="pgo-event-heading">
							<?php gaming_hub_pgo_icon( 'raid' ); ?>
							<?php esc_html_e( 'レイド', 'gaming-hub' ); ?>
						</h2>
						<?php foreach ( $event['raids'] as $row ) : ?>
							<h3 class="pgo-event-sub"><?php echo esc_html( $row['phase'] ?? '' ); ?></h3>
							<?php gaming_hub_render_pgo_mon_row( $row['pokemon'] ?? array() ); ?>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $event['moves'] ) ) : ?>
		<section class="section pgo-event-block">
			<div class="container">
				<h2 class="pgo-event-heading">
					<?php gaming_hub_pgo_icon( 'evolve' ); ?>
					<?php esc_html_e( '特別なわざ', 'gaming-hub' ); ?>
				</h2>
				<p class="section-desc"><?php esc_html_e( 'この期間に捕獲・進化した個体だけが覚えます。', 'gaming-hub' ); ?></p>
				<div class="pgo-move-grid">
					<?php foreach ( $event['moves'] as $row ) : ?>
						<div class="pgo-move-card">
							<?php gaming_hub_pgo_icon( 'battle' ); ?>
							<strong><?php echo esc_html( $row['move'] ?? '' ); ?></strong>
							<span><?php echo esc_html( $row['pokemon'] ?? '' ); ?></span>
							<em><?php echo esc_html( $row['kind'] ?? '' ); ?></em>
						</div>
					<?php endforeach; ?>
				</div>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $event['how_to'] ) || ! empty( $event['watch'] ) ) : ?>
		<section class="section pgo-event-block">
			<div class="container pgo-event-split">
				<?php if ( ! empty( $event['how_to'] ) ) : ?>
					<div>
						<h2 class="pgo-event-heading">
							<?php gaming_hub_pgo_icon( 'pass' ); ?>
							<?php esc_html_e( '入手・パス', 'gaming-hub' ); ?>
						</h2>
						<ul class="pgo-icon-list">
							<?php foreach ( $event['how_to'] as $row ) : ?>
								<?php $item = $line_of( $row ); ?>
								<li>
									<?php gaming_hub_pgo_icon( $item['icon'] ?: 'pass' ); ?>
									<span><b><?php echo esc_html( $item['label'] ); ?></b> <?php echo esc_html( $item['text'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
				<?php if ( ! empty( $event['watch'] ) ) : ?>
					<div>
						<h2 class="pgo-event-heading">
							<?php gaming_hub_pgo_icon( 'tv' ); ?>
							<?php esc_html_e( '配信・視聴特典', 'gaming-hub' ); ?>
						</h2>
						<ul class="pgo-icon-list">
							<?php foreach ( $event['watch'] as $row ) : ?>
								<?php $item = $line_of( $row ); ?>
								<li>
									<?php gaming_hub_pgo_icon( $item['icon'] ?: 'tv' ); ?>
									<span><b><?php echo esc_html( $item['label'] ); ?></b> <?php echo esc_html( $item['text'] ); ?></span>
								</li>
							<?php endforeach; ?>
						</ul>
					</div>
				<?php endif; ?>
			</div>
		</section>
	<?php endif; ?>

	<?php if ( ! empty( $related ) ) : ?>
		<section class="section pgo-event-block">
			<div class="container">
				<h2 class="pgo-event-heading">
					<?php gaming_hub_pgo_icon( 'research' ); ?>
					<?php esc_html_e( '関連ニュース', 'gaming-hub' ); ?>
				</h2>
				<?php
				get_template_part(
					'template-parts/pokemon-go',
					'news',
					array(
						'news'        => $related,
						'show_header' => false,
						'limit'       => count( $related ),
					)
				);
				?>
			</div>
		</section>
	<?php endif; ?>

	<p class="pgo-source-note pgo-event-source">
		<?php esc_html_e( '内容は公式発表をもとにしたまとめです。最新の詳細は公式ニュースで確認してください。', 'gaming-hub' ); ?>
		<a href="<?php echo esc_url( $official ); ?>" target="_blank" rel="noopener noreferrer"><?php esc_html_e( '公式を見る', 'gaming-hub' ); ?></a>
	</p>
</div>
