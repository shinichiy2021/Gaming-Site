/**
 * EcoFlow App Login + MQTT client for Delta 3 (D361) devices.
 */
import mqtt from 'mqtt';
import { createHash, randomBytes } from 'crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'fs';
import { join } from 'path';

export function loadBridgeConfig( cacheDir ) {
	const configPath = process.env.ECOFLOW_BRIDGE_CONFIG || join( cacheDir, 'bridge-config.json' );

	try {
		return JSON.parse( readFileSync( configPath, 'utf8' ) );
	} catch {
		return {};
	}
}

export function resolveConfig( cacheDir, deviceSnArg = '' ) {
	const fileConfig = loadBridgeConfig( cacheDir );

	return {
		email: process.env.ECOFLOW_APP_EMAIL || fileConfig.email || '',
		password: process.env.ECOFLOW_APP_PASSWORD || fileConfig.password || '',
		deviceSn: deviceSnArg || process.env.ECOFLOW_DEVICE_SN_2 || fileConfig.device_sn_2 || '',
		region: process.env.ECOFLOW_API_REGION || fileConfig.region || 'us',
		cacheDir,
	};
}

function apiHost( region ) {
	if ( region === 'eu' ) {
		return 'api-e.ecoflow.com';
	}

	if ( region === 'a' || region === 'asia' || region === 'jp' ) {
		return 'api-a.ecoflow.com';
	}

	return 'api.ecoflow.com';
}

function apiHostsToTry( region ) {
	const hosts = [ apiHost( region ) ];

	if ( region === 'us' ) {
		hosts.push( 'api-a.ecoflow.com' );
	}

	return [ ...new Set( hosts ) ];
}

function buildClientId( userId, useExtended = false ) {
	const stableSeed = createHash( 'sha256' ).update( `gaming-hub-ecoflow-${ userId }` ).digest( 'hex' ).slice( 0, 16 ).toUpperCase();
	const base = `ANDROID_${ stableSeed }_${ userId }`;

	if ( ! useExtended ) {
		return base;
	}

	const millis = Date.now();
	const verifyInfo = '0'.repeat( 64 );
	const pub = verifyInfo.slice( 0, 32 );
	const priv = verifyInfo.slice( 32 );
	const hash = createHash( 'md5' ).update( `${ priv }${ base }${ millis }` ).digest( 'hex' );

	return `${ base }_${ pub }_${ millis }_${ hash }`;
}

function normalizeQuotaKey( key ) {
	if ( key.startsWith( 'pdStatus.' ) ) {
		return key.replace( 'pdStatus.', 'pd.' );
	}

	if ( key.startsWith( 'mpptStatus.' ) ) {
		return key.replace( 'mpptStatus.', 'mppt.' );
	}

	if ( key.startsWith( 'invStatus.' ) ) {
		return key.replace( 'invStatus.', 'inv.' );
	}

	return key;
}

function flattenQuota( obj, prefix = '', out = {} ) {
	if ( ! obj || typeof obj !== 'object' || Array.isArray( obj ) ) {
		return out;
	}

	for ( const [ key, value ] of Object.entries( obj ) ) {
		const nextKey = normalizeQuotaKey( prefix ? `${ prefix }.${ key }` : key );
		if ( value && typeof value === 'object' && ! Array.isArray( value ) ) {
			flattenQuota( value, nextKey, out );
		} else if ( value !== 255 && value !== '255' ) {
			out[ nextKey ] = value;
		}
	}

	return out;
}

function genClientId( userId ) {
	return buildClientId( userId, true );
}

export async function login( email, password, region ) {
	let lastError = 'EcoFlow app login failed';

	for ( const host of apiHostsToTry( region ) ) {
		const resp = await fetch( `https://${ host }/auth/login`, {
			method: 'POST',
			headers: {
				lang: 'en_US',
				'content-type': 'application/json',
			},
			body: JSON.stringify( {
				email,
				password: Buffer.from( password ).toString( 'base64' ),
				scene: 'IOT_APP',
				userType: 'ECOFLOW',
			} ),
		} );

		const json = await resp.json();
		if ( String( json.message || '' ).toLowerCase() === 'success' ) {
			return { ...json.data, __apiHost: host };
		}

		lastError = json.message || lastError;
	}

	throw new Error( lastError );
}

export async function getCert( token, userId, region, apiHostOverride = '' ) {
	const host = apiHostOverride || apiHost( region );
	const headers = {
		authorization: `Bearer ${ token }`,
		lang: 'en_US',
		'content-type': 'application/json',
	};

	let resp = await fetch( `https://${ host }/iot-auth/app/certification?userId=${ encodeURIComponent( userId ) }`, {
		method: 'GET',
		headers,
	} );

	let json = await resp.json();
	if ( String( json.message || '' ).toLowerCase() !== 'success' ) {
		resp = await fetch( `https://${ host }/iot-auth/app/certification`, {
			method: 'POST',
			headers: {
				...headers,
				'content-type': 'application/x-www-form-urlencoded',
			},
			body: `userId=${ encodeURIComponent( userId ) }`,
		} );
		json = await resp.json();
	}

	if ( String( json.message || '' ).toLowerCase() !== 'success' ) {
		resp = await fetch( `https://${ host }/iot-auth/app/certification`, {
			method: 'POST',
			headers,
			body: JSON.stringify( { userId } ),
		} );
		json = await resp.json();
	}

	if ( String( json.message || '' ).toLowerCase() !== 'success' ) {
		throw new Error( json.message || 'EcoFlow MQTT certification failed' );
	}

	return json.data;
}

function mergePayload( quota, payload ) {
	if ( ! payload || typeof payload !== 'object' ) {
		return;
	}

	if ( typeof payload.params === 'string' ) {
		try {
			mergePayload( quota, JSON.parse( payload.params ) );
		} catch {
			// Ignore string params that are not JSON.
		}
	}

	const chunks = [ payload.data, payload.params, payload.quotaMap, payload.payLoad, payload ];
	for ( const chunk of chunks ) {
		if ( chunk && typeof chunk === 'object' && ! Array.isArray( chunk ) ) {
			Object.assign( quota, flattenQuota( chunk ) );
		}
	}
}

export async function pollQuota( deviceSn, loginData, cert ) {
	return new Promise( ( resolvePromise, reject ) => {
		const userId = loginData.user.userId;
		const clientId = buildClientId( userId, true );
		const topicTelemetry = `/app/device/property/${ deviceSn }`;
		const topicStatus = `/app/device/status/${ deviceSn }`;
		const topicGetReply = `/app/${ userId }/${ deviceSn }/thing/property/get_reply`;
		const topicGet = `/app/${ userId }/${ deviceSn }/thing/property/get`;
		const quota = {};
		let settled = false;
		let telemetryCount = 0;
		let messageCount = 0;
		let getReplyCount = 0;

		const finish = () => {
			if ( settled ) {
				return;
			}

			settled = true;
			clearTimeout( timeout );
			clearInterval( keepAlive );
			client.end( true );
			resolvePromise( { quota, telemetryCount, messageCount, getReplyCount } );
		};

		const maybeFinish = ( force = false ) => {
			const keys = Object.keys( quota );
			const hasSoc = keys.some( ( key ) => key.endsWith( '.soc' ) || key.includes( 'BattSoc' ) || key.includes( 'lcdShowSoc' ) );
			const hasPower = keys.some( ( key ) => /watts|Watts|powGet/i.test( key ) );

			if ( force || ( hasSoc && hasPower ) || keys.length >= 10 || ( getReplyCount > 0 && keys.length >= 4 ) || ( telemetryCount >= 3 && keys.length >= 2 ) ) {
				finish();
			}
		};

		const timeout = setTimeout( () => maybeFinish( true ), 25000 );

		const brokerUrl = String( cert.url || '' ).includes( '://' )
			? cert.url
			: `mqtts://${ cert.url }:${ cert.port }`;

		const client = mqtt.connect( brokerUrl, {
			clientId,
			username: cert.certificateAccount,
			password: cert.certificatePassword,
			reconnectPeriod: 0,
			connectTimeout: 15000,
			clean: true,
		} );

		const publishLatestQuotas = () => {
			const message = JSON.stringify( {
				id: Date.now() % 1000000,
				version: '1.0',
				sn: deviceSn,
				moduleType: 0,
				operateType: 'latestQuotas',
				params: {},
			} );
			client.publish( topicGet, message, { qos: 1 } );
		};

		client.on( 'connect', () => {
			client.subscribe( [ topicTelemetry, topicStatus, topicGetReply ], { qos: 1 }, () => {
				setTimeout( publishLatestQuotas, 700 );
			} );
		} );

		const keepAlive = setInterval( publishLatestQuotas, 4000 );

		client.on( 'message', ( topic, buffer ) => {
			messageCount += 1;

			try {
				const payload = JSON.parse( buffer.toString() );
				mergePayload( quota, payload );

				if ( topic === topicTelemetry || topic === topicStatus ) {
					telemetryCount += 1;
				}

				if ( topic.includes( 'get_reply' ) ) {
					getReplyCount += 1;
				}

				maybeFinish( topic.includes( 'get_reply' ) );
			} catch {
				// Ignore binary / malformed payloads.
			}
		} );

		client.on( 'error', ( error ) => {
			if ( ! settled ) {
				settled = true;
				clearTimeout( timeout );
				clearInterval( keepAlive );
				client.end( true );
				reject( error );
			}
		} );
	} );
}

export async function fetchAppQuota( config ) {
	if ( ! config.email || ! config.password || ! config.deviceSn ) {
		throw new Error( 'App login email, password, and Delta 3 serial number are required' );
	}

	const loginData = await login( config.email, config.password, config.region );
	const cert = await getCert( loginData.token, loginData.user.userId, config.region, loginData.__apiHost );

	let result;
	try {
		result = await pollQuota( config.deviceSn, loginData, cert );
	} catch ( error ) {
		const broker = cert.url ? `${ cert.url }:${ cert.port }` : 'unknown';
		throw new Error( `${ error.message || error } (broker: ${ broker })` );
	}

	const quota = result.quota || result;
	const keys = Object.keys( quota );

	if ( keys.length < 2 ) {
		const broker = cert.url ? `${ cert.url }:${ cert.port }` : 'unknown';
		throw new Error(
			`MQTT connected but no Delta 3 telemetry (${ result.messageCount || 0 } msgs, broker: ${ broker })`
		);
	}

	return quota;
}

function formatBridgeError( error ) {
	const message = String( error || '' );

	if ( /account doesn't exist|incorrect password/i.test( message ) ) {
		return 'Googleログインのみのアカウントです。EcoFlowアプリで「ログインパスワード」を設定し、Customizer にメールとそのパスワードを入力してください。';
	}

	return message;
}

export function writeQuotaCache( cacheDirectory, deviceSn, quota ) {
	mkdirSync( cacheDirectory, { recursive: true } );
	writeFileSync( join( cacheDirectory, `${ deviceSn }.json` ), JSON.stringify( quota ) );
	writeFileSync(
		join( cacheDirectory, 'bridge-status.json' ),
		JSON.stringify( {
			ok: true,
			device_sn: deviceSn,
			keys: Object.keys( quota ).length,
			updated_at: new Date().toISOString(),
		} )
	);
}

export function writeBridgeError( cacheDirectory, error ) {
	mkdirSync( cacheDirectory, { recursive: true } );
	const formatted = formatBridgeError( error );
	writeFileSync(
		join( cacheDirectory, 'bridge-status.json' ),
		JSON.stringify( {
			ok: false,
			error: formatted,
			error_raw: String( error ),
			updated_at: new Date().toISOString(),
		} )
	);
}
