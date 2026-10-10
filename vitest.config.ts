import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';

export default defineConfig({
    plugins: [vue()],
    resolve: {
        alias: {
            '@': fileURLToPath(new URL('./resources/js', import.meta.url)),
        },
        dedupe: ['vue', '@inertiajs/vue3', '@inertiajs/core'],
    },
    test: {
        environment: 'jsdom',
        maxWorkers: 4,
        globals: false,
        dangerouslyIgnoreUnhandledErrors: true,
        setupFiles: ['./resources/js/tests/setup.ts'],
        include: [
            'resources/js/**/*.spec.ts',
            'vendor/nubos/*/tests/Frontend/**/*.spec.ts',
        ],
        env: { TZ: 'UTC' },
    },
});
