import { defineConfig } from 'vite';
import laravel from 'laravel-vite-plugin';
import tailwindcss from '@tailwindcss/vite';
import fs from 'fs';

// Check if SSL certificates exist (dev environment only)
const sslKeyPath = './docker/ssl/key.pem';
const sslCertPath = './docker/ssl/cert.pem';
const hasSSL = fs.existsSync(sslKeyPath) && fs.existsSync(sslCertPath);

export default defineConfig({
    plugins: [
        tailwindcss(), // MUST be before laravel plugin for Tailwind v4.0
        laravel({
            input: [
                'resources/css/app.css',
                'resources/css/filament/admin.css',
                'resources/css/filament/platform.css',
                'resources/js/app.js',
                'resources/js/filament-admin.js',
            ],
            refresh: true,
        }),
    ],
    server: {
        host: '0.0.0.0',        // Listen on all interfaces (required for Docker)
        port: 5173,
        strictPort: true,
        https: hasSSL ? {
            key: fs.readFileSync(sslKeyPath),
            cert: fs.readFileSync(sslCertPath),
        } : false,
        hmr: {
            host: 'registro.local',  // Browser-accessible hostname
            protocol: hasSSL ? 'wss' : 'ws',  // Secure WebSocket for HMR (if SSL available)
            clientPort: 8444,         // Match nginx SSL port
        },
        cors: true,  // Enable CORS for cross-origin requests
    },
    build: {
        manifest: 'manifest.json', // Vite 7: force manifest in root build dir (was .vite/manifest.json)
        outDir: 'public/build', // Output directory (default for Laravel)
    },
});
