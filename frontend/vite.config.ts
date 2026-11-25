import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
export default defineConfig(({ command }) => ({
  plugins: [react()],
  base: command === 'serve' ? '/' : '/app/',
  build: { outDir: '../backend/public/app', emptyOutDir: true },
  server: { proxy: { '/api': 'http://127.0.0.1:8002' } },
}));
