import { fileURLToPath, URL } from 'node:url'

import tailwindcss from '@tailwindcss/vite'
import react from '@vitejs/plugin-react'
import { defineConfig } from 'vite'

// The SPA is a container of its own (§3.2.2) and talks to the API over JSON.
// In development it is served by Vite while the API answers from the nginx+php
// container published on port 8080, so every /api call is proxied there and the
// browser sees a single origin — no CORS preflight, and the bearer token is the
// only credential in play.
export default defineConfig({
  plugins: [react(), tailwindcss()],
  resolve: {
    alias: {
      '@': fileURLToPath(new URL('./src', import.meta.url)),
    },
  },
  server: {
    proxy: {
      '/api': {
        target: 'http://localhost:8080',
        changeOrigin: true,
      },
    },
  },
})
