/**
 * EcoFlow App Login + MQTT client for Delta 3 (D361) devices.
 */
import mqtt from 'mqtt';
import { createHash } from 'crypto';
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
		hosts.push( 'api-a.ecoflow.com', 'api-e.ecoflow.com' );
	}

	if ( region === 'a' || region === 'asia' || region === 'jp' ) {
		hosts.push( 'api.ecoflow.com', 'api-e.ecoflow.com' );
	}

	if ( region === 'eu' ) {
		hosts.push( 'api.ecoflow.com', 'api-a.ecoflow.com' );
	}

	return [ ...new Set( hosts ) ];
}

function isTooBusy( error ) {
	return /server is too busy|too busy/i.test( String( error?.message || error || '' ) );
}

function stableMqttClientId( userId ) {
	const stable = createHash( 'sha256' ).update( `gaming-hub-ecoflow-${ userId }` ).digest( 'hex' ).slice( 0, 16 ).toUpperCase();
	return `ANDROID_${ stable }_${ userId }`;
}

function mqttClientIdVariants( userId ) {
	return [ stableMqttClientId( userId ) ];
}

function isMqttUnauthorized( error ) {
	const message = String( error?.message || error || '' );
	return /not authorized|not authorised|connection refused: 5/i.test( message );
}

function isMqttTlsError( error ) {
	const message = String( error?.message || error || '' );
	return /self signed|certificate|tls|ssl|EPROTO|ECONNRESET/i.test( message );
}

function buildMqttBrokerUrl( cert ) {
	const host = String( cert.url || '' )
		.replace( /^mqtts?:\/\//i, '' )
		.replace( /\/$/, '' )
		.split( ':' )[ 0 ];
	const port = Number( cert.port ) || 8883;

	if ( ! host ) {
		throw new Error( 'EcoFlow MQTT broker host missing from certification response' );
	}

	// EcoFlow requires TLS; never fall back to plain mqtt://.
	return `mqtts://${ host }:${ port }`;
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

function mqttCanPublish( mqttClient ) {
	return !!( mqttClient && mqttClient.connected && mqttClient.outgoingStore );
}

async function fetchJson( url, options = {}, timeoutMs = 20000 ) {
	const resp = await fetch( url, {
		...options,
		signal: options.signal || AbortSignal.timeout( timeoutMs ),
	} );

	return resp.json();
}

export async function login( email, password, region ) {
	let lastError = 'EcoFlow app login failed';

	for ( const host of apiHostsToTry( region ) ) {
		const json = await fetchJson( `https://${ host }/auth/login`, {
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

		if ( String( json.message || '' ).toLowerCase() === 'success' ) {
			return { ...json.data, __apiHost: host };
		}

		lastError = json.message || lastError;
		if ( isTooBusy( lastError ) ) {
			throw new Error( lastError );
		}
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
		signal: AbortSignal.timeout( 20000 ),
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
			signal: AbortSignal.timeout( 20000 ),
		} );
		json = await resp.json();
	}

	if ( String( json.message || '' ).toLowerCase() !== 'success' ) {
		resp = await fetch( `https://${ host }/iot-auth/app/certification`, {
			method: 'POST',
			headers,
			body: JSON.stringify( { userId } ),
			signal: AbortSignal.timeout( 20000 ),
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

function quotaHasMainSoc( quota ) {
	return Object.keys( quota ).some( ( key ) => {
		if ( key.includes( 'bms_slave' ) || key.includes( 'Slave' ) ) {
			return false;
		}

		return /\.soc$/.test( key )
			|| key.includes( 'BattSoc' )
			|| key.includes( 'lcdShowSoc' )
			|| key.includes( 'f32ShowSoc' );
	} );
}

function quotaHasAcOut( quota ) {
	return Object.keys( quota ).some( ( key ) => /inv\.outputWatts|pd\.acOutWatts|powGetAcLvOut|powGetAcHvOut/.test( key ) );
}

export function quotaHasSlaveSoc( quota ) {
	return Object.keys( quota || {} ).some( ( key ) => {
		const normalized = String( key ).replace( /^(params\.|data\.quotaMap\.|quotaMap\.)/, '' );

		return ( normalized.startsWith( 'bms_slave' ) || normalized.includes( 'bms_slave' ) )
			&& ( normalized.endsWith( '.soc' ) || normalized.includes( 'ShowSoc' ) );
	} );
}

export async function pollQuota( deviceSn, loginData, cert, clientIdOverride = '', command = null ) {
	return new Promise( ( resolvePromise, reject ) => {
		const userId = loginData.user.userId;
		const clientId = clientIdOverride || mqttClientIdVariants( userId )[ 0 ];
		const topicTelemetry = `/app/device/property/${ deviceSn }`;
		const topicStatus = `/app/device/status/${ deviceSn }`;
		const topicGetReply = `/app/${ userId }/${ deviceSn }/thing/property/get_reply`;
		const topicGet = `/app/${ userId }/${ deviceSn }/thing/property/get`;
		const topicSet = `/app/${ userId }/${ deviceSn }/thing/property/set`;
		const topicSetReply = `/app/${ userId }/${ deviceSn }/thing/property/set_reply`;
		const quota = {};
		let settled = false;
		let telemetryCount = 0;
		let messageCount = 0;
		let getReplyCount = 0;
		let setAck = false;
		let setError = '';

		const finish = () => {
			if ( settled ) {
				return;
			}

			settled = true;
			clearTimeout( timeout );
			clearInterval( keepAlive );
			client.end( true );
			resolvePromise( { quota, telemetryCount, messageCount, getReplyCount, setAck, setError } );
		};

		const maybeFinish = ( force = false ) => {
			const hasMainSoc = quotaHasMainSoc( quota );
			const hasSlaveSoc = quotaHasSlaveSoc( quota );
			const hasPower = Object.keys( quota ).some( ( key ) => /^(inv\.|pd\.|powGet)/.test( key ) && /watts|Watts|powGet/i.test( key ) );
			const setReady = ! command || setAck;

			if ( force ) {
				finish();
				return;
			}

			// Wait for Extra (bms_slave) when possible; do not close on the first get_reply.
			if ( setReady && hasMainSoc && hasSlaveSoc && ( hasPower || quotaHasAcOut( quota ) ) ) {
				finish();
			}
		};

		const timeout = setTimeout( () => maybeFinish( true ), 25000 );

		const brokerUrl = buildMqttBrokerUrl( cert );

		const client = mqtt.connect( brokerUrl, {
			clientId,
			username: String( cert.certificateAccount || '' ),
			password: String( cert.certificatePassword || '' ),
			protocol: 'mqtts',
			protocolVersion: 4,
			reconnectPeriod: 0,
			connectTimeout: 15000,
			clean: true,
			rejectUnauthorized: true,
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

		const publishAcCharge = ( watts ) => {
			const chgWatts = Math.max( 0, Math.round( Number( watts ) || 0 ) );
			const id = Date.now() % 1000000;
			const payloads = [
				{
					id,
					version: '1.0',
					sn: deviceSn,
					moduleType: 5,
					operateType: 'acChgCfg',
					from: 'Android',
					params: {
						chgWatts,
						chgPauseFlag: 255,
					},
				},
				{
					id: id + 1,
					version: '1.0',
					sn: deviceSn,
					moduleType: 5,
					operateType: 'TCP',
					from: 'Android',
					params: {
						cmdSet: 32,
						id: 69,
						chgWatts,
						chgPauseFlag: 255,
					},
				},
			];

			payloads.forEach( ( payload ) => {
				client.publish( topicSet, JSON.stringify( payload ), { qos: 1 } );
			} );
		};

		client.on( 'connect', () => {
			const topics = [ topicTelemetry, topicStatus, topicGetReply ];
			if ( command ) {
				topics.push( topicSetReply );
			}

			client.subscribe( topics, { qos: 1 }, () => {
				if ( command && ( command.action === 'ac_charge' || command.watts !== undefined ) ) {
					setTimeout( () => {
						publishAcCharge( command.watts );
						setTimeout( () => {
							if ( ! setAck ) {
								setAck = true;
							}
						}, 1500 );
					}, 400 );
				}
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

				if ( topic.includes( 'set_reply' ) ) {
					setAck = true;
				}

				maybeFinish( false );
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

export async function fetchAppQuota( config, command = null ) {
	if ( ! config.email || ! config.password || ! config.deviceSn ) {
		throw new Error( 'App login email, password, and Delta 3 serial number are required' );
	}

	const region = config.region || 'us';
	let loginData;

	try {
		loginData = await login( config.email, config.password, region );
	} catch ( error ) {
		throw error;
	}

	let cert;
	try {
		cert = await getCert( loginData.token, loginData.user.userId, region, loginData.__apiHost );
	} catch ( error ) {
		throw error;
	}

	const broker = cert.url ? `${ cert.url }:${ cert.port }` : 'unknown';
	const clientId = stableMqttClientId( loginData.user.userId );

	try {
		const result = await pollQuota( config.deviceSn, loginData, cert, clientId, command );
		const quota = result.quota || result;
		const keys = Object.keys( quota );

		if ( keys.length < 2 ) {
			throw new Error( `MQTT connected but no Delta 3 telemetry (${ result.messageCount || 0 } msgs, broker: ${ broker })` );
		}

		if ( command ) {
			writeCommandResult( config.cacheDir, command, true, result.setError || '' );
		}

		return quota;
	} catch ( error ) {
		if ( isMqttUnauthorized( error ) ) {
			throw new Error( `${ error.message || error } (broker: ${ broker }, region: ${ region })` );
		}

		throw error;
	}
}

function formatBridgeError( error ) {
	const message = String( error || '' );

	if ( /server is too busy|too busy/i.test( message ) ) {
		return 'EcoFlow ログイン API が混雑しています（1日10個までの MQTT client ID 制限の可能性）。5〜30分おきに自動再試行します。';
	}

	if ( /account doesn't exist|incorrect password/i.test( message ) ) {
		return 'Googleログインのみのアカウントです。EcoFlowアプリで「ログインパスワード」を設定し、Customizer にメールとそのパスワードを入力してください。';
	}

	if ( /not authorized|not authorised/i.test( message ) ) {
		return 'MQTT 認証に失敗しました。日本のアカウントは API Region を Asia にしてください（外観 → カスタマイズ → EcoFlow API）。変更後は docker compose restart ecoflow-bridge を実行してください。';
	}

	if ( isMqttTlsError( message ) ) {
		return 'MQTT TLS 接続に失敗しました。EcoFlow ブローカーは mqtts (TLS) 必須です。bridge ログを確認し、docker compose restart ecoflow-bridge を試してください。';
	}

	return message;
}

export function writeCommandResult( cacheDirectory, command, ok, error = '' ) {
	mkdirSync( cacheDirectory, { recursive: true } );
	writeFileSync(
		join( cacheDirectory, 'bridge-command-result.json' ),
		JSON.stringify( {
			ok: !! ok,
			id: command?.id || '',
			watts: command?.watts ?? null,
			error: error || '',
			updated_at: new Date().toISOString(),
		} )
	);
}

export function writeQuotaCache( cacheDirectory, deviceSn, quota, options = {} ) {
	mkdirSync( cacheDirectory, { recursive: true } );
	const path = join( cacheDirectory, `${ deviceSn }.json` );
	const statusPath = join( cacheDirectory, 'bridge-status.json' );
	let merged = {};
	let prevStatus = {};

	try {
		merged = JSON.parse( readFileSync( path, 'utf8' ) );
	} catch {
		merged = {};
	}

	try {
		prevStatus = JSON.parse( readFileSync( statusPath, 'utf8' ) );
	} catch {
		prevStatus = {};
	}

	Object.assign( merged, quota );

	const extraTouched = Object.prototype.hasOwnProperty.call( options, 'extraTouched' )
		? !! options.extraTouched
		: quotaHasSlaveSoc( quota );
	const extraUpdatedAt = extraTouched
		? new Date().toISOString()
		: ( prevStatus.extra_updated_at || '' );

	writeFileSync( path, JSON.stringify( merged ) );
	writeFileSync(
		statusPath,
		JSON.stringify( {
			ok: true,
			device_sn: deviceSn,
			keys: Object.keys( merged ).length,
			updated_at: new Date().toISOString(),
			extra_updated_at: extraUpdatedAt || undefined,
			mode: options.mode || prevStatus.mode || undefined,
		} )
	);
}

export function writeBridgeError( cacheDirectory, error ) {
	mkdirSync( cacheDirectory, { recursive: true } );
	const statusPath = join( cacheDirectory, 'bridge-status.json' );
	let prevStatus = {};

	try {
		prevStatus = JSON.parse( readFileSync( statusPath, 'utf8' ) );
	} catch {
		prevStatus = {};
	}

	const formatted = formatBridgeError( error );
	writeFileSync(
		statusPath,
		JSON.stringify( {
			ok: false,
			error: formatted,
			error_raw: String( error ),
			device_sn: prevStatus.device_sn || undefined,
			updated_at: new Date().toISOString(),
			extra_updated_at: prevStatus.extra_updated_at || undefined,
			mode: prevStatus.mode || undefined,
		} )
	);
}

function loadQuotaFile( cacheDirectory, deviceSn ) {
	try {
		const raw = JSON.parse( readFileSync( join( cacheDirectory, `${ deviceSn }.json` ), 'utf8' ) );
		return raw && typeof raw === 'object' ? raw : {};
	} catch {
		return {};
	}
}

function configFingerprint( config ) {
	return [ config.email, config.password, config.deviceSn, config.region ].join( '|' );
}

function mqttPublishLatestQuotas( client, userId, deviceSn ) {
	if ( ! mqttCanPublish( client ) ) {
		return;
	}

	const topicGet = `/app/${ userId }/${ deviceSn }/thing/property/get`;
	const message = JSON.stringify( {
		id: Date.now() % 1000000,
		version: '1.0',
		sn: deviceSn,
		moduleType: 0,
		operateType: 'latestQuotas',
		params: {},
	} );
	client.publish( topicGet, message, { qos: 1 } );
}

function mqttPublishAcCharge( client, userId, deviceSn, watts ) {
	if ( ! mqttCanPublish( client ) ) {
		return;
	}

	const topicSet = `/app/${ userId }/${ deviceSn }/thing/property/set`;
	const chgWatts = Math.max( 0, Math.round( Number( watts ) || 0 ) );
	const id = Date.now() % 1000000;
	const payloads = [
		{
			id,
			version: '1.0',
			sn: deviceSn,
			moduleType: 5,
			operateType: 'acChgCfg',
			from: 'Android',
			params: {
				chgWatts,
				chgPauseFlag: 255,
			},
		},
		{
			id: id + 1,
			version: '1.0',
			sn: deviceSn,
			moduleType: 5,
			operateType: 'TCP',
			from: 'Android',
			params: {
				cmdSet: 32,
				id: 69,
				chgWatts,
				chgPauseFlag: 255,
			},
		},
	];

	payloads.forEach( ( payload ) => {
		client.publish( topicSet, JSON.stringify( payload ), { qos: 1 } );
	} );
}

/**
 * Keep a single MQTT session open and merge Extra Battery (bms_slave) packets as they arrive.
 *
 * @param {string} cacheDir Shared ecoflow-cache directory.
 * @param {{ readCommand?: Function, clearCommand?: Function, pingCron?: Function }} hooks
 */
export async function startPersistentMqttBridge( cacheDir, hooks = {} ) {
	const heartbeatMs = Math.max( 5000, Number( process.env.ECOFLOW_BRIDGE_INTERVAL_MS || 30000 ) );
	const writeMinMs = 1000;
	const sessionPollMs = 5000;
	const commandPollMs = 1000;

	let client = null;
	let quota = {};
	let auth = null;
	let lastConfigFp = '';
	let lastWriteAt = 0;
	let pendingWrite = false;
	let pendingExtra = false;
	let nextAuthAt = 0;
	let loginBackoffMs = heartbeatMs;
	let lastLogAt = 0;
	let lastCommandId = '';

	function bridgeLog( message ) {
		process.stderr.write( `[ecoflow-bridge] ${ message }\n` );
	}

	const publishers = {
		latestQuotas() {},
		acCharge() {},
		deviceSn: '',
	};

	function disconnectClient() {
		publishers.latestQuotas = () => {};
		publishers.acCharge = () => {};

		if ( ! client ) {
			return;
		}

		const ending = client;
		client = null;
		ending.removeAllListeners();
		try {
			ending.end( true );
		} catch {
			// Already closed.
		}
	}

	function flushQuota( deviceSn, extraTouched = false, force = false ) {
		if ( extraTouched ) {
			pendingExtra = true;
		}

		pendingWrite = true;
		const now = Date.now();
		if ( ! force && ! extraTouched && now - lastWriteAt < writeMinMs ) {
			return;
		}

		lastWriteAt = now;
		pendingWrite = false;
		const touched = pendingExtra;
		pendingExtra = false;
		writeQuotaCache( cacheDir, deviceSn, quota, {
			extraTouched: touched,
			mode: 'persistent',
		} );
	}

	async function authenticate( config ) {
		const loginData = await login( config.email, config.password, config.region || 'us' );
		const cert = await getCert( loginData.token, loginData.user.userId, config.region || 'us', loginData.__apiHost );
		const broker = cert.url ? `${ cert.url }:${ cert.port }` : 'unknown';

		return {
			loginData,
			cert,
			broker,
			clientId: stableMqttClientId( loginData.user.userId ),
		};
	}

	function attachClient( config, authState ) {
		disconnectClient();

		const userId = authState.loginData.user.userId;
		const deviceSn = config.deviceSn;
		const topicTelemetry = `/app/device/property/${ deviceSn }`;
		const topicStatus = `/app/device/status/${ deviceSn }`;
		const topicGetReply = `/app/${ userId }/${ deviceSn }/thing/property/get_reply`;
		const topicSetReply = `/app/${ userId }/${ deviceSn }/thing/property/set_reply`;
		const topics = [ topicTelemetry, topicStatus, topicGetReply, topicSetReply ];

		if ( ! Object.keys( quota ).length ) {
			quota = loadQuotaFile( cacheDir, deviceSn );
		}

		const mqttClient = mqtt.connect( buildMqttBrokerUrl( authState.cert ), {
			clientId: authState.clientId,
			username: String( authState.cert.certificateAccount || '' ),
			password: String( authState.cert.certificatePassword || '' ),
			protocol: 'mqtts',
			protocolVersion: 4,
			reconnectPeriod: 5000,
			connectTimeout: 15000,
			keepalive: 30,
			clean: true,
			rejectUnauthorized: true,
		} );
		client = mqttClient;

		publishers.deviceSn = deviceSn;
		publishers.latestQuotas = () => {
			mqttPublishLatestQuotas( mqttClient, userId, deviceSn );
		};
		publishers.acCharge = ( watts ) => {
			mqttPublishAcCharge( mqttClient, userId, deviceSn, watts );
		};

		mqttClient.on( 'connect', () => {
			if ( client !== mqttClient ) {
				return;
			}

			bridgeLog( `mqtt connected ${ deviceSn } broker=${ authState.broker }` );
			loginBackoffMs = heartbeatMs;
			mqttClient.subscribe( topics, { qos: 1 }, () => {
				if ( client === mqttClient ) {
					publishers.latestQuotas();
				}
			} );
		} );

		mqttClient.on( 'reconnect', () => {
			if ( client === mqttClient ) {
				bridgeLog( 'mqtt reconnecting' );
			}
		} );

		mqttClient.on( 'message', ( topic, buffer ) => {
			if ( client !== mqttClient ) {
				return;
			}

			try {
				const payload = JSON.parse( buffer.toString() );
				const chunk = {};
				mergePayload( chunk, payload );
				mergePayload( quota, payload );

				if ( topic.includes( 'set_reply' ) && hooks.clearCommand ) {
					writeCommandResult( cacheDir, hooks.readCommand?.() || { id: lastCommandId }, true, '' );
					hooks.clearCommand();
					lastCommandId = '';
				}

				flushQuota( deviceSn, quotaHasSlaveSoc( chunk ) );

				const now = Date.now();
				if ( now - lastLogAt > 15000 ) {
					lastLogAt = now;
					bridgeLog( `${ deviceSn } keys=${ Object.keys( quota ).length } extra=${ quotaHasSlaveSoc( quota ) ? 'yes' : 'no' }` );
				}
			} catch {
				// Ignore binary / malformed payloads.
			}
		} );

		mqttClient.on( 'error', ( error ) => {
			if ( client !== mqttClient ) {
				return;
			}

			bridgeLog( `mqtt ${ error.message || error }` );
			if ( isMqttUnauthorized( error ) ) {
				disconnectClient();
				auth = null;
				nextAuthAt = 0;
			}
		} );

		mqttClient.on( 'close', () => {
			if ( client === mqttClient ) {
				bridgeLog( 'mqtt closed' );
			}
		} );
	}

	async function ensureSession() {
		const config = resolveConfig( cacheDir );

		if ( ! config.email || ! config.password || ! config.deviceSn ) {
			writeBridgeError( cacheDir, 'Waiting for App Login in Customizer and Delta 3 SN (bridge-config.json)' );
			disconnectClient();
			auth = null;
			lastConfigFp = '';
			quota = {};
			return;
		}

		const fp = configFingerprint( config );
		if ( fp !== lastConfigFp ) {
			disconnectClient();
			auth = null;
			quota = loadQuotaFile( cacheDir, config.deviceSn );
			lastConfigFp = fp;
			nextAuthAt = 0;
		}

		if ( ! auth ) {
			if ( Date.now() < nextAuthAt ) {
				return;
			}

			try {
				bridgeLog( `login ${ config.deviceSn } region=${ config.region || 'us' }` );
				auth = await authenticate( config );
				bridgeLog( `login ok ${ config.deviceSn }` );
				loginBackoffMs = heartbeatMs;
				nextAuthAt = 0;
			} catch ( error ) {
				writeBridgeError( cacheDir, error.message || error );
				bridgeLog( String( error.message || error ) );
				auth = null;
				if ( /server is too busy|too busy/i.test( String( error.message || error ) ) ) {
					loginBackoffMs = Math.min( 1800000, Math.max( 300000, loginBackoffMs * 2 ) );
				} else {
					loginBackoffMs = Math.min( 120000, Math.max( sessionPollMs, loginBackoffMs * 2 ) );
				}
				nextAuthAt = Date.now() + loginBackoffMs;
				return;
			}
		}

		if ( ! client && auth ) {
			attachClient( config, auth );
		}
	}

	async function tickCommand() {
		if ( ! hooks.readCommand || ! client || ! client.connected ) {
			return;
		}

		const command = hooks.readCommand();
		if ( ! command || typeof command !== 'object' ) {
			return;
		}

		const commandId = String( command.id || `${ command.watts }-${ command.action || 'ac_charge' }` );
		if ( commandId === lastCommandId ) {
			return;
		}

		lastCommandId = commandId;
		publishers.acCharge( command.watts );
		setTimeout( () => {
			if ( lastCommandId !== commandId ) {
				return;
			}

			writeCommandResult( cacheDir, command, true, '' );
			hooks.clearCommand?.();
			lastCommandId = '';
		}, 2500 );
	}

	function shutdown() {
		if ( publishers.deviceSn && Object.keys( quota ).length ) {
			flushQuota( publishers.deviceSn, false, true );
		}
		disconnectClient();
		process.exit( 0 );
	}

	process.on( 'SIGTERM', shutdown );
	process.on( 'SIGINT', shutdown );

	bridgeLog( `persistent mqtt cache=${ cacheDir } heartbeat=${ heartbeatMs }ms` );
	await ensureSession();

	setInterval( () => {
		ensureSession().catch( ( error ) => {
			bridgeLog( String( error.message || error ) );
		} );
		hooks.pingCron?.();
	}, sessionPollMs );

	setInterval( () => {
		publishers.latestQuotas();
	}, heartbeatMs );

	setInterval( () => {
		if ( pendingWrite && publishers.deviceSn ) {
			flushQuota( publishers.deviceSn, false, true );
		}
	}, writeMinMs );

	setInterval( () => {
		tickCommand().catch( ( error ) => {
			bridgeLog( `command ${ error.message || error }` );
		} );
	}, commandPollMs );
}
