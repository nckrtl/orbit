---
title: "ADR 0168: Offload large Pi tool output"
sidebarTitle: "0168 Offload Pi tool output"
description: "Proposed. A Pi read or bash result larger than 8 KiB is stored under the workspace .git directory. The model receives the path, the size, and a short preview."
---

# ADR 0168: Offload large Pi tool output

When a `read` or `bash` result in an Orbit Pi session is larger than 8 KiB of text, the Pi server writes the full text under the workspace's `.git/orbit/tool-output/` and returns a short notice. The model context keeps the path, the size, a short preview, and the `bash` exit code. Output at or under 8 KiB stays as it is. The file is removed with the workspace and never leaves the Node.

## Status

Proposed.

This extends the Pi session tools in [ADR 0116](/decisions/0116-run-task-implementers-on-pi). The tool names stay `read`, `bash`, `edit`, and `write`. [ADR 0160](/decisions/0160-push-each-approved-subtask-and-remove-the-finished-workspace-clone) already deletes the workspace clone, and these files live inside that clone.

## Context

The text of a tool result is appended to the Pi session. Every following model call sends that session again. A large `read` or `bash` result is therefore paid for on every call after it, not once.

[ADR 0165](/decisions/0165-record-per-thread-token-metrics) records the baseline for groups 109 through 125. On that same baseline, implementer tool output dominates the context:

| Measure | Value |
| --- | --- |
| Tokens in the baseline | 596 million |
| Implementer tool output in context | 332 million tokens, 56 percent of the total |
| Replay at an 8 KiB cap | about 67 million fewer tokens |

Pi's own `read` and `bash` tools cut a result at 50 KiB or 2,000 lines, whichever comes first. `bash` keeps a tail and writes the full output to a file in the system temp directory. `read` keeps a head and does not keep a second copy of the file. That cut still leaves up to 50 KiB in the session. The temp file is outside the workspace, so removing the workspace leaves it behind, and the model may be unable to open a path outside the workspace.

Eric Zakariasson's harness advice is to write a large output to a file and return the path, the size, and a short tail. Cutting the output without a file loses data. Leaving the full output in the transcript sends it again on every following request.

The review workspace tree is a Git tree of the working tree, including uncommitted and untracked files. Git does not list paths inside `.git`, so a file there stays out of `git diff` and out of that tree. [ADR 0133](/decisions/0133-verify-typed-subtask-deliverables-at-handoff) records the tree the Gateway stores when review starts.

## Decision

The Pi server owns the rule. It applies the rule to the finished `read` or `bash` result before that result is appended to the session. The Gateway does not fetch or store the file. `edit`, `write`, and `search_docs` are unchanged.

### Which text is measured

8 KiB is 8,192 bytes of UTF-8. The server measures the text the tool decoded. Invalid bytes are already replaced by that decoding.

| Tool | Measured text |
| --- | --- |
| `read` | The selected line range when the call sets `offset` or `limit`; otherwise the whole file |
| `bash` | Stdout and stderr in the order the tool read them, without the exit, abort, or timeout line |
| `read` of an image | Not measured. The image result is unchanged |

A measured text of 8,192 bytes or fewer is returned in full. Pi's 2,000-line cut does not apply to it. The `bash` exit code stays in the form that result already uses: success has no exit line, and a non-zero exit keeps `Command exited with code N`.

A workspace whose `.git` entry is not a directory returns the full measured text, with no byte cap and no file. Orbit task workspaces are clones, so `.git` is a directory.

### Where the file goes

When the measured text is larger than 8,192 bytes and `.git` is a directory, the server writes that text and nothing else to a new file at `<workspace>/.git/orbit/tool-output/`. The workspace is the session's current directory. The server creates `orbit/tool-output` with mode `0700`. The file mode is `0600`.

The file name is the tool name, the session id, and the tool call id, with every character outside letters, digits, `.`, `_`, and `-` replaced by `_`, then `.txt`. When that name is already present, the server adds a suffix and does not replace the existing file. The notice carries the absolute path that was written.

The file remains until the workspace clone is removed. Idle unload, a server restart, and the end of the turn do not delete it. The next turn can still open the path. Removing the clone removes the file. The server does not copy it to the Gateway, the web app, or another Node, and Git does not push it.

When the file cannot be written, the tool result is an error. Its text is `{bytes} bytes, {lines} lines, not saved: {reason}`. It does not include the output.

### What the model receives

The stored result, which the stream then shows, is at most 8,192 bytes and contains no other output. Lines split on `\n`. A final `\n` does not add a line.

The first line is `{bytes} bytes, {lines} lines, saved to {path}`. `{bytes}` and `{lines}` describe the file. When the preview below has fewer lines than the 20 or 40 lines asked for, that line continues ` Preview shows {shown} lines.`

The preview is the first 20 lines for `read` and the last 40 lines for `bash`. The server drops whole lines until the notice fits in 8,192 bytes: from the end of a `read` preview, and from the start of a `bash` preview. A line that does not fit is left out. The file keeps the line whole.

The next line is exactly `View more with the read tool using offset and limit, or grep on that file.`

A `bash` notice ends with one status line. `Exit code: N` is that line when the process exits, including `0`. `Command aborted` is that line when the command is aborted. `Command timed out after N seconds` is that line on a timeout. The server does not invent an exit code for an abort or a timeout.

Exit code 0 is a successful tool result. A non-zero exit, an abort, and a timeout stay failed tool results. The failure text is the notice.

A following `read` of the saved file uses this same rule. A slice of 8,192 bytes or fewer returns in full. A larger slice is written to another file.

The `read` and `bash` descriptions tell the model that text over 8,192 bytes is stored under `.git/orbit/tool-output/` and that the result contains the path, the counts, and a short preview. They do not describe a 50 KiB or 2,000-line inline result.

```text
90000 bytes, 100 lines, saved to /srv/orbit/apps/acme/.git/orbit/tool-output/bash-session-call.txt
<last 40 lines, or fewer when the notice would pass 8192 bytes>
View more with the read tool using offset and limit, or grep on that file.
Exit code: 0
```

## Rejected alternatives

- Cut the result at 8 KiB and discard the rest: rejected because the dropped text is gone, and a following turn cannot recover it.
- Keep inlining the full tool output: rejected because groups 109 through 125 put 332 million tokens of tool output into context, 56 percent of 596 million, and each following call sends that text again.
- Keep Pi's 50 KiB and 2,000-line cut, with the bash remainder in the system temp directory: rejected because the session still holds up to 50 KiB, the replay's saving is at 8 KiB, and the temp file outlives the workspace.
- Return the tail for `read` as well as `bash`: rejected because a file is read from the start. `bash` keeps the tail, which matches the short-tail advice. `read` keeps the first lines.
- Write the file into the working tree: rejected because `git diff` and the review workspace tree would then include it.
- Send the full text to the Gateway or the web viewer: rejected because the file stays on the Node. The transcript carries the notice.
- Delete the file at the end of the turn: rejected because the transcript still names the path, and a following turn reads it.
- Offload `edit`, `write`, and `search_docs` with the same rule: rejected because this record covers the `read` and `bash` dumps that dominate the measured tool output.
- Inline the output when the file cannot be written: rejected because a full disk would put the large result into context, which is the cost this record removes.

## Consequences

- A `read` or `bash` result over 8,192 bytes adds at most 8,192 bytes to the session. The full text is on the Node that runs the session.
- A result of 8,192 bytes or fewer is unchanged, including a result Pi would have cut for passing 2,000 lines while staying inside 8 KiB.
- The web app and the Gateway show the notice. They do not show the file.
- The Node holds the full text until the workspace clone is removed. A command that prints a large stream writes that stream once, under `.git`.
- Diffs and the review workspace tree do not include the files.
- A session whose workspace has no `.git` directory keeps a large result in context.
- A failed write drops the output and returns an error that names the size and the reason.
- Idle unload and restart do not delete the files. Removing the workspace does.

## Affects

- Components: apps/docs
- ADRs: extends [ADR 0116](/decisions/0116-run-task-implementers-on-pi)
- Detail: [Pi server](/reference/pi-server#large-tool-output)
- Verify: `composer docs-lint`; Pi server tests that a `read` or `bash` result of 8,192 bytes stays inline, a result of 8,193 bytes is the notice above with the full text under `.git/orbit/tool-output/`, `read` previews the first lines and `bash` the last, the `bash` exit code remains on a failed and a successful offload, and a missing `.git` directory does not write a file
