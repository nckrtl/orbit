import { annotationServerProxy } from "../../packages/agent-annotation/bin/vite.mjs";
import { annotationThread } from "./dev/annotation-thread.ts";
import path from "node:path";
import { fileURLToPath } from "node:url";
import { Agent } from "node:https";
import tailwindcss from "@tailwindcss/vite";
import react from "@vitejs/plugin-react";
import { defineConfig, loadEnv, type Plugin } from "vite-plus";
import { playwright } from "vite-plus/test/browser-playwright";
import { gatewayProfile, grafanaTarget, realtimeTarget } from "./dev/gateway-profile.ts";
import { commanderOneShot } from "./dev/commander-oneshot.ts";
import { orbitProfile } from "./dev/profile.ts";

const rootDir = path.dirname(fileURLToPath(import.meta.url));

/**
 * Points the dev server at a Gateway. Only `vp dev` needs this: the built app is served by the
 * Gateway itself, so `/api` is same-origin there. The proxy carries the Orbit CA and this
 * machine's WireGuard identity, so the browser needs neither. Demo mode and the tests answer
 * every request in the page, so they skip it.
 */
function orbitGateway(): Plugin {
    return {
        name: "orbit-gateway",
        apply: (_, { command }) =>
            command === "serve" && !process.env.VITEST && !process.env.VITE_ORBIT_DEMO,
        async config() {
            const profile = gatewayProfile();
            const [realtime, grafana] = await Promise.all([
                realtimeTarget(profile),
                grafanaTarget(profile),
            ]);
            const agent = profile.ca === undefined ? undefined : new Agent({ ca: profile.ca });
            console.log(
                `  orbit  Gateway ${profile.url} (profile ${profile.name}), realtime ${realtime ?? "not configured"}, Grafana ${grafana ?? "not available"}`,
            );

            return {
                define: { __ORBIT_GATEWAY__: JSON.stringify(profile.url) },
                server: {
                    proxy: {
                        "^/api/": { target: profile.url, changeOrigin: true, agent },
                        // pusher-js connects to `/app/{key}`; `^/app/` keeps the `/projects` page out of the proxy.
                        ...(realtime === null
                            ? {}
                            : {
                                  "^/app/": {
                                      target: realtime,
                                      ws: true,
                                      changeOrigin: true,
                                      agent,
                                      headers: { Origin: profile.url },
                                  },
                              }),
                        // The page reads node metrics from Grafana at the same-origin path `/grafana`.
                        ...(grafana === null
                            ? {}
                            : {
                                  "^/grafana/": {
                                      target: grafana,
                                      changeOrigin: true,
                                      agent,
                                      rewrite: (path: string) => path.replace(/^\/grafana/, ""),
                                  },
                              }),
                    },
                },
            };
        },
    };
}

function annotationSpeech(): Plugin {
    return {
        name: "annotation-speech",
        config(_, { mode }) {
            const target =
                process.env.ANNOTATION_TRANSCRIPTION_TARGET ??
                loadEnv(mode, rootDir, "ANNOTATION_").ANNOTATION_TRANSCRIPTION_TARGET;
            if (!target) return;
            return {
                server: {
                    proxy: {
                        "/__annotate/speech": {
                            target,
                            ws: true,
                            changeOrigin: true,
                            rewrite: (url: string) =>
                                url.replace(/^\/__annotate\/speech/, "/v1/audio/stream"),
                        },
                    },
                },
            };
        },
    };
}

export default defineConfig({
    plugins: [
        react(),
        tailwindcss(),
        orbitGateway(),
        commanderOneShot(),
        orbitProfile(),
        annotationSpeech(),
        annotationThread(),
        annotationServerProxy(),
    ],
    resolve: {
        alias: [
            {
                find: /^@nckrtl\/annotate$/,
                replacement: path.resolve(rootDir, "../../packages/agent-annotation/src/index.ts"),
            },
            {
                find: "@nckrtl/annotate",
                replacement: path.resolve(rootDir, "../../packages/agent-annotation/src"),
            },
            { find: "@", replacement: path.join(rootDir, "src") },
        ],
        dedupe: ["react", "react-dom"],
    },
    define: { __ORBIT_GATEWAY__: "null" },
    // The demo Gateway imports recorded fixtures from the SDK package, outside this app.
    server: {
        fs: { allow: [".", "../../packages/php-sdk/fixtures", "../../packages/agent-annotation"] },
    },
    test: {
        projects: [
            // Pure logic: health rules, event application, log and uptime formatting.
            {
                extends: true,
                test: { name: "unit", environment: "node", include: ["src/**/*.test.ts"] },
            },
            // The whole app in a real browser against the demo Gateway: keyboard, menus, forms, screens.
            {
                extends: true,
                test: {
                    name: "browser",
                    include: ["tests/browser/**/*.test.tsx"],
                    browser: {
                        enabled: true,
                        provider: playwright(),
                        headless: true,
                        instances: [{ browser: "chromium" }],
                        viewport: { width: 1280, height: 800 },
                    },
                },
            },
        ],
    },
    fmt: { ignorePatterns: ["src/api/schema.d.ts", "tests/browser/expected/**"] },
    lint: {
        jsPlugins: [{ name: "vite-plus", specifier: "vite-plus/oxlint-plugin" }],
        rules: { "vite-plus/prefer-vite-plus-imports": "error" },
        options: { typeAware: true, typeCheck: true },
    },
});
