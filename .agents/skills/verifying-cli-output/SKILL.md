---
name: verifying-cli-output
description: Record and inspect Orbit command behavior in real terminals, including prompts, animation, ANSI repainting, wrapping, liveness, cancellation, and terminal-versus-pipe differences.
---

# Verifying CLI output

Read `docs/reference/cli-ux.md` and the command's current contract. Work within the assigned issue and delivery flow. Use the current topology harness for Incus work; this skill defines evidence, not topology or merge policy.

## Record

Run the exact candidate source or binary in its intended runtime. Record the commit, launcher path, terminal size, and relevant environment, including TERM, NO_COLOR, explicit decoration, input interactivity, and machine-output flags. Do not store secrets in command metadata or scripted input.

Use an interactive terminal visible to the operator. When Solo is requested, create a dedicated terminal and enter the assigned host or disposable guest shell before running command cases. Use the session-authorized SSH identity and do not replace it with an agent that prompts for unrelated credentials.

Retain timestamped raw PTY bytes, decoded chunks, reconstructed screen frames, child exit status, duration, first-output delay, maximum idle gap, and timeout/cancellation outcomes. The recorder must forward interactive input, preserve terminal size, decode split UTF-8 correctly, drain final output, and clean up its own child processes without affecting other sessions.

## Inspect

Check actual prompt choices, keyboard selection, validation retry, cancellation, consent, and follow-up action. Compare exact columns and values, not just whether their words occur somewhere.

Inspect full reconstructed frames for alignment, borders, wrapping, colors, readable active/completed labels, and terminal-only summaries. For liveness, inspect changes near the required 300 ms cadence while work is active. Chunk arrival alone does not prove a glyph changed. Confirm waiting-to-running-to-terminal transitions and reject regressions or invented milestones.

Test plain/piped and machine modes separately. They must not receive escape sequences or interactive prompts. Verify each command's existing JSON or stream shape and exit contract. Human truncation must not remove machine data.

Expected failure cases require assertions on status, output, and side-effect boundaries. A verifier can exit zero after proving the expected nonzero child result; never suppress failures with an unconditional success wrapper.

## Handoff

Attach command cases and their evidence to the adoption matrix. State the actual runtime and any evidence limitation. Preserve recordings before review, and record again after a relevant correction. Distinguish inspectable transcript content from proved animation and interaction. Human review follows your artifact inspection.

Retain the full recording directory, not just the harness's stdout/stderr tails. A proof fixture writes recordings to an issue/attempt/case-specific guest path and emits a bounded manifest of file names, sizes, and SHA-256 hashes before the harness captures proof. Keep complete files on the retained Node. Export them through supported recorded read actions before topology release, using bounded chunks if output limits require it, and verify every exported size and hash. Record the durable export location in the matrix. Do not edit immutable captured evidence to add later review results; retain interactive Solo recordings in the separate review record. Protect secret-bearing raw files and share sanitized findings.

Use the actual supported operating system for platform-specific commands. An Ubuntu guest can prove a macOS-only command's unsupported-platform refusal, but cannot prove its success path. Name a disposable macOS runtime for that case; mocks or a forced platform label are not native success proof.

## Tools

Create and activate an isolated Python environment, then install `scripts/requirements.txt` with `python -m pip install -r scripts/requirements.txt`. Use that environment’s `python` for every command below. Run `python -m unittest discover -s tests -v` from this skill directory after changing either script. These are recorder and analyzer tests, not proof that an Orbit command complies.

Run `python scripts/capture.py --output-dir NEW_PATH --candidate SHA --label CASE -- COMMAND...`. The output directory must not already exist. Default terminal size is 100 columns by 40 rows; set `--columns` and `--rows` for the named case. Set bounded `--timeout` and `--idle-timeout` values appropriate to the operation. `--no-live` suppresses forwarding to the parent terminal for automated recorder tests; actual Solo proof keeps live forwarding.

Cleanup owns the child PTY session, including descendants that form another process group in that session. Fixtures must not daemonize or create a detached session; the recorder cannot safely identify those descendants after reparenting. It never signals another session or selects processes by command name. Linux uses a child subreaper to reap orphan descendants; other supported POSIX systems use their system reaper. Run the recorder as its own process, as shown above.

The lifecycle tests use a real outer PTY and private child trees. They cover success, child failure, SIGINT/SIGTERM/SIGHUP, both timeouts, and collector I/O failures. They compare terminal settings, inspect cursor state, check owned PIDs, and require an unrelated sentinel to survive. Fault injection also checks that a failed restoration cannot skip process cleanup. A rejected terminal operation remains a collector failure; it cannot prove successful terminal restoration.

The recorder forwards interactive input without storing its contents. `--input-plan PATH` supports a JSON list of objects with `wait_for` and `send` string fields. Each input is sent only after the matching prompt text appears. Use only non-secret disposable fixture input. Input uses a nonblocking queue so a child that stops reading cannot block capture deadlines. Each event records bytes actually written, with `complete` marking the end of that queued input; one scripted action can span several events. `input_actions_sent` counts only fully delivered actions. `input_bytes_sent` totals delivered bytes, and `input_bytes_pending` reports queued bytes left at exit. An unsent planned action or undelivered queued input makes an otherwise successful capture fail. Delivery means the PTY accepted the bytes; it does not prove the child consumed or acted on them.

`summary.json` separates the child exit status from collector failures and lists `collector_errors`. If writing that file fails, the recorder emits the summary on stderr and exits 125; the recording is incomplete and cannot pass verification. Capture exit 124 means a timeout; 125 means a collector or incomplete-input failure. A child's expected nonzero exit remains nonzero until the separate verifier proves that negative case. `raw.bin`, `chunks.jsonl`, `frames.jsonl`, `transcript.txt`, and `input-events.jsonl` retain evidence. Arbitrary command arguments are omitted to avoid credential leakage; the separately retained case definition must record the exact safe invocation and runtime identity. A supplied candidate label alone is not candidate proof: verify it through the current harness and the guest source or artifact identity.

Run `python scripts/verify.py --capture PATH --expect EXPECTATION.json`. An expectation requires `candidate`, `label`, and `exit_code`, plus output assertions. `contains` and `absent` inspect visible reconstructed frames; `final_contains` inspects the final frame. Optional first-output and idle bounds use `max_first_output_seconds` and `max_idle_gap_seconds`.

For a state row, supply `state_rows` entries with `name`, a `pattern` containing the named regex group `state`, admitted `states`, permitted `transitions` as state pairs, and `required` observed states.

For cadence, `animation_rows` entries use `name`, a `pattern` containing the named group `glyph`, a `terminal_pattern` identifying that row's completed/failed/cancelled state, `minimum_changes`, `min_interval`, and `max_interval`. The first glyph is an observation, not a change. The maximum interval also bounds the active tail through the terminal-state observation. Keep the row visible throughout the case; disappearance or an active final frame cannot establish completion. Choose timing bounds that account for capture resolution and the documented cadence. Inspect chunk timing when terminal writes coalesce; missing intermediate frames are a proof limitation.

Frames include each cell's foreground/background, bold and dim intensity, and other supported style attributes. The recorder extends pyte to preserve SGR 2 and its resets; real Solo inspection still determines whether those attributes are readable in the target terminal. These case-specific checks supplement full-frame inspection; they do not infer backend scheduling, consent, side effects, or whole-command compliance.
