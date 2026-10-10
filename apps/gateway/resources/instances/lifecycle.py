import fcntl
import json
import os
import signal
import subprocess
import sys
import tempfile
import time

child = None
command_path = None
stderr_tail = b''


def read_stderr():
    global stderr_tail
    try:
        chunk = os.read(child.stderr.fileno(), 65536)
    except BlockingIOError:
        return False
    stderr_tail = (stderr_tail + chunk)[-1024:]
    return bool(chunk)


def terminate():
    if child is None:
        return
    try:
        os.killpg(child.pid, signal.SIGTERM)
        time.sleep(0.1)
        os.killpg(child.pid, signal.SIGKILL)
    except ProcessLookupError:
        pass
    child.wait()


def interrupted(signum, frame):
    raise SystemExit(128 + signum)


for sig in (signal.SIGHUP, signal.SIGINT, signal.SIGTERM):
    signal.signal(sig, interrupted)

try:
    payload = json.load(sys.stdin)
    checkout = payload['checkout']
    if not os.path.isabs(checkout) or os.path.realpath(checkout) != checkout:
        raise SystemExit(125)
    # The lifecycle lock is a flock on the checkout directory itself, so it leaves no file behind.
    lock = os.open(checkout, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        raise SystemExit(75)
    descriptor, command_path = tempfile.mkstemp(prefix='orbit-lifecycle-')
    with os.fdopen(descriptor, 'w') as command_file:
        command_file.write(payload['command'])
    child = subprocess.Popen(
        ['/usr/bin/bash', '-eu', command_path],
        cwd=checkout,
        env={**os.environ, **payload.get('environment', {})},
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.PIPE,
        start_new_session=True,
    )
    os.set_blocking(child.stderr.fileno(), False)
    owner = os.getppid()
    deadline = time.monotonic() + payload['timeout']
    while child.poll() is None:
        if os.getppid() != owner:
            raise SystemExit(125)
        if time.monotonic() >= deadline:
            raise SystemExit(124)
        if not read_stderr():
            time.sleep(0.05)
    raise SystemExit(child.returncode if child.returncode in (0, 126, 127) else 1)
finally:
    terminate()
    if child is not None:
        while read_stderr():
            pass
        child.stderr.close()
        sys.stderr.buffer.write(stderr_tail)
    if command_path is not None:
        os.unlink(command_path)
