"""Bounded bundle transfer inside a task VM. No repository credential is accepted."""
import base64
import hashlib
import json
import os
from pathlib import Path
import re
import shutil
import stat
import subprocess
import sys
import uuid

LIMIT = 512 * 1024 * 1024


def git(directory, *args):
    env = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    env.update({'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_CONFIG_NOSYSTEM': '1', 'GIT_TERMINAL_PROMPT': '0'})
    result = subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                             '-c', 'credential.helper=', '-C', str(directory), *args],
                            stdin=subprocess.DEVNULL, capture_output=True, env=env, timeout=180, check=True)
    return result.stdout.decode().strip()


def transfer(request):
    identity = request['id']
    if str(uuid.UUID(identity)) != identity:
        raise ValueError('Invalid transfer identity')
    directory = Path('/tmp/orbit-git-transfer-' + identity)
    bundle = directory / 'objects.bundle'
    operation = request['operation']
    if operation in ('export', 'begin'):
        directory.mkdir(mode=0o700)
    elif directory.is_symlink() or not directory.is_dir() or directory.stat().st_uid != os.geteuid():
        raise ValueError('Transfer ownership is unavailable')
    if operation == 'remove':
        shutil.rmtree(directory)
        return {'removed': True}
    if operation == 'begin':
        with bundle.open('xb'):
            pass
        return {'offset': 0}
    if operation in ('export', 'import'):
        checkout = request['checkout']
        commit = request['commit']
        if not isinstance(checkout, str) or not checkout.startswith('/') or '\0' in checkout:
            raise ValueError('Invalid checkout')
        if not isinstance(commit, str) or not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', commit):
            raise ValueError('Invalid commit')
        if operation == 'export':
            repository = directory / 'repository'
            repository.mkdir()
            git(repository, 'init', '--bare', '--quiet', '--object-format=' + ('sha256' if len(commit) == 64 else 'sha1'))
            git(repository, 'fetch', '--quiet', '--no-tags', '--no-recurse-submodules', checkout, commit + ':refs/heads/orbit-approved')
            git(repository, 'bundle', 'create', str(bundle), 'refs/heads/orbit-approved')
            shutil.rmtree(repository)
            size = bundle.stat().st_size
            if not 0 < size <= LIMIT:
                raise ValueError('Bundle is too large')
            with bundle.open('rb') as source:
                digest = hashlib.file_digest(source, 'sha256').hexdigest()
            return {'size': size, 'sha256': digest}
        ref = request['ref']
        if not isinstance(ref, str) or not ref.startswith('refs/remotes/origin/'):
            raise ValueError('Only remote tracking refs may be imported')
        git(checkout, 'check-ref-format', ref)
        if git(checkout, 'bundle', 'list-heads', str(bundle)).splitlines() != [commit + ' refs/heads/orbit-approved']:
            raise ValueError('Bundle commit does not match')
        git(checkout, 'bundle', 'verify', str(bundle))
        git(checkout, '-c', 'fetch.fsckObjects=true', 'fetch', '--quiet', '--no-tags', '--no-recurse-submodules', str(bundle), '+refs/heads/orbit-approved:' + ref)
        if git(checkout, 'rev-parse', '--verify', ref + '^{commit}') != commit:
            raise ValueError('Imported commit does not match')
        return {'commit': commit, 'ref': ref}
    offset = request['offset']
    if type(offset) is not int or not 0 <= offset < LIMIT:
        raise ValueError('Invalid transfer offset')
    descriptor = os.open(bundle, (os.O_RDONLY if operation == 'read' else os.O_RDWR) | os.O_NOFOLLOW | os.O_NONBLOCK)
    with os.fdopen(descriptor, 'rb' if operation == 'read' else 'r+b') as stream:
        details = os.fstat(stream.fileno())
        if not stat.S_ISREG(details.st_mode) or details.st_size > LIMIT:
            raise ValueError('Invalid bundle file')
        stream.seek(offset)
        if operation == 'read':
            count = request['length']
            if type(count) is not int or not 1 <= count <= 4 * 1024 * 1024:
                raise ValueError('Invalid read size')
            return stream.read(count)
        if operation == 'write':
            data = base64.b64decode(request['data'], validate=True)
            if not 0 < len(data) <= 256 * 1024 or offset != details.st_size or offset + len(data) > LIMIT:
                raise ValueError('Invalid bundle chunk')
            if stream.write(data) != len(data):
                raise ValueError('Incomplete bundle chunk')
            return {'offset': offset + len(data)}
    raise ValueError('Unknown operation')


if __name__ == '__main__':
    try:
        value = transfer(json.loads(sys.stdin.buffer.read(512 * 1024 + 1)))
        if isinstance(value, bytes):
            sys.stdout.buffer.write(value)
        else:
            print(json.dumps(value))
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print('Guest bundle operation failed.', file=sys.stderr)
        sys.exit(1)
