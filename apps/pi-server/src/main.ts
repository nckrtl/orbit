import { registerBunOAuthFlows } from "@earendil-works/pi-ai/bun-oauth";
import { login } from "./login.ts";
import { piVersion, serve } from "./serve.ts";

// Pi loads sign-in flows by path at runtime, which a compiled binary does not contain.
// Register them statically, as Pi's own Bun binary does. Sign-in and token refresh need them.
registerBunOAuthFlows();

const [command, ...rest] = process.argv.slice(2);

if (command === "login") {
    process.exit(await login(rest));
} else if (command === "--version") {
    console.log(piVersion);
} else {
    // `pi-server serve [flags]` and `pi-server [flags]` both start the server.
    await serve(command === "serve" ? rest : process.argv.slice(2));
}
