import { defineConfig } from 'vite'
import vue from '@vitejs/plugin-vue'

// https://vite.dev/config/
export default defineConfig({
  plugins: [vue()],
  // Deployed at https://<your-domain>/insurehub/
  // All asset URLs and the favicon path are prefixed with this.
  base: '/insurehub/',
})
