<?php
/**
 * Mobile EcoFlow / Tesla hub switcher (React SPA shell mount).
 *
 * @package Gaming_Hub
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Whether the mobile hub switcher should render.
 */
function gaming_hub_should_show_mobile_hub_switcher() {
	if ( is_admin() ) {
		return false;
	}

	// Dashboard tags + related single posts.
	if ( is_tag( array( 'ecoflow', 'tesla', 'pokemon-go' ) ) ) {
		return true;
	}
	if ( function_exists( 'gaming_hub_has_ecoflow_tag' ) && is_singular( 'post' ) && gaming_hub_has_ecoflow_tag() ) {
		return true;
	}
	if ( is_singular( 'post' ) && has_tag( 'tesla' ) ) {
		return true;
	}

	return (bool) apply_filters( 'gaming_hub_show_mobile_hub_switcher', false );
}

/**
 * Hub switcher destinations.
 *
 * @return array<int, array{slug:string,label:string,url:string,current:bool}>
 */
function gaming_hub_mobile_hub_switcher_items() {
	$items = array(
		array(
			'slug'    => 'ecoflow',
			'label'   => 'EcoFlow',
			'url'     => function_exists( 'gaming_hub_ecoflow_url' ) ? gaming_hub_ecoflow_url() : home_url( '/tag/ecoflow/' ),
			'current' => is_tag( 'ecoflow' ) || ( function_exists( 'gaming_hub_has_ecoflow_tag' ) && is_singular( 'post' ) && gaming_hub_has_ecoflow_tag() ),
		),
		array(
			'slug'    => 'tesla',
			'label'   => 'Tesla',
			'url'     => function_exists( 'gaming_hub_tesla_url' ) ? gaming_hub_tesla_url() : home_url( '/tag/tesla/' ),
			'current' => is_tag( 'tesla' ) || ( is_singular( 'post' ) && has_tag( 'tesla' ) ),
		),
	);

	/**
	 * Filter mobile hub switcher items.
	 *
	 * @param array<int, array{slug:string,label:string,url:string,current:bool}> $items Items.
	 */
	return apply_filters( 'gaming_hub_mobile_hub_switcher_items', $items );
}

/**
 * Render sticky mobile EcoFlow / Tesla control (React mount).
 */
function gaming_hub_render_mobile_hub_switcher() {
	if ( ! gaming_hub_should_show_mobile_hub_switcher() ) {
		return;
	}

	$items = gaming_hub_mobile_hub_switcher_items();
	if ( count( $items ) < 2 ) {
		return;
	}

	$active = '';
	foreach ( $items as $item ) {
		if ( ! empty( $item['current'] ) ) {
			$active = (string) $item['slug'];
			break;
		}
	}

	$spa = function_exists( 'gaming_hub_is_hub_spa_page' ) && gaming_hub_is_hub_spa_page();
	?>
	<nav
		id="hub-spa-root"
		class="hub-switcher"
		aria-label="<?php esc_attr_e( 'ダッシュボード切替', 'gaming-hub' ); ?>"
		data-hub-swipe="1"
		data-hub-spa="<?php echo $spa ? '1' : '0'; ?>"
		<?php echo $active !== '' ? ' data-active="' . esc_attr( $active ) . '"' : ''; ?>
	>
		<?php /* React HubApp hydrates tabs here. Fallback links for no-JS. */ ?>
		<div class="hub-switcher-track" role="tablist">
			<?php foreach ( $items as $item ) : ?>
				<?php
				$is_current = ! empty( $item['current'] );
				$classes    = 'hub-switcher-tab hub-switcher-tab--' . sanitize_html_class( (string) $item['slug'] );
				if ( $is_current ) {
					$classes .= ' is-active';
				}
				?>
				<a
					class="<?php echo esc_attr( $classes ); ?>"
					href="<?php echo esc_url( $item['url'] ); ?>"
					role="tab"
					aria-selected="<?php echo $is_current ? 'true' : 'false'; ?>"
					data-hub-slug="<?php echo esc_attr( (string) $item['slug'] ); ?>"
					<?php echo $is_current ? 'aria-current="page"' : ''; ?>
				>
					<span class="hub-switcher-label"><?php echo esc_html( $item['label'] ); ?></span>
				</a>
			<?php endforeach; ?>
		</div>
	</nav>
	<?php
}
