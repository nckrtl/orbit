import { join } from "node:path";
import { createInterface } from "node:readline/promises";
import { parseArgs } from "node:util";
import type { AuthEvent, AuthInteraction, AuthPrompt } from "@earendil-works/pi-ai";
import { getAgentDir, ModelRuntime } from "@earendil-works/pi-coding-agent";

/** Subscription providers whose terms permit use in Pi. Anthropic's do not. */
const REFUSED = new Map([
    ["anthropic", "Anthropic permits Claude subscription sign-ins only in its own applications."],
]);

export interface LoginIo {
    write: (text: string) => void;
    ask: (question: string) => Promise<string>;
}

/**
 * Signs in to a provider with its subscription (OAuth) flow and stores the credential in Pi's
 * directory, where the server reads it. Run it on the Node as the user that runs the server.
 *
 *   pi-server login openai-codex [--agent-dir=DIR]
 */
export async function login(argv: string[], io: LoginIo = terminal()): Promise<number> {
    const { values, positionals } = parseArgs({
        args: argv,
        strict: true,
        allowPositionals: true,
        options: { "agent-dir": { type: "string" } },
    });
    const provider = positionals[0];
    if (provider === undefined || positionals.length !== 1) {
        io.write("Usage: pi-server login <provider> [--agent-dir=DIR]\n");
        return 2;
    }
    const refusal = REFUSED.get(provider);
    if (refusal !== undefined) {
        io.write(`${refusal}\n`);
        return 1;
    }

    const agentDir = values["agent-dir"] ?? getAgentDir();
    const runtime = await ModelRuntime.create({
        authPath: join(agentDir, "auth.json"),
        modelsPath: join(agentDir, "models.json"),
    });
    if (runtime.getProvider(provider) === undefined) {
        io.write(`Unknown provider ${provider}.\n`);
        return 1;
    }

    await runtime.login(provider, "oauth", interaction(io));
    const subscription = runtime.isUsingSubscription(provider);
    io.write(
        subscription
            ? `Signed in to ${provider} with a subscription.\n`
            : `Signed in to ${provider}, but Pi does not mark this sign-in as a subscription. The server will not use it unless API keys are allowed.\n`,
    );

    return 0;
}

export function interaction(io: LoginIo): AuthInteraction {
    return {
        notify: (event: AuthEvent) => io.write(describe(event)),
        prompt: async (prompt: AuthPrompt) => {
            if (prompt.type !== "select") {
                return (await io.ask(`${prompt.message} `)).trim();
            }
            prompt.options.forEach((option, index) =>
                io.write(
                    `  ${index + 1}. ${option.label}${option.description === undefined ? "" : ` - ${option.description}`}\n`,
                ),
            );
            const answer = Number(
                (await io.ask(`${prompt.message} [1-${prompt.options.length}] `)).trim(),
            );
            const choice = prompt.options[answer - 1];
            if (!Number.isInteger(answer) || choice === undefined) {
                throw new Error("Choose one of the listed numbers.");
            }

            return choice.id;
        },
    };
}

function describe(event: AuthEvent): string {
    switch (event.type) {
        case "device_code":
            return `Open ${event.verificationUri} and enter the code ${event.userCode}.\n`;
        case "auth_url":
            return `Open this address to sign in:\n${event.url}\n${event.instructions === undefined ? "" : `${event.instructions}\n`}`;
        case "info":
        case "progress":
            return `${event.message}\n`;
    }
}

function terminal(): LoginIo {
    const lines = createInterface({ input: process.stdin, output: process.stdout });

    return {
        write: (text) => process.stdout.write(text),
        ask: (question) => lines.question(question),
    };
}
