import {
    defineConfig
} from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from "@tailwindcss/vite";

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                // Its own entry, not an import of app.css: the venue draw
                // board runs on the chrome-less layout, which never loads the
                // admin bundle.
                'resources/css/ceremony.css',
                'resources/js/app.js',
                'resources/js/passkeys.js',
            ],
            refresh: true,
            // No plugin-managed webfonts. The interface prefers the platform
            // face and falls back to Source Sans 3, whose WOFF2 files are
            // committed under public/fonts and declared with @font-face in
            // resources/css/app.css — nothing is fetched from a font CDN at
            // build time or at run time.
            fonts: [],
        }),
        tailwindcss(),
    ],
    server: {
        // Every interface, not just loopback. `npm run dev` serves the assets
        // the pages ask for, so a dev server bound to localhost leaves every
        // other machine on the network loading a page with no stylesheet —
        // which looks like a broken application rather than an unreachable
        // asset server. Set VITE_DEV_HOST to pin it to one address.
        host: process.env.VITE_DEV_HOST || '0.0.0.0',

        // Laravel serves the page from :8000 and this serves the assets from
        // :5173, so they are different origins even on the same machine.
        cors: true,

        // Left to infer the address from whatever the browser is looking at,
        // so a tablet on the network reconnects to the network address rather
        // than to a localhost that means itself.
        hmr: {
            host: process.env.VITE_DEV_HOST || undefined,
        },

        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
