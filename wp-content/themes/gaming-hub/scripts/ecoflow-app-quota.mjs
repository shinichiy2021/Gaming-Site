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
const config = resolveConfig( cacheDir, process.argv[2] || '' );

try {
	const quota = await fetchAppQuota( config );
	writeQuotaCache( config.cacheDir, config.deviceSn, quota );
	process.stdout.write( JSON.stringify( quota ) );
} catch ( error ) {
	writeBridgeError( config.cacheDir, error.message || error );
	process.stderr.write( JSON.stringify( { error: error.message || String( error ) } ) );
	process.exit( 1 );
}
