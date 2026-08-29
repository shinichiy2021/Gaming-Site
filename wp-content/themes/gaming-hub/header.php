<!DOCTYPE html>
<html <?php language_attributes(); ?>>
<head>
	<meta charset="<?php bloginfo( 'charset' ); ?>">
	<meta name="viewport" content="width=device-width, initial-scale=1.0">
	<?php wp_head(); ?>
</head>
<body <?php body_class(); ?>>
<?php wp_body_open(); ?>

<header class="site-header">
	<div class="container header-inner">
		<div class="site-branding">
			<a href="<?php echo esc_url( gaming_hub_default_entry_url() ); ?>" class="site-title">
				<?php gaming_hub_render_logo_mark(); ?>
				<?php bloginfo( 'name' ); ?>
			</a>
		</div>

		<nav class="main-navigation" aria-label="<?php esc_attr_e( 'Primary Navigation', 'gaming-hub' ); ?>">
			<?php
			wp_nav_menu( array(
				'theme_location' => 'primary',
				'menu_class'     => 'nav-menu',
				'container'      => false,
				'fallback_cb'    => 'gaming_hub_fallback_menu',
			) );
			?>
		</nav>

		<div class="header-tools">
			<?php gaming_hub_language_switcher(); ?>
			<button class="menu-toggle" aria-label="<?php esc_attr_e( 'Toggle menu', 'gaming-hub' ); ?>" aria-expanded="false">
				<span></span>
				<span></span>
				<span></span>
			</button>
		</div>
	</div>
	<?php if ( function_exists( 'gaming_hub_render_mobile_hub_switcher' ) ) : ?>
		<?php gaming_hub_render_mobile_hub_switcher(); ?>
	<?php endif; ?>
</header>

<main id="main-content" class="site-main">
