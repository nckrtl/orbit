// A deterministic OpenAI-compatible provider for the orbit-worker Incus proof.
// Only the model response is a fixture. Pi executes this bash call itself.
const command = `set -euo pipefail
[ "$(id -un)" = orbit-worker ]
[ "$(stat -c %U .)" = orbit ]
if ls /home/orbit; then echo 'managed home was readable' >&2; exit 1; fi
if cat /home/orbit/worker-proof-private; then echo 'managed private file was readable' >&2; exit 1; fi
echo WORKER_HOME_DENIED
if sudo -n true; then echo 'worker had sudo' >&2; exit 1; fi
echo WORKER_SUDO_DENIED
if ssh -o BatchMode=yes -o StrictHostKeyChecking=no -o UserKnownHostsFile=/dev/null -o ConnectTimeout=5 orbit@localhost true; then echo 'worker could ssh as orbit' >&2; exit 1; fi
echo WORKER_SSH_DENIED
printf 'orbit-worker proof\\n' > worker-proof.txt
python3 -c 'import pathlib; assert pathlib.Path("worker-proof.txt").read_text() == "orbit-worker proof\\n"'
git status --porcelain
[ "$(git config --global --get-all safe.directory)" = "$PWD" ]
echo WORKER_EDIT_TEST_PASSED
incus list --format=json > .git/orbit/worker-incus.json
python3 -c 'import json; assert json.load(open(".git/orbit/worker-incus.json")) == []'
echo WORKER_INCUS_PASSED
.git/orbit/turn --thread=THREAD_ID --outcome=ready_for_review --summary='Worker boundaries, file edit, test and Incus passed' --deliverable=worker-file=worker-proof.txt
`;

Bun.serve({
    hostname: "127.0.0.1",
    port: 18317,
    async fetch(request) {
        if (new URL(request.url).pathname === "/health") return new Response("WORKER_PROVIDER_READY\n");
        if (new URL(request.url).pathname !== "/v1/chat/completions" || request.method !== "POST") {
            return new Response("not found", { status: 404 });
        }
        const body = await request.json() as { messages: { role: string; content: unknown }[] };
        const completed = body.messages.some((message) => message.role === "tool");
        const prompt = JSON.stringify(body.messages);
        const thread = prompt.match(/--thread=(\d+)/)?.[1];
        if (!thread) return new Response("missing Orbit thread", { status: 422 });
        const delta = completed
            ? { content: "The worker proof tool call finished. See its exit status and receipt." }
            : { tool_calls: [{ index: 0, id: "worker-proof-call", type: "function", function: {
                name: "bash", arguments: JSON.stringify({ command: command.replace("THREAD_ID", thread), timeout: 60 }),
            } }] };
        const chunk = (value: unknown) => `data: ${JSON.stringify(value)}\n\n`;
        const common = { id: "worker-proof-response", object: "chat.completion.chunk", created: Math.floor(Date.now() / 1000), model: "worker-proof" };
        return new Response(
            chunk({ ...common, choices: [{ index: 0, delta: { role: "assistant", ...delta }, finish_reason: null }] })
            + chunk({ ...common, choices: [{ index: 0, delta: {}, finish_reason: completed ? "stop" : "tool_calls" }] })
            + "data: [DONE]\n\n",
            { headers: { "Content-Type": "text/event-stream" } },
        );
    },
});
console.log("WORKER_PROVIDER_READY");
