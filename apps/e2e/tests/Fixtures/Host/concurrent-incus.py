#!/usr/bin/env python3
import fcntl
import os
import sys
import time


label = sys.argv[-1]

if label == "failure":
    print("failure stdout")
    print("failure stderr", file=sys.stderr)
    raise SystemExit(17)

if label == "timeout":
    time.sleep(5)
    raise SystemExit(0)

barrier = os.environ["ORBIT_E2E_EXEC_ALL_BARRIER"]
expected = int(os.environ["ORBIT_E2E_EXEC_ALL_EXPECTED"])
deadline = time.monotonic() + 2

with open(barrier, "a+", encoding="utf-8") as marker:
    fcntl.flock(marker, fcntl.LOCK_EX)
    marker.seek(0)
    labels = set(marker.read().splitlines())
    labels.add(label)
    marker.seek(0)
    marker.truncate()
    marker.write("\n".join(sorted(labels)) + "\n")
    marker.flush()
    fcntl.flock(marker, fcntl.LOCK_UN)

while time.monotonic() < deadline:
    with open(barrier, encoding="utf-8") as marker:
        if len(set(marker.read().splitlines())) == expected:
            print(label)
            raise SystemExit(0)
    time.sleep(0.01)

print("guest process barrier was not reached", file=sys.stderr)
raise SystemExit(70)
