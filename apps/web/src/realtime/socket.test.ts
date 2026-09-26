import { afterEach, beforeEach, expect, it, vi } from "vite-plus/test";
import { authorizeChannel, forgetLogStream, signLogStream } from "./socket";

type Result = { error: Error | null; data: unknown };

function authorize(channelName: string, socketId = "123.456"): Promise<Result> {
    return new Promise((resolve) =>
        authorizeChannel({ socketId, channelName }, (error, data) => resolve({ error, data })),
    );
}

let fetchMock: ReturnType<typeof vi.fn>;

beforeEach(() => {
    fetchMock = vi.fn();
    vi.stubGlobal("fetch", fetchMock);
});

afterEach(() => {
    forgetLogStream("private-log-stream.abc");
    vi.unstubAllGlobals();
});

it.each(["private-orbit", "presence-node.7"])(
    "asks the browser auth endpoint for %s, as pusher-js's ajax transport does",
    async (channel) => {
        const signature = { auth: "key:signature", channel_data: '{"user_id":"browser.1"}' };
        fetchMock.mockResolvedValue(new Response(JSON.stringify(signature), { status: 200 }));

        const result = await authorize(channel);

        expect(result).toEqual({ error: null, data: signature });
        expect(fetchMock).toHaveBeenCalledExactlyOnceWith("/api/v1/broadcasting/auth", {
            method: "POST",
            headers: {
                "Content-Type": "application/x-www-form-urlencoded",
                Accept: "application/json",
            },
            body: `socket_id=123.456&channel_name=${encodeURIComponent(channel)}`,
        });
    },
);

it("reports a refusal or an invalid answer from the auth endpoint as an error", async () => {
    fetchMock.mockResolvedValueOnce(new Response("{}", { status: 403 }));
    fetchMock.mockResolvedValueOnce(new Response("not json", { status: 200 }));
    fetchMock.mockRejectedValueOnce(new TypeError("Failed to fetch"));

    for (const expected of [/status: 403/, /invalid/, /Failed to fetch/]) {
        const result = await authorize("private-orbit");
        expect(result.data).toBeNull();
        expect(result.error?.message).toMatch(expected);
    }
});

it("uses the stored signature for a log stream channel and never asks the auth endpoint", async () => {
    signLogStream("private-log-stream.abc", "123.456", "key:stream-signature");

    expect(await authorize("private-log-stream.abc")).toEqual({
        error: null,
        data: { auth: "key:stream-signature" },
    });
    expect(fetchMock).not.toHaveBeenCalled();
});

it("refuses a log stream channel with no signature, or one signed for another socket", async () => {
    signLogStream("private-log-stream.abc", "123.456", "key:stream-signature");

    const otherSocket = await authorize("private-log-stream.abc", "789.012");
    const unknown = await authorize("private-log-stream.def");
    forgetLogStream("private-log-stream.abc");
    const forgotten = await authorize("private-log-stream.abc");

    for (const result of [otherSocket, unknown, forgotten]) {
        expect(result.data).toBeNull();
        expect(result.error).toBeInstanceOf(Error);
    }
    expect(fetchMock).not.toHaveBeenCalled();
});
