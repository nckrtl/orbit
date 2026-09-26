---
title: "ADR 0167: Resume a Pi turn interrupted by a server restart"
sidebarTitle: "0167 Resume a restarted Pi turn"
description: "Proposed. A Pi turn that failed because the Pi server restarted is resumed on the same thread, at most twice per subtask, and does not ask for assistance."
---

# ADR 0167: Resume a Pi turn interrupted by a server restart

A Pi thread whose turn failed with the Pi server restart error is not a real failure. The tick sends one continue to that same thread and does not ask for assistance. The continue uses a new send key that is stored before the send and reused for every retry of that resume. A subtask gets at most two reserved resumes. Any other failure still asks for assistance.

## Status

Proposed.

This extends [ADR 0116](/decisions/0116-run-task-implementers-on-pi). That record reports a turn active during a server restart as `failed` with a restart error. The error text and the `failed` state stay. This record changes the Gateway's response to that one error.

## Context

[ADR 0116](/decisions/0116-run-task-implementers-on-pi) maps a Pi server restart during a turn to `failed`. The error is `The Pi server restarted during the turn.` The tick treats every `failed` thread as assistance and sends no reminder. The reason is `The implementer thread failed.` or `The reviewer thread failed.`

On 2026-09-27 a pi-server upgrade restarted the server while groups 129 and 133 had turns in progress. Those turns failed with the restart error. The checkout still held the agent's work. The operator posted resolution comments. The Gateway sends a resolution only when the subtask is already asking for assistance. A comment posted before the tick sets that flag is stored and is not sent. The operator waited for assistance, then posted the comment again.

Accepted send keys persist across a restart. A repeated key starts no turn. The continue needs a new key, not the key of the interrupted turn. The Pi driver mints that key inside the send call and reuses it only for the retry inside that same call. A key that exists only in that call is gone when the process stops after Pi accepts the send. The next call would mint another key and would not count the acceptance.

A provider error, an interruption, and the output limit are real failures. The restart error is the server process dying under a turn that had not finished.

## Decision

The tick resumes a Pi turn that failed only because the server restarted.

The acting thread is the subtask's implementer while the subtask is `running`, and the group's reviewer while the subtask is `reviewing`. All of the following must hold:

- The acting thread's driver is `pi`.
- Its state is `failed`.
- Its error is exactly `The Pi server restarted during the turn.`
- The subtask is not asking for assistance, and the group is not asking either.
- The observation includes a turn id. The Pi server sets that id to the key of the latest accepted send.

The subtask stores `pi_restart_resumes`, an integer starting at 0, plus one reservation: `pi_restart_key`, `pi_restart_thread_id`, `pi_restart_source_turn_id`, and `pi_restart_reservation`. The key, thread id, and source turn id start null. `pi_restart_reservation` starts null and is then `pending`, `accepted`, or `superseded`. The group response, the subtask response, the web task board, and the agents API do not add these fields. A resolution comment does not reset the count or the reservation. A process stop does not reset them either.

`pi_restart_resumes` counts a resume when the Gateway reserves it, before the HTTP call. The reservation belongs to one acting thread and one interrupted source turn. `pi_restart_key` is the send key for that pair. The Pi driver posts that key on this send. It does not mint a different key for it. Only an unresolved send for that same thread and that same source turn posts the key again, including after the process starts again.

When the conditions hold, the tick reconciles the reservation, then sends or asks for assistance:

- The tick repeats the stored key only when `pi_restart_reservation` is `pending`, `pi_restart_thread_id` is the acting thread, and the observed turn id is still `pi_restart_source_turn_id`. It does not increment the count. Any other observation is not that unresolved send.
- When the acting thread's turn id equals `pi_restart_key`, Pi accepted that reservation. The tick sets `pi_restart_reservation` to `accepted` and does not send that key again. A restart of that accepted turn is a new interruption. Its source turn id is the accepted key.
- When the reservation is `pending` and the observed turn id is neither the source turn id nor the stored key, a different turn is current. The tick sets `pi_restart_reservation` to `superseded` and does not send the stored key. Pi keeps every accepted key, so posting a finished resume key again returns a duplicate and starts no turn.
- A reminder, a relay of review findings, or a resolution starts that different turn with its own key. A restart during that turn is a new interruption. The stored resume key is not the send for it.
- A reservation whose `pi_restart_thread_id` is not the acting thread is not sent on the acting thread. The implementer's key is not a resume of the reviewer, and the reviewer's key is not a resume of the implementer.
- A new interruption reserves one resume when `pi_restart_resumes` is below 2. One write stores a new key, the acting thread id, and the observed turn id, sets `pi_restart_reservation` to `pending`, and adds 1 to the count. The tick then sends that key to that thread, with this text: `Your previous turn was interrupted by a server restart. Check git status and git diff, finish the subtask, and hand off with the run script.` The interrupted turn's key is not reused. Replacing a `pending`, `accepted`, or `superseded` reservation does not subtract from the count.
- When the count is already 2, a new interruption asks for assistance and does not send. That includes a restart during the accepted resume, a restart during a following normal turn, a restart during the turn a resolution started, and a restart on the other role. The reason matches any other failed thread: `The implementer thread failed.` while the subtask is `running`, and `The reviewer thread failed.` while it is `reviewing`.
- When the send returns, the tick sets `communication_failures` to 0 and does not change the count, the key, the thread id, the source turn id, or the reservation.
- When the send throws, the pending reservation stands. The throw is a communication failure. The fifth consecutive communication failure asks for assistance, as it does for any other failed send. The next tick repeats the stored key only when the same thread still shows that same source turn id.

The reservation is the cap. An accepted send whose response is lost is already counted. A process that stops after Pi accepts, and before another write, does not free that count. The next tick sees the turn id equal the stored key, marks the reservation `accepted`, and does not spend another resume on that same acceptance. A following turn keeps its own id, so the tick does not read that id as proof the reserved key is still unaccepted.

The tick does not read the receipt, run the rubric, or send a reminder on this path. It does not install the run script again. The script from the interrupted turn remains at `.git/orbit/run`.

A `failed` thread that misses any condition asks for assistance on the first observation, with no resume. That includes a Pi thread with a different error, a Pi restart with no turn id, and every thread on another driver. A planner thread is not an acting thread, so this rule does not read it.

### Roll out a Pi server binary

Pause the Gateway scheduler before replacing `pi-server`. Stop the Process that runs `php artisan schedule:work` in the Gateway checkout. `process:stop` returns when that unit is inactive. The cache lock is not the proof that `tasks:tick` has exited. The command holds `orbit:tasks:tick` for 300 seconds and releases the lock when it returns. The lock can expire while the command is still running. On the host that runs the Gateway checkout, continue only when no process is running `artisan tasks:tick`.

Do not read `working` from `tasks:agents` or from `GET /api/v1/task-groups/{group}/agents` for this wait. Those routes return the stored row. The tick writes that row, and the tick is paused, so a finished turn can stay `working`. The agents payload still has `external_id`. `GET /sessions/{external_id}` on the Node's Pi server is the live snapshot. Wait until its `state` is not `working`. The first `snapshot` event on `GET /api/v1/task-groups/{group}/agents/{thread}/stream` is that same snapshot. The stream does not write the stored row. Replace the binary that the Node's `pi-server` Process runs, then restart that Process. Start the scheduler Process again.

A turn that is still `working` at that restart fails with the restart error. The next tick resumes it under the rule above. Two reserved accepts are the cap. The operator does not post a resolution comment for that failure. [Tasks](/reference/tasks#recover-a-pi-server-restart) and [Pi server](/reference/pi-server#roll-out-a-new-binary) state the steps.

## Rejected alternatives

- Ask for assistance on the restart error: rejected because the checkout still holds the work and the same thread can finish it. On 2026-09-27 that assistance wait kept the resolution comments for groups 129 and 133 from being sent.
- Retry the interrupted turn's send key: rejected because accepted keys persist across a restart, and a repeated key starts no turn.
- Start a new Pi thread: rejected because the transcript and the partial edit stay on the existing session.
- Resume with no cap: rejected because a restart loop would send the continue on every tick. Two reserved resumes cover one upgrade and one retry. The third interruption asks for assistance.
- Count a resume only after the send returns: rejected because Pi can accept the send while the response is lost, or the process can stop before that write. The next restart of that accepted turn would then send an uncounted resume.
- Treat every other turn id as an unaccepted reservation: rejected because an accepted resume can finish, and a reminder, a review relay, or a resolution can then start another turn on that thread. A restart during that turn has a new id. Posting the old key again is a duplicate and starts no turn, so the tick would repeat it without recovering the new turn. A key reserved for the implementer is the same mistake on the reviewer.
- Reset the count when a resolution is delivered: rejected because a restart after that delivery would keep spending resumes with no cap.
- Resume a thread that is not Pi, or a Pi thread with another error: rejected because those failures stay on the assistance path.
- Send the continue as a resolution comment: rejected because a resolution is delivered only while assistance is already set. This recovery does not set assistance, so the comment is not sent.
- Use different text for the reviewer: rejected because one message tells the agent to inspect the checkout and finish through the run script. The script still refuses an outcome for the wrong role.

## Consequences

- A Pi restart sends at most two continues per subtask and does not ask for assistance for those two.
- The third restart on that subtask asks for assistance with the failed-thread reason.
- Another failure still asks for assistance on the first observation.
- A resolution posted before assistance is set is stored and not delivered.
- A binary swap that kills a `working` turn still heals through the same resume.
- A lost response, or a process stop after Pi accepts, does not drop the count. The stored key matches that accepted turn id on that thread, so the tick marks the reservation `accepted` and does not send another resume for the same acceptance.
- Two reserved resumes are the cap. The count survives a resolution and a process stop. A third interruption asks for assistance, including a restart after a resolution that already used both resumes.
- The stored key is repeated only for the same thread and the same source turn while the reservation is `pending`. A following turn, and a switch from implementer to reviewer, reserves a new key or asks for assistance when the count is 2.
- The reviewer receives the same continue text when its driver is `pi`. The reviewer driver in [ADR 0116](/decisions/0116-run-task-implementers-on-pi) stays `t3` unless an operator selects `pi`.

## Affects

- Components: apps/gateway, apps/docs
- ADRs: extends [ADR 0116](/decisions/0116-run-task-implementers-on-pi)
- Detail: [Tasks](/reference/tasks#recover-a-pi-server-restart), [Pi server](/reference/pi-server#roll-out-a-new-binary)
- Verify: `composer docs-lint`; Gateway tests that the restart error reserves a new key for that thread and source turn, sends that key, and does not ask for assistance; that a second observation of the same unaccepted source turn retries the stored key and does not increment; that an accepted resume whose response is lost, followed by a restart of that accepted turn, marks the reservation `accepted` and does not send that key again; that a throw before accept retries the stored key and does not increment; that a restart during a normal turn which started after an accepted resume, such as a reminder, does not send the accepted key and reserves a new key when the count is below 2; that a restart after a resolution when the count is already 2 asks for assistance and does not send; that an implementer reservation is not sent to the reviewer, and the reviewer reserves a new key or asks for assistance when the count is 2; that the third interruption after two reserved resumes asks for assistance with the failed-thread reason; that another Pi error, a missing turn id, and a failed T3 thread ask on the first observation; and that a resolution does not reset `pi_restart_resumes`
