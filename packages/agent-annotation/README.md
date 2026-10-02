# @nckrtl/annotator

Browser annotations with voice input and a local task server for coding agents. Requires Node.js 22 or newer for the CLI.

Install the toolbar in your development project:

```sh
npm install --save-dev @nckrtl/annotator
```

Mount it once from your browser entry point:

```ts
import { mountAnnotation } from "@nckrtl/annotator";

const annotator = mountAnnotation();
// Call annotator.destroy() when tearing down the host application.
```

Start the local server with `npx @nckrtl/annotator serve`, then open the toolbar settings, choose **Local server**, paste the printed URL, and save.

Orbit mode requires a configured annotation service URL, an enabled tasks extension, and access to the Instance annotation endpoint. Settings checks these requirements and shows why Orbit is unavailable. The T3 thread ID selects the session that receives the task. Hosts can override the tasks status endpoint with `orbit.tasksStatusUrl`; its default is `/api/v1/tasks/status` on the annotation service origin.

## Local annotation server

Run `npx @nckrtl/annotator serve` and choose **Local server** in **Delivery mode**, and paste the printed annotation URL into **Annotation server URL** in the toolbar settings. The URL is remembered for the tab across refreshes. Select **Orbit** to use the host integration with a thread ID. Clearing a thread ID keeps it empty after saving and refreshing. A failed submission stays in the browser for manual retry, without falling back to Orbit or T3.

The server binds to `127.0.0.1` on a random available port. Use `--port 29703` for a fixed port. Use `--host` without an IP to bind to `0.0.0.0`; the printed URL then uses the machine's first network address. Each server has a separate port and store. The URL is `http://127.0.0.1:<port>/annotations`. Local-network browser permission may be required. On mobile, loopback refers to the phone, not the development machine.

To run the server in the background, use `npx @nckrtl/annotator start --state storage/annotator.json`. It returns when the server answers and prints the state as JSON: PID, port, URL, skill URL, and store. `status --state FILE` reports whether that server runs and removes a state file left by a crashed server. `stop --state FILE` stops it with SIGTERM, so the store closes cleanly; it never signals a process that does not answer as the annotation server. Output goes to a `.log` file next to the state file. `serve --state FILE` writes the same file for a foreground server.

By default, annotations are saved in a new temporary directory printed at startup. To resume them later, run `npx @nckrtl/annotator serve --store /path/to/session`. If the port changes after restarting, paste the new URL into settings.

Each annotation has one JSON file. Its directory matches its state:

```text
/tmp/annotate-XXXXXX/
  todo/<id>.json
  in-progress/<id>.json
  done/<id>.json
```

The server also prints a **Skill URL** at startup: `http://127.0.0.1:<port>/skill`. Give this URL to an LLM to load a short Markdown skill with the running server’s annotation URL and instructions to watch for annotations, claim them, and complete or release them. The skill does not set up the server or the browser settings; the user does that. `GET /annotations` advertises it as `meta.skillUrl`; the development bridge also serves it. Reading the skill does not claim or change work.

Use the printed URL as `$ANNOTATIONS_URL`. A monitor claims work, then completes or releases it:

```sh
curl -X POST "$ANNOTATIONS_URL/claim"
curl -X POST "$ANNOTATIONS_URL/complete" \
  -H 'Content-Type: application/json' \
  --data '{"id":"ID","summary":"Updated the button and verified it."}'
curl -X POST "$ANNOTATIONS_URL/release" \
  -H 'Content-Type: application/json' --data '{"id":"ID"}'
curl "$ANNOTATIONS_URL"
curl -N "$ANNOTATIONS_URL/events"
```

`POST /claim`, `/complete`, and `/release` also work at the server root. Claim takes the oldest todo annotation, moves it into `in-progress/`, and returns `{data: annotation}` with status `in_progress`. It returns `204` when empty. Concurrent monitors receive different annotations. Completion accepts an in-progress ID and optional summary, moves the file into `done/`, and sets status `done`. Repeating completion succeeds without changing the record. Release moves an in-progress annotation back to `todo/` with status `todo`. Invalid transitions return `409`; unknown IDs return `404`.

`GET /annotations` returns `{data: [...]}`, including completed records. `POST /annotations` creates work; repeated IDs return the existing record without resetting it. The existing `POST /annotations/:id/status` endpoint remains available and accepts both `todo`/`done` and the older `pending`/`resolved` names. Cancelled annotations are kept in `done/` with status `cancelled`. The toolbar translates these states to its existing pending, in-progress, and resolved markers.

One server owns a store directory at a time. Claims run without yielding between selecting and moving the file. Writes finish before HTTP acknowledgement. In-progress work survives restarts and stays claimed until completed or released; there is no automatic lease expiry. Old single-file stores passed to `--store` are migrated, preserving the original as a `.legacy` backup. The server watches the three directories, and file changes trigger browser updates. Write external JSON edits atomically; moving a record between the directories also updates its status when the server reads it.

Local annotations receive a stable `number` from the server. Numbers increase across the store and do not change when work completes or is released. The toolbar keeps the session counter after completion and uses the same numbers on pins. Reusing a store preserves its numbering; a new store starts at 1. The server prints one activity line per creation or status change, such as `#1 created: Fix the heading`, `#1 in progress: Fix the heading`, and `#1 done: Fix the heading`. Repeated submissions and unchanged statuses do not produce duplicate activity lines.

In Local server mode, deleting a pin removes its JSON file and logs `#1 deleted: Fix the heading`. The bin icon removes all annotations from the connected store, including completed work, and logs `All annotations removed`. `DELETE /annotations/{id}` removes one record; `DELETE /annotations` clears the store, and `DELETE /annotations?pathname=/page` removes only the annotations of that page. Hosts call `clearPageAnnotations()` for the page-scoped removal. Connected browsers receive deletion updates, including after reconnect. The sequence counter is preserved. Failed deletions keep the pins visible and show an error so the action can be retried.

The local server does not launch agents or deliver T3 messages. Orbit remains an alternative implementation of the annotation API, with its existing task storage and routing.

When the browser runs on a different machine, its loopback address does not reach the server. Use `--host` to bind to all addresses, or `--host <private-IP>` to bind to one private network address. An HTTPS page also requires an HTTPS endpoint for a remote server; a reverse proxy can forward it to the local annotation process. The proxy changes transport only: annotations remain in the local store and are not sent to Orbit tasks or T3.

## Browsers on another machine

For a Vite application, add the package’s `annotationServerProxy()` plugin once. No annotation port or URL belongs in the Vite configuration:

```ts
import { annotationServerProxy } from "@nckrtl/annotator/vite";

export default { plugins: [annotationServerProxy()] };
```

Keep pasting the URL printed by `serve` into the toolbar. The plugin makes loopback URLs refer to the development machine, even when the browser runs elsewhere. Requests and live events use the page’s origin, so HTTPS works without a separate certificate for the annotation server. Random ports work across server restarts after saving the new URL. The bridge verifies that the target is an annotation server before forwarding requests. It is development-only and does not store or deliver tasks.

## Host toolbar integration

Pass `floatingControl: false` to `mountAnnotation` to keep the overlay and keyboard
shortcuts while supplying your own toolbar controls. `getAnnotationState` and
`subscribeAnnotationState` expose a framework-independent, stable snapshot for
`useSyncExternalStore` or other UI frameworks. The snapshot includes active mode,
count, connection, removal errors, Orbit availability, and thread selection.

Use `getAnnotationSettings()` to populate a native settings form and
`saveAnnotationSettings({ mode: "server", serviceUrl: url })` to save it for this
tab. For Orbit, pass `mode: "orbit"` and optionally `threadId` (an empty string
explicitly clears it). `checkAnnotationServer(url, signal)` validates the local
endpoint; `checkOrbit()` checks host-provided Orbit support. These APIs share the
floating UI's storage and transport. `toggleAnnotationMode` and
`clearAllAnnotations` also work with either UI.

Mark a host toolbar container with `data-feedback-toolbar` so clicks inside it
are excluded from annotation targeting. Call the mount handle's `destroy()` when
the host is removed. Only mount one annotation integration per page.
