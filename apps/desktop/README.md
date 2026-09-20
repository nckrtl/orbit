# Orbit desktop

The desktop shell for the Orbit web app, built with Tauri 2. It renders
`apps/web` and will host the native bridge (CA trust, local DNS) that a browser
page cannot perform.

## Development

```bash
bun install
bun run dev
```

`bun run dev` starts the Vite dev server for `apps/web` and opens the desktop
window against it, with hot module replacement. The dev server's existing
proxy carries this machine's WireGuard identity and the Orbit CA to the
Gateway, so the shell needs no extra setup in development.

## Production

The release build loads the web app served by the Gateway itself
(`frontendDist` is a URL, not bundled assets). The Gateway already serves the
built app, and keeping `/api` same-origin avoids duplicating its proxying,
identity, and trust behavior inside the shell. Revisit bundling only if the
shell needs to work without a reachable Gateway.
