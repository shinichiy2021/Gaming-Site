<?php
/**
 * Phase 1: push Fleet Telemetry config to the vehicle via tesla-http-proxy.
 *
 * Usage (production):
 *   docker compose -f docker-compose.prod.yml exec -T wordpress \
 *     php /var/www/html/scripts/tesla-telemetry-configure.php
 *
 * Optional:
 *   php .../tesla-telemetry-configure.php --status
 *   php .../tesla-telemetry-configure.php --delete
 *
 * Reads vehicle payload from /opt/gaming-hub/tesla/telemetry/vehicle-config.json
 * (inside the container: theme scripts are under /var/www/html/scripts, but
 * telemetry files live on the host mount — pass via env or default path).
 *
 * @package Gaming_Hub
 */

if ( 'cli' !== php_sapi_name() ) {
	exit( 1 );
}

$wp_load = dirname( __DIR__ ) . '/wp-load.php';
if ( ! is_readable( $wp_load ) ) {
	fwrite( STDERR, "wp-load.php not found at {$wp_load}\n" );
	exit( 1 );
}

require $wp_load;

$args   = array_slice( $argv, 1 );
$status = in_array( '--status', $args, true );
$delete = in_array( '--delete', $args, true );

$config_path = getenv( 'TESLA_TELEMETRY_VEHICLE_CONFIG' );
if ( ! is_string( $config_path ) || '' === $config_path ) {
	// Host path is bind-mounted into WP only for theme/scripts — use explicit
	// path under /tesla if present, else repo-relative for local experiments.
	$candidates = array(
		'/tesla/telemetry/vehicle-config.json',
		dirname( __DIR__ ) . '/tesla/telemetry/vehicle-config.json',
		'/opt/gaming-hub/tesla/telemetry/vehicle-config.json',
	);
	$config_path = '';
	foreach ( $candidates as $candidate ) {
		if ( is_readable( $candidate ) ) {
			$config_path = $candidate;
			break;
		}
	}
}

$api = gaming_hub_tesla_get_api();
if ( is_wp_error( $api ) ) {
	fwrite( STDERR, $api->get_error_message() . "\n" );
	exit( 1 );
}

$vin = (string) ( gaming_hub_get_tesla_config()['vehicle_vin'] ?? '' );
if ( '' === $vin ) {
	fwrite( STDERR, "TESLA_VEHICLE_VIN is empty.\n" );
	exit( 1 );
}

echo "Tesla Fleet Telemetry configure\n";
echo "  vin: {$vin}\n";

if ( $status ) {
	$result = $api->get_fleet_telemetry_config( $vin );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, 'Status failed: ' . $result->get_error_message() . "\n" );
		exit( 1 );
	}
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
	exit( 0 );
}

if ( $delete ) {
	$result = $api->delete_fleet_telemetry_config( $vin );
	if ( is_wp_error( $result ) ) {
		fwrite( STDERR, 'Delete failed: ' . $result->get_error_message() . "\n" );
		exit( 1 );
	}
	echo "Delete: OK\n";
	echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
	exit( 0 );
}

if ( '' === $config_path || ! is_readable( $config_path ) ) {
	fwrite(
		STDERR,
		"vehicle-config.json not found. Run scripts/tesla-telemetry-prepare.sh on the host,\n"
		. "then mount ./tesla into wordpress or set TESLA_TELEMETRY_VEHICLE_CONFIG.\n"
	);
	exit( 1 );
}

$raw = file_get_contents( $config_path );
$cfg = json_decode( (string) $raw, true );
if ( ! is_array( $cfg ) || empty( $cfg['hostname'] ) || empty( $cfg['ca'] ) || empty( $cfg['fields'] ) ) {
	fwrite( STDERR, "Invalid vehicle-config.json at {$config_path}\n" );
	exit( 1 );
}

echo "  config: {$config_path}\n";
echo "  host:   {$cfg['hostname']}:" . (int) ( $cfg['port'] ?? 443 ) . "\n";

$result = $api->create_fleet_telemetry_config( array( $vin ), $cfg );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'Configure failed: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

echo "Configure: OK\n";
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";

$check = $api->get_fleet_telemetry_config( $vin );
if ( ! is_wp_error( $check ) ) {
	echo "Current config:\n";
	echo json_encode( $check, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
}
