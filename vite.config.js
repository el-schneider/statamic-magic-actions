import laravel from 'laravel-vite-plugin'
import path from 'path'
import { defineConfig } from 'vite'

export default defineConfig({
  plugins: [
    laravel({
      input: ['resources/js/addon.ts'],
      publicDirectory: 'resources/dist',
    }),
  ],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'resources/js'),
      '@types': path.resolve(__dirname, './resources/js/types'),
    },
  },
  server: {
    cors: true,
  },
})
