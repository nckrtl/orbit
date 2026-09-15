# Terminal recorder

Run these commands from the `verifying-cli-output` skill directory. Install `scripts/requirements.txt` in an isolated Python environment and use that environment's Python.

## Capture

```bash
python scripts/capture.py --output-dir NEW_PATH --candidate SHA --label CASE -- COMMAND...
```

Use a new output directory for each capture. Verify the running source or binary separately; `--candidate` records the supplied label.

| Option | Use |
| --- | --- |
| `--columns`, `--rows` | Terminal size; defaults to 100 by 40 |
| `--timeout`, `--idle-timeout` | Limit total duration and waiting without output |
| `--input-plan PATH` | JSON list of `wait_for` and `send` strings for non-secret test input |
| `--no-live` | Suppress forwarding for automated recorder tests |

When Solo is requested, use a dedicated visible terminal in the assigned host or guest. The recorder forwards interactive input but does not retain its contents. Scripted input is sent after its matching prompt appears. Verify the command's response: delivery to the terminal only confirms the bytes were sent.

Run capture as its own process. It cleans up its child terminal session, including processes in that session. Test commands must stay in that session so cleanup can identify them. Linux uses a child subreaper; other supported POSIX systems use the system reaper.

## Read the evidence

| File | Content |
| --- | --- |
| `raw.bin` | Original terminal output bytes |
| `chunks.jsonl` | Decoded output and timing |
| `frames.jsonl` | Reconstructed screen frames and cell styles |
| `transcript.txt` | Readable transcript |
| `input-events.jsonl` | Scripted input delivery events |
| `summary.json` | Child result, timing, input counts, and collector errors |

Capture exit code 124 means a timeout. Code 125 means a collector error or incomplete scripted input. Other results retain the child exit status. If the summary file cannot be written, capture prints it to stderr and exits 125. Treat those recordings as incomplete.

Command arguments are omitted from metadata to protect credentials. Keep the safe command invocation and runtime identity with the test case. Protect raw files that contain secrets and share sanitized results.

## Verify

```bash
python scripts/verify.py --capture PATH --expect EXPECTATION.json
```

An expectation requires `candidate`, `label`, `exit_code`, and output assertions.

| Field | Check |
| --- | --- |
| `contains`, `absent` | Visible text in reconstructed frames |
| `final_contains` | Text in the final frame |
| `max_first_output_seconds` | Delay before first output |
| `max_idle_gap_seconds` | Longest gap between output chunks |
| `state_rows` | Named rows, allowed states, transitions, and required states |
| `animation_rows` | Glyph changes, timing, and completion of named rows |

Each `state_rows` entry uses `name`, a `pattern` with the named regex group `state`, allowed `states`, `transitions` as state pairs, and `required` states.

Each `animation_rows` entry uses `name`, a `pattern` with the named group `glyph`, a `terminal_pattern`, `minimum_changes`, `min_interval`, and `max_interval`. The maximum interval includes the time between the last glyph change and completion. Keep the row visible throughout the case. Choose timing bounds that account for capture resolution and the documented cadence.

Inspect full frames and timing alongside assertions. Missing frames limit what a recording can establish about animation. Cell styles include color, bold, and dim intensity; inspect their readability in the target terminal.

For an expected failure, verify the nonzero child status, output, and side effects. A successful verifier result means that those expectations passed.

## Check recorder changes

After changing capture or verification scripts, run:

```bash
python -m unittest discover -s tests -v
```

These tests exercise terminal restoration, child-process cleanup, signals, timeouts, and collector failures. They check the recording tools; feature behavior needs its own recorded cases.
