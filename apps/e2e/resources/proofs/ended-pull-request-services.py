#!/usr/bin/env python3
"""Durable ownership for the proof's transient systemd units on its leased Node.

A reservation precedes remote spawn dispatch. Reconciliation never trusts a host
success flag: it checks the fixture/lease/run markers and systemd's actual argv.
"""
import json
import os
import subprocess
import sys
from pathlib import Path


def demand(condition, message):
    if not condition:
        raise RuntimeError(message)


def properties(unit):
    result = subprocess.run(
        ["systemctl", "show", unit, "--property=LoadState,ActiveState,MainPID,User,ExecStart"],
        text=True, capture_output=True,
    )
    values = dict(line.split("=", 1) for line in result.stdout.splitlines() if "=" in line)
    if values.get("LoadState") == "not-found":
        return None
    demand(result.returncode == 0 and values.get("LoadState") == "loaded", "Cannot inspect " + unit)
    return values


def durable_json(path, value):
    temporary = path.with_name(path.name + ".tmp")
    with temporary.open("w") as output:
        json.dump(value, output)
        output.flush()
        os.fsync(output.fileno())
    os.replace(temporary, path)
    descriptor = os.open(path.parent, os.O_DIRECTORY)
    try:
        os.fsync(descriptor)
    finally:
        os.close(descriptor)


def main():
    mode, directory, issue, lease, run = sys.argv[1:6]
    root = Path(directory)
    if mode == "preflight":
        for short in ["model", "pi"]:
            demand(properties("orbit-e2e-ended-pr-" + short + ".service") is None,
                   "Refusing an occupied service before creating any fixture")
        print("NO_OCCUPIED_SERVICES")
        return
    workspace = Path("/home/orbit/apps/ended-pr-" + issue.removeprefix("TASK-"))
    if mode == "absent":
        # Read-only absence evidence needs no deleted local markers. Never stop
        # or adopt a loaded unit when its reservation is missing.
        demand(not os.path.lexists(root) and not os.path.lexists(workspace),
               "Deleted fixture paths are present or replaced")
        for short in ["model", "pi"]:
            demand(properties("orbit-e2e-ended-pr-" + short + ".service") is None,
                   "Cannot accept absence with a present/replaced unit: " + short)
        print("DELETED_NODE_PATHS_AND_SERVICES_INDEPENDENTLY_ABSENT", lease, run)
        return
    if mode == "metadata-state" and not os.path.lexists(root):
        print("METADATA_ABSENT")
        return
    for filename, expected in [("owner", issue), ("lease", lease), ("run", run)]:
        demand((root / filename).read_text().strip() == expected, "Ownership changed: " + filename)
    name = sys.argv[6] if len(sys.argv) > 6 else None

    def record(short):
        path = root / "services" / (short + ".json")
        if not path.exists():
            return None
        value = json.loads(path.read_text())
        demand(value["issue"] == issue and value["lease"] == lease and value["run"] == run,
               "Service reservation belongs to another fixture or lease")
        demand(value["unit"] == "orbit-e2e-ended-pr-" + short + ".service", "Wrong reserved unit")
        return value

    def owned(short):
        value = record(short)
        unit = "orbit-e2e-ended-pr-" + short + ".service"
        actual = properties(unit)
        if actual is None:
            return value, None
        demand(value is not None, "Loaded unit has no ownership reservation: " + unit)
        expected = "argv[]=" + " ".join(value["argv"]) + " ;"
        demand(actual["User"] == "orbit" and expected in actual["ExecStart"],
               "Refusing an unrelated or replaced unit: " + unit)
        return value, actual

    authorization = {"issue": issue, "lease": lease, "run": run,
                     "phase": "filesystem-deletion-authorized", "directory": directory,
                     "workspace": str(workspace), "nodes": ["app-dev", "gateway"]}
    progress = root / "teardown.json"
    if mode == "metadata-state":
        print("METADATA_PRESENT")
    elif mode == "deletion-state":
        if progress.exists():
            demand(json.loads(progress.read_text()) == authorization, "Teardown authorization changed")
            print("DELETION_AUTHORIZED")
        else:
            print("DELETION_NOT_AUTHORIZED")
    elif mode == "authorize-delete":
        for short in ["model", "pi"]:
            _, actual = owned(short)
            demand(actual is None or (actual["ActiveState"] in ["inactive", "failed"]
                                     and actual["MainPID"] == "0"), "Service active at deletion authorization")
        if progress.exists():
            demand(json.loads(progress.read_text()) == authorization, "Teardown authorization changed")
        else:
            durable_json(progress, authorization)
        print("DURABLE_FILESYSTEM_DELETION_AUTHORIZED", lease, run)
    elif mode == "reserve":
        demand(name in ["model", "pi"], "Invalid service name")
        demand(properties("orbit-e2e-ended-pr-" + name + ".service") is None,
               "Refusing an occupied service before dispatch")
        argv = sys.argv[7:]
        demand(argv[:3] == ["/usr/bin/env", "ORBIT_ENDED_PR_RUN=" + run, "ORBIT_ENDED_PR_LEASE=" + lease]
               and any(directory in arg for arg in argv), "Spawn argv lacks fixture/run ownership")
        demand(all(" " not in arg and ";" not in arg for arg in argv), "Unsafe service argv")
        value = {"issue": issue, "lease": lease, "run": run,
                 "unit": "orbit-e2e-ended-pr-" + name + ".service", "argv": argv}
        # An immutable reservation may be reused after the proof's intentional Pi outage.
        previous = record(name)
        demand(previous is None or previous == value, "Reservation changed during retry")
        if previous is None:
            path = root / "services" / (name + ".json")
            with path.open("x") as output:
                json.dump(value, output)
                output.flush()
                os.fsync(output.fileno())
            descriptor = os.open(path.parent, os.O_DIRECTORY)
            try:
                os.fsync(descriptor)
            finally:
                os.close(descriptor)
        print("SPAWN_RESERVED", name, lease, run)
    elif mode == "stop":
        value, actual = owned(name)
        if actual is not None:
            subprocess.run(["sudo", "systemctl", "stop", value["unit"]], check=True)
        _, remaining = owned(name)
        demand(remaining is None or (remaining["ActiveState"] in ["inactive", "failed"]
                                    and remaining["MainPID"] == "0"), "Owned service did not stop")
        print("OWNED_SERVICE_STOPPED", name)
    elif mode == "running":
        _, actual = owned(name)
        demand(actual is not None and actual["ActiveState"] == "active" and int(actual["MainPID"]) > 0,
               "Lost-response injection did not start a real service")
        print("REAL_SPAWN_ACCEPTED_RESPONSE_LOST", name, lease, run)
    elif mode == "all-stopped":
        for short in ["model", "pi"]:
            _, actual = owned(short)
            demand(actual is None or (actual["ActiveState"] in ["inactive", "failed"]
                                     and actual["MainPID"] == "0"), "A service still runs: " + short)
        print("ALL_OWNED_SERVICES_STOPPED_METADATA_STILL_INTACT", lease, run)
    else:
        raise RuntimeError("Unknown service reconciliation mode: " + mode)


try:
    main()
except Exception as error:
    print(type(error).__name__ + ": " + str(error), file=sys.stderr)
    sys.exit(1)
