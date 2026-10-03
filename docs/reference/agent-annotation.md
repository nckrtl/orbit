---
title: "Agent annotation package"
description: "The browser annotation overlay, its speech input, and its two delivery modes: a local annotation server, or Orbit Tasks sent to a T3 thread."
covers:
  - packages/agent-annotation/**
  - apps/gateway/resources/annotator/**
  - bin/annotator-build
  - apps/web/dev/annotation-thread.ts
  - apps/gateway/app/{Actions,Data,Http/Requests}/Annotations/**
  - apps/gateway/app/Http/Controllers/Api/AnnotationsController.php
  - apps/gateway/app/Models/Annotation.php
  - apps/gateway/app/Console/Commands/DispatchAnnotations.php
---

# Agent annotation package

`@nckrtl/annotator` in `packages/agent-annotation` is a browser overlay. You click an element, write or speak a comment, and the overlay saves an annotation with the element, page context, and a screenshot. It runs in its own Shadow DOM and needs neither Orbit nor Laravel. The Orbit web app uses it.

An annotation goes to one of two places:

- **Local server**: a small server on your machine stores it as a file, and your agent picks it up.
- **Orbit**: the Gateway stores it as a task and sends it to a T3 thread in the Instance's checkout.

## Install and mount

In `packages/agent-annotation`, run `bun install`, `bun run build`, and `npm pack`. Install the archive in the target project. The bundle includes React and its styles, so the host needs neither.

```ts
import { mountAnnotation } from "@nckrtl/annotator";

const annotation = mountAnnotation({
    dictation: { wsUrl: "wss://speech.example.com/v1/audio/stream" },
});

// Remove the controls, listeners, and microphone capture.
annotation.destroy();
```

A second `mountAnnotation` call updates the options and adds no second overlay. Loading `inject.js` again, for example by a host such as T3 on a page whose toolbar already loaded it, does the same: the running overlay takes the new options and no second copy starts. To inject the overlay as a script, set `window.__AGENT_ANNOTATION__` to the options and load `dist/inject.js`. The script mounts when the document is ready and sets `window.AgentAnnotation.mountAnnotation`.

| Option | Meaning |
| --- | --- |
| `serviceUrl` | The Orbit annotation endpoint of an Instance. With it, Orbit is the default delivery mode. |
| `orbit.tasksStatusUrl` | The Tasks status endpoint. The default is `/api/v1/tasks/status` on the `serviceUrl` origin. |
| `realtime` | How the overlay hears about changes in Orbit mode. See [Orbit delivery](#orbit-delivery). |
| `thread` | `{ id, discoveryUrl }`: a fixed T3 thread ID, or a URL that detects one. |
| `dictation` | Speech input. See [Speech](#speech). |
| `getToolbarData` | A callback that returns `primary_color`, `primary_text_color`, `font_size`, and `request.controller_action` and `request.route_name`. Laravel hosts use it to add request context. |
| `floatingControl` | `false` hides the floating controls. The overlay and the shortcuts keep working, so a host can show its own controls. |

### Host controls

A host with its own toolbar reads and changes the overlay through these functions. They share storage and transport with the floating controls.

| Function | What it does |
| --- | --- |
| `getAnnotationState()`, `subscribeAnnotationState(listener)` | A stable snapshot for `useSyncExternalStore` or another framework: mode, count, page count, delivery mode, connection, removal error, Orbit availability, and thread. |
| `getAnnotationSettings()`, `saveAnnotationSettings(settings)` | Read and save the delivery mode, server URL, and thread ID for the tab. An empty `threadId` clears the thread. |
| `checkAnnotationServer(url, signal)`, `checkOrbit()` | Check a local server URL, or the Orbit support of the host. |
| `toggleAnnotationMode()`, `clearAllAnnotations()`, `clearPageAnnotations()` | Turn annotation mode on or off, remove every annotation, or remove those of the current page. |

Mark the host toolbar with `data-feedback-toolbar`, so a click in it does not pick an element. Mount one annotation integration per page.

## Use the overlay

Press Cmd+Shift+A on macOS, or Ctrl+Shift+A on Windows and Linux, to turn annotation mode on or off. Press Cmd+Shift+R or Ctrl+Shift+R to clear all annotations. Both shortcuts work while the comment field has focus. The page receives no reload for the clear shortcut when the browser passes the keys to the page.

The comment field opens above the buttons, with the placeholder "Listening". The browser keeps annotations in local storage per path for seven days, and drops older ones when it reads them. The trash button next to the toggle clears the annotations of every path on the site and closes an open draft. It does not change annotation mode. Clearing hides annotations in this browser only. It does not cancel work that a server already holds.

## Speech

Speech is off until you set `dictation.wsUrl`, or `postUrl` for [desktop dictation](#desktop-dictation).

`dictation.wsUrl` is a Diction streaming endpoint: a `ws://` or `wss://` URL, or a path on the page origin. The overlay turns an `http` or `https` URL into its WebSocket form and refuses any other scheme. It sends Opus audio when the browser can record Opus and the service accepts the `diction.opus.v1` subprotocol. Otherwise it sends PCM16 at 16 kHz. `dictation.codec: "pcm"` always sends PCM16. It ends the audio with `{"action":"done"}` and reads `{"text":"..."}` back. The Diction service picks the model. Keep provider secrets out of the browser.

Recording starts when a new comment field opens. Set `autoStart: false` to record only when you press the microphone button. The browser allows the microphone only on HTTPS or `localhost`.

### Desktop dictation

Set `provider: "post"` and `postUrl` to a dictation service on your own machine, such as `http://127.0.0.1:12321/dictate`. The overlay then records nothing. A new annotation focuses its comment field and sends one POST with no body. Any successful response counts. The desktop service records and types the text. That service must allow the page origin through CORS, and the browser can ask for local-network permission.

Also set `stopUrl`, such as `http://127.0.0.1:12321/dictate-stop`, to move straight to the next element. A click outside the open popup then sends a POST to `stopUrl`, and the placeholder changes to "Waiting for paste…". When the text arrives and the field stays unchanged for 500 ms, the overlay saves the annotation and opens a new one on the clicked element. A failed stop request, an empty field, or no text within 15 seconds keeps the annotation open with an error. Escape closes the draft without saving it, which also cancels a pending move.

The browser Performance API keeps the latest `annotate:dictate-request`, `annotate:dictate-stop-request`, and `annotate:paste-wait` measures. A request measure includes connection and permission time in the browser.

## Delivery modes

The gear button opens the settings. **Delivery mode** is **Local server** or **Orbit**. The overlay keeps the choice for the browser tab, across refreshes. A failed submission stays in the browser with its error. The overlay never switches to the other mode.

| Mode | Needs |
| --- | --- |
| Local server | An **Annotation server URL**. |
| Orbit | A `serviceUrl` from the host, the Tasks extension enabled, access to the Instance, and a **T3 thread ID**. The settings show why Orbit is unavailable. |

## Local server

Run the server from a project that has the package installed:

```bash
npx @nckrtl/annotator serve [--port PORT] [--host [IP]] [--store DIRECTORY] [--state FILE]
```

It binds `127.0.0.1` on a random port, creates a temporary store, and prints both, for example `http://127.0.0.1:52817/annotations`. Paste that URL into **Annotation server URL**. The overlay checks the URL when you press Enter or leave the field. `--port` fixes the port. `--host` without an IP binds `0.0.0.0` and prints the machine's first network address. `--store` reuses a store, so numbering and records continue. Only one server can use a store at a time. Each server has its own URL and store, so several can run at once.

To run the server in the background, use `start --state FILE` with the same options. It returns when the server answers and prints JSON with the PID, port, URL, skill URL, and store. `status --state FILE` reports whether that server runs and removes the state file of a crashed one. `stop --state FILE` stops it with SIGTERM, so the store closes cleanly. It never signals a process that does not answer as an annotation server. The server writes its output to a `.log` file next to the state file. `serve --state FILE` writes the same state file for a server in the foreground.

The server also prints a **Skill URL**, `http://127.0.0.1:<port>/skill`. It serves a short Markdown skill with the server's annotation URL and the project folder, which tells an agent how to watch, claim, complete, and release annotations. `GET /annotations` returns it as `meta.skillUrl`. Reading the skill changes no work.

The server writes each annotation to `<store>/todo/`, `<store>/in-progress/`, or `<store>/done/` as `<id>.json`, before it answers. The folder decides the status: `todo`, `in_progress`, or `done`. A record with status `cancelled` also lives in `done/`. The server reads the folders on every request. Edit a file by replacing it atomically.

Each annotation gets a `number` that never changes. Numbers go up across the store. The server prints one line when an annotation is created or changes status, such as `#1 created: Fix the heading`.

### Agent API

Append the operation to the printed URL.

| Request | Result |
| --- | --- |
| `GET /annotations` | `{data: [...]}` with every record, completed ones included. |
| `POST /annotations` | Creates a `todo` annotation. The same ID again returns the existing record. |
| `POST /annotations/claim` | Without a body, claims the oldest non-question `todo` record. With `id`, claims that todo record, including a question, and clears its question marker. `204` when no eligible work remains. |
| `POST /annotations/complete` | Takes `id` and an optional `summary`, and moves an `in_progress` annotation to `done`. |
| `POST /annotations/release` | Takes `id`, optional `summary`, and optional `question: true`, and returns an `in_progress` annotation to `todo`. A question requires its text as `summary`. |
| `GET /annotations/events` | A server-sent event stream. Each event tells the client to fetch again. |
| `DELETE /annotations/{id}` | Removes one annotation. |
| `DELETE /annotations` | Removes every annotation, or with `?pathname=/page` those of one page. |

A removed annotation stays removed: the server records it, so a late submission of the same ID gets `410` and does not bring it back. Numbering continues. Connected browsers receive the removal, also after they reconnect.

An unknown ID returns `404`. Completing or releasing an annotation that is not `in_progress` returns `409`, unless it already has the target status. Two agents that claim at once get different annotations. A claim has no timeout: an `in_progress` annotation stays so across restarts until an agent completes or releases it. The server only stores work. It starts no agent and sends nothing to T3.

### Another machine

A browser on another machine cannot reach `127.0.0.1` on the server machine. Start the server with `--host <private-IP>`. An HTTPS page also needs an HTTPS endpoint, such as a reverse proxy in front of the server.

A Vite host can add the `annotationServerProxy()` plugin from `@nckrtl/annotator/vite` instead. During development it forwards `/__annotate/local/<port>/annotations` to that port on the development machine. The overlay then sends a loopback server URL through the page origin, so a remote browser and an HTTPS page both work. The plugin forwards only to a port that answers as an annotation server. The Orbit web app enables it.

## Orbit delivery

In Orbit mode, the Gateway stores each annotation and sends it to a T3 thread. The endpoint is `/api/v1/instances/{instance}/annotations`, and every call needs [access](/cli/node) to the Instance's Node.

| Request | Result |
| --- | --- |
| `GET` | The Instance's annotations. |
| `POST` | Stores an annotation. The same ID with the same content returns the stored one. A changed instruction needs a new annotation, or it fails with `annotation.conflict`. |
| `POST {annotation}/status` | `in_progress`, or `resolved` with a `summary`. `resolved` needs `in_progress` first. |
| `POST {annotation}/retry` | Queues a failed delivery again. It can set `threadId` while nothing was sent. |

The Orbit web app is on the Gateway origin, so it calls the endpoint directly: set `VITE_ANNOTATION_SERVICE_URL=/api/v1/instances/107/annotations` with the owning Instance ID. Another host passes the path as `serviceUrl` and proxies it to the Gateway. Its backend then calls under its own Node identity, so the host must admit only trusted annotators.

### Threads

The annotation carries the T3 thread ID that is selected when you submit it. A later change of the selection does not move saved work. The thread ID field fills itself from `thread.id`, or from `thread.discoveryUrl`. The Orbit web app reads `VITE_ANNOTATION_THREAD_ID`. During development it detects the thread at `/__annotate/thread`: the dev server reads the local T3 database read-only and picks the one open thread for this worktree. No match, or more than one, leaves the field empty.

You can type a thread ID and save it. The overlay keeps it in session storage for the site and tab, across refreshes. A saved empty value turns detection off for that tab.

### Tasks

Each annotation creates one task with `execution_mode=existing_thread` and one subtask with `type=annotation`. The task points at the Instance but does not own it. The annotation keeps the page context and the delivery state. The subtask keeps the status, the summary, and the times.

| Annotation status | Subtask status |
| --- | --- |
| `pending` | `todo`, and every status not listed here, such as `failed` |
| `in_progress` | `running` or `reviewing` |
| `resolved` | `completed` |
| `cancelled` | `cancelled` |

The [managed scheduler](/reference/tasks) never claims, provisions, reviews, or cleans up an existing-thread task, and does not count it for concurrency. The managed task commands refuse such a task with `tasks.external_execution`. So no managed action can remove the Instance.

### Delivery

The scheduler runs `annotations:dispatch` every ten seconds, with or without a browser. It takes queued annotations in submission order. Each T3 thread gets one unfinished annotation at a time. For each annotation, the Gateway reads the thread through the Instance Node's T3 connection and checks these:

- The thread exists, and is neither archived nor deleted.
- The thread's worktree is the Instance checkout, or the path that the Instance was registered from.
- The thread is idle. A busy thread waits for the next run.

Then the Gateway starts a turn with the annotation as the message. The turn keeps the thread's runtime mode and interaction mode. The message tells the agent to report `in_progress` before it edits and `resolved` with a summary after it checks the work. A sent message does not complete the annotation. Only the `resolved` report does.

The command and message IDs are fixed when the annotation is stored, so a repeated send creates no second turn. A missing thread ID, a failed thread check other than a busy thread, or a failed send marks the delivery `error`. Retry sends it again. [Tasks](/reference/tasks#coder-settle-webhook) lists the T3 URL and token settings of a Node. Keep the T3 token on the Gateway, never in the browser.

### Live updates

Every change sends an [`annotation.updated`](/reference/events#annotation) notice with the ID, Instance ID, and revision. The browser then fetches the list. It also fetches when it subscribes, reconnects, or refreshes. Submissions stay HTTP requests.

The Orbit web app shares its own realtime connection through `realtime.subscribe` and `realtime.live`. Another host sets `realtime.configUrl`, normally `/api/v1/realtime`, and `realtime.authUrl`, normally `/api/v1/broadcasting/auth`, or it sets `realtime.url`, `key`, and `channel`. While no subscription is live, the browser also fetches every 15 seconds and when a hidden tab becomes visible. That timed fetch skips a hidden tab. A realtime notice starts a fetch in a hidden tab too.

### Instance removal

The Gateway refuses new annotations for an Instance that is being removed, with `annotation.instance_removing`. [Instance removal](/reference/instance-removal#removal-steps) cancels the Instance's open annotation Tasks and marks their annotations `cancelled`. Completed Tasks keep their history. Cancellation does not undo changes or recall a message that T3 already has.

## Orbit web configuration

The Orbit web app reads these values at startup. Set them in `apps/web/.env.local` and restart the dev server.

| Variable | Meaning |
| --- | --- |
| `VITE_ANNOTATION_SERVICE_URL` | The Instance annotation endpoint. |
| `VITE_ANNOTATION_THREAD_ID` | A fixed T3 thread ID. |
| `VITE_ANNOTATION_TRANSCRIPTION_URL` | The speech URL. Empty turns speech off. |
| `VITE_ANNOTATION_AUTO_START` | `0` records only on the microphone button. |
| `VITE_ANNOTATION_DICTATION_PROVIDER` | `post` for desktop dictation. |
| `VITE_ANNOTATION_DICTATION_POST_URL`, `VITE_ANNOTATION_DICTATION_STOP_URL` | The desktop dictation endpoints. |
| `ANNOTATION_TRANSCRIPTION_TARGET` | A Diction origin, such as `http://127.0.0.1:8080`. The dev server proxies `/__annotate/speech` to its `/v1/audio/stream`. Set the speech URL to `/__annotate/speech` to keep speech on the page origin. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### One package for every host

A copy of the overlay in each application would drift. So one package owns it, and Orbit web uses the package like any other host.

### React and styles in the bundle

A host that must compile Tailwind or mount React cannot load the overlay as a script. So the bundle carries both.

### HTTP submissions, realtime notices

HTTP keeps Gateway validation, storage, and a durable answer for each submission. A realtime client message cannot run a Gateway action. So a notice only tells the browser to fetch.

### A subtask in an existing-thread task

An annotation is work for an agent, so it uses a task and one subtask and shows on the task board. The execution mode, not the subtask type, keeps it out of the managed scheduler. So the managed lifecycle never provisions or removes the Instance for it.

### Completion is a report

A delivered message does not mean done. The next annotation for a thread waits for the explicit `resolved` report, so two instructions never mix in one turn.

### Fixed IDs

An annotation ID and its T3 command ID never change. A retry then cannot create a second turn. A changed instruction is a new annotation.

## Instance Process server and queue threads

Orbit runs the annotator as an Instance Process and publishes it under the Instance Route at `/__orbit/annotator`. Its `ANNOTATOR_URL` points to `/__orbit/annotator/annotations`, while the Instance API exposes the service base as `annotator_url`. The preset admits the page's HTTPS origin and the T3 renderer origin `t3code://app`; other supplied origins are refused for reads, SSE, deletion, and preflight. See [Annotator Process](/reference/agentation#annotator-process).

`GET /health` returns 200 without reading the annotation store. `GET /inject.js` serves the installed package's `dist/inject.js`; hosts can load the overlay from the same server and version rather than bundle a copy.

The Gateway ships that asset in its tracked resource distribution. Its source manifest covers the server and browser code, build configuration, and dependency lock. Run `bin/annotator-build` after changing the package sources and include both resource outputs. Installation refuses a missing or mismatched asset instead of starting a health-only server.

Pass `--allow-origin ORIGIN` once for each allowed browser origin. Orbit uses the Instance origin, `t3code://app`, and `t3code-dev://app`. The allow-list applies to reads, writes, event streams, and DELETE, including preflight requests. Requests from other origins receive 403. Requests without an Origin header remain available to agents. Without the flag, the server keeps wildcard CORS for local use.

A host can call `mountAnnotation({ serverUrl: "/__orbit/annotator/annotations" })` or set that option in `window.__AGENT_ANNOTATION__` before loading `inject.js`. Without an explicit server or saved delivery choice, the overlay probes same-origin `/__orbit/annotator/annotations` and uses it only when the response identifies `meta.service` as `@nckrtl/annotator`. In server mode, a successful list is the only source of pins: local records missing from it are removed, even when the server's store was replaced and has no deletion history. Failed reads keep the cached pins.

`GET /skill` keeps the watch instructions. `GET /skill?mode=queue` serves instructions for an orchestrating T3 thread: claim until the queue is empty, delegate independent annotations to sub-agents, keep related work together, complete with a summary, and end the turn. It does not run an endless event-stream loop. Refer to annotations by `number` (for example, #3), never by their internal `id`.

When a comment needs clarification, release the claimed annotation with `POST /annotations/release` and `{"id":"CLAIMED_ID","question":true,"summary":"The question for the user"}`, then ask the user in the thread. The record stays `todo` with `question: true` and its summary; live clients and pins show that it needs an answer. A bodyless `POST /annotations/claim` skips questions, so Watch mode never re-sends them. After the user answers, claim that annotation with `{"id":"CLAIMED_ID"}`. An explicit claim accepts any todo annotation, clears the question marker and its summary, and marks it in progress. Live pins clear those fields without requiring a reload.

Store transitions atomically commit the complete intended record before changing directories; after a process stop, the next read recovers that record, including question markers and cleared summaries. A stop before the intent commits leaves the old record unchanged. Plain releases keep their existing behavior.
