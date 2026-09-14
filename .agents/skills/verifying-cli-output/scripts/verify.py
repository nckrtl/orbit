#!/usr/bin/env python3
"""Check explicit case expectations against a PTY recording and its frames."""

import argparse
import json
from pathlib import Path
import re
import sys


def verify(directory, expectation):
    summary = json.loads((directory / "summary.json").read_text())
    metadata = json.loads((directory / "metadata.json").read_text())
    frames = [json.loads(line) for line in (directory / "frames.jsonl").read_text().splitlines()]
    failures = []
    required = {"candidate", "label", "exit_code"}
    allowed = required | {"contains", "absent", "final_contains", "max_first_output_seconds",
                          "max_idle_gap_seconds", "state_rows", "animation_rows"}
    if not required.issubset(expectation) or set(expectation) - allowed:
        raise ValueError("expectation needs candidate, label, exit_code and only documented checks")
    if not any(expectation.get(key) for key in ("contains", "absent", "final_contains", "state_rows", "animation_rows")):
        raise ValueError("expectation must assert observable output")
    for key in ("candidate", "label"):
        if summary[key] != expectation[key] or metadata[key] != expectation[key]:
            failures.append(f"{key} does not match the expected case")
    if summary["termination"] is not None or not summary["drained"]:
        failures.append("capture did not complete and drain normally")
    if summary["child_exit_code"] != expectation["exit_code"]:
        failures.append("child exit status differs from the expected command result")
    if summary["capture_exit_code"] != summary["child_exit_code"]:
        failures.append("capture failure differs from the child result")
    if summary["input_actions_sent"] != summary["input_actions_expected"]:
        failures.append("the input plan was not completed")
    if summary.get("input_bytes_pending", 0):
        failures.append("queued input was not fully delivered")
    if not frames or not frames[-1].get("final"):
        failures.append("final reconstructed frame is missing")
    visible = "\n".join("\n".join(frame["lines"]) for frame in frames)
    final = "\n".join(frames[-1]["lines"]) if frames else ""
    for key, text, present in (("contains", visible, True), ("absent", visible, False),
                               ("final_contains", final, True)):
        for literal in expectation.get(key, []):
            if (literal in text) != present:
                failures.append(f"{key} assertion failed: {literal}")
    for key, actual_key in (("max_first_output_seconds", "first_output_seconds"),
                             ("max_idle_gap_seconds", "max_idle_gap_seconds")):
        if key in expectation and (summary[actual_key] is None or summary[actual_key] > expectation[key]):
            failures.append(f"{key} exceeded")
    for row in expectation.get("state_rows", []):
        matcher = re.compile(row["pattern"])
        previous = None
        observed = []
        for frame in frames:
            matches = [matcher.search(line) for line in frame["lines"]]
            matches = [match for match in matches if match]
            if len(matches) > 1:
                failures.append(f"ambiguous state row: {row['name']}")
                break
            if not matches:
                continue
            state = matches[0].group("state")
            if state not in row["states"]:
                failures.append(f"unknown row state: {row['name']} {state}")
            if state != previous:
                if previous is not None and [previous, state] not in row["transitions"]:
                    failures.append(f"forbidden transition: {row['name']} {previous} -> {state}")
                observed.append(state)
                previous = state
        for expected in row["required"]:
            if expected not in observed:
                failures.append(f"missing state: {row['name']} {expected}")
    for row in expectation.get("animation_rows", []):
        matcher = re.compile(row["pattern"])
        terminal = re.compile(row["terminal_pattern"])
        changes = 0
        previous = None
        changed_at = None
        observed_terminal = False
        for frame in frames:
            matches = [matcher.search(line) for line in frame["lines"]]
            matches = [match for match in matches if match]
            if len(matches) > 1:
                failures.append(f"ambiguous animation row: {row['name']}")
                break
            if matches:
                observed_terminal = False
                glyph = matches[0].group("glyph")
                if previous is None:
                    changed_at = frame["elapsed"]
                    previous = glyph
                elif glyph != previous:
                    interval = frame["elapsed"] - changed_at
                    if not row["min_interval"] <= interval <= row["max_interval"]:
                        failures.append(f"animation cadence outside bounds: {row['name']}")
                    changes += 1
                    changed_at = frame["elapsed"]
                    previous = glyph
                elif frame["elapsed"] - changed_at > row["max_interval"]:
                    failures.append(f"animation remained frozen: {row['name']}")
            elif previous is not None:
                if frame["elapsed"] - changed_at > row["max_interval"]:
                    failures.append(f"animation active tail outside bounds: {row['name']}")
                if any(terminal.search(line) for line in frame["lines"]):
                    previous = None
                    observed_terminal = True
                else:
                    failures.append(f"animation disappeared without terminal state: {row['name']}")
        if previous is not None or not observed_terminal:
            failures.append(f"animation terminal state missing: {row['name']}")
        if changes < row["minimum_changes"]:
            failures.append(f"insufficient visible animation: {row['name']}")
    return {"candidate": expectation["candidate"], "label": expectation["label"],
            "passed": not failures, "failures": failures}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--capture", type=Path, required=True)
    parser.add_argument("--expect", type=Path, required=True)
    args = parser.parse_args()
    try:
        result = verify(args.capture, json.loads(args.expect.read_text()))
    except (ValueError, OSError, KeyError, TypeError, re.error) as error:
        result = {"passed": False, "failures": [str(error)]}
    print(json.dumps(result, indent=2))
    return 0 if result["passed"] else 1


if __name__ == "__main__":
    sys.exit(main())
