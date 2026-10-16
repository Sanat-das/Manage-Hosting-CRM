import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import tailwindcss from '@tailwindcss/vite';

export default defineConfig({
    plugins: [
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/adminlte.css',
                'resources/js/adminlte.js',
                'resources/css/branding.css',
                // A separate entry, not an import inside adminlte.js: the client
                // portal loads it too, and pusher-js stays out of the main bundle.
                'resources/js/echo.js',
                'resources/js/chat.js',
                'resources/css/chat.css',
                // The customer widget is its own entry, not part of the admin
                // chat bundle: it ships on public and client-portal pages and
                // must not drag the operator workspace's JS along with it.
                'resources/js/client-chat.js',
                'resources/css/client-chat.css',
                // Its own entry, not an import of adminlte.js: the installer
                // runs on a bespoke layout with no Bootstrap and no AdminLTE
                // bundle, yet needs the same password controls.
                'resources/js/password-field.js',
            ],
            refresh: true,
            fonts: [
                bunny('Instrument Sans', {
                    weights: [400, 500, 600],
                }),
            ],
        }),
        tailwindcss(),
    ],
    server: {
        watch: {
            ignored: ['**/storage/framework/views/**'],
        },
    },
});
