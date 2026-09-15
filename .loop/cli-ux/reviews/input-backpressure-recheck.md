# Independent correction recheck

Result: the original blocking-input and false partial-send completion finding is closed for candidate `b58b6077b1190e20c3494d5934972221481dfb24`.

Read-only candidate binding compared HEAD and every exercised recorder/verifier/skill/test file with the exact committed blob before and after the reproductions and again after the full suite. SHA-256 hashes are in candidate-binding.json. No source, Git state, or proof fixture was edited.

Fresh macOS results, using the original raw-mode fixture and the same 1,048,577-byte planned input:

| Case | Child | Capture | Verifier | Capture duration |
| --- | --- | --- | --- | --- |
| 0.5-second wall deadline | 143 | 124 | 1 | 0.633 s |
| 0.5-second idle deadline | 143 | 124 | 1 | 0.612 s |
| Child exits zero after 3 seconds without reading | 0 | 125 | 1 | 3.132 s |

Each case records 1,022 bytes actually written and 1,047,555 pending bytes. The instrumented system-call return totals, input-event totals, and summary totals agree. All actions remain 0/1 complete, and no input event falsely marks completion. Both deadlines remain responsive. Every fixture child is gone after cleanup. The formerly passing initial-prompt/exit-zero expectation now rejects the incomplete capture.

All 38 existing tests passed on macOS in 16.522 seconds; tests.stderr retains the complete test output. This includes the added large scripted/live-input backpressure cases and forced short-write/EAGAIN successful-delivery control.

The skill accurately distinguishes bytes accepted by the PTY from bytes consumed or acted on by the child. This recheck closes only the previously reported tooling defect. It is not formal PR approval, whole-command adoption proof, Linux proof, Solo visual acceptance, or Incus acceptance. Those outcomes require their own retained evidence.

Reproduction: run.py records the source-bound cases into a new empty temporary directory; input-raw-backpressure.py and count_writes.py are copied unchanged from the original reproduction. The retained case directories include exact invocation, input plan, raw/chunk/frame files, actual-write audit, expectations, verifier verdicts, and summaries. results.json aggregates the inspected outcomes.
