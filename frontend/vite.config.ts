import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

// En desarrollo, /api y /uploads se reenvían al servidor PHP
// (php -S localhost:8000 -t public public/router.php) para que el navegador
// vea un solo origen y la cookie de sesión funcione igual que en producción.
const backend = process.env.VITE_BACKEND_URL || 'http://localhost:8000';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    port: 5173,
    proxy: {
      '/api': { target: backend, changeOrigin: true },
      '/uploads': { target: backend, changeOrigin: true },
    },
  },
});
