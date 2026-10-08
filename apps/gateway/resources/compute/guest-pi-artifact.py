"""Install a pinned Pi ELF from bounded stdin; never adopt another binary."""
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import stat
import sys
import uuid

ROOT = Path('/etc/orbit/sandbox-pi')
BINARY = Path('/usr/local/bin/orbit-pi-server')
MAX_BYTES = 256 * 1024 * 1024


def regular(path):
    details = path.lstat()
    if not stat.S_ISREG(details.st_mode) or details.st_uid != 0 or details.st_nlink != 1 or details.st_mode & 0o022:
        raise ValueError('Unsafe artifact file')


def digest(path):
    with path.open('rb') as stream:
        checksum = hashlib.sha256()
        for chunk in iter(lambda: stream.read(1024 * 1024), b''):
            checksum.update(chunk)
        return checksum.hexdigest()


def install(stream):
    header = stream.readline(1024)
    if not header.endswith(b'\n'):
        raise ValueError('Invalid artifact header')
    request = json.loads(header)
    if set(request) != {'sandbox_id', 'sha256', 'size'} or str(uuid.UUID(request['sandbox_id'])) != request['sandbox_id']:
        raise ValueError('Invalid artifact identity')
    if not isinstance(request['sha256'], str) or not re.fullmatch('[a-f0-9]{64}', request['sha256']):
        raise ValueError('Invalid artifact digest')
    if type(request['size']) is not int or not 64 <= request['size'] <= MAX_BYTES or os.geteuid() != 0:
        raise ValueError('Invalid artifact size')
    receipt = json.dumps(request, sort_keys=True)
    owner = ROOT / 'owner.json'
    if ROOT.exists() or ROOT.is_symlink():
        details = ROOT.lstat()
        if not stat.S_ISDIR(details.st_mode) or details.st_uid != 0 or details.st_mode & 0o077:
            raise ValueError('Unsafe artifact directory')
    else:
        # The footprint must be empty before claiming it.
        if BINARY.exists() or BINARY.is_symlink():
            raise ValueError('Foreign Pi binary')
        ROOT.mkdir(mode=0o700, parents=True)
    lock = os.open(ROOT, os.O_RDONLY | os.O_DIRECTORY | os.O_NOFOLLOW)
    fcntl.flock(lock, fcntl.LOCK_EX)
    try:
        return install_owned(stream, request, receipt, owner)
    finally:
        os.close(lock)


def install_owned(stream, request, receipt, owner):
    if owner.exists() or owner.is_symlink():
        regular(owner)
        if owner.read_text() != receipt:
            raise ValueError('Foreign artifact receipt')
    else:
        if BINARY.exists() or BINARY.is_symlink():
            raise ValueError('Foreign Pi binary')
        descriptor = os.open(owner, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
        with os.fdopen(descriptor, 'w') as output:
            output.write(receipt)
            output.flush()
            os.fsync(output.fileno())
    if BINARY.exists() or BINARY.is_symlink():
        regular(BINARY)
        if BINARY.stat().st_size != request['size'] or digest(BINARY) != request['sha256'] or stat.S_IMODE(BINARY.stat().st_mode) != 0o755:
            raise ValueError('Pi binary drift')
    # A unique candidate cannot collide with or replace another attempt's files.
    candidate = BINARY.parent / ('.orbit-pi-' + str(uuid.uuid4()))
    descriptor = os.open(candidate, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
    try:
        checksum = hashlib.sha256()
        remaining = request['size']
        first = b''
        with os.fdopen(descriptor, 'wb') as output:
            while remaining:
                chunk = stream.read(min(remaining, 1024 * 1024))
                if not chunk:
                    raise ValueError('Truncated artifact')
                if not first:
                    first = chunk[:64]
                checksum.update(chunk)
                output.write(chunk)
                remaining -= len(chunk)
            if stream.read(1) or checksum.hexdigest() != request['sha256']:
                raise ValueError('Artifact digest mismatch')
            # ELF64, little endian, executable or PIE, x86-64.
            if first[:6] != b'\x7fELF\x02\x01' or first[16:18] not in (b'\x02\x00', b'\x03\x00') or first[18:20] != b'\x3e\x00':
                raise ValueError('Unsupported Pi executable')
            output.flush()
            os.fsync(output.fileno())
            os.fchmod(output.fileno(), 0o755)
        if not BINARY.exists():
            # link refuses to overwrite a concurrently installed or foreign binary.
            os.link(candidate, BINARY)
        return {'sandbox_id': request['sandbox_id'], 'sha256': request['sha256']}
    finally:
        candidate.unlink(missing_ok=True)


if __name__ == '__main__':
    try:
        print(json.dumps(install(sys.stdin.buffer)))
    except Exception:
        print(json.dumps({'error': 'sandbox_pi_artifact_failed'}))
        sys.exit(1)
