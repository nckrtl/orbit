import { useQuery } from "@tanstack/react-query";
import { useEffect, useRef, useState } from "react";
import { api, GatewayError } from "../api/client";
import { instanceLogsQuery, POLL_SECONDS, processLogsQuery } from "../api/queries";
import { useLiveness } from "./liveness";
import {
    forgetLogStream,
    LOG_STREAM_PREFIX,
    type RealtimeChannel,
    type RealtimeSocket,
    signLogStream,
    useRealtimeSocket,
} from "./socket";

// Local types for the log stream endpoints and events (docs/reference/live-logs.md), until
// `src/api/schema.d.ts` is regenerated from docs/openapi.json.

/** The record whose log a stream follows, as its API path segment. */
export type LogStreamKind = "instances" | "processes";

/** `data` of `POST /api/v1/{instances|processes}/{id}/log-streams` (201). */
export type LogStreamOpened = {
    id: string;
    channel: string;
    auth: string;
    lines: number;
    lease_seconds: number;
    renew_seconds: number;
};

/** `data` of `PUT /api/v1/{instances|processes}/{id}/log-streams/{stream}` (200). */
export type LogStreamRenewed = { id: string; lease_seconds: number };

/** `data` of `DELETE /api/v1/{instances|processes}/{id}/log-streams/{stream}` (200). */
export type LogStreamClosed = { id: string; closed: true };

/** `data` of the `log.lines` event on `private-log-stream.{stream}`. */
export type LogLinesEvent = { sequence: number; lines: string[]; dropped: number; skipped: number };

export type LogEndedReason =
    | "closed"
    | "expired"
    | "revoked"
    | "agent_left"
    | "source_unavailable"
    | "relay_behind";

/** `data` of the `log.ended` event on `private-log-stream.{stream}`. */
export type LogEndedEvent = { reason: LogEndedReason };

/** The most lines a pane keeps from a stream. Older lines drop off the top. */
export const LOG_TAIL_MAX_LINES = 2_000;

/** The renewal interval when the open response names none. */
const DEFAULT_RENEW_SECONDS = 20;

/**
 * - `off`: realtime is not live, so the pane polls.
 * - `opening`: the stream is being opened; the pane does not poll.
 * - `streaming`: the stream runs; the pane does not poll.
 * - `fallback`: the Gateway refused the stream or it ended; the pane polls until the socket changes.
 * - `revoked`: a renewal found no access edge; the pane stops.
 */
export type LogTailStatus = "off" | "opening" | "streaming" | "fallback" | "revoked";

export type LogTailState = {
    status: LogTailStatus;
    /** The lines from the latest stream, markers included, or null before the first stream sent any. */
    lines: string[] | null;
    /** When `lines` last changed, in milliseconds, to compare with the poll's `dataUpdatedAt`. */
    updatedAt: number;
    /** True once the current stream's channel is subscribed. */
    subscribed: boolean;
    /** Why the pane falls back or stopped: a `logs.live_unavailable` reason, an error code, or an end reason. */
    reason: string | null;
};

export const initialLogTailState: LogTailState = {
    status: "off",
    lines: null,
    updatedAt: 0,
    subscribed: false,
    reason: null,
};

export type LogSource = { kind: LogStreamKind; id: number; lines: number };

type Stream = {
    id: string;
    channel: string;
    socket: RealtimeSocket;
    bound: RealtimeChannel;
    renewSeconds: number;
    timer: ReturnType<typeof setTimeout> | undefined;
    /** Whether this stream sent lines yet: its first lines replace the pane, later lines append. */
    received: boolean;
    /** Whether the pane renewed it yet. The Gateway starts the agent's reading on the first renewal. */
    activated: boolean;
    sequence: number;
};

/** The marker lines for what a `log.lines` event reports as lost. */
export function logMarkers(dropped: number, skipped: number): string[] {
    const markers: string[] = [];

    if (dropped > 0) {
        markers.push(`[orbit] ${dropped} ${dropped === 1 ? "line" : "lines"} dropped`);
    }

    if (skipped > 0) {
        markers.push(`[orbit] ${(skipped / 1024 / 1024).toFixed(1)} MiB skipped`);
    }

    return markers;
}

/** Whether the pane polls the one-shot read: only while no stream runs or opens, and never after a revocation. */
export function logTailPolls(status: LogTailStatus): boolean {
    return status === "off" || status === "fallback";
}

/** The newer of the streamed lines and the polled lines. */
export function pickLogLines(
    state: LogTailState,
    polled: string[] | undefined,
    polledAt: number,
): string[] | undefined {
    if (state.lines === null) {
        return polled;
    }

    if (polled === undefined) {
        return state.lines;
    }

    return polledAt > state.updatedAt ? polled : state.lines;
}

/** The `data` of an event envelope `{ type, id, at, data }`, or null when it is not one for `streamId`. */
function eventData(payload: unknown, streamId: string): Record<string, unknown> | null {
    let event: unknown = payload;

    if (typeof payload === "string") {
        try {
            event = JSON.parse(payload);
        } catch {
            return null;
        }
    }

    if (typeof event !== "object" || event === null) {
        return null;
    }

    const { id, data } = event as { id?: unknown; data?: unknown };

    if (typeof id === "string" && id !== streamId) {
        return null;
    }

    return typeof data === "object" && data !== null ? (data as Record<string, unknown>) : null;
}

const count = (value: unknown): number =>
    typeof value === "number" && Number.isFinite(value) && value > 0 ? value : 0;

function failureReason(error: unknown): string {
    if (error instanceof GatewayError) {
        const details = error.details as { reason?: unknown } | null;

        if (typeof details?.reason === "string") {
            return details.reason;
        }

        return error.code ?? `http_${error.status}`;
    }

    return error instanceof Error ? error.message : String(error);
}

/**
 * The live tail of one log. `connect` hands it the shared socket while realtime is live, or null;
 * it opens, renews, and closes the stream, and reports each change through `onChange`.
 */
export class LogTail {
    private state: LogTailState = initialLogTailState;
    private socket: RealtimeSocket | null = null;
    private stream: Stream | null = null;
    /** Bumped whenever the pane stops caring about an answer that is still on its way. */
    private attempt = 0;
    private disposed = false;
    private readonly source: LogSource;
    private readonly onChange: (state: LogTailState) => void;

    constructor(source: LogSource, onChange: (state: LogTailState) => void) {
        this.source = source;
        this.onChange = onChange;
    }

    get current(): LogTailState {
        return this.state;
    }

    private get path(): string {
        return `/api/v1/${this.source.kind}/${this.source.id}/log-streams`;
    }

    /** The socket while realtime is live, or null while it is down. A new socket ID opens a new stream. */
    connect(socket: RealtimeSocket | null): void {
        if (this.disposed) {
            return;
        }

        if (socket === null) {
            this.socket = null;
            this.end(true);

            if (this.state.status !== "revoked") {
                this.set({ status: "off", subscribed: false, reason: null });
            }

            return;
        }

        if (this.socket?.socketId === socket.socketId) {
            this.socket = socket;

            return;
        }

        this.socket = socket;
        this.end(true);

        if (this.state.status !== "revoked") {
            this.open();
        }
    }

    /** Closes the stream for good, when the pane closes. */
    dispose(): void {
        this.end(true);
        this.disposed = true;
    }

    /** Closes the stream with a request that outlives the page, which is unloading. */
    unload(): void {
        if (this.stream === null && this.state.status !== "opening") {
            return;
        }

        this.end(true, true);
        this.socket = null;
        this.set({ status: "fallback", subscribed: false, reason: "unload" });
    }

    private set(patch: Partial<LogTailState>): void {
        this.state = { ...this.state, ...patch };
        this.onChange(this.state);
    }

    private open(): void {
        const socket = this.socket;

        if (socket === null) {
            return;
        }

        const attempt = ++this.attempt;
        this.set({ status: "opening", subscribed: false, reason: null });

        api<LogStreamOpened>("POST", this.path, {
            socket_id: socket.socketId,
            lines: this.source.lines,
        }).then(
            (opened) => {
                if (attempt !== this.attempt || this.disposed) {
                    // The pane moved on while the Gateway opened it. Nobody will renew it; close it now.
                    this.close(opened.id);

                    return;
                }

                if (!opened.channel.startsWith(LOG_STREAM_PREFIX)) {
                    this.close(opened.id);
                    this.fallback("invalid_channel");

                    return;
                }

                this.subscribe(socket, opened);
            },
            (error: unknown) => {
                if (attempt === this.attempt && !this.disposed) {
                    this.fallback(failureReason(error));
                }
            },
        );
    }

    private subscribe(socket: RealtimeSocket, opened: LogStreamOpened): void {
        // The signature must be in place before pusher-js asks for it, which `subscribe` may do at once.
        signLogStream(opened.channel, socket.socketId, opened.auth);
        const bound = socket.subscribe(opened.channel);
        const stream: Stream = {
            id: opened.id,
            channel: opened.channel,
            socket,
            bound,
            renewSeconds: opened.renew_seconds > 0 ? opened.renew_seconds : DEFAULT_RENEW_SECONDS,
            timer: undefined,
            received: false,
            activated: false,
            sequence: 0,
        };
        this.stream = stream;

        // The Gateway opens a stream inactive and starts it on the first renewal, so no line is
        // published before this pane listens. Renew once subscribed, then every `renew_seconds`.
        bound.bind("pusher:subscription_succeeded", () => {
            if (this.stream === stream && !stream.activated) {
                stream.activated = true;
                this.set({ subscribed: true });
                this.renew(stream);
            }
        });
        bound.bind("pusher:subscription_error", () => {
            if (this.stream === stream) {
                this.end(true);
                this.fallback("subscription_error");
            }
        });
        bound.bind("log.lines", (payload) => this.receive(stream, payload));
        bound.bind("log.ended", (payload) => this.ended(stream, payload));

        this.set({ status: "streaming" });
    }

    private receive(stream: Stream, payload: unknown): void {
        const data = this.stream === stream ? eventData(payload, stream.id) : null;

        if (data === null) {
            return;
        }

        const sequence = typeof data.sequence === "number" ? data.sequence : null;

        if (sequence !== null) {
            if (sequence <= stream.sequence) {
                return;
            }

            stream.sequence = sequence;
        }

        const lines = Array.isArray(data.lines)
            ? data.lines.filter((line): line is string => typeof line === "string")
            : [];
        const incoming = [...logMarkers(count(data.dropped), count(data.skipped)), ...lines];
        const base = stream.received ? (this.state.lines ?? []) : [];
        const next = base.length === 0 ? incoming : base.concat(incoming);
        stream.received = true;

        this.set({
            lines: next.length > LOG_TAIL_MAX_LINES ? next.slice(-LOG_TAIL_MAX_LINES) : next,
            updatedAt: Date.now(),
        });
    }

    private ended(stream: Stream, payload: unknown): void {
        const data = this.stream === stream ? eventData(payload, stream.id) : null;

        if (data === null) {
            return;
        }

        const reason = typeof data.reason === "string" ? data.reason : "closed";
        this.end(false);

        if (reason === "expired") {
            this.open();
        } else if (reason === "revoked") {
            this.set({ status: "revoked", subscribed: false, reason });
        } else {
            // `agent_left`, `source_unavailable`, `relay_behind`, and a `closed` this pane did not ask for.
            this.fallback(reason);
        }
    }

    private scheduleRenewal(stream: Stream): void {
        stream.timer = setTimeout(() => this.renew(stream), stream.renewSeconds * 1000);
    }

    private renew(stream: Stream): void {
        if (this.stream !== stream) {
            return;
        }

        api<LogStreamRenewed>("PUT", `${this.path}/${stream.id}`).then(
            () => {
                if (this.stream === stream) {
                    this.scheduleRenewal(stream);
                }
            },
            (error: unknown) => {
                if (this.stream !== stream) {
                    return;
                }

                if (error instanceof GatewayError && error.code === "logs.stream_not_found") {
                    // The stream ended without the pane hearing it. Try again; a refusal falls back.
                    this.end(false);
                    this.open();
                } else if (error instanceof GatewayError && error.status === 403) {
                    this.end(false);
                    this.set({ status: "revoked", subscribed: false, reason: "revoked" });
                } else {
                    // A failed renewal leaves the lease running; the next one may get through.
                    this.scheduleRenewal(stream);
                }
            },
        );
    }

    private fallback(reason: string): void {
        this.set({ status: "fallback", subscribed: false, reason });
    }

    /** Stops listening to the current stream, and closes it on the Gateway when `close` is set. */
    private end(close: boolean, keepalive = false): void {
        this.attempt++;
        const stream = this.stream;

        if (stream === null) {
            return;
        }

        this.stream = null;
        clearTimeout(stream.timer);
        stream.bound.unbind_all();
        stream.socket.unsubscribe(stream.channel);
        forgetLogStream(stream.channel);

        if (close) {
            this.close(stream.id, keepalive);
        }
    }

    /** Best effort: a stream that is not closed ends with its lease. */
    private close(id: string, keepalive = false): void {
        api<LogStreamClosed>(
            "DELETE",
            `${this.path}/${id}`,
            undefined,
            keepalive ? { keepalive: true } : undefined,
        ).catch(() => undefined);
    }
}

export type LogTailView = {
    lines: string[] | undefined;
    loading: boolean;
    /** Set when the pane stopped: the reader has no access to the log any more. */
    error: string | null;
    /** True while a stream runs. */
    live: boolean;
    status: LogTailStatus;
};

/**
 * One log pane's lines: a live stream while realtime is live, and the 10-second one-shot poll
 * while realtime is down or the Gateway cannot stream. The pane never polls while a stream runs.
 */
export function useLogTail(kind: LogStreamKind, id: number, lines: number): LogTailView {
    const socket = useRealtimeSocket();
    const liveness = useLiveness();
    const [state, setState] = useState<LogTailState>(initialLogTailState);
    const tail = useRef<LogTail | null>(null);

    useEffect(() => {
        const next = new LogTail({ kind, id, lines }, setState);
        const onPageHide = () => next.unload();
        tail.current = next;
        setState(next.current);
        window.addEventListener("pagehide", onPageHide);

        return () => {
            window.removeEventListener("pagehide", onPageHide);
            next.dispose();

            if (tail.current === next) {
                tail.current = null;
            }
        };
    }, [kind, id, lines]);

    useEffect(() => {
        tail.current?.connect(liveness === "live" ? socket : null);
    }, [kind, id, lines, liveness, socket]);

    // Right after mount the tail is still `off` while realtime is already live: the effect is about
    // to open a stream, so the pane must not start a one-shot read first.
    const polls =
        state.status === "off"
            ? !(liveness === "live" && socket !== null)
            : logTailPolls(state.status);
    const query = useQuery({
        ...(kind === "instances" ? instanceLogsQuery(id) : processLogsQuery(id)),
        enabled: polls,
        refetchInterval: polls ? POLL_SECONDS * 1000 : false,
    });
    const picked = pickLogLines(state, query.data, query.dataUpdatedAt);

    if (state.status === "revoked") {
        return {
            lines: undefined,
            loading: false,
            error: "Access to this log was revoked.",
            live: false,
            status: state.status,
        };
    }

    return {
        lines: picked ?? (state.subscribed ? [] : undefined),
        // Before a stream's channel is subscribed, or while the first poll is on its way.
        loading: picked === undefined && (polls ? query.isPending : !state.subscribed),
        error: null,
        live: state.status === "streaming",
        status: state.status,
    };
}
