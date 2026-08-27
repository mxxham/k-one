import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  resolve: {
    alias: {
      '@': path.resolve(__dirname, './src'),
    },
  },
  server: {
    host: true,
    port: 5173,
    proxy: {
      '/index.php': {
        target: 'http://127.0.0.1:80/k-one/api/index.php',
        changeOrigin: true,
      },
    },
  },
});
