# Task consults and direction requests: Incus proof

## Result

The leased Gateway ran the branch's scheduler in one Tinker process with a scripted `AgentDriver`. Disposable task 7, subtask 8, and Project 7 exercised a reviewer consult, a direction request, a CLI-posted operator resolution, and the reviewer's relay. The two question records finished `answered`; both task and subtask settle metrics reported **2 questions and 1 escalation**. Cleanup passed and the lease was released.

Agent turns came from a scripted AgentDriver bound in-process; no live Pi or T3 model ran. Gateway feature tests cover the drivers.

## Source and environment

- Branch: `task-753`.
- Running source commit: `fdaf17b4f133fd8ed842d3d9fde58f7611c6215d`, plus the proof helpers in this diff. No product code was patched.
- Bridge: `/fast/worktrees/orbit/task-753-e2e`.
- Discovery lease: `0fe465d650e58420fa3ee73124991aea`, acquired `2026-10-01T22:22:34Z`.
- Profile: `gateway_app-dev_app-prod`.
- Successful run: `2026-10-01T22:47:19.365Z` to `2026-10-01T22:47:34.923Z`, recorded duration 15,557 ms.
- Original append-only log: `/fast/worktrees/orbit/task-753-e2e/.e2e/evidence.log`.
- Preserved evidence: `task-consults-evidence.txt` beside this file. It contains eight verbatim `--record` entries, including the complete successful run, focused deliverable entries, and cleanup. Earlier failed development runs remain in the original log and are described below.
- Release result: `task-consults-release.json`. All three owned VMs and the owned network were deleted; no other network was reaped.

Guest Git metadata points at an unmounted host directory. The host bridge HEAD was verified instead, and the recorded source label contains matching guest hashes of the scheduler, notifier, and proof helpers. The product hashes are:

```text
f5aa7a997fc25eb800b8ccf917a2f081951383409d66e117d1fbc5d747926f49  TaskScheduler.php
1adbd5f166b7a2e195af73d2d56ebc1a50d858cf9336ba3c33edf7c1415345a3  HttpCoderSettleNotifier.php
```

## Evidence labels

All labels are in `task-consults-evidence.txt`. Each header records the actual command, UTC start/end, exit code, and output. Every preserved command exited zero.

| Deliverable | `--record` label | Observed result |
| --- | --- | --- |
| `incus-consult` | `760 incus-consult answered and continued` | Question 5 is `answered`, `answered_by=reviewer`, `cause=missed_contract`, and has no escalation time. The implementer received the answer and wrote its continuation into the real workspace. Its completion attempt remained 1, status remained `running`, and no assistance was requested. |
| `incus-direction` | `760 incus-direction webhook resolution and relay` | Human `tasks:status` says `Needs your direction`; JSON carries `assistance_kind=direction` and the question. The received assistance webhook carries `kind=direction` and that same question, with a valid HMAC. The CLI operator resolution returned HTTP 201 and exit 0. Reviewer thread 4 received it as a relay and sent the translated answer to implementer thread 3. Question 6 became `answered_by=operator`, with `cause=contract_gap` and its escalation time retained. |
| `incus-records` | `760 incus-records question list and settle metrics` | Captured human and JSON `tasks:question:list --project=7` output shows exactly records 5 and 6. Stored task and subtask metrics each show 2 questions and 1 escalation. The real signed `task_group.settled` webhook carries those counts too. |
| Source and limitation | `760 source commit and scripted-driver limitation` | Names the running commit, hashes the mounted product and helper files, and records the required scripted-driver limitation. |
| Whole flow | `760 successful scripted scheduler flow` | Tinker output includes the real baseline check, every scripted turn, installed turn-command receipts, record snapshots, CLI output, settle metrics, received webhooks, and the cleanup assertion. |
| Webhook transport | `760 signed webhook receiver log` | The local receiver logs actual HTTP posts and verifies their timestamp/body HMAC. Entries for failed earlier fixtures are distinct from the successful task 7 events. |
| app-dev cleanup | `760 app-dev final leftover audit` | The fixture checkout and its check process are absent. |
| Gateway cleanup | `760 confirmed gateway leftover audit` | Projects and Instances return to 3 and 4; tasks, questions, and threads return to zero. The extension is disabled, foreign-key checks are clean, the temporary profile/TLS keys are gone, and neither fixture port has a listener. |

Question 5 asks which cause applies when the contract already answers a question. The reviewer answers from ADR 0187 with `missed_contract`. Question 6 asks which database the disposable fixture should use. The reviewer cannot choose an operator preference, so it escalates that same record with `contract_gap`. The operator comment says `Use SQLite only for the disposable TASK-753 fixture.` The reviewer relays that direction to the implementer without starting a new attempt or creating a third question.

## How the proof runs

`task-consults.php` implements only the scripted driver boundary, modeled on Gateway `tests/Support/FakeAgentDriver.php`. It binds `AgentDriverRegistry` with `app()->instance(...)` before resolving the scheduler. It does not replace the scheduler, spawner, turn-receipt reader, comment action, question model, assistance handler, metrics collector, SSH executor, or notifier. The driver writes its receipts through the real `.git/orbit/turn` installed by the engine, over real SSH to app-dev.

The script seeds an isolated running group and a real Git checkout at `/home/orbit/task-760-workspace`. The real baseline check runs before the implementer starts. Bounded scheduler ticks then produce the first answer and the second escalation. Normal Gateway HTTPS serves the status and question-list CLI calls. CLI output is saved at the time of those calls, before records are cleaned up; the focused labels print those saved outputs rather than claiming the deleted rows are still present.

The resolution must use the same registry binding as the scheduler. A temporary TLS listener at `10.44.0.1:18760` therefore serves only the CLI's extension discovery and resolution POST through the real Laravel HTTP kernel in that Tinker process. It checks the actual TCP peer is the Gateway's WireGuard address; the normal authentication, access, route binding, and validation middleware still run. A private fixture profile trusts only the generated one-day fixture certificate. No existing profile or certificate is changed.

`task-consults-webhook.py` listens only at `127.0.0.1:18761`. The in-process notifier configuration points to that local receiver with a disposable fixture secret, not a shared credential. The receiver saves actual bodies and checks the HMAC. The proof requires exactly the assistance and settle events for its successful run.

To enter the scheduler process after preparing the owned fixtures and starting the receiver with `spawn`:

```sh
bin/e2e-topology exec TASK-753 gateway \
  --argv='["sh","-c","cd /home/orbit/orbit/apps/gateway && php artisan tinker --execute '\''require \"/home/orbit/orbit/apps/e2e/resources/proofs/task-consults.php\";'\''"]' \
  --timeout=300 --record='760 successful scripted scheduler flow'
```

These helpers are task-specific proof fixtures, not an installed runtime. Their ownership guard deliberately names the recorded lease. For an independent reproduction on a newly acquired lease, update both helpers' lease guard and the fixture ownership marker to that lease's id. Do not use them against a live Gateway. Before entry, create `/home/orbit/task-760-proof` exclusively with mode 0700, and write this marker:

```json
{"issue":"TASK-753","subtask":760,"lease":"0fe465d650e58420fa3ee73124991aea"}
```

Generate a local TLS certificate with IP SAN `10.44.0.1`, place the certificate/key bundle at `tls.pem`, and create a mode-0600 `cli/config.json` pointing to `https://10.44.0.1:18760` with `ca_path` set to the fixture certificate. Keep all these files under the owned fixture directory. Start the Python receiver through `bin/e2e-topology spawn`, not a backgrounded `exec`. The PHP proof refuses another task, an existing fixture Project or checkout, an already-enabled extension, unexpected driver calls, and unexpected resolution requests. It fails on missing answers, duplicate questions, changed attempts, wrong causes, missing webhook fields/signatures, or incorrect counts.

Save the evidence before removing Gateway artifacts. The PHP `finally` block stops any running fixture check, removes the owned app-dev checkout and database rows, restores the disabled extension, and checks baseline counts and foreign keys. Stop the receiver with `kill`, verify the ownership marker before deleting `/home/orbit/task-760-proof`, audit the listeners and both Nodes, and release the lease. The final recorded audit and release result demonstrate those steps for this run.

## Checks

- Root `composer check`: passed, including all Project checks and explicit feature-test selections. Final receipt is recorded by the task handoff check.
- E2E `composer test:affected`: passed with no affected tests selected. E2E `composer check`: passed; its guidance suite reports 17 warnings, not failures.
- Explicit Gateway feature run: **294 tests passed, 2,057 assertions**, covering scheduler ticks, assistance kinds, comment resolution, Pi driver behavior, driver routing, webhook transport, and task APIs. A second explicit run of `T3AgentSpawnerTest`, `HttpT3ThreadReaderTest`, `HttpT3DispatcherTest`, `LocalTaskSettleMetricsCollectorTest`, and `TurnReceiptTest` passed **108 tests, 564 assertions**.
- The Incus proof itself is an executable assertion test, not just transcript inspection. It checks all three deliverables and cleanup and exits nonzero on failure.
- PHP syntax and Python compilation checks passed. Pint's dirty check passed. Resource fixtures are outside E2E's normal Pint/Larastan application paths.
- Root documentation lint passed. No maintained contract or product behavior changed in this subtask.

## Limitations and failed setup attempts

- Agent turns came from a scripted AgentDriver bound in-process; no live Pi or T3 model ran. Gateway feature tests cover the drivers.
- No Pi/T3 runtime, model credential, or TypeSafe key was provisioned. No shared credential file was read or copied. The initial access blocker was resolved by the operator's explicit scripted-driver direction, not by granting access.
- This proves deterministic question routing and persistence, not an agent's ability to reason about the contract. The opening running group and checkout are fixture setup rather than a repository claim/provision proof.
- To exercise the real settle collector and notifier without a live repository, the fixture marks its subtask completed and its group settling, with `https://example.invalid/task-760-proof` as the pull-request URL. No approval, push, actual pull request, merge, or cleanup-through-cancel lifecycle is claimed. Token and line-diff values of zero are fixture values; no model usage is measured.
- The first script attempt expected an immediate implementer before the asynchronous baseline had finished. The second attempt reached escalation but the temporary CLI profile was not mode 0600. The third completed the resolution relay but used the nonexistent fixture enum case `TaskStatus::Done`. Those fixture errors were corrected with bounded baseline polling, a private profile, and `TaskStatus::Completed`. Their database rows/checks were cleaned, and their receiver bodies were kept separate before the final successful run. No product patch was needed.
- An early diagnostic read incorrect token/key configuration paths. Its booleans are not evidence; the later `760 confirmed runtime access boundary` entry used the actual configuration paths. Guest `git rev-parse` failed for the bridge-mount reason recorded above.
- An extra post-cleanup CLI discovery call timed out, giving `760 gateway final leftover audit` a nonzero exit after the fixture directory had already been removed. The successful `760 confirmed gateway leftover audit` rechecked the real database, extension, foreign keys, paths, and listeners and is the authoritative final audit.
- `composer guidance:update` could not run `boost:update` because E2E disables Boost discovery. Existing `guidance:check` and `composer check` passed; no application code or guidance was changed.
- Affected-test selection reported empty selections in E2E and some unchanged Projects. Explicit feature runs and the root gate's fallback selections are recorded; an empty selection is not treated as proof of the feature.
- Activity/SSH command history and journals were retained as audit evidence while the lease existed. They disappeared with its VMs. No route, publication, shared snapshot, live resource, or other task fixture was changed.
