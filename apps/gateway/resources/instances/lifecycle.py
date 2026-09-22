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
    lock_path = '/tmp/orbit-lifecycle-' + str(os.getuid()) + '-' + hashlib.sha256(checkout.encode()).hexdigest() + '.lock'
    lock = os.open(lock_path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    descriptor, command_path = tempfile.mkstemp(prefix='orbit-lifecycle-')
    with os.fdopen(descriptor, 'w') as command_file:
        command_file.write(payload['command'])
    child = subprocess.Popen(
        ['/usr/bin/bash', '-eu', command_path],
        cwd=checkout,
        stdin=subprocess.DEVNULL,
        stdout=subprocess.DEVNULL,
        stderr=subprocess.DEVNULL,
        start_new_session=True,
    )
    owner = os.getppid()
    deadline = time.monotonic() + payload['timeout']
    while child.poll() is None:
        if os.getppid() != owner:
            raise SystemExit(125)
        if time.monotonic() >= deadline:
            raise SystemExit(124)
        time.sleep(0.05)
    raise SystemExit(0 if child.returncode == 0 else 1)
finally:
    terminate()
    if command_path is not None:
        os.unlink(command_path)
