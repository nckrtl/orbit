import fcntl
import hashlib
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
    # A release-layout Instance serves a release under its checkout; the lock stays on the checkout.
    directory = payload.get('directory', checkout)
    if not isinstance(directory, str) or os.path.realpath(directory) != directory \
            or (directory != checkout and not directory.startswith(checkout + '/releases/')):
        raise SystemExit(125)
    lock_path = '/tmp/orbit-lifecycle-' + str(os.getuid()) + '-' + hashlib.sha256(checkout.encode()).hexdigest() + '.lock'
    lock = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        raise SystemExit(75)
    descriptor, command_path = tempfile.mkstemp(prefix='orbit-lifecycle-')
    with os.fdopen(descriptor, 'w') as command_file:
        command_file.write(payload['command'])
    child = subprocess.Popen(
        ['/usr/bin/bash', '-eu', command_path],
        cwd=directory,
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
