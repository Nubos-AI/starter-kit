import vue from '@vitejs/plugin-vue';
import { fileURLToPath } from 'node:url';
import { defineConfig } from 'vitest/config';
import { installedModules, moduleAliases } from './vite.modules.ts';

const root = fileURLToPath(new URL('.', import.meta.url));

export default defineConfig({
  plugins: [vue()],
  resolve: {
    alias: [
      { find: '@', replacement: fileURLToPath(new URL('./resources/js', import.meta.url)) },
      ...moduleAliases(root),
    ],
    dedupe: ['vue', '@inertiajs/vue3', '@inertiajs/core'],
  },
  test: {
    environment: 'jsdom',
    maxWorkers: 4,
    testTimeout: 30000,
    globals: false,
    dangerouslyIgnoreUnhandledErrors: true,
    setupFiles: ['./resources/js/shared/testing/setup.ts'],
    include: [
      'resources/js/**/*.spec.ts',
      ...installedModules(root).map((module) => `vendor/${module}/tests/Frontend/**/*.spec.ts`),
    ],
    env: { TZ: 'UTC' },
  },
});
