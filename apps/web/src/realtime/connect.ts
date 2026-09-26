import { notifyAnnotationUpdates } from "./annotations";
import type { QueryClient } from "@tanstack/react-query";
import Pusher from "pusher-js";
import { get } from "../api/client";
import { applyEvent, type RealtimeEvent } from "./apply";
import { downForMs, setLiveness } from "./liveness";
import {
    acceptAgentEvent,
    agentMemberAdded,
    agentMemberRemoved,
    agentSubscriptionSucceeded,
    clearAgentPresence,
} from "./agent-presence";
import { applyAgentProcessEvent, clearAgentProcesses } from "./agent-processes";

const RETRY_SECONDS = 30;

/**
 * A first subscription this soon after page load reloads nothing: the lists were fetched moments
 * ago. After a longer wait the lists only polled, with a backoff of up to 5 minutes, so they reload.
 */
export const FIRST_CONNECT_GRACE_MS = 5_000;

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
        const agentChannels = new Map<number, ReturnType<typeof pusher.subscribe>>();
        const syncAgentChannels = () => {
            const nodes =
                client.getQueryData<Array<{ id: number; status?: string }>>(["nodes"]) ?? [];
            const activeIds = new Set(
                nodes.filter((node) => node.status === "active").map((node) => node.id),
            );
            for (const [id, agentChannel] of agentChannels) {
                if (!activeIds.has(id)) {
                    agentChannel.unbind_all();
                    pusher.unsubscribe(`presence-node.${id}`);
                    agentChannels.delete(id);
                    clearAgentPresence(id);
                    clearAgentProcesses(id);
                }
            }
            for (const id of activeIds) {
                if (agentChannels.has(id)) continue;
                const agentChannel = pusher.subscribe(`presence-node.${id}`);
                agentChannel.bind("pusher:subscription_succeeded", (data: unknown) => {
                    clearAgentProcesses(id);
                    agentSubscriptionSucceeded(id, data);
                });
                agentChannel.bind("pusher:member_added", (data: { id?: unknown }) => {
                    if (data?.id === `agent.${id}`) clearAgentProcesses(id);
                    agentMemberAdded(id, data?.id);
                });
                agentChannel.bind("pusher:member_removed", (data: { id?: unknown }) => {
                    if (data?.id === `agent.${id}`) clearAgentProcesses(id);
                    agentMemberRemoved(id, data?.id);
                });
                const onAgentEvent =
                    (name: string) => (data: unknown, metadata: { user_id?: unknown }) => {
                        if (acceptAgentEvent(id, metadata?.user_id, data)) {
                            applyAgentProcessEvent(id, name, data);
                        }
                    };
                agentChannel.bind("client-heartbeat", onAgentEvent("client-heartbeat"));
                agentChannel.bind("client-snapshot", onAgentEvent("client-snapshot"));
                agentChannel.bind("client-process", onAgentEvent("client-process"));
                agentChannels.set(id, agentChannel);
            }
        };
        syncAgentChannels();
        const unsubscribeCache = client.getQueryCache().subscribe((event) => {
            if (event.query.queryKey[0] === "nodes") syncAgentChannels();
        });

        channel.bind("pusher:subscription_succeeded", () => {
            if (signal.aborted) {
                return;
            }

            // Events sent while the socket was down are gone; reload what they would have changed.
            // That holds after a reconnect, and after a first connect that came late, when the
            // lists had been polling (a failed socket or a retried realtime discovery). On a prompt
            // first subscription, reload what now polls only rarely: the task and Process queries,
            // because a change between their first load and this moment sent no event to this page.
            if (wasLive || downForMs() > FIRST_CONNECT_GRACE_MS) {
                void client.invalidateQueries();
            } else {
                for (const queryKey of [["task-groups"], ["tasks-status"], ["processes"]]) {
                    void client.invalidateQueries({ queryKey });
                }
            }

            notifyAnnotationUpdates();
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
                if (event.type === "annotation.updated") {
                    notifyAnnotationUpdates();
                    void client.invalidateQueries({ queryKey: ["instance-annotations"] });
                    void client.invalidateQueries({ queryKey: ["task-groups"] });
                }
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
            unsubscribeCache();
            channel.unbind_all();
            for (const [id, agentChannel] of agentChannels) {
                agentChannel.unbind_all();
                clearAgentPresence(id);
            }
            agentChannels.clear();
            pusher.connection.unbind("state_change", onStateChange);
            pusher.disconnect();
        };
        setLiveness("reconnecting");
    }

    return attempt();
}
