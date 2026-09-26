import { useSyncExternalStore } from "react";
import type { ChannelAuthorizationHandler } from "pusher-js";

/** The browser auth endpoint. It signs `private-orbit` and `presence-node.{id}`, and refuses log stream channels. */
export const AUTH_ENDPOINT = "/api/v1/broadcasting/auth";

/** Log stream channels are signed by the Gateway when the stream opens, never by the auth endpoint. */
export const LOG_STREAM_PREFIX = "private-log-stream.";

/** One channel on the shared socket: the part of a pusher-js channel that the page uses. */
export type RealtimeChannel = {
    bind(event: string, callback: (data: unknown) => void): unknown;
    unbind_all(): unknown;
};

/** The shared socket while realtime is live. `socketId` changes with every new connection. */
export type RealtimeSocket = {
    socketId: string;
    subscribe(channel: string): RealtimeChannel;
    unsubscribe(channel: string): void;
};

let current: RealtimeSocket | null = null;
const listeners = new Set<() => void>();

/** Set by the connection: the socket once `private-orbit` is subscribed, null while it is down. */
export function setRealtimeSocket(socket: RealtimeSocket | null): void {
    if (socket !== current) {
        current = socket;
        listeners.forEach((listener) => listener());
    }
}

export const realtimeSocket = (): RealtimeSocket | null => current;

const subscribe = (listener: () => void) => {
    listeners.add(listener);

    return () => listeners.delete(listener);
};

export const useRealtimeSocket = (): RealtimeSocket | null =>
    useSyncExternalStore(subscribe, () => current);

/** The signatures from open log streams, by channel, with the socket each one is valid for. */
const signed = new Map<string, { socketId: string; auth: string }>();

/** Stores the signature a stream's open response returned. Call it before subscribing to the channel. */
export function signLogStream(channel: string, socketId: string, auth: string): void {
    signed.set(channel, { socketId, auth });
}

export function forgetLogStream(channel: string): void {
    signed.delete(channel);
}

/**
 * Authorizes a channel for pusher-js. A log stream channel uses the signature its open response
 * returned, and only for the socket it was signed for. Every other channel asks the auth endpoint,
 * with the same request as pusher-js's own `ajax` transport.
 */
export const authorizeChannel: ChannelAuthorizationHandler = (
    { socketId, channelName },
    callback,
) => {
    if (channelName.startsWith(LOG_STREAM_PREFIX)) {
        const signature = signed.get(channelName);

        if (signature === undefined || signature.socketId !== socketId) {
            callback(new Error(`No signature for ${channelName} on socket ${socketId}.`), null);
        } else {
            callback(null, { auth: signature.auth });
        }

        return;
    }

    const body = `socket_id=${encodeURIComponent(socketId)}&channel_name=${encodeURIComponent(channelName)}`;

    fetch(AUTH_ENDPOINT, {
        method: "POST",
        headers: {
            "Content-Type": "application/x-www-form-urlencoded",
            Accept: "application/json",
        },
        body,
    })
        .then(async (response) => {
            if (response.status !== 200) {
                callback(
                    new Error(
                        `Unable to retrieve auth string from channel-authorization endpoint - received status: ${response.status} from ${AUTH_ENDPOINT}.`,
                    ),
                    null,
                );

                return;
            }

            const text = await response.text();
            let data;

            try {
                data = JSON.parse(text);
            } catch {
                callback(
                    new Error(
                        `JSON returned from channel-authorization endpoint was invalid, yet status code was 200. Data was: ${text}`,
                    ),
                    null,
                );

                return;
            }

            callback(null, data);
        })
        .catch((error: unknown) =>
            callback(error instanceof Error ? error : new Error(String(error)), null),
        );
};
