import inertia from '@inertiajs/vite';
import { wayfinder } from '@laravel/vite-plugin-wayfinder';
import tailwindcss from '@tailwindcss/vite';
import vue from '@vitejs/plugin-vue';
import laravel from 'laravel-vite-plugin';
import { bunny } from 'laravel-vite-plugin/fonts';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vite';
import { ignoresVendorPath, installedModules, moduleAliases } from './vite.modules.ts';

const root = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  resolve: {
    alias: moduleAliases(root),
    dedupe: ['vue', '@inertiajs/vue3', '@inertiajs/core'],
  },
  server: {
    watch: {
      usePolling: process.env.LARAVEL_SAIL === '1',
      interval: 500,
      ignored: [
        ignoresVendorPath(root, installedModules(root)),
        '**/storage/**',
        '**/bootstrap/cache/**',
        '**/public/build/**',
      ],
    },
  },
  plugins: [
    laravel({
      input: ['resources/css/app.css', 'resources/js/app.ts'],
      refresh: true,
      fonts: [
        bunny('Source Sans 3', {
          weights: [400, 500, 600, 700],
        }),
      ],
    }),
    inertia(),
    tailwindcss(),
    vue({
      template: {
        transformAssetUrls: {
          base: null,
          includeAbsolute: false,
        },
      },
    }),
    wayfinder({
      formVariants: true,
    }),
  ],
});
