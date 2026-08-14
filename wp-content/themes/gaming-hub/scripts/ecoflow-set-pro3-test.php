<?php
/**
 * One-off CLI: try Pro 3 bypass disable + AC charge 0W.
 *
 * Usage: docker exec gaming-site-wp php /var/www/html/wp-content/themes/gaming-hub/scripts/ecoflow-set-pro3-test.php
 */

if ( php_sapi_name() !== 'cli' ) {
	exit( 1 );
}

require '/var/www/html/wp-load.php';

function ecoflow_test_snapshot( $quota ) {
	$keys = array(
		'bypassOutDisable',
		'cfgBypassOutDisable',
		'plugInInfoAcInChgPowMax',
		'plugInInfoAcInChgHalPowMax',
		'plugInInfoAcInFlag',
		'plugInInfoAcChargerFlag',
		'powGetAcIn',
		'powInSumW',
		'powOutSumW',
		'bmsChgDsgState',
		'cmsChgDsgState',
		'energyBackupEn',
		'energyStrategyOperateMode.operateSelfPoweredOpen',
		'cmsBattSoc',
	);

	$out = array();
	foreach ( $keys as $key ) {
		if ( array_key_exists( $key, $quota ) ) {
			$out[ $key ] = $quota[ $key ];
		}
	}

	foreach ( array_keys( $quota ) as $key ) {
		if ( false !== stripos( $key, 'bypass' ) ) {
			$out[ $key ] = $quota[ $key ];
		}
	}

	return $out;
}

function ecoflow_test_result( $label, $result ) {
	if ( is_wp_error( $result ) ) {
		$data = $result->get_error_data();
		return array(
			'label' => $label,
			'ok'    => false,
			'error' => $result->get_error_message(),
			'code'  => is_array( $data ) && isset( $data['code'] ) ? $data['code'] : '',
			'http'  => is_array( $data ) && isset( $data['http'] ) ? $data['http'] : '',
			'raw'   => is_array( $data ) && isset( $data['raw'] ) ? $data['raw'] : '',
		);
	}

	return array(
		'label' => $label,
		'ok'    => true,
		'data'  => $result,
	);
}

$config = gaming_hub_get_ecoflow_config();
$api    = new Gaming_Hub_Ecoflow_Api( $config['access_key'], $config['secret_key'], $config['region'] );
$sn     = $config['device_sn'];

$before = $api->get_device_quota( $sn );
if ( is_wp_error( $before ) ) {
	fwrite( STDERR, 'quota read failed: ' . $before->get_error_message() . "\n" );
	exit( 1 );
}

$attempts = array();

$bypass_bodies = array(
	'gen3-bool' => array(
		'sn'      => $sn,
		'cmdId'   => 17,
		'cmdFunc' => 254,
		'dest'    => 2,
		'dirDest' => 1,
		'dirSrc'  => 1,
		'needAck' => true,
		'params'  => array( 'cfgBypassOutDisable' => true ),
	),
	'params-only-bool' => array(
		'sn'     => $sn,
		'params' => array( 'cfgBypassOutDisable' => true ),
	),
	'gen3-int' => array(
		'sn'      => $sn,
		'cmdId'   => 17,
		'cmdFunc' => 254,
		'dest'    => 2,
		'dirDest' => 1,
		'dirSrc'  => 1,
		'needAck' => true,
		'params'  => array( 'cfgBypassOutDisable' => 1 ),
	),
);

$bypass_ok = false;
foreach ( $bypass_bodies as $label => $body ) {
	$result      = $api->put_quota( $body );
	$attempts[]  = ecoflow_test_result( 'bypass:' . $label, $result );
	if ( ! is_wp_error( $result ) ) {
		$bypass_ok = true;
		break;
	}
}

$charge_bodies = array(
	'gen3-0w' => array(
		'sn'      => $sn,
		'cmdId'   => 17,
		'cmdFunc' => 254,
		'dest'    => 2,
		'dirDest' => 1,
		'dirSrc'  => 1,
		'needAck' => true,
		'params'  => array( 'cfgPlugInInfoAcInChgPowMax' => 0 ),
	),
	'params-only-0w' => array(
		'sn'     => $sn,
		'params' => array( 'cfgPlugInInfoAcInChgPowMax' => 0 ),
	),
);

$charge_ok = false;
foreach ( $charge_bodies as $label => $body ) {
	$result     = $api->put_quota( $body );
	$attempts[] = ecoflow_test_result( 'charge:' . $label, $result );
	if ( ! is_wp_error( $result ) ) {
		$charge_ok = true;
		break;
	}
}

sleep( 4 );

$after = $api->get_device_quota( $sn );
if ( is_wp_error( $after ) ) {
	$after_snap = array( 'error' => $after->get_error_message() );
} else {
	$after_snap = ecoflow_test_snapshot( $after );
}

echo wp_json_encode(
	array(
		'bypass_ok' => $bypass_ok,
		'charge_ok' => $charge_ok,
		'attempts'  => $attempts,
		'before'    => ecoflow_test_snapshot( $before ),
		'after'     => $after_snap,
	),
	JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE
) . "\n";
