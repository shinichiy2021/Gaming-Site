<?php
/**
 * Index of Pokémon GO tokushuu (large-event feature pages).
 *
 * @package Gaming_Hub
 */

$events = function_exists( 'gaming_hub_pgo_index_events' )
	? gaming_hub_pgo_index_events()
	: array();
?>
<div class="pokemon-go-page pgo-tokushuu-page">
	<section class="pgo-hero pgo-tokushuu-hero">
		<div class="pgo-hero-bg"></div>
		<div class="container pgo-hero-content">
			<span class="pgo-hero-badge"><?php esc_html_e('Pokémon GO features', 'gaming-hub'); ?></span>
			<h1 class="pgo-hero-title"><?php esc_html_e('Major event features', 'gaming-hub'); ?></h1>
			<p class="pgo-hero-desc">
				<?php esc_html_e('Feature pages for large events such as GO Fest, Ultra Unlock, Worlds, and Raid Days.', 'gaming-hub'); ?>
			</p>
			<div class="pgo-hero-links">
				<a href="<?php echo esc_url( gaming_hub_pokemon_go_url() ); ?>" class="btn btn-outline">
					<?php esc_html_e('Pokémon GO news', 'gaming-hub'); ?>
				</a>
				<a href="https://pokemongolive.com/ja/events/" target="_blank" rel="noopener noreferrer" class="btn btn-primary">
					<?php esc_html_e('Official event list', 'gaming-hub'); ?>
				</a>
			</div>
		</div>
	</section>

	<section class="section pgo-tokushuu-index">
		<div class="container">
			<?php if ( empty( $events ) ) : ?>
				<p class="pgo-tokushuu-empty"><?php esc_html_e('No major-event features are live right now. The next large event will appear here.', 'gaming-hub'); ?></p>
			<?php else : ?>
				<div class="pgo-tokushuu-grid">
					<?php foreach ( $events as $event ) : ?>
						<?php $art = gaming_hub_pgo_artwork_url( (int) ( $event['featured_dex'] ?? 0 ) ); ?>
						<a href="<?php echo esc_url( $event['url'] ); ?>" class="pgo-tokushuu-card theme-<?php echo esc_attr( (string) ( $event['theme'] ?? 'worlds' ) ); ?>">
							<?php if ( $art ) : ?>
								<img class="pgo-tokushuu-card-art" src="<?php echo esc_url( $art ); ?>" alt="" width="160" height="160" loading="lazy" decoding="async" />
							<?php else : ?>
								<span class="pgo-tokushuu-card-icon"><?php gaming_hub_pgo_icon( (string) ( $event['icon'] ?? 'ball' ), 'pgo-ico pgo-ico-lg' ); ?></span>
							<?php endif; ?>
							<span class="pgo-badge pgo-status-<?php echo esc_attr( $event['status'] ); ?>">
								<?php echo esc_html( gaming_hub_pgo_event_status_label( $event['status'] ) ); ?>
							</span>
							<h2><?php echo esc_html( $event['title'] ); ?></h2>
							<p class="pgo-tokushuu-when"><?php echo esc_html( gaming_hub_pgo_format_range( $event['start_dt'], $event['end_dt'] ) ); ?></p>
							<?php if ( ! empty( $event['today'][0]['title'] ) ) : ?>
								<p class="pgo-tokushuu-card-do">
									<?php gaming_hub_pgo_icon( (string) ( $event['today'][0]['icon'] ?? 'check' ) ); ?>
									<?php echo esc_html( $event['today'][0]['title'] ); ?>
								</p>
							<?php endif; ?>
							<span class="pgo-read-more"><?php esc_html_e('Open feature', 'gaming-hub'); ?></span>
						</a>
					<?php endforeach; ?>
				</div>
			<?php endif; ?>
		</div>
	</section>
</div>
