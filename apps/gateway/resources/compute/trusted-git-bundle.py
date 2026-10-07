#!/usr/bin/env python3
"""Import only an approved bundle commit in a fresh trusted bare repository, then push it."""
import contextlib
import json
import os
from pathlib import Path
import re
import stat
import subprocess
import sys
import tempfile


class Refusal(RuntimeError):
    pass


def git(repository, *arguments, environment=None):
    env = {key: value for key, value in os.environ.items() if not key.startswith('GIT_')}
    env.update({'GIT_CONFIG_NOSYSTEM': '1', 'GIT_CONFIG_GLOBAL': '/dev/null', 'GIT_TERMINAL_PROMPT': '0'})
    env.update(environment or {})
    result = subprocess.run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false',
                             '-c', 'credential.helper=', '-C', str(repository), *arguments],
                            stdin=subprocess.DEVNULL, capture_output=True, env=env, timeout=180)
    if result.returncode:
        # Git output can include credentials or untrusted object contents.
        raise Refusal('The trusted bundle Git operation failed.')
    return result.stdout.decode().strip()


@contextlib.contextmanager
def verified_bundle(bundle, commit):
    if not isinstance(commit, str) or re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', commit) is None:
        raise Refusal('The approved commit must be a full SHA.')
    bundle = Path(bundle)
    if not bundle.is_absolute() or not bundle.is_file() or bundle.is_symlink():
        raise Refusal('The received bundle must be a regular file.')
    with tempfile.TemporaryDirectory(prefix='orbit-trusted-bundle-') as temporary:
        repository = Path(temporary)
        git(repository, 'init', '--bare', '--quiet', '--object-format=' + ('sha256' if len(commit) == 64 else 'sha1'))
        # Pin a private copy before verification so a replaced transfer cannot change what gets pushed.
        received = repository / 'received.bundle'
        descriptor = os.open(bundle, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
        with os.fdopen(descriptor, 'rb') as source, received.open('xb') as target:
            details = os.fstat(source.fileno())
            if not stat.S_ISREG(details.st_mode) or details.st_size > 512 * 1024 * 1024:
                raise Refusal('The received bundle is not a bounded regular file.')
            copied = 0
            while block := source.read(1024 * 1024):
                copied += len(block)
                if copied > 512 * 1024 * 1024:
                    raise Refusal('The received bundle is too large.')
                target.write(block)
        advertised = git(repository, 'bundle', 'list-heads', str(received)).splitlines()
        if advertised != [commit + ' refs/heads/orbit-approved']:
            raise Refusal('The bundle does not advertise exactly the approved commit.')
        git(repository, 'bundle', 'verify', str(received))
        git(repository, '-c', 'fetch.fsckObjects=true', 'fetch', '--quiet', '--no-tags', str(received), 'refs/heads/orbit-approved')
        if git(repository, 'rev-parse', '--verify', 'FETCH_HEAD^{commit}') != commit:
            raise Refusal('The bundle commit does not match the approved SHA.')
        git(repository, 'fsck', '--strict', '--full')
        yield repository


def publish(bundle, commit, repository, group, token):
    if not isinstance(repository, str) or not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository):
        raise Refusal('Invalid GitHub repository.')
    if not isinstance(group, int) or isinstance(group, bool) or group < 1:
        raise Refusal('Invalid task group.')
    if not isinstance(token, str) or not token or '\n' in token or '\x00' in token:
        raise Refusal('The publication token is unavailable.')
    with verified_bundle(bundle, commit) as trusted:
        password = trusted / 'token'
        descriptor = os.open(password, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
        with os.fdopen(descriptor, 'w') as output:
            output.write(token)
        askpass = trusted / 'askpass'
        askpass.write_text('#!/bin/sh\ncase "$1" in *Username*) printf "%s\\n" x-access-token ;; *) cat -- "$ORBIT_BUNDLE_TOKEN_FILE" ;; esac\n')
        askpass.chmod(0o700)
        git(trusted, 'push', '--quiet', 'https://github.com/' + repository + '.git',
            commit + ':refs/heads/task-' + str(group), environment={
                'GIT_ASKPASS': str(askpass), 'ORBIT_BUNDLE_TOKEN_FILE': str(password),
            })
    return {'commit': commit, 'branch': 'task-' + str(group)}


if __name__ == '__main__':
    try:
        request = json.loads(sys.stdin.buffer.read(1024 * 1024))
        print(json.dumps(publish(request['bundle'], request['commit'], request['repository'], request['group'], request['token'])))
    except (Refusal, OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_bundle_publication_failed'}))
        sys.exit(1)
