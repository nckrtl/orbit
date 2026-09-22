import { describe, expect, it } from "vite-plus/test";
import { interaction, login, type LoginIo } from "../src/login.ts";

function recorder(answers: string[] = []): LoginIo & { output: string } {
    const io = {
        output: "",
        write: (text: string) => {
            io.output += text;
        },
        ask: async (question: string) => {
            io.output += question;
            return answers.shift() ?? "";
        },
    };

    return io;
}

describe("login", () => {
    it("refuses Anthropic before any sign-in flow starts", async () => {
        const io = recorder();

        expect(await login(["anthropic", "--agent-dir=/nonexistent"], io)).toBe(1);
        expect(io.output).toContain("only in its own applications");
    });

    it("requires exactly one provider", async () => {
        const io = recorder();

        expect(await login([], io)).toBe(2);
        expect(io.output).toContain("Usage: pi-server login <provider>");
    });

    it("shows a device code so an operator can approve it from another machine", () => {
        const io = recorder();
        interaction(io).notify({
            type: "device_code",
            userCode: "ABCD-1234",
            verificationUri: "https://auth.example/device",
        });

        expect(io.output).toBe("Open https://auth.example/device and enter the code ABCD-1234.\n");
    });

    it("returns the id of the chosen option", async () => {
        const io = recorder(["2"]);
        const choice = await interaction(io).prompt({
            type: "select",
            message: "Sign-in method",
            options: [
                { id: "browser", label: "Browser" },
                {
                    id: "device_code",
                    label: "Device code",
                    description: "For machines without a browser",
                },
            ],
        });

        expect(choice).toBe("device_code");
        expect(io.output).toContain("2. Device code - For machines without a browser");
    });

    it("refuses an option outside the list", async () => {
        const prompt = interaction(recorder(["7"])).prompt({
            type: "select",
            message: "Method",
            options: [{ id: "browser", label: "Browser" }],
        });

        await expect(prompt).rejects.toThrow("Choose one of the listed numbers.");
    });
});
