import { defineConfig } from 'vite';
import preact from '@preact/preset-vite';

// Build output lands in `build/` with a manifest at build/.vite/manifest.json,
// which the PHP `Assets` loader reads to enqueue hashed files. The manifest
// stores build-root-relative paths, so the PHP side prefixes the plugin build
// URL itself for the JS/CSS entries. `base: './'` makes assets *referenced from
// within* the CSS (e.g. the badge icon PNGs) resolve relative to the emitted
// CSS file rather than the site root, which is where the plugin build actually
// lives (…/wp-content/plugins/obw-beer-tracker/build/assets/).
export default defineConfig({
	base: './',
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
