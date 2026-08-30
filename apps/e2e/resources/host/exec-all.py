#!/usr/bin/env python3
"""Run bounded, concurrent Incus guest commands from a JSON request."""
import json
import os
import signal
import subprocess
import sys
from concurrent.futures import ThreadPoolExecutor


def fail(message: str) -> int:
    print(message, file=sys.stderr)
    return 2


def run(request: dict) -> dict:
    label = request["label"]
    argv = ["incus", "--project", request["project"], "exec", request["instance"], "--", *request["argv"]]
    process = subprocess.Popen(argv, stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                               start_new_session=True)
    try:
        stdout, stderr = process.communicate((request.get("stdin") or "").encode(), timeout=request["timeout"])
        return {"label": label, "stdout": stdout.decode(errors="replace"), "stderr": stderr.decode(errors="replace"), "exit_code": process.returncode}
    except subprocess.TimeoutExpired:
        os.killpg(process.pid, signal.SIGKILL)
        stdout, stderr = process.communicate()
        return {"label": label, "stdout": stdout.decode(errors="replace"), "stderr": stderr.decode(errors="replace"), "exit_code": 124, "timed_out": True}


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        requests = payload["requests"]
        if not isinstance(requests, list) or not requests or len(requests) > 128:
            raise ValueError("invalid requests")
        labels = [item["label"] for item in requests]
        if any(not isinstance(label, str) or not label or label in (".", "..") for label in labels) or len(set(labels)) != len(labels):
            raise ValueError("invalid labels")
        for item in requests:
            if not isinstance(item, dict) or not isinstance(item.get("project"), str) or not isinstance(item.get("instance"), str):
                raise ValueError("invalid request")
            if not isinstance(item.get("argv"), list) or any(not isinstance(arg, str) for arg in item["argv"]):
                raise ValueError("invalid argv")
            if not isinstance(item.get("timeout"), (int, float)) or item["timeout"] <= 0:
                raise ValueError("invalid timeout")
        with ThreadPoolExecutor(max_workers=len(requests)) as pool:
            results = list(pool.map(run, requests))
        json.dump(results, sys.stdout)
        return 0
    except (KeyError, TypeError, ValueError, json.JSONDecodeError) as error:
        return fail(f"invalid batch request: {error}")


if __name__ == "__main__":
    raise SystemExit(main())
