<?php
/**
 * CLI: DELTA 3 1500 self-powered style AC controller (no solar input).
 *
 * Usage:
 *   docker exec gaming-site-wp php /var/www/html/wp-content/themes/gaming-hub/scripts/ecoflow-delta1500-selfpowered-reserve.php
 *   docker exec gaming-site-wp php .../ecoflow-delta1500-selfpowered-reserve.php --dry-run
 *
 * @package Gaming_Hub
 */

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

require '/var/www/html/wp-load.php';

if ( ! function_exists( 'gaming_hub_ecoflow_delta1500_selfpowered_run' ) ) {
	fwrite( STDERR, "Delta 1500 self-powered controller not loaded\n" );
	exit( 1 );
}

$dry = in_array( '--dry-run', $argv, true );
$out = gaming_hub_ecoflow_delta1500_selfpowered_run( $dry );
fwrite( STDOUT, wp_json_encode( $out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n" );
exit( ! empty( $out['ok'] ) ? 0 : 1 );
