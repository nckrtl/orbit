import Pusher from "pusher-js";

export type AnnotationRealtime = {
    configUrl?: string;
    authUrl?: string;
    url?: string;
    key?: string;
    channel?: string;
    /** Host connection: notify on annotation changes and every successful subscription. */
    subscribe?: (refresh: () => void) => () => void;
};

/** Own a connection only when the host does not supply its existing subscription. */
export function connectAnnotationRealtime(
    options: AnnotationRealtime,
    refresh: () => void,
): () => void {
    if (options.subscribe) return options.subscribe(refresh);
    let stopped = false;
    let socket: Pusher | undefined;
    let retry: ReturnType<typeof setTimeout> | undefined;
    const controller = new AbortController();
    const connect = async () => {
        try {
            let config = options;
            if (options.configUrl) {
                const response = await fetch(options.configUrl, {
                    signal: AbortSignal.any([controller.signal, AbortSignal.timeout(10000)]),
                });
                if (!response.ok) throw new Error("Realtime discovery failed");
                const body = await response.json();
                config = { ...options, ...(body.data ?? body) };
            }
            if (stopped) return;
            if (!config.url || !config.key || !config.channel)
                throw new Error("Realtime unavailable");
            const target = new URL(config.url, window.location.href);
            if (!["ws:", "wss:", "http:", "https:"].includes(target.protocol))
                throw new Error("Invalid WebSocket URL");
            const tls = target.protocol === "wss:" || target.protocol === "https:";
            const port = Number(target.port) || (tls ? 443 : 80);
            const authUrl =
                options.authUrl ??
                new URL(
                    "broadcasting/auth",
                    new URL(options.configUrl ?? "/api/v1/realtime", window.location.href),
                ).href;
            socket = new Pusher(config.key, {
                cluster: "orbit",
                wsHost: target.hostname,
                wsPort: port,
                wssPort: port,
                forceTLS: tls,
                enabledTransports: ["ws", "wss"],
                channelAuthorization: {
                    endpoint: authUrl,
                    transport: "ajax",
                    headers: { Accept: "application/json" },
                },
            });
            const channel = socket.subscribe(`private-${config.channel}`);
            const notify = () => {
                if (!stopped) refresh();
            };
            channel.bind("annotation.updated", notify);
            channel.bind("pusher:subscription_succeeded", notify);
        } catch {
            if (!stopped) retry = setTimeout(() => void connect(), 30000);
        }
    };
    void connect();
    return () => {
        stopped = true;
        controller.abort();
        clearTimeout(retry);
        socket?.disconnect();
    };
}
