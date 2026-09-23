---
title: "Agent annotation package"
description: "Install the annotation overlay and configure its speech endpoint."
---

# Agent annotation package

`@nckrtl/annotate` installs a floating annotation control into its own Shadow DOM. It captures element context and screenshots and stores annotations in browser local storage. Orbit web uses the same package. A host application can mount it without rendering a React component. The comment field opens above the buttons with a “Listening” placeholder. Recording and text entry keep this layout without an expansion animation.

## Install and mount

Build the package with `bun install` and `bun run build` in `packages/agent-annotation`, then run `npm pack` there. Install the resulting archive in the target project. The package includes React and its styles in the browser bundle; the host does not need Tailwind or React.

```ts
import { mountAnnotation } from "@nckrtl/annotate";

const annotation = mountAnnotation({
    dictation: {
        wsUrl: "wss://speech.example.com/v1/audio/stream",
        autoStart: false,
    },
});

// Remove controls, listeners, and microphone capture when the host shuts down.
annotation.destroy();
```

For script injection, serve `dist/inject.js` and set `window.__AGENT_ANNOTATION__` to the same options before loading the script. The script mounts after the document is ready. It exposes `window.AgentAnnotation` with the package API.

## Speech configuration

Speech is disabled when no URL is configured. `dictation.wsUrl` accepts a `ws://` or `wss://` URL, or a route such as `/speech/stream` on the current origin. HTTP and HTTPS routes are converted to WebSocket URLs. An invalid scheme fails during configuration, before microphone capture. Set `autoStart: false` to record only when the microphone control is pressed. The default is `true`.

The endpoint must implement the Diction streaming protocol: binary Opus or PCM16 audio, a final `{"action":"done"}` message, and a `{"text":"..."}` response. The model is chosen by that service. This URL is not an arbitrary HTTP transcription API. Do not put provider API secrets in browser configuration.

Orbit web reads `VITE_ANNOTATION_TRANSCRIPTION_URL` and `VITE_ANNOTATION_AUTO_START` at startup. Set them in `apps/web/.env.local` and restart the dev server. An empty URL disables speech. Mobile browsers use the same endpoint and need HTTPS, microphone permission, and network access to that endpoint. Browsers block microphone access on HTTP network addresses. Use HTTPS or localhost. Recording failures appear below the comment field.

### Local development

For local development, set `ANNOTATION_TRANSCRIPTION_TARGET` to the Diction service origin, such as `http://127.0.0.1:8080`, and set `VITE_ANNOTATION_TRANSCRIPTION_URL=/__annotate/speech`. The dev server proxies that route to `/v1/audio/stream` on the service. This keeps the speech connection on the page origin. The page still needs HTTPS when accessed from another machine.

Explicit mount options override the legacy `window.__TOOLBAR_AGENTATION__.dictation` configuration. Legacy `ws_url` and `auto_start` fields remain supported.

## Host integration

The optional `commander` object accepts `enabled`, `project`, and `endpoint`. Submission is disabled by default. Set `serviceUrl` to use the Gateway annotation service. The optional Commander adapter is used only when no service URL is set and Commander is enabled. Annotations remain stored locally when submission fails.

The optional `getToolbarData` callback returns toolbar colors, font size, and current request context (`request.controller_action` and `request.route_name`). Laravel integrations can use it without making the package depend on toolbar source files. Existing toolbar host identifiers and Inertia navigation events remain supported. Migrating the Laravel extension and adding a desktop hotkey bridge are separate follow-up work.

## Local dictation trigger

Set `dictation.provider` to `post` and `dictation.postUrl` to `http://127.0.0.1:12321/dictate` to trigger a desktop dictation service instead of capturing audio in the browser. A new annotation focuses its comment field and sends one POST with no body. Any successful HTTP response acknowledges the trigger; the desktop service owns recording and text insertion. The microphone button can trigger another request. Failed requests show an error and do not start browser recording.

Orbit web accepts `VITE_ANNOTATION_DICTATION_PROVIDER=post` and `VITE_ANNOTATION_DICTATION_POST_URL=http://127.0.0.1:12321/dictate`. Set `autoStart: false` (or `VITE_ANNOTATION_AUTO_START=0`) for manual triggering only. The request goes directly from the browser to its own machine. The local service must allow the page origin through CORS; the browser may also request local-network permission.

### Stop and move to another element

Set `dictation.stopUrl` (web: `VITE_ANNOTATION_DICTATION_STOP_URL`) to `http://127.0.0.1:12321/dictate-stop`. In POST mode, clicking outside the current popup keeps the comment field focused, sends a bodyless POST to the stop endpoint, and waits for a new input event. After the request succeeds and input has settled for 500 ms, the tool saves the comment and opens a new annotation on the clicked element. The new popup triggers dictation as usual.

The placeholder changes from “Listening” to “Waiting for paste…” during the stop request. No extra status panel appears. Only the first outside click is queued while waiting. A failed stop request, an empty transcript, or no paste within 15 seconds leaves the current annotation open with an error. Escape cancels the pending transition. Local storage receives the annotation before the next popup opens; agent submission continues in the background. Without a configured stop URL, outside clicks retain the existing behavior.

### Request timing

Both local POST requests start without an intentional delay. The stop flow waits for the HTTP response and for 500 ms of settled input before moving to the next annotation. Browser Performance measures `annotate:dictate-request`, `annotate:dictate-stop-request`, and `annotate:paste-wait` record the latest request durations and the time spent waiting after the stop response. Request duration includes browser connection and permission handling as well as service response time; it is not a measurement of service processing alone.

## Clear annotations and keyboard shortcuts

The trash button beside the floating annotation toggle clears all saved annotations for this site, including other paths, and closes any open draft. It leaves annotation mode enabled or disabled as it was. Submitted work remains in the service. Clearing or deleting pins hides them in this browser; it does not cancel queued work or remove shared records.

Press Cmd+Shift+A on macOS or Ctrl+Shift+A on Windows and Linux to toggle annotation mode. Cmd+Shift+R or Ctrl+Shift+R clears all annotations. These shortcuts work while the comment field is focused. The clear shortcut replaces the browser reload action when the browser delivers that key combination to the page.

## T3 thread selection

On page load or refresh, automatic detection fills the T3 thread ID field when a match is available. Open the gear button in the annotation pill to edit and save a manual override. Clear the field and save to keep the thread ID empty. An explicit empty value disables automatic filling for that tab, including after refresh. Saving an unchanged automatic value keeps automatic detection enabled.

The override is stored in browser session storage, scoped to the site and tab. It survives refreshes and is cleared when the tab session ends. New tabs opened by another tab may inherit its initial session storage; later edits remain separate.

Hosts can pass `thread: { id, discoveryUrl }` to `mountAnnotation`. Orbit web accepts `VITE_ANNOTATION_THREAD_ID` and uses `/__annotate/thread` during development. The dev server reads the local T3 database in read-only mode and selects a single non-archived thread for this worktree. Missing data or multiple matches leave the thread unset. Manual selection takes priority over host configuration and detection.

New annotations capture the selected `threadId` when their draft opens. Existing annotations retain their thread ID. With an annotation service configured, the Gateway uses this ID to deliver the annotation to T3.

## Orbit delivery

Orbit mode requires a configured annotation service URL, an enabled tasks extension, and access to the Instance annotation endpoint. Settings checks these requirements and shows why Orbit is unavailable. The T3 thread ID selects the session that receives the task. Hosts can override the tasks status endpoint with `orbit.tasksStatusUrl`; its default is `/api/v1/tasks/status` on the annotation service origin.

The annotation service stores each submitted annotation before delivery. The selected T3 thread ID travels with the annotation; later changes to the setting do not reroute saved work. Requests use the annotation ID for idempotency. Missing thread IDs remain visible as delivery errors instead of being sent to another thread.

The service delivers pending annotations in order, with one unfinished annotation per T3 thread. It waits until the thread is idle before sending a message. The message includes the annotation context and instructions to report `in_progress` before editing, then `resolved` with a summary after verification. Successful message delivery alone does not mark work complete. Delivery failures retain the annotation and display the error.

### Reconnect and shared state

The browser receives change notifications through the Gateway's existing private Reverb channel. Orbit shares its existing WebSocket connection with the annotation tool. Standalone hosts configure `realtime.configUrl` (normally `/api/v1/realtime`) and `realtime.authUrl` (normally `/api/v1/broadcasting/auth`), or provide `realtime.url`, `key`, and `channel` directly. Only the public app key reaches the browser; the signing secret stays on the server. These WebSocket settings apply only to Orbit mode. Local server mode needs only the annotation server URL; it discovers the server’s event stream automatically. The host backend must proxy discovery and authorization using its WireGuard identity and restrict access to trusted annotators.

Submissions remain HTTP POSTs so Gateway validation, persistence, and retries use the same API. Reverb client messages do not execute Gateway actions. Notifications contain only the annotation ID, Instance ID, and revision; the browser fetches the authorized annotation snapshot. It also fetches on subscription, reconnect, refresh, and every 15 seconds as an outage fallback. Server state is authoritative; browser storage retains submissions that could not reach the service. The legacy SSE endpoint remains available for older clients.

Annotations belong to an Orbit Instance and live in the Gateway database. Configure the host with a service URL of `/api/v1/instances/{id}/annotations`, proxied through the host backend with its WireGuard identity. Instance visitors share the same records and events. The Instance page has Overview and Tasks tabs. Overview contains properties, processes, schedules, and application logs. Tasks reuses the main Kanban board, filtered to task groups belonging to the Instance, including annotation tasks.

The Gateway uses its existing T3 connection for the Instance’s Node. Configure that Node’s `settings.t3.url` and `settings.t3.token` on the Gateway; keep the bearer token out of browser settings. T3 can issue a server credential with `t3 auth session issue --ttl 7d --label "Orbit annotations" --token-only`. Renew it before its chosen expiry. A scheduled `annotations:dispatch` command attempts delivery every ten seconds, independently of browser connections. No full Task or Task Group is created. The T3 thread must use the Instance checkout. The Gateway preserves the thread’s model, permission mode, and interaction mode.

### Connect a host

Set `VITE_ANNOTATION_SERVICE_URL=/api/v1/instances/107/annotations` in the Orbit web environment, replacing `107` with the owning Instance ID. Other hosts pass that path as `serviceUrl` to `mountAnnotation` and proxy it to the Gateway. The host must restrict access to trusted annotators: its backend submits under its Node identity. Public visitor authentication and named authors are not part of this slice.

The API offers GET and POST on `/api/v1/instances/{instance}/annotations`, GET on `events`, and POST on `{annotation}/status` and `{annotation}/retry`. Status accepts `in_progress` or `resolved`; resolution requires a `summary`. These routes use the existing Instance Node access checks. The annotation service retains records and events until an operator defines a retention policy.

An edit to an instruction that has reached the server needs a new annotation. The existing ID is immutable to keep retries from creating duplicate work. A missing thread ID produces a delivery error. Select a thread and create a new annotation. Connection failures offer Retry delivery.

## Orbit tasks

Every annotation creates one Task with `type=annotation` in a TaskGroup with `execution_mode=existing_thread`. The group references the existing Instance; it does not own that Instance. Each annotation has its own group so completion remains independent. The browser continues to call these records annotations. Its pending, in-progress, and done states derive from the Task's `todo`, `running`, and `completed` states. The Task owns the completion summary and timestamps; the annotation stores page context and delivery bookkeeping.

Managed groups keep `execution_mode=managed`. The managed scheduler excludes existing-thread groups from claiming, monitoring, provisioning, review, cleanup, and concurrency counts. Managed lifecycle actions reject these groups, preventing accidental Instance removal. The annotation dispatcher sends existing-thread tasks serially to their selected T3 thread, waits for explicit completion, and never falls back to starting a managed worker. Missing destinations remain pending. A retry can assign a thread before the first delivery attempt; commands already sent cannot be retargeted.

The migration converts existing annotations to Tasks and preserves their IDs, state, summaries, and delivery commands. It stops if an annotation references a missing Instance, because Project ownership cannot safely be inferred. Back up the Gateway database before applying this migration; production rollback uses that backup. Existing annotation status endpoints update their Tasks and publish the same browser notifications. Token usage stays unknown unless it can be attributed to the individual task; a shared thread total is not a task total.

When Instance removal is accepted, unfinished annotation tasks are cancelled and further delivery stops. Completed tasks retain their history. Cancellation does not undo changes or recall a message already delivered to T3. A late completion report cannot reopen a cancelled task.

## Local server

Run `npx @nckrtl/annotate serve` to start an independent local annotation service. It binds to loopback on a random available port and prints `http://127.0.0.1:<port>/annotations`. Choose **Local server** and paste that URL into **Annotation server URL** in the toolbar settings. No Vite configuration is required. Each server uses its own URL and storage directory, so several sessions can run together.

Use `--port 29703` for a fixed port or `--store /path/to/session` to resume a saved store. By default, the server creates a temporary directory and prints the storage path. Writes persist before acknowledgement. If the port changes after restarting, paste the new URL into settings.

The toolbar remembers the URL for the browser tab across refreshes. Choose **Local server** in **Delivery mode** to use that URL without Orbit or T3 delivery. Clearing the URL does not change the mode; server mode requires a URL. Choose **Orbit** explicitly to use the host delivery integration. Orbit mode requires a nonempty thread ID.

Failed submissions stay in the browser for manual retry and do not fall back to T3. Settings show the connection state. Press Enter in the server URL field or leave the field to check the entered URL before saving. The check uses the same development bridge as annotation writes and shows whether the server is reachable; it does not save settings or create an annotation. Browsers may request local-network permission; loopback URLs on mobile refer to the mobile device.

### Agent access

Each annotation is stored as `<id>.json` under `todo/`, `in-progress/`, or `done/` in the printed store directory. Files use status `todo`, `in_progress`, or `done`. Cancelled records also live in `done/` but retain status `cancelled`. Existing single-file stores are migrated when passed to `--store`, and the original file is preserved with a `.legacy` suffix.

| Endpoint | Result |
| --- | --- |
| `POST /claim` | Claims the oldest todo annotation, sets `in_progress`, moves its file, and returns `{data: annotation}`. Returns `204` when no work is available. |
| `POST /complete` | Accepts an in-progress `id` and optional `summary`, sets `done`, and moves its file into `done/`. Repeating completion returns the completed record. |
| `POST /release` | Accepts an in-progress `id`, sets `todo`, and moves its file back to `todo/`. |
| `GET /annotations` | Returns `{data: [...]}`, including completed records. |
| `POST /annotations` | Creates a todo annotation; duplicate IDs return the existing record. |
| `GET /annotations/events` | Streams invalidations so clients re-fetch current state. |

The worker endpoints also accept `/annotations/claim`, `/annotations/complete`, and `/annotations/release`, so agents can append the operation to the printed annotation URL. Unknown IDs return `404`; completion or release from an invalid state returns `409`. The older `/annotations/:id/status` endpoint still accepts `pending` and `resolved` as aliases for `todo` and `done`. The toolbar translates local states to its existing marker states; Orbit's task API is unchanged.

One server owns each directory. Claim selection, file movement, and status writing execute without yielding, so concurrent monitor requests receive distinct work. Writes finish before acknowledgement. In-progress records survive a restart and require completion or explicit release; there is no lease timeout. The server watches all three directories and reads current files for each request. External edits should use atomic file replacement. Directory placement determines status when a move was interrupted before its JSON update, or when an external tool moves a file.

Local annotations receive a stable `number` from the server. Numbers increase across the store and do not change when work completes or is released. The toolbar keeps the session counter after completion and uses the same numbers on pins. Reusing a store preserves its numbering; a new store starts at 1. The server prints one activity line per creation or status change, such as `#1 created: Fix the heading`, `#1 in progress: Fix the heading`, and `#1 done: Fix the heading`. Repeated submissions and unchanged statuses do not produce duplicate activity lines.

The browser re-fetches on connection and after each event, retaining periodic polling as a fallback. The local server stores and exposes work but does not launch agents or send messages to T3.

When the browser runs on a different machine, its loopback address does not reach the server. Use `--host <private-IP>` to bind to the server machine’s private network address. An HTTPS page also requires an HTTPS endpoint for a remote server; a reverse proxy can forward it to the local annotation process. The proxy changes transport only: annotations remain in the local store and are not sent to Orbit tasks or T3.

### Development server bridge

Orbit’s web development server enables the package’s `annotationServerProxy()` Vite plugin. When Local server mode contains an HTTP loopback URL ending in `/annotations`, the toolbar sends requests through the development server on the page’s origin. The development server connects to that annotation port on its own machine. Both submissions and SSE use this bridge. This supports a remote browser and an HTTPS page without hard-coding an annotation port or involving Orbit tasks or T3 delivery. 

A newly started server can use a different random port; paste its new URL into settings. Restart older annotation servers with the current package so they provide the service identification required by the bridge.

Other Vite hosts enable the same plugin from `@nckrtl/annotate/vite`. The bridge runs only during development and accepts annotation API paths after verifying the target service. Without the plugin, the URL must be directly reachable from the browser.

