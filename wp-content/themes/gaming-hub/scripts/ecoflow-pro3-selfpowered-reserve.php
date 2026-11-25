<?php
/**
 * CLI: DELTA Pro 3 self-powered backup-reserve controller.
 *
 * Usage:
 *   docker exec gaming-site-wp php /var/www/html/wp-content/themes/gaming-hub/scripts/ecoflow-pro3-selfpowered-reserve.php
 *   docker exec gaming-site-wp php .../ecoflow-pro3-selfpowered-reserve.php --dry-run
 *
 * @package Gaming_Hub
 */

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

require '/var/www/html/wp-load.php';

if ( ! function_exists( 'gaming_hub_ecoflow_selfpowered_reserve_run' ) ) {
	fwrite( STDERR, "self-powered reserve controller not loaded\n" );
	exit( 1 );
}

$dry = in_array( '--dry-run', $argv, true );
$out = gaming_hub_ecoflow_selfpowered_reserve_run( $dry );
fwrite( STDOUT, wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );
exit( ! empty( $out['ok'] ) ? 0 : 1 );
