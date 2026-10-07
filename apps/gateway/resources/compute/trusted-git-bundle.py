#!/usr/bin/env python3
"""Import only an approved bundle commit in a fresh trusted bare repository, then push it."""
import base64
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
                             '-c', 'credential.helper=', '-c', 'http.followRedirects=false', '-C', str(repository), *arguments],
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


@contextlib.contextmanager
def credentials(directory, token):
    if not isinstance(token, str) or not token or '\n' in token or '\x00' in token:
        raise Refusal('The repository token is unavailable.')
    password = directory / 'token'
    descriptor = os.open(password, os.O_CREAT | os.O_EXCL | os.O_WRONLY, 0o600)
    with os.fdopen(descriptor, 'w') as output:
        output.write(token)
    askpass = directory / 'askpass'
    askpass.write_text('#!/bin/sh\ncase "$1" in *Username*) printf "%s\\n" x-access-token ;; *) cat -- "$ORBIT_BUNDLE_TOKEN_FILE" ;; esac\n')
    askpass.chmod(0o700)
    try:
        yield {'GIT_ASKPASS': str(askpass), 'ORBIT_BUNDLE_TOKEN_FILE': str(password)}
    finally:
        password.unlink()
        askpass.unlink()


def repository_url(repository):
    if not isinstance(repository, str) or not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', repository):
        raise Refusal('Invalid GitHub repository.')
    return 'https://github.com/' + repository + '.git'


def publish(bundle, commit, repository, group, token):
    url = repository_url(repository)
    if not isinstance(group, int) or isinstance(group, bool) or group < 1:
        raise Refusal('Invalid task group.')
    with verified_bundle(bundle, commit) as trusted, credentials(trusted, token) as environment:
        git(trusted, 'push', '--quiet', url, commit + ':refs/heads/task-' + str(group), environment=environment)
    return {'commit': commit, 'branch': 'task-' + str(group)}


def read_token(environment):
    if environment == {} or environment == []:
        return None
    expected = {'GIT_CONFIG_COUNT': '3', 'GIT_CONFIG_KEY_0': 'http.https://github.com/.extraheader',
                'GIT_CONFIG_KEY_1': 'url.https://github.com/.insteadOf', 'GIT_CONFIG_VALUE_1': 'git@github.com:',
                'GIT_CONFIG_KEY_2': 'url.https://github.com/.insteadOf', 'GIT_CONFIG_VALUE_2': 'ssh://git@github.com/'}
    if (not isinstance(environment, dict) or set(environment) != {*expected, 'GIT_CONFIG_VALUE_0'}
            or any(environment.get(key) != value for key, value in expected.items())):
        raise Refusal('Invalid repository read credentials.')
    header = environment['GIT_CONFIG_VALUE_0']
    if not isinstance(header, str) or not header.startswith('Authorization: Basic '):
        raise Refusal('Invalid repository read credentials.')
    credential = base64.b64decode(header[len('Authorization: Basic '):], validate=True).decode()
    if not credential.startswith('x-access-token:'):
        raise Refusal('Invalid repository read credentials.')
    return credential[len('x-access-token:'):]


def fetch(bundle, repository, branch, environment, missing_ok=False):
    token = read_token(environment)
    url = repository_url(repository)
    if not isinstance(branch, str) or not branch or branch.startswith('-') or '\0' in branch:
        raise Refusal('Invalid branch.')
    destination = Path(bundle)
    if not destination.is_absolute() or destination.exists() or destination.is_symlink():
        raise Refusal('The bundle destination must be new.')
    with tempfile.TemporaryDirectory(prefix='orbit-trusted-fetch-') as temporary:
        trusted = Path(temporary)
        git(trusted, 'init', '--bare', '--quiet')
        git(trusted, 'check-ref-format', 'refs/heads/' + branch)
        with (credentials(trusted, token) if token is not None else contextlib.nullcontext({})) as environment:
            if missing_ok:
                # A successful listing proves absence; authentication and transport failures still fail closed.
                refs = git(trusted, 'ls-remote', '--heads', url, 'refs/heads/' + branch, environment=environment)
                if not refs:
                    return {'missing': True}
            git(trusted, '-c', 'fetch.fsckObjects=true', 'fetch', '--quiet', '--no-tags', '--no-recurse-submodules', url,
                'refs/heads/' + branch + ':refs/heads/orbit-approved', environment=environment)
        commit = git(trusted, 'rev-parse', '--verify', 'refs/heads/orbit-approved^{commit}')
        git(trusted, 'fsck', '--strict', '--full')
        received = trusted / 'received.bundle'
        git(trusted, 'bundle', 'create', str(received), 'refs/heads/orbit-approved')
        if not 0 < received.stat().st_size <= 512 * 1024 * 1024:
            raise Refusal('The fetched bundle is too large.')
        descriptor = os.open(destination, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, 0o600)
        try:
            with os.fdopen(descriptor, 'wb') as output, received.open('rb') as source:
                while block := source.read(1024 * 1024):
                    output.write(block)
        except BaseException:
            destination.unlink()
            raise
    return {'commit': commit, 'size': destination.stat().st_size}


if __name__ == '__main__':
    try:
        request = json.loads(sys.stdin.buffer.read(1024 * 1024))
        operation = request.get('operation', 'publish')
        if operation == 'publish':
            result = publish(request['bundle'], request['commit'], request['repository'], request['group'], request['token'])
        elif operation == 'fetch':
            result = fetch(request['bundle'], request['repository'], request['branch'], request['environment'], request.get('missing_ok', False))
        else:
            raise Refusal('Unknown bundle operation.')
        print(json.dumps(result))
    except (Refusal, OSError, ValueError, KeyError, TypeError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_bundle_publication_failed'}))
        sys.exit(1)
