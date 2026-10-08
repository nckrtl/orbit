"""Serialize candidate and publication commands independently of TMPDIR."""
from contextlib import contextmanager
import fcntl
import os
from pathlib import Path
import re
import stat


@contextmanager
def locked(identities, directory=Path('/run/lock')):
    descriptors = []
    try:
        for identity in sorted(set(identities)):
            if not isinstance(identity, str) or not re.fullmatch(r'ot-(?:template-)?[a-f0-9]{10}', identity):
                raise ValueError('Invalid template lock identity')
            path = directory / ('orbit-template-' + str(os.geteuid()) + '-' + identity + '.lock')
            fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_NONBLOCK | os.O_CLOEXEC, 0o600)
            descriptors.append(fd)
            details = os.fstat(fd)
            if not stat.S_ISREG(details.st_mode) or details.st_uid != os.geteuid() or details.st_nlink != 1 or details.st_mode & 0o077:
                raise ValueError('Unsafe template lock')
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        yield
    finally:
        for fd in descriptors:
            os.close(fd)
