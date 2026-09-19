export class GatewayError extends Error {
    readonly status: number;
    readonly code: string | null;

    constructor(message: string, status: number, code: string | null = null) {
        super(message);
        this.status = status;
        this.code = code;
    }
}

export type Method = "GET" | "POST" | "DELETE";

/** What carries a request to a Gateway: `fetch` by default, the in-memory demo Gateway in demo mode and tests. */
export type Transport = (
    method: Method,
    path: string,
    body: unknown,
) => Promise<{ status: number; payload: unknown }>;

/**
 * The Gateway identifies the caller by its WireGuard address, so there is no token to attach.
 * `Content-Type: application/json` makes every mutation a preflighted request, which no other
 * origin can send.
 */
const http: Transport = async (method, path, body) => {
    const response = await fetch(path, {
        method,
        headers: { Accept: "application/json", "Content-Type": "application/json" },
        ...(method === "GET" ? {} : { body: JSON.stringify(body ?? {}) }),
    });

    return { status: response.status, payload: await response.json().catch(() => null) };
};

let transport: Transport = http;
let label: string | null = null;

/** Replaces `fetch` as the carrier. `name` is what the header shows instead of the Gateway's host. */
export function setTransport(next: Transport | null, name: string | null = null): void {
    transport = next ?? http;
    label = next === null ? null : name;
}

export const transportLabel = (): string | null => label;

/** One Gateway API call: the `data` of a successful answer, or a GatewayError with the Gateway's own message. */
export async function api<T>(method: Method, path: string, body?: unknown): Promise<T> {
    const { status, payload } = await transport(method, path, body);
    const answer = payload as { data?: T; error?: { code?: string; message?: string } } | null;

    if (status < 200 || status >= 300 || answer === null) {
        throw new GatewayError(
            answer?.error?.message ?? `The Gateway answered ${status}.`,
            status,
            answer?.error?.code ?? null,
        );
    }

    return answer.data as T;
}

export const get = <T>(path: string): Promise<T> => api<T>("GET", path);
