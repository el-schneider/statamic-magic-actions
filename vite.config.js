import laravel from 'laravel-vite-plugin'
import { defineConfig } from 'vite'

export default defineConfig({
    plugins: [
        laravel({
            input: ['resources/js/addon.ts'],
            publicDirectory: 'resources/dist',
        }),
    ],
    server: {
        cors: true,
    },
})
