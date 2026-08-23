import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

const entries = {
	ecoflow: {
		input: 'src/ecoflow/main.jsx',
		out: 'ecoflow-flow.js',
	},
	powerwall: {
		input: 'src/powerwall/main.jsx',
		out: 'powerwall-flow.js',
	},
	tesla: {
		input: 'src/tesla/main.jsx',
		out: 'tesla-flow.js',
	},
};

const target = process.env.FLOW_TARGET || 'ecoflow';
const selected = entries[ target ] || entries.ecoflow;

export default defineConfig( {
	plugins: [ react() ],
	build: {
		outDir: path.resolve( __dirname, 'assets/js' ),
		emptyOutDir: false,
		rollupOptions: {
			input: path.resolve( __dirname, selected.input ),
			output: {
				entryFileNames: selected.out,
				format: 'iife',
				inlineDynamicImports: true,
			},
		},
	},
} );
