#!/usr/bin/env bun

function usage(): never {
    console.error(
        "Usage: bun apps/agent/scripts/node-agent-viewer.ts --gateway=https://gateway.orbit --reverb=wss://reverb.orbit --key=APP_KEY --node=NODE_ID [--event=client-event] [--publish-event=client-event]",
    );
    process.exit(64);
}

function argument(name: string): string | undefined {
    const prefix = `--${name}=`;
    return Bun.argv
        .slice(2)
        .find((value) => value.startsWith(prefix))
        ?.slice(prefix.length);
}

const gateway = argument("gateway");
const reverb = argument("reverb");
const key = argument("key");
const nodeId = argument("node");
const eventFilter = argument("event");
const publishEvent = argument("publish-event");

if (!gateway || !reverb || !key || !nodeId || !/^\d+$/.test(nodeId)) {
    usage();
}

const gatewayUrl = new URL(gateway);
const reverbUrl = new URL(reverb);
const channelName = `presence-node.${nodeId}`;
const timestamp = (): string => new Date().toISOString();
const socketUrl = new URL(`/app/${encodeURIComponent(key)}`, reverbUrl);
socketUrl.search = "protocol=7&client=orbit-viewer&version=1.0.0&flash=false";

const socket = new WebSocket(socketUrl);
let subscribed = false;

function send(event: string, data: unknown): void {
    socket.send(JSON.stringify({ event, data: JSON.stringify(data) }));
}

function publish(event: string, data: unknown): void {
    socket.send(JSON.stringify({ event, channel: channelName, data: JSON.stringify(data) }));
}

async function authorize(socketId: string): Promise<void> {
    const response = await fetch(new URL("/api/v1/broadcasting/auth", gatewayUrl), {
        method: "POST",
        headers: {
            Accept: "application/json",
            "Content-Type": "application/x-www-form-urlencoded",
        },
        body: new URLSearchParams({ socket_id: socketId, channel_name: channelName }),
    });

    if (!response.ok) {
        throw new Error(
            `Channel authorization failed (${response.status}): ${await response.text()}`,
        );
    }

    const auth = (await response.json()) as { auth: string; channel_data?: string };
    send("pusher:subscribe", {
        auth: auth.auth,
        channel: channelName,
        ...(auth.channel_data ? { channel_data: auth.channel_data } : {}),
    });
}

socket.addEventListener("open", () => {
    console.log(`${timestamp()} websocket_open ${reverbUrl.host}`);
});

socket.addEventListener("message", (message) => {
    try {
        const frame = JSON.parse(String(message.data)) as {
            event?: string;
            data?: unknown;
            channel?: string;
            user_id?: unknown;
        };
        const eventName = frame.event ?? "unknown";
        const data = typeof frame.data === "string" ? JSON.parse(frame.data) : frame.data;

        if (eventName === "pusher:connection_established") {
            const socketId = (data as { socket_id?: string }).socket_id;
            if (!socketId) throw new Error("Reverb omitted socket_id");
            void authorize(socketId).catch((error: unknown) => {
                console.error(`${timestamp()} authorization_error ${String(error)}`);
                socket.close();
            });
            return;
        }

        if (eventName === "pusher:ping") {
            send("pusher:pong", {});
            return;
        }

        if (eventName === "pusher_internal:subscription_succeeded") {
            subscribed = true;
            console.log(`${timestamp()} subscribed ${channelName} ${JSON.stringify(data)}`);
            if (publishEvent) {
                if (!publishEvent.startsWith("client-")) {
                    throw new Error("--publish-event must start with client-");
                }
                publish(publishEvent, { at: timestamp(), probe: "viewer-client" });
                console.log(`${timestamp()} published ${publishEvent}`);
            }
            return;
        }

        if (
            eventName === "pusher_internal:member_added" ||
            eventName === "pusher_internal:member_removed"
        ) {
            const member = data as { user_id?: unknown; user_info?: unknown };
            const action = eventName.endsWith("added") ? "member_added" : "member_removed";
            console.log(
                `${timestamp()} ${action} user_id=${String(member?.user_id ?? "unknown")} ${JSON.stringify(member)}`,
            );
            return;
        }

        if (eventName.startsWith("client-") && (!eventFilter || eventName === eventFilter)) {
            console.log(
                `${timestamp()} ${eventName} user_id=${String(frame.user_id ?? "unknown")} ${JSON.stringify(data)}`,
            );
        }
    } catch (error) {
        console.error(`${timestamp()} frame_error ${String(error)}`);
    }
});

socket.addEventListener("error", () => {
    console.error(`${timestamp()} websocket_error`);
});
socket.addEventListener("close", (event) => {
    console.log(
        `${timestamp()} websocket_closed code=${event.code} reason=${event.reason} subscribed=${subscribed}`,
    );
    process.exit(subscribed ? 0 : 1);
});

const stop = (): void => {
    console.log(`${timestamp()} stopping`);
    socket.close();
};
process.on("SIGINT", stop);
process.on("SIGTERM", stop);
