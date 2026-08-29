#!/usr/bin/env python3
"""Reconcile one Orbit-owned Incus FORWARD rule group."""

import fcntl
import json
import os
import re
import shlex
import stat
import subprocess
import sys


NETWORK = re.compile(r"\Aoe-[a-z0-9](?:[a-z0-9-]{0,10}[a-z0-9])?\Z")
OWNER = "orbit-e2e"
MANAGED_PATTERN = "oe+"


def fail(message: str) -> int:
    print(message, file=sys.stderr)
    return 2


def iptables(*arguments: str) -> subprocess.CompletedProcess[str]:
    return subprocess.run(
        [*privilege_prefix(), "iptables", "-w", "5", *arguments],
        check=False,
        capture_output=True,
        text=True,
    )


def privilege_prefix() -> list[str]:
    return [] if os.geteuid() == 0 else ["sudo", "-n"]


def forward_rules() -> list[list[str]]:
    result = iptables("-S", "FORWARD")
    if result.returncode != 0:
        raise RuntimeError("unable to inspect FORWARD rules")

    rules: list[list[str]] = []
    for line in result.stdout.splitlines():
        tokens = shlex.split(line)
        if tokens[:2] == ["-A", "FORWARD"]:
            rules.append(tokens[2:])

    return rules


def desired_rules(network: str) -> list[list[str]]:
    def owned(label: str) -> list[str]:
        return ["-m", "comment", "--comment", f"{OWNER}:{network}:{label}"]

    return [
        ["-i", network, "-o", network, *owned("intra"), "-j", "ACCEPT"],
        ["-i", network, "-o", MANAGED_PATTERN, *owned("isolate"), "-j", "DROP"],
        [
            "-i",
            network,
            "-m",
            "conntrack",
            "--ctstate",
            "NEW,RELATED,ESTABLISHED",
            *owned("egress"),
            "-j",
            "ACCEPT",
        ],
        [
            "-o",
            network,
            "-m",
            "conntrack",
            "--ctstate",
            "RELATED,ESTABLISHED",
            *owned("return"),
            "-j",
            "ACCEPT",
        ],
    ]


def comment(rule: list[str]) -> str | None:
    try:
        index = rule.index("--comment")
    except ValueError:
        return None

    return rule[index + 1] if index + 1 < len(rule) else None


def owned_rules(rules: list[list[str]], network: str) -> list[list[str]]:
    marker = f"{OWNER}:{network}:"
    return [rule for rule in rules if (comment(rule) or "").startswith(marker)]


def current(rules: list[list[str]], desired: list[list[str]]) -> bool:
    positions: list[int] = []
    for expected in desired:
        matches = [index for index, rule in enumerate(rules) if rule == expected]
        if len(matches) != 1:
            return False
        positions.append(matches[0])

    if positions != sorted(positions):
        return False

    last = positions[-1]
    return all((comment(rule) or "").startswith(f"{OWNER}:") for rule in rules[: last + 1])


def restore_transaction(removals: list[list[str]], additions: list[list[str]]) -> str:
    lines = ["*filter"]
    lines.extend(f"-D FORWARD {shlex.join(rule)}" for rule in removals)
    lines.extend(
        f"-I FORWARD {position} {shlex.join(rule)}"
        for position, rule in enumerate(additions, start=1)
    )
    lines.extend(["COMMIT", ""])

    return "\n".join(lines)


def apply_transaction(removals: list[list[str]], additions: list[list[str]]) -> None:
    result = subprocess.run(
        [*privilege_prefix(), "iptables-restore", "-w", "5", "--noflush"],
        input=restore_transaction(removals, additions),
        check=False,
        capture_output=True,
        text=True,
    )
    if result.returncode != 0:
        diagnostic = result.stderr.strip() or result.stdout.strip() or "iptables failed"
        raise RuntimeError(diagnostic)


def reconcile(operation: str, network: str) -> bool:
    rules = forward_rules()
    desired = desired_rules(network)
    if operation == "ensure" and current(rules, desired):
        return False

    owned = owned_rules(rules, network)
    additions = desired if operation == "ensure" else []
    if owned or additions:
        apply_transaction(list(reversed(owned)), additions)

    final = forward_rules()
    if operation == "ensure" and not current(final, desired):
        raise RuntimeError("owned FORWARD rules failed postcondition")
    if operation == "remove" and owned_rules(final, network):
        raise RuntimeError("owned FORWARD rules remain after removal")

    return bool(owned) or operation == "ensure"


def lock_firewall() -> int:
    path = f"/tmp/orbit-e2e-firewall-{os.getuid()}.lock"
    descriptor = os.open(path, os.O_RDWR | os.O_CREAT | os.O_CLOEXEC | os.O_NOFOLLOW, 0o600)
    identity = os.fstat(descriptor)
    if not stat.S_ISREG(identity.st_mode) or identity.st_uid != os.getuid():
        os.close(descriptor)
        raise RuntimeError("unsafe firewall lock identity")
    os.fchmod(descriptor, 0o600)
    fcntl.flock(descriptor, fcntl.LOCK_EX)
    return descriptor


def main() -> int:
    try:
        payload = json.load(sys.stdin)
        if not isinstance(payload, dict) or set(payload) != {
            "operation",
            "network",
            "managed_interface_pattern",
            "owner",
        }:
            raise ValueError("invalid request shape")
        operation = payload["operation"]
        network = payload["network"]
        if operation not in {"ensure", "remove"}:
            raise ValueError("invalid operation")
        if not isinstance(network, str) or NETWORK.fullmatch(network) is None:
            raise ValueError("invalid network")
        if payload["managed_interface_pattern"] != MANAGED_PATTERN or payload["owner"] != OWNER:
            raise ValueError("invalid ownership contract")

        descriptor = lock_firewall()
        try:
            changed = reconcile(operation, network)
        finally:
            os.close(descriptor)
        json.dump({"changed": changed}, sys.stdout)
        sys.stdout.write("\n")
        return 0
    except (json.JSONDecodeError, KeyError, OSError, TypeError, ValueError, RuntimeError) as error:
        return fail(str(error))


if __name__ == "__main__":
    raise SystemExit(main())
