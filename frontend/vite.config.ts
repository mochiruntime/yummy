import { defineConfig } from "vite";
import react from "@vitejs/plugin-react";
import tailwindcss from "@tailwindcss/vite";
import { VitePWA } from "vite-plugin-pwa";

export default defineConfig({
  plugins: [
    react(),
    tailwindcss(),
    VitePWA({
      registerType: "autoUpdate",
      manifest: {
        name: "yummy — Collaborative Food Map",
        short_name: "yummy",
        description: "Privacy-first collaborative map for sharing food spots with friends.",
        display: "standalone",
        theme_color: "#E11D48",
        background_color: "#FFFFFF",
        start_url: "/",
        icons: []
      },
      workbox: {
        navigateFallbackDenylist: [/^\/api\//],
        runtimeCaching: []
      }
    })
  ],
  server: {
    proxy: {
      "/api": {
        target: "http://localhost:8000",
        changeOrigin: true
      }
    }
  }
});
