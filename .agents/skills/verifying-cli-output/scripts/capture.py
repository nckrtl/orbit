#!/usr/bin/env python3
"""Capture a command under a live PTY without confusing child status with proof."""

import argparse
import base64
import codecs
import ctypes
from collections import deque, namedtuple
import errno
import fcntl
import json
import os
from pathlib import Path
import pty
import select
import signal
import struct
import subprocess
import sys
import termios
import time
import tty

import pyte


StyledChar = namedtuple("StyledChar", [*pyte.screens.Char._fields, "dim"])


class EvidenceScreen(pyte.Screen):
    """Retain SGR 2 faint intensity, which pyte's base cell model omits."""

    @property
    def default_char(self):
        return StyledChar(**super().default_char._asdict(), dim=False)

    def reset(self):
        super().reset()
        self.cursor.attrs = self.default_char

    def select_graphic_rendition(self, *attrs):
        dim = self.cursor.attrs.dim
        codes = iter(attrs or (0,))
        for code in codes:
            if code in (0, 22):
                dim = False
            elif code == 2:
                dim = True
            elif code in (38, 48):
                # Color payloads may contain 0, 2, or 22. They are not SGR
                # intensity operations. Follow pyte's supported color forms.
                mode = next(codes, None)
                for _ in range({5: 1, 2: 3}.get(mode, 0)):
                    next(codes, None)
        super().select_graphic_rendition(*attrs)
        self.cursor.attrs = self.cursor.attrs._replace(dim=dim)


def arguments():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument("--output-dir", required=True)
    parser.add_argument("--columns", type=int, default=100)
    parser.add_argument("--rows", type=int, default=40)
    parser.add_argument("--timeout", type=float, default=300)
    parser.add_argument("--idle-timeout", type=float, default=60)
    parser.add_argument("--input-plan", type=Path)
    parser.add_argument("--candidate", required=True)
    parser.add_argument("--label", required=True, help="Safe non-secret case identifier")
    parser.add_argument("--no-live", action="store_true")
    parser.add_argument("command", nargs=argparse.REMAINDER)
    args = parser.parse_args()
    if args.command[:1] == ["--"]:
        args.command = args.command[1:]
    if not args.command:
        parser.error("a command is required after --")
    if args.columns < 20 or args.rows < 5 or args.timeout <= 0 or args.idle_timeout <= 0:
        parser.error("terminal size and timeouts must be positive and usable")
    return args


def read_plan(path):
    if path is None:
        return []
    plan = json.loads(path.read_text())
    if not isinstance(plan, list):
        raise ValueError("input plan must be a list")
    for action in plan:
        if not isinstance(action, dict) or set(action) != {"wait_for", "send"}:
            raise ValueError("each input action needs only wait_for and send")
        if not all(isinstance(action[key], str) for key in action):
            raise ValueError("input action values must be strings")
        if not action["wait_for"]:
            raise ValueError("wait_for must identify an observed prompt")
    return plan


def record(stream, value):
    stream.write(json.dumps(value, ensure_ascii=False) + "\n")
    stream.flush()


def group_signal(pid, sig):
    try:
        os.killpg(pid, sig)
    except ProcessLookupError:
        pass


def wait_status(pid):
    try:
        completed, status = os.waitpid(pid, os.WNOHANG)
    except ChildProcessError:
        raise RuntimeError("child status was consumed outside the capture loop")
    return status if completed == pid else None


def set_subreaper(enabled=None):
    """Linux must reap orphaned session members itself, including in containers."""
    if not sys.platform.startswith("linux"):
        return None
    libc = ctypes.CDLL(None, use_errno=True)
    current = ctypes.c_int()
    if libc.prctl(37, ctypes.byref(current), 0, 0, 0) != 0:  # PR_GET_CHILD_SUBREAPER
        raise OSError(ctypes.get_errno(), "cannot read child subreaper setting")
    if enabled is not None and libc.prctl(36, int(enabled), 0, 0, 0) != 0:
        raise OSError(ctypes.get_errno(), "cannot set child subreaper setting")
    return current.value


def session_members(pid):
    # Session identity survives the leader's exit. Enumerate PIDs without
    # guessing from command names or touching processes in other sessions.
    if sys.platform.startswith("linux"):
        candidates = [int(path.name) for path in Path("/proc").iterdir()
                      if path.name.isdigit()]
    else:
        candidates = [int(value) for value in subprocess.check_output(
            ["ps", "-axo", "pid="], text=True).split()]
    members = []
    for candidate in candidates:
        try:
            if os.getsid(candidate) == pid:
                members.append(candidate)
        except ProcessLookupError:
            pass
    return members


def member_signal(member, session, sig):
    try:
        # Recheck identity immediately before signalling a tracked PID.
        if os.getsid(member) == session:
            os.kill(member, sig)
    except ProcessLookupError:
        pass


def signal_session(pid, sig):
    members = session_members(pid)
    group_signal(pid, sig)
    for member in members:
        member_signal(member, pid, sig)
    return members


def reap_descendants(members):
    # Never use waitpid(-1): another child may belong to the caller. A nested
    # descendant becomes waitable after its own parent exits and is reaped.
    remaining = set(members)
    deadline = time.monotonic() + 2
    while remaining:
        for member in list(remaining):
            try:
                completed, _ = os.waitpid(member, os.WNOHANG)
                if completed:
                    remaining.remove(member)
            except ChildProcessError:
                try:
                    os.kill(member, 0)
                except ProcessLookupError:
                    remaining.remove(member)
        if remaining:
            if time.monotonic() >= deadline:
                raise RuntimeError("owned descendants were not reaped")
            time.sleep(.01)


def snapshot(screen):
    # Preserve glyph attributes as well as the visible screen. Plain strings
    # alone cannot establish color, dimming, or active-label contrast.
    cells = []
    for row, line in screen.buffer.items():
        for column, cell in line.items():
            if cell != screen.default_char:
                cells.append({"row": row, "column": column, **cell._asdict()})
    return {"lines": screen.display, "cells": cells,
            "cursor": {"x": screen.cursor.x, "y": screen.cursor.y,
                       "hidden": screen.cursor.hidden}}


def capture(args):
    plan = read_plan(args.input_plan)
    directory = Path(args.output_dir)
    directory.mkdir(parents=True, exist_ok=False)
    os.chmod(directory, 0o700)
    screen = EvidenceScreen(args.columns, args.rows)
    emulator = pyte.Stream(screen)
    decoder = codecs.getincrementaldecoder("utf-8")("replace")
    started = time.monotonic()
    last_output = started
    first_output = None
    max_gap = 0.0
    status = None
    eof = False
    termination = None
    terminating_at = None
    input_index = 0
    pending_input = deque()
    pending_bytes = 0
    input_bytes_sent = 0
    plan_queued = False
    input_closed = False
    since_input = ""
    stdin_open = sys.stdin.isatty() and not args.no_live
    original_terminal = None
    previous_signals = {}
    received_signal = None
    pid = fd = None
    collector_errors = []
    original_subreaper = None

    def interrupted(signum, _frame):
        nonlocal received_signal
        received_signal = signum

    metadata = {
        "schema": 1, "label": args.label, "candidate": args.candidate,
        "launcher": args.command[0], "cwd": os.getcwd(),
        "columns": args.columns, "rows": args.rows,
        "environment": {key: os.environ.get(key) for key in ("TERM", "NO_COLOR", "CLICOLOR", "FORCE_COLOR")},
        "child_term": os.environ.get("TERM", "xterm-256color"),
        "child_has_pty": True, "parent_stdin_tty": sys.stdin.isatty(),
        "input_actions": len(plan),
    }
    # Arbitrary argv and stdin can contain credentials. The case definition,
    # held outside the recording, owns exact non-secret invocation evidence.
    try:
        # Register handlers before forking. Every operation that can fail after
        # acquiring a child or changing terminal state belongs to this scope.
        for sig in (signal.SIGTERM, signal.SIGINT, signal.SIGHUP):
            previous_signals[sig] = signal.signal(sig, interrupted)
        original_subreaper = set_subreaper(True)
        pid, fd = pty.fork()
        if pid == 0:
            try:
                for sig, previous in previous_signals.items():
                    signal.signal(sig, previous)
                fcntl.ioctl(0, termios.TIOCSWINSZ,
                            struct.pack("HHHH", args.rows, args.columns, 0, 0))
                os.environ.setdefault("TERM", "xterm-256color")
                os.execvp(args.command[0], args.command)
            except BaseException as error:
                print(f"Cannot start command: {error}", file=sys.stderr, flush=True)
                os._exit(127)
        os.set_blocking(fd, False)
        (directory / "metadata.json").write_text(json.dumps(metadata, indent=2) + "\n")
        if stdin_open:
            original_terminal = termios.tcgetattr(sys.stdin.fileno())
            tty.setraw(sys.stdin.fileno())
        with (directory / "raw.bin").open("wb") as raw, \
             (directory / "chunks.jsonl").open("w") as chunks, \
             (directory / "frames.jsonl").open("w") as frames, \
             (directory / "input-events.jsonl").open("w") as inputs, \
             (directory / "transcript.txt").open("w") as transcript:
            while not (eof and status is not None):
                now = time.monotonic()
                if status is None:
                    status = wait_status(pid)
                if termination is None:
                    if received_signal is not None:
                        termination = "signal"
                    elif now - started >= args.timeout:
                        termination = "timeout"
                    elif now - last_output >= args.idle_timeout:
                        termination = "idle_timeout"
                    if termination is not None:
                        terminating_at = now
                        signal_session(pid, signal.SIGTERM)
                if terminating_at is not None and now - terminating_at >= 1:
                    signal_session(pid, signal.SIGKILL)
                    # A deliberately detached descendant is outside session
                    # ownership; it must not hold the collector indefinitely.
                    # Report incomplete draining instead of claiming success.
                    if now - terminating_at >= 2:
                        break
                accepting_input = termination is None and status is None and not eof and not input_closed
                # Bound queued live input and apply backpressure to its source.
                # A scripted action can be larger, but only one is queued.
                live_ready = stdin_open and accepting_input and pending_bytes < 65536
                readers = ([] if eof else [fd]) + ([sys.stdin.fileno()] if live_ready else [])
                writers = [fd] if pending_input and accepting_input else []
                ready, writable, _ = select.select(readers, writers, [], 0.03)
                if fd in ready:
                    try:
                        data = os.read(fd, 65536)
                    except BlockingIOError:
                        data = None
                    except OSError as error:
                        if error.errno != errno.EIO:
                            raise
                        data = b""
                    if data == b"":
                        eof = True
                    elif data:
                        now = time.monotonic()
                        elapsed = now - started
                        gap = now - last_output
                        first_output = elapsed if first_output is None else first_output
                        max_gap = max(max_gap, gap)
                        last_output = now
                        raw.write(data)
                        raw.flush()
                        text = decoder.decode(data)
                        transcript.write(text)
                        transcript.flush()
                        emulator.feed(text)
                        record(chunks, {"elapsed": elapsed, "delta": gap, "bytes": len(data),
                                        "base64": base64.b64encode(data).decode(), "text": text})
                        record(frames, {"elapsed": elapsed, **snapshot(screen)})
                        since_input = (since_input + text)[-131072:]
                        if not args.no_live:
                            sys.stdout.buffer.write(data)
                            sys.stdout.buffer.flush()
                if live_ready and not eof and sys.stdin.fileno() in ready:
                    typed = os.read(sys.stdin.fileno(), 4096)
                    if typed:
                        pending_input.append({"data": memoryview(typed), "source": "terminal"})
                        pending_bytes += len(typed)
                    else:
                        stdin_open = False
                if input_index < len(plan) and not plan_queued and accepting_input and not eof:
                    action = plan[input_index]
                    if action["wait_for"] in since_input:
                        outgoing = action["send"].encode()
                        if outgoing:
                            pending_input.append({"data": memoryview(outgoing),
                                                  "source": "plan", "index": input_index})
                            pending_bytes += len(outgoing)
                            plan_queued = True
                        else:
                            record(inputs, {"elapsed": time.monotonic() - started,
                                            "source": "plan", "index": input_index,
                                            "bytes": 0, "complete": True})
                            input_index += 1
                        since_input = ""
                if fd in writable and not eof:
                    outgoing = pending_input[0]
                    try:
                        # One bounded, nonblocking write per loop keeps output,
                        # signals, and both deadlines responsive under pressure.
                        written = os.write(fd, outgoing["data"][:65536])
                    except BlockingIOError:
                        written = 0
                    except OSError as error:
                        if error.errno not in (errno.EIO, errno.EPIPE):
                            raise
                        input_closed = True
                        written = 0
                    if written:
                        pending_bytes -= written
                        input_bytes_sent += written
                        outgoing["data"] = outgoing["data"][written:]
                        complete = not outgoing["data"]
                        if complete:
                            pending_input.popleft()
                            if outgoing["source"] == "plan":
                                input_index += 1
                                plan_queued = False
                        event = {key: value for key, value in outgoing.items() if key != "data"}
                        record(inputs, {"elapsed": time.monotonic() - started, **event,
                                        "bytes": written, "complete": complete})
            final_text = decoder.decode(b"", final=True)
            transcript.write(final_text)
            emulator.feed(final_text)
            record(frames, {"elapsed": time.monotonic() - started, "final": True, **snapshot(screen)})
    except Exception as error:
        # Keep actual child status even when the collector failed. Filesystem
        # failures can prevent an on-disk summary; stderr remains a fallback.
        collector_errors.append(f"capture: {type(error).__name__}: {error}")
    finally:
        def cleanup(name, operation):
            try:
                return operation()
            except Exception as error:
                collector_errors.append(f"{name}: {type(error).__name__}: {error}")

        # None of these independent actions may mask or skip another. Restore
        # immediately (TCSANOW), even if a child left output flow stopped.
        if original_terminal is not None:
            def restore_terminal():
                terminal = sys.stdin.fileno()
                termios.tcsetattr(terminal, termios.TCSANOW, original_terminal)
                # Darwin adds PENDIN when switching back to canonical input.
                # Drop pending raw-mode input instead of replaying it to the
                # caller's shell; unlike TCSAFLUSH this does not wait on output.
                pending = getattr(termios, "PENDIN", 0)
                if (not original_terminal[3] & pending
                        and termios.tcgetattr(terminal)[3] & pending):
                    termios.tcflush(terminal, termios.TCIFLUSH)
            cleanup("terminal restoration", restore_terminal)
        if not args.no_live:
            def restore_cursor():
                sys.stdout.write("\x1b[?25h\x1b[0m")
                sys.stdout.flush()
            cleanup("cursor restoration", restore_cursor)
        if pid is not None:
            # A failed process listing must not prevent group termination.
            members = cleanup("session inventory", lambda: session_members(pid)) or []
            cleanup("child termination", lambda: group_signal(pid, signal.SIGKILL))
            for member in members:
                cleanup("descendant termination", lambda member=member:
                        member_signal(member, pid, signal.SIGKILL))
            if status is None:
                # pty.fork may return before the child establishes its session.
                # An unreaped direct child is still ours even in that window.
                def terminate_child():
                    try:
                        os.kill(pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                cleanup("direct child termination", terminate_child)
                waited = cleanup("child reap", lambda: os.waitpid(pid, 0))
                if waited is not None:
                    _, status = waited
            if original_subreaper is not None:
                cleanup("descendant reap", lambda: reap_descendants(
                    member for member in members if member != pid))
        if original_subreaper is not None:
            cleanup("subreaper restoration", lambda: set_subreaper(original_subreaper))
        if fd is not None:
            cleanup("PTY close", lambda: os.close(fd))
        for sig, previous in previous_signals.items():
            cleanup("signal restoration", lambda sig=sig, previous=previous:
                    signal.signal(sig, previous))

    duration = time.monotonic() - started
    max_gap = max(max_gap, time.monotonic() - last_output)
    child_exit = os.waitstatus_to_exitcode(status) if status is not None else None
    if child_exit is not None and child_exit < 0:
        child_exit = 128 - child_exit
    capture_exit = 124 if termination in ("timeout", "idle_timeout") else child_exit
    if termination == "signal":
        capture_exit = 128 + received_signal
    if collector_errors or child_exit is None:
        capture_exit = 125
        termination = "collector_error"
    if ((capture_exit == 0 and (input_index != len(plan) or not eof))
            or (termination is None and pending_bytes)):
        capture_exit = 125
    summary = {"schema": 1, "label": args.label, "candidate": args.candidate,
               "child_exit_code": child_exit, "capture_exit_code": capture_exit,
               "duration_seconds": duration, "first_output_seconds": first_output,
               "max_idle_gap_seconds": max_gap, "termination": termination,
               "drained": eof, "input_actions_sent": input_index,
               "input_actions_expected": len(plan), "input_bytes_sent": input_bytes_sent,
               "input_bytes_pending": pending_bytes, "collector_errors": collector_errors}
    try:
        (directory / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
    except OSError as error:
        collector_errors.append(f"summary: {type(error).__name__}: {error}")
        capture_exit = summary["capture_exit_code"] = 125
        summary["termination"] = "collector_error"
    try:
        print(json.dumps({"capture": str(directory), **summary}), file=sys.stderr)
    except OSError as error:
        collector_errors.append(f"summary stderr: {type(error).__name__}: {error}")
        capture_exit = summary["capture_exit_code"] = 125
        summary["termination"] = "collector_error"
        try:
            (directory / "summary.json").write_text(json.dumps(summary, indent=2) + "\n")
        except OSError:
            pass  # Both reporting destinations failed; the process still fails.
    return capture_exit


if __name__ == "__main__":
    try:
        sys.exit(capture(arguments()))
    except (ValueError, OSError) as error:
        print(f"Capture failed: {error}", file=sys.stderr)
        sys.exit(125)
