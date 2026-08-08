#!/usr/bin/env node
import { dirname, resolve } from 'path';
import { fileURLToPath } from 'url';
import {
	fetchAppQuota,
	resolveConfig,
	writeBridgeError,
	writeQuotaCache,
} from './ecoflow-app-client.mjs';

const __dirname = dirname( fileURLToPath( import.meta.url ) );
const cacheDir = process.env.ECOFLOW_CACHE_DIR || resolve( __dirname, '../../ecoflow-cache' );
const baseIntervalMs = Number( process.env.ECOFLOW_BRIDGE_INTERVAL_MS || 10000 );

let nextDelayMs = baseIntervalMs;

async function tick() {
	const config = resolveConfig( cacheDir );

	if ( ! config.email || ! config.password || ! config.deviceSn ) {
		writeBridgeError( cacheDir, 'Waiting for App Login in Customizer and Delta 3 SN (bridge-config.json)' );
		nextDelayMs = 30000;
		return;
	}

	try {
		const quota = await fetchAppQuota( config );
		writeQuotaCache( cacheDir, config.deviceSn, quota );
		console.log( `[ecoflow-bridge] ${ config.deviceSn } keys=${ Object.keys( quota ).length }` );
		nextDelayMs = baseIntervalMs;
	} catch ( error ) {
		writeBridgeError( cacheDir, error.message || error );
		console.error( `[ecoflow-bridge] ${ error.message || error }` );
		nextDelayMs = Math.min( 120000, nextDelayMs * 2 );
	}
}

function scheduleNext() {
	setTimeout( async () => {
		await tick();
		scheduleNext();
	}, nextDelayMs );
}

console.log( `[ecoflow-bridge] starting, cache=${ cacheDir }, baseInterval=${ baseIntervalMs }ms` );
tick().then( scheduleNext );
