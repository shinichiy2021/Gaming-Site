#!/usr/bin/env node
/**
 * Phase 2: Fleet Telemetry MQTT → WordPress bridge.
 *
 * Subscribes to fleet-telemetry MQTT topics, merges SOC/charge fields,
 * writes tesla/telemetry-data/latest.json, and POSTs to WP REST.
 */
import { mkdirSync, writeFileSync } from 'fs';
import { join, resolve } from 'path';
import mqtt from 'mqtt';

const dataDir = process.env.TESLA_TELEMETRY_DATA_DIR
	|| resolve( '/tesla/telemetry-data' );
const mqttUrl = process.env.TESLA_TELEMETRY_MQTT_URL || 'mqtt://tesla-telemetry-mqtt:1883';
const topicBase = ( process.env.TESLA_TELEMETRY_MQTT_TOPIC_BASE || 'gaming_hub' ).replace( /\/$/, '' );
const wpUrl = process.env.TESLA_TELEMETRY_WP_URL
	|| 'http://wordpress/wp-json/gaming-hub/v1/tesla/telemetry';
const token = process.env.TESLA_TELEMETRY_BRIDGE_TOKEN || '';
const vinFilter = ( process.env.TESLA_VEHICLE_VIN || '' ).toUpperCase();
const debounceMs = Math.max( 500, Number( process.env.TESLA_TELEMETRY_BRIDGE_DEBOUNCE_MS || 2000 ) );
const heartbeatMs = Math.max( 5000, Number( process.env.TESLA_TELEMETRY_BRIDGE_HEARTBEAT_MS || 30000 ) );

/** @type {Map<string, { fields: Record<string, unknown>, updatedAt: number, connectivity?: unknown }>} */
const byVin = new Map();

let flushTimer = null;
let lastPostAt = 0;
let lastPostKey = '';

function log( ...args ) {
	console.log( new Date().toISOString(), ...args );
}

function ensureDataDir() {
	try {
		mkdirSync( dataDir, { recursive: true } );
	} catch {
		// ignore
	}
}

function parsePayload( buf ) {
	const text = Buffer.isBuffer( buf ) ? buf.toString( 'utf8' ) : String( buf ?? '' );
	if ( ! text ) {
		return null;
	}
	try {
		return JSON.parse( text );
	} catch {
		return text;
	}
}

/**
 * topic: gaming_hub/<VIN>/v/<Field>
 *        gaming_hub/<VIN>/connectivity
 */
function handleMessage( topic, payload ) {
	const prefix = `${ topicBase }/`;
	if ( ! topic.startsWith( prefix ) ) {
		return;
	}
	const rest = topic.slice( prefix.length );
	const parts = rest.split( '/' );
	if ( parts.length < 2 ) {
		return;
	}

	const vin = String( parts[ 0 ] || '' ).toUpperCase();
	if ( ! vin ) {
		return;
	}
	if ( vinFilter && vin !== vinFilter ) {
		return;
	}

	let entry = byVin.get( vin );
	if ( ! entry ) {
		entry = { fields: {}, updatedAt: 0 };
		byVin.set( vin, entry );
	}

	const kind = parts[ 1 ];
	if ( kind === 'v' && parts[ 2 ] ) {
		const field = parts[ 2 ];
		entry.fields[ field ] = parsePayload( payload );
		entry.updatedAt = Date.now();
		scheduleFlush();
		return;
	}

	if ( kind === 'connectivity' ) {
		entry.connectivity = parsePayload( payload );
		entry.updatedAt = Date.now();
		scheduleFlush();
	}
}

function snapshotFor( vin, entry ) {
	return {
		vin,
		fields: { ...entry.fields },
		connectivity: entry.connectivity ?? null,
		received_at: Math.floor( ( entry.updatedAt || Date.now() ) / 1000 ),
		bridge_at: Math.floor( Date.now() / 1000 ),
	};
}

function writeLatest( body ) {
	ensureDataDir();
	const path = join( dataDir, 'latest.json' );
	writeFileSync( path, JSON.stringify( body, null, 2 ) + '\n', 'utf8' );
}

async function postWp( body ) {
	if ( ! token ) {
		log( 'bridge token missing; skip WP POST (file only)' );
		return;
	}

	const key = JSON.stringify( {
		vin: body.vin,
		fields: body.fields,
	} );
	// Never re-POST an unchanged snapshot — heartbeats were keeping the car
	// "awake" in WP with stale cabin_w long after the stream stopped.
	if ( key === lastPostKey ) {
		return;
	}

	try {
		const res = await fetch( wpUrl, {
			method: 'POST',
			headers: {
				'Content-Type': 'application/json',
				'X-Gaming-Hub-Telemetry-Token': token,
			},
			body: JSON.stringify( body ),
		} );
		const text = await res.text();
		let json = null;
		try {
			json = JSON.parse( text );
		} catch {
			json = { raw: text.slice( 0, 200 ) };
		}
		if ( ! res.ok ) {
			log( 'WP POST failed', res.status, json );
			return;
		}
		lastPostAt = Date.now();
		lastPostKey = key;
		log( 'WP updated', {
			soc: json.soc,
			charging: json.charging,
			updated: json.updated,
		} );
	} catch ( err ) {
		log( 'WP POST error', err?.message || err );
	}
}

async function flush() {
	flushTimer = null;
	for ( const [ vin, entry ] of byVin.entries() ) {
		if ( ! entry.fields || Object.keys( entry.fields ).length === 0 ) {
			continue;
		}
		const body = snapshotFor( vin, entry );
		writeLatest( body );
		await postWp( body );
	}
}

function scheduleFlush() {
	if ( flushTimer ) {
		return;
	}
	flushTimer = setTimeout( () => {
		flush().catch( ( err ) => log( 'flush error', err?.message || err ) );
	}, debounceMs );
}

ensureDataDir();

if ( ! token ) {
	log( 'WARN: TESLA_TELEMETRY_BRIDGE_TOKEN is empty — writing latest.json only' );
}

log( 'connecting MQTT', mqttUrl, 'topic', `${ topicBase }/#` );
const client = mqtt.connect( mqttUrl, {
	clientId: `gaming-hub-telemetry-bridge-${ Math.random().toString( 16 ).slice( 2, 8 ) }`,
	reconnectPeriod: 5000,
	connectTimeout: 30000,
} );

client.on( 'connect', () => {
	log( 'MQTT connected' );
	client.subscribe( `${ topicBase }/#`, { qos: 1 }, ( err ) => {
		if ( err ) {
			log( 'subscribe error', err.message || err );
		} else {
			log( 'subscribed', `${ topicBase }/#` );
		}
	} );
} );

client.on( 'message', ( topic, payload ) => {
	try {
		handleMessage( topic, payload );
	} catch ( err ) {
		log( 'message handler error', err?.message || err );
	}
} );

client.on( 'error', ( err ) => log( 'MQTT error', err?.message || err ) );
client.on( 'reconnect', () => log( 'MQTT reconnecting' ) );
client.on( 'offline', () => log( 'MQTT offline' ) );

setInterval( () => {
	if ( byVin.size ) {
		scheduleFlush();
	}
}, heartbeatMs );
