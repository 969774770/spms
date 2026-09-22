import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// 开发期代理：API 与静态契约转发到后端（默认本机 8080，可用 API_PROXY 环境变量指向宝塔服务器）
const target = process.env.API_PROXY || 'http://127.0.0.1:8080'

export default defineConfig({
  base: './',
  plugins: [vue()],
  server: {
    port: 5173,
    proxy: {
      '/api.php': { target, changeOrigin: true },
      '/download.php': { target, changeOrigin: true },
      '/pub': { target, changeOrigin: true },
    },
  },
  build: {
    outDir: 'dist',
    chunkSizeWarningLimit: 2000,
  },
})
