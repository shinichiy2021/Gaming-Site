<?php
/**
 * Register Tesla Fleet API partner account (domain + public key).
 *
 * Usage (production server):
 *   docker compose -f docker-compose.prod.yml exec -T wordpress \
 *     php /var/www/html/scripts/tesla-register-partner.php
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

$config = gaming_hub_get_tesla_config();
$domain = gaming_hub_tesla_partner_domain();
$key_url = gaming_hub_tesla_public_key_url( $domain );

echo "Tesla partner registration\n";
echo "  domain: {$domain}\n";
echo "  fleet:  {$config['fleet_base_url']}\n";
echo "  key:    {$key_url}\n";

$hosted = gaming_hub_tesla_verify_public_key_hosted();
if ( is_wp_error( $hosted ) ) {
	fwrite( STDERR, 'Public key check failed: ' . $hosted->get_error_message() . "\n" );
	exit( 1 );
}
echo "Public key: OK\n";

$result = gaming_hub_tesla_register_partner_account( $domain );
if ( is_wp_error( $result ) ) {
	fwrite( STDERR, 'Register failed: ' . $result->get_error_message() . "\n" );
	exit( 1 );
}

echo "Register: OK\n";
echo json_encode( $result, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";

$api = new Gaming_Hub_Tesla_Api(
	$config['client_id'],
	$config['client_secret'],
	$config['fleet_base_url']
);
$verify = $api->get_partner_public_key( $domain );
if ( is_wp_error( $verify ) ) {
	fwrite( STDERR, 'Verify failed: ' . $verify->get_error_message() . "\n" );
	exit( 1 );
}

echo "Verify public_key: OK\n";
echo json_encode( $verify, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE ) . "\n";
