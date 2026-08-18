#!/usr/bin/env node
import { dirname, join, resolve } from 'path';
import { readFileSync, unlinkSync } from 'fs';
import { fileURLToPath } from 'url';
import {
	resolveConfig,
	startPersistentMqttBridge,
	writeBridgeError,
} from './ecoflow-app-client.mjs';

const __dirname = dirname( fileURLToPath( import.meta.url ) );
const cacheDir = process.env.ECOFLOW_CACHE_DIR || resolve( __dirname, '../../ecoflow-cache' );
const wpCronUrl = process.env.WP_CRON_URL || 'http://wordpress/wp-cron.php?doing_wp_cron';

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

const config = resolveConfig( cacheDir );
if ( ! config.email || ! config.password || ! config.deviceSn ) {
	writeBridgeError( cacheDir, 'Waiting for App Login in Customizer and Delta 3 SN (bridge-config.json)' );
}

await startPersistentMqttBridge( cacheDir, {
	readCommand: readPendingCommand,
	clearCommand: clearPendingCommand,
	pingCron: pingWpCron,
} );
