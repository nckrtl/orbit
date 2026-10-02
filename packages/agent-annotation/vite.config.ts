import { defineConfig } from "vite-plus";
import tailwindcss from "@tailwindcss/vite";

const inject = process.env.ANNOTATION_INJECT === "1";

export default defineConfig({
    plugins: [tailwindcss()],
    define: { "process.env.NODE_ENV": JSON.stringify("production") },
    build: {
        emptyOutDir: !inject,
        lib: {
            entry: inject ? "src/inject.ts" : "src/index.ts",
            name: "AgentAnnotationBundle",
            formats: inject ? ["iife"] : ["es"],
            fileName: () => (inject ? "inject.js" : "index.js"),
        },
    },
});
