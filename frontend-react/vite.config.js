import { defineConfig } from "vite";
import preact from "@preact/preset-vite";

// Servido pela própria API (FastAPI/StaticFiles) na RAIZ do domínio.
export default defineConfig({
  base: "/",
  plugins: [preact()],
  build: {
    outDir: "dist",
    target: "es2020",
    // hls.js/mpegts.js saem do bundle inicial (só carregam quando dá play)
    chunkSizeWarningLimit: 900,
  },
  server: {
    port: 5174,
    // dev local: encaminha as chamadas da API pro backend (uvicorn na 7678)
    proxy: {
      "/admin": "http://localhost:7678",
      "/p": "http://localhost:7678",
      "/health": "http://localhost:7678",
    },
  },
});
