import { readFileSync } from "node:fs";
import { get } from "node:https";
import { homedir } from "node:os";
import { join } from "node:path";

export type GatewayProfile = { name: string; url: string; ca: string | undefined };

/**
 * The Gateway the dev server proxies to: the CLI's active profile in `~/.orbit/config.json`, the
 * same one `orbit top` uses. `ORBIT_GATEWAY_URL` and `ORBIT_CA_PATH` override it.
 */
export function gatewayProfile(): GatewayProfile {
    const home = process.env.ORBIT_HOME ?? join(homedir(), ".orbit");
    let name = "env";
    let url = process.env.ORBIT_GATEWAY_URL;
    let caPath = process.env.ORBIT_CA_PATH;

    if (url === undefined) {
        const config = JSON.parse(readFileSync(join(home, "config.json"), "utf8")) as {
            active_gateway?: string;
            gateways?: Record<string, { url?: string; ca_path?: string | null }>;
        };
        name = process.env.ORBIT_GATEWAY ?? config.active_gateway ?? "";
        const profile = config.gateways?.[name];

        if (profile?.url === undefined) {
            throw new Error(
                `No Gateway profile [${name}] in ${home}/config.json. Run orbit gateway:add first.`,
            );
        }

        url = profile.url;
        caPath ??= profile.ca_path ?? undefined;
    }

    return {
        name,
        url: url.replace(/\/+$/, ""),
        ca: caPath === undefined ? undefined : readFileSync(caPath, "utf8"),
    };
}

/** The `data` of one Gateway GET, or null when the Gateway refuses it or does not answer in time. */
function gatewayData(
    profile: GatewayProfile,
    path: string,
): Promise<Record<string, unknown> | null> {
    return new Promise((resolve) => {
        const request = get(
            `${profile.url}${path}`,
            { ca: profile.ca, headers: { Accept: "application/json" }, timeout: 4000 },
            (response) => {
                let body = "";
                response.on("data", (chunk: Buffer) => (body += chunk.toString()));
                response.on("end", () => {
                    try {
                        resolve(
                            (JSON.parse(body) as { data?: Record<string, unknown> }).data ?? null,
                        );
                    } catch {
                        resolve(null);
                    }
                });
            },
        );
        request.on("timeout", () => request.destroy());
        request.on("error", () => resolve(null));
    });
}

const origin = (url: unknown): string | null =>
    typeof url === "string" && url !== "" ? url.replace(/^ws/, "http").replace(/\/+$/, "") : null;

/**
 * The reserved private hostname beside `gateway.<tld>`, such as `reverb.orbit`. The proxy falls back to it when the
 * Gateway does not answer at startup, so a dev server started during a Gateway outage works once the Gateway is back.
 */
function reserved(profile: GatewayProfile, name: string): string | null {
    const url = new URL(profile.url);

    return url.hostname.startsWith("gateway.")
        ? `https://${name}.${url.hostname.slice("gateway.".length)}`
        : null;
}

/** The WebSocket origin `GET /api/v1/realtime` names, as an https URL the dev proxy can target. */
export async function realtimeTarget(profile: GatewayProfile): Promise<string | null> {
    return (
        origin(
            process.env.ORBIT_REALTIME_URL ?? (await gatewayData(profile, "/api/v1/realtime"))?.url,
        ) ?? reserved(profile, "reverb")
    );
}

/** The Grafana origin `GET /api/v1/metrics/credentials` names. Only the URL is read here; the page sends the credential itself. */
export async function grafanaTarget(profile: GatewayProfile): Promise<string | null> {
    return (
        origin(
            process.env.ORBIT_GRAFANA_URL ??
                (await gatewayData(profile, "/api/v1/metrics/credentials"))?.url,
        ) ?? reserved(profile, "metrics")
    );
}
