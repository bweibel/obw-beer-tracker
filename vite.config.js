import { defineConfig } from 'vite';
import preact from '@preact/preset-vite';

// Build output lands in `build/` with a manifest at build/.vite/manifest.json,
// which the PHP `Assets` loader reads to enqueue hashed files. Keep `base`
// relative-free: the PHP side prefixes the plugin build URL itself.
export default defineConfig({
	plugins: [preact()],
	build: {
		manifest: true,
		outDir: 'build',
		emptyOutDir: true,
		rollupOptions: {
			input: {
				finder: 'src/finder/main.jsx',
			},
		},
	},
	server: {
		// Bind predictably so the PHP dev-server URL default matches.
		host: 'localhost',
		port: 5173,
		strictPort: true,
		cors: true,
	},
});
