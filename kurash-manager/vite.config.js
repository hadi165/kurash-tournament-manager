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
        cors: true,
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
