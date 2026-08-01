import { defineConfig } from 'vite';
import react from '@vitejs/plugin-react';
import path from 'path';

export default defineConfig({
  plugins: [react()],
  root: 'frontend',
  build: {
    outDir: '../assets/dist',
    emptyOutDir: true,
    rollupOptions: {
      input: path.resolve(__dirname, 'frontend/main.tsx'),
      output: {
        format: 'iife',
        entryFileNames: 'nexora-engine.js',
        inlineDynamicImports: true,
        assetFileNames: (assetInfo) => {
          if (assetInfo.name?.endsWith('.css')) return 'nexora-engine.css';
          return assetInfo.name ?? 'asset-[hash]';
        },
      },
    },
    sourcemap: false,
    minify: true,
  },
  resolve: {
    alias: {
      '@': path.resolve(__dirname, 'frontend'),
    },
  },
});
