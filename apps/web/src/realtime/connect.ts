import type { QueryClient } from "@tanstack/react-query";
import Pusher from "pusher-js";
import { get } from "../api/client";
import { applyEvent, type RealtimeEvent } from "./apply";
import { setLiveness } from "./liveness";

const RETRY_SECONDS = 30;

type RealtimeConfig = { url: string | null; key: string | null; channel: string | null };

/**
 * Subscribes to the Gateway's record-change channel and patches the query cache from each event.
 * The dev server proxies the socket (see vite.config.ts), so the browser needs no trust in the
 * Orbit CA there; the built app connects to the URL `GET /api/v1/realtime` names.
 */
export async function connectRealtime(client: QueryClient, signal: AbortSignal): Promise<void> {
    if (signal.aborted) {
        return;
    }

    let retry: ReturnType<typeof setTimeout> | undefined;
    let disconnect: (() => void) | undefined;

    signal.addEventListener(
        "abort",
        () => {
            clearTimeout(retry);
            disconnect?.();
        },
        { once: true },
    );

    async function attempt(): Promise<void> {
        const config = await get<RealtimeConfig>("/api/v1/realtime").catch((error: unknown) =>
            error instanceof Error ? error : new Error(String(error)),
        );

        if (signal.aborted) {
            return;
        }

        if (
            config instanceof Error ||
            config.url === null ||
            config.key === null ||
            config.channel === null
        ) {
            // The lists poll meanwhile. Ask again later, so the page goes live when the Gateway can offer a socket.
            retry = setTimeout(() => {
                retry = undefined;
                void attempt();
            }, RETRY_SECONDS * 1000);
            setLiveness("polling", config instanceof Error ? config.message : null);

            return;
        }

        const target = import.meta.env.DEV
            ? new URL(window.location.origin)
            : new URL(config.url.replace(/^ws/, "http"));
        const tls = target.protocol === "https:";
        const port = Number(target.port) || (tls ? 443 : 80);

        const pusher = new Pusher(config.key, {
            cluster: "orbit",
            wsHost: target.hostname,
            wsPort: port,
            wssPort: port,
            forceTLS: tls,
            enabledTransports: ["ws", "wss"],
            channelAuthorization: {
                endpoint: "/api/v1/broadcasting/auth",
                transport: "ajax",
                headers: { Accept: "application/json" },
            },
        });

        let wasLive = false;
        const channel = pusher.subscribe(`private-${config.channel}`);

        channel.bind("pusher:subscription_succeeded", () => {
            if (signal.aborted) {
                return;
            }

            // Events sent while the socket was down are gone; reload what they would have changed.
            if (wasLive) {
                void client.invalidateQueries();
            }

            wasLive = true;
            setLiveness("live");
        });

        channel.bind_global((name: string, payload: unknown) => {
            if (
                signal.aborted ||
                name.startsWith("pusher:") ||
                name.startsWith("pusher_internal:")
            ) {
                return;
            }

            const event = (
                typeof payload === "string" ? JSON.parse(payload) : payload
            ) as Partial<RealtimeEvent> | null;

            if (
                event !== null &&
                typeof event.type === "string" &&
                typeof event.data === "object" &&
                event.data !== null
            ) {
                applyEvent(client, event as RealtimeEvent);
            }
        });

        const onStateChange = ({ current }: { current: string }) => {
            if (!signal.aborted && current !== "connected") {
                setLiveness("reconnecting");
            }
        };
        pusher.connection.bind("state_change", onStateChange);

        disconnect = () => {
            channel.unbind_all();
            pusher.connection.unbind("state_change", onStateChange);
            pusher.disconnect();
        };
        setLiveness("reconnecting");
    }

    return attempt();
}
