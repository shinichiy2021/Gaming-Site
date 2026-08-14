#!/usr/bin/env node
import { dirname, join, resolve } from 'path';
import { readFileSync, unlinkSync } from 'fs';
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
const wpCronUrl = process.env.WP_CRON_URL || 'http://wordpress/wp-cron.php?doing_wp_cron';

let nextDelayMs = baseIntervalMs;
let lastCronAt = 0;

function readPendingCommand() {
	try {
		const raw = JSON.parse( readFileSync( join( cacheDir, 'bridge-command.json' ), 'utf8' ) );
		return raw && typeof raw === 'object' ? raw : null;
	} catch {
		return null;
	}
}

function clearPendingCommand() {
	try {
		unlinkSync( join( cacheDir, 'bridge-command.json' ) );
	} catch {
		// Already consumed.
	}
}

async function pingWpCron() {
	if ( Date.now() - lastCronAt < 60000 ) {
		return;
	}

	lastCronAt = Date.now();

	try {
		await fetch( wpCronUrl, { method: 'GET' } );
	} catch {
		// WordPress cron is best-effort; apply still runs on page views.
	}
}

async function tick() {
	const config = resolveConfig( cacheDir );

	if ( ! config.email || ! config.password || ! config.deviceSn ) {
		writeBridgeError( cacheDir, 'Waiting for App Login in Customizer and Delta 3 SN (bridge-config.json)' );
		nextDelayMs = 30000;
		await pingWpCron();
		return;
	}

	try {
		const command = readPendingCommand();
		const quota = await fetchAppQuota( config, command );
		writeQuotaCache( cacheDir, config.deviceSn, quota );
		if ( command ) {
			clearPendingCommand();
			console.log( `[ecoflow-bridge] ${ config.deviceSn } set ac_charge=${ command.watts }W keys=${ Object.keys( quota ).length }` );
		} else {
			console.log( `[ecoflow-bridge] ${ config.deviceSn } keys=${ Object.keys( quota ).length }` );
		}
		nextDelayMs = baseIntervalMs;
		await pingWpCron();
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
