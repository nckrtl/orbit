---
title: "Agent annotation"
description: "How Orbit uses the @nckrtl/annotator browser overlay: the Orbit delivery mode that sends annotations to a T3 thread as Tasks, and the annotator Instance Process."
covers:
  - apps/web/src/annotation/**
  - apps/gateway/resources/annotator/**
  - bin/annotator-build
  - apps/web/dev/annotation-thread.ts
  - apps/gateway/app/{Actions,Data,Http/Requests}/Annotations/**
  - apps/gateway/app/Http/Controllers/Api/AnnotationsController.php
  - apps/gateway/app/Models/Annotation.php
  - apps/gateway/app/Console/Commands/DispatchAnnotations.php
---

# Agent annotation

[`@nckrtl/annotator`](https://github.com/nckrtl/annotator) is a browser overlay. You click an element, write or speak a comment, and the overlay saves an annotation with the element, page context, and a screenshot. It is an independent package with its own repository and documentation; its README covers mounting, options, speech, host controls, the local annotation server, and its agent API. It knows nothing about Orbit.

Orbit uses it in two ways:

- **Orbit delivery**: the Orbit web app registers an `orbit` transport. The Gateway stores each annotation as a Task and sends it to a T3 thread in the Instance's checkout.
- **Annotator Process**: an Instance Process runs the package's local annotation server under the Instance Route.

## Orbit delivery

`apps/web/src/annotation/orbit-transport.ts` implements the package's transport interface. It adds **Orbit** to the overlay's **Delivery mode** list, next to the built-in **Local server**. Before it shows Orbit as available, and before every write, it checks that the Tasks extension is enabled and that the Instance annotation endpoint answers. Otherwise the settings show the reason and delivery fails with it. A failed submission stays in the browser with its error; the overlay never switches to another mode.

In Orbit mode, the Gateway stores each annotation and sends it to a T3 thread. The endpoint is `/api/v1/instances/{instance}/annotations`, and every call needs [access](/cli/node) to the Instance's Node.

| Request | Result |
| --- | --- |
| `GET` | The Instance's annotations. |
| `POST` | Stores an annotation. The same ID with the same content returns the stored one. A changed instruction needs a new annotation, or it fails with `annotation.conflict`. |
| `POST {annotation}/status` | `in_progress`, or `resolved` with a `summary`. `resolved` needs `in_progress` first. |
| `POST {annotation}/retry` | Queues a failed delivery again. It can set `threadId` while nothing was sent. |

The Orbit web app is on the Gateway origin, so it calls the endpoint directly: set `VITE_ANNOTATION_SERVICE_URL=/api/v1/instances/107/annotations` with the owning Instance ID. Without it, the web app registers no Orbit transport.

### Threads

The transport adds the selected T3 thread ID to each annotation when it submits it. A later change of the selection does not move saved work. The **T3 thread ID** field appears in the overlay settings while Orbit is selected. The Orbit web app fills it from `VITE_ANNOTATION_THREAD_ID`. During development it detects the thread at `/__annotate/thread`: the dev server reads the local T3 database read-only and picks the one open thread for this worktree. No match, or more than one, leaves the field empty.

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

The transport subscribes through the web app's own realtime connection. While that connection is not live, the browser also fetches every 15 seconds and when a hidden tab becomes visible. That timed fetch skips a hidden tab. A realtime notice starts a fetch in a hidden tab too.

### Instance removal

The Gateway refuses new annotations for an Instance that is being removed, with `annotation.instance_removing`. [Instance removal](/reference/instance-removal#removal-steps) cancels the Instance's open annotation Tasks and marks their annotations `cancelled`. Completed Tasks keep their history. Cancellation does not undo changes or recall a message that T3 already has.

## Orbit web configuration

The Orbit web app reads these values at startup. Set them in `apps/web/.env.local` and restart the dev server.

| Variable | Meaning |
| --- | --- |
| `VITE_ANNOTATION_SERVICE_URL` | The Instance annotation endpoint. |
| `VITE_ANNOTATION_THREAD_ID` | A fixed T3 thread ID. |
| `VITE_ANNOTATION_TRANSCRIPTION_URL` | The speech URL, passed as `dictation.wsUrl`. Empty turns speech off. |
| `VITE_ANNOTATION_AUTO_START` | `0` records only on the microphone button. |
| `VITE_ANNOTATION_DICTATION_PROVIDER` | `post` for desktop dictation. |
| `VITE_ANNOTATION_DICTATION_POST_URL`, `VITE_ANNOTATION_DICTATION_STOP_URL` | The desktop dictation endpoints. |
| `ANNOTATION_TRANSCRIPTION_TARGET` | A Diction origin, such as `http://127.0.0.1:8080`. The dev server proxies `/__annotate/speech` to its `/v1/audio/stream`. Set the speech URL to `/__annotate/speech` to keep speech on the page origin. |

## Why it works this way

These reasons explain the design. Check them before you propose a change.

### An independent package with transports

A copy of the overlay in each application would drift, and Orbit code in the overlay would tie every host to Orbit. So the overlay lives in its own package, and Orbit adds its delivery as a transport, like any other host would.

### HTTP submissions, realtime notices

HTTP keeps Gateway validation, storage, and a durable answer for each submission. A realtime client message cannot run a Gateway action. So a notice only tells the browser to fetch.

### A subtask in an existing-thread task

An annotation is work for an agent, so it uses a task and one subtask and shows on the task board. The execution mode, not the subtask type, keeps it out of the managed scheduler. So the managed lifecycle never provisions or removes the Instance for it.

### Completion is a report

A delivered message does not mean done. The next annotation for a thread waits for the explicit `resolved` report, so two instructions never mix in one turn.

### Fixed IDs

An annotation ID and its T3 command ID never change. A retry then cannot create a second turn. A changed instruction is a new annotation.

## Annotator Process

Orbit runs the annotator as an Instance Process and publishes it under the Instance Route at `/__orbit/annotator`. Its `ANNOTATOR_URL` points to `/__orbit/annotator/annotations`, while the Instance API exposes the service base as `annotator_url`. The preset admits the page's HTTPS origin and the T3 renderer origin `t3code://app`; other supplied origins are refused for reads, SSE, deletion, and preflight. See [Annotator Process](/reference/agentation#annotator-process).

`GET /health` returns 200 without reading the annotation store. `GET /inject.js` serves the installed package's `dist/inject.js`; hosts can load the overlay from the same server and version rather than bundle a copy.

The Gateway vendors the server files and the injection asset of the `@nckrtl/annotator` version that `apps/web/bun.lock` locks, in `apps/gateway/resources/annotator`. After you update the package in `apps/web`, run `bin/annotator-build` and commit its outputs. `composer check` in the Gateway runs `bin/annotator-build --check`, which fails when the vendored version differs from the locked one or a vendored file changed. Installation verifies every file against the manifest and refuses a missing or changed file instead of starting a health-only server.

### Loading the overlay from the Process

A page that loads `/__orbit/annotator/inject.js` needs no configuration. When it has no server URL and no saved delivery choice, the overlay probes `/__orbit/annotator/annotations`, next to the script, and uses it when the response identifies `meta.service` as `@nckrtl/annotator`. A page that bundles the overlay passes `serverUrl: "/__orbit/annotator/annotations"` to `mountAnnotation`, or sets it in `window.__AGENT_ANNOTATION__`.

`GET /skill?mode=queue` serves instructions for an orchestrating T3 thread: claim until the queue is empty, delegate independent annotations to sub-agents, keep related work together, complete with a summary, and end the turn. The package README describes the agent API and questions.
