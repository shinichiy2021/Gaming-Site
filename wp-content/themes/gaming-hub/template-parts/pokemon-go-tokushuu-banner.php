<?php
/**
 * Hub banner linking to current large Pokémon GO event tokushuu pages.
 *
 * @package Gaming_Hub
 */

$events = function_exists( 'gaming_hub_pgo_hub_events' ) ? gaming_hub_pgo_hub_events() : array();
if ( empty( $events ) ) {
	return;
}

$primary = $events[0];
$rest    = array_slice( $events, 1 );
$art     = gaming_hub_pgo_artwork_url( (int) ( $primary['featured_dex'] ?? 0 ) );
$steps   = is_array( $primary['today'] ?? null ) ? array_slice( $primary['today'], 0, 3 ) : array();
?>
<section class="pgo-tokushuu-banner-wrap" aria-label="<?php esc_attr_e( 'Pokémon GO 大型イベント特集', 'gaming-hub' ); ?>">
	<div class="container">
		<a href="<?php echo esc_url( $primary['url'] ); ?>" class="pgo-tokushuu-banner theme-<?php echo esc_attr( (string) ( $primary['theme'] ?? 'worlds' ) ); ?>">
			<div class="pgo-tokushuu-banner-copy">
				<span class="pgo-hero-badge"><?php esc_html_e( '特集', 'gaming-hub' ); ?></span>
				<span class="pgo-badge pgo-status-<?php echo esc_attr( $primary['status'] ); ?>">
					<?php echo esc_html( gaming_hub_pgo_event_status_label( $primary['status'] ) ); ?>
				</span>
				<h2><?php echo esc_html( $primary['title'] ); ?></h2>
				<p class="pgo-tokushuu-when"><?php echo esc_html( gaming_hub_pgo_format_range( $primary['start_dt'], $primary['end_dt'] ) ); ?></p>
				<?php if ( ! empty( $steps ) ) : ?>
					<ol class="pgo-banner-steps">
						<?php foreach ( $steps as $step ) : ?>
							<li>
								<?php gaming_hub_pgo_icon( (string) ( $step['icon'] ?? 'check' ) ); ?>
								<span><?php echo esc_html( $step['title'] ?? '' ); ?></span>
							</li>
						<?php endforeach; ?>
					</ol>
				<?php endif; ?>
				<span class="pgo-read-more"><?php esc_html_e( '特集ページを開く', 'gaming-hub' ); ?></span>
			</div>
			<?php if ( $art ) : ?>
				<img class="pgo-tokushuu-banner-art" src="<?php echo esc_url( $art ); ?>" alt="" width="220" height="220" decoding="async" />
			<?php else : ?>
				<span class="pgo-tokushuu-banner-icon"><?php gaming_hub_pgo_icon( (string) ( $primary['icon'] ?? 'ball' ), 'pgo-ico pgo-ico-xl' ); ?></span>
			<?php endif; ?>
		</a>

		<?php if ( ! empty( $rest ) ) : ?>
			<div class="pgo-tokushuu-banner-more">
				<?php foreach ( $rest as $event ) : ?>
					<?php $mini_art = gaming_hub_pgo_artwork_url( (int) ( $event['featured_dex'] ?? 0 ) ); ?>
					<a href="<?php echo esc_url( $event['url'] ); ?>" class="pgo-tokushuu-mini theme-<?php echo esc_attr( (string) ( $event['theme'] ?? 'worlds' ) ); ?>">
						<?php if ( $mini_art ) : ?>
							<img src="<?php echo esc_url( $mini_art ); ?>" alt="" width="64" height="64" loading="lazy" decoding="async" />
						<?php else : ?>
							<?php gaming_hub_pgo_icon( (string) ( $event['icon'] ?? 'ball' ) ); ?>
						<?php endif; ?>
						<div>
							<span class="pgo-badge pgo-status-<?php echo esc_attr( $event['status'] ); ?>">
								<?php echo esc_html( gaming_hub_pgo_event_status_label( $event['status'] ) ); ?>
							</span>
							<strong><?php echo esc_html( $event['title'] ); ?></strong>
							<span><?php echo esc_html( gaming_hub_pgo_format_range( $event['start_dt'], $event['end_dt'] ) ); ?></span>
						</div>
					</a>
				<?php endforeach; ?>
			</div>
		<?php endif; ?>
	</div>
</section>
