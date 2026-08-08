import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';
import { fileURLToPath } from 'url';

const __dirname = path.dirname( fileURLToPath( import.meta.url ) );

export default defineConfig( {
	plugins: [ react() ],
	build: {
		outDir: path.resolve( __dirname, 'assets/js' ),
		emptyOutDir: false,
		rollupOptions: {
			input: path.resolve( __dirname, 'src/ecoflow/main.jsx' ),
			output: {
				entryFileNames: 'ecoflow-flow.js',
				format: 'iife',
				name: 'GamingHubEcoflowFlow',
				inlineDynamicImports: true,
			},
		},
	},
} );
