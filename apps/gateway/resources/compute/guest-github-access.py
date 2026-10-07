"""Install and run repository-scoped GitHub access. Installation tokens are never stored."""
import http.client
import ipaddress
import json
import os
from pathlib import Path
import pwd
import re
import socket
import ssl
import stat
import subprocess
import sys
import urllib.parse
import uuid

ROOT = Path('/home/orbit/.orbit-github')
HELPER = Path('/usr/local/bin/orbit-github')
GH_WRAPPER = Path('/usr/local/bin/gh')
GH_PROGRAM = '#!/bin/sh\nexec /usr/local/bin/orbit-github gh "$@"\n'
PI = Path('/home/orbit/.orbit-sandbox-pi')


def regular(path, uid):
    info = path.lstat()
    if not stat.S_ISREG(info.st_mode) or info.st_uid != uid or info.st_nlink != 1 or info.st_mode & 0o022:
        raise ValueError('Unsafe access file')


def install(request):
    if set(request) != {'sandbox_id', 'repository', 'url', 'gateway_address', 'ca'} or os.geteuid() != 0:
        raise ValueError('Invalid access installation')
    if str(uuid.UUID(request['sandbox_id'])) != request['sandbox_id'] or not re.fullmatch(r'[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+', request['repository']):
        raise ValueError('Invalid access identity')
    url = urllib.parse.urlsplit(request['url'])
    if url.scheme != 'https' or not url.hostname or url.port not in (None, 443) or url.username or url.password or url.query or url.fragment or url.path != '/api/v1/compute/github-token':
        raise ValueError('Invalid access endpoint')
    if ipaddress.ip_address(request['gateway_address']) not in ipaddress.ip_network('10.44.0.0/16'):
        raise ValueError('Invalid Gateway address')
    ssl.create_default_context(cadata=request['ca'])
    user = pwd.getpwnam('orbit')
    regular(PI / 'owner', user.pw_uid)
    regular(PI / 'token', user.pw_uid)
    if (PI / 'owner').read_text() != request['sandbox_id']:
        raise ValueError('Foreign runtime identity')
    if ROOT.exists() or ROOT.is_symlink():
        info = ROOT.lstat()
        if not stat.S_ISDIR(info.st_mode) or info.st_uid != user.pw_uid or info.st_mode & 0o077:
            raise ValueError('Unsafe access directory')
        regular(ROOT / 'config.json', user.pw_uid)
        if json.loads((ROOT / 'config.json').read_text()) != request:
            raise ValueError('Foreign access configuration')
        if HELPER.exists() or HELPER.is_symlink():
            regular(HELPER, 0)
        if GH_WRAPPER.exists() or GH_WRAPPER.is_symlink():
            regular(GH_WRAPPER, 0)
            if GH_WRAPPER.read_text() != GH_PROGRAM:
                raise ValueError('Foreign GitHub CLI wrapper')
    else:
        if GH_WRAPPER.exists() or GH_WRAPPER.is_symlink() or HELPER.exists() or HELPER.is_symlink() or Path('/home/orbit/.gitconfig').exists() or Path('/home/orbit/.gitconfig').is_symlink():
            raise ValueError('Foreign credential helper')
        ROOT.mkdir(mode=0o700)
        os.chown(ROOT, user.pw_uid, user.pw_gid)
        fd = os.open(ROOT / 'config.json', os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(fd, 'w') as stream:
            stream.write(json.dumps(request))
        os.chown(ROOT / 'config.json', user.pw_uid, user.pw_gid)
    program = '#!/usr/bin/python3 -I\n' + SOURCE
    # Root installs only this code-owned program, without credential bytes in argv.
    temporary = HELPER.with_name('.orbit-github-' + request['sandbox_id'])
    fd = os.open(temporary, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o755)
    with os.fdopen(fd, 'w') as stream:
        stream.write(program)
    os.replace(temporary, HELPER)
    if not GH_WRAPPER.exists():
        fd = os.open(GH_WRAPPER, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o755)
        with os.fdopen(fd, 'w') as stream:
            stream.write(GH_PROGRAM)
    global_config = Path('/home/orbit/.gitconfig')
    if global_config.exists() or global_config.is_symlink():
        regular(global_config, user.pw_uid)
    subprocess.run(['runuser', '-u', 'orbit', '--', 'git', 'config', '--global', '--unset-all', 'url.https://github.com/.insteadOf'], capture_output=True, check=False)
    for key, value in [('credential.https://github.com.helper', ''), ('credential.https://github.com.helper', str(HELPER) + ' git'),
                       ('credential.https://github.com.useHttpPath', 'true'), ('url.https://github.com/.insteadOf', 'git@github.com:'),
                       ('url.https://github.com/.insteadOf', 'ssh://git@github.com/')]:
        if key.endswith('.helper') and value == '':
            subprocess.run(['runuser', '-u', 'orbit', '--', 'git', 'config', '--global', '--unset-all', key], capture_output=True, check=False)
        subprocess.run(['runuser', '-u', 'orbit', '--', 'git', 'config', '--global', '--add', key, value], capture_output=True, check=True)
    print(json.dumps({'ready': True}))


def configuration():
    regular(ROOT / 'config.json', os.getuid())
    request = json.loads((ROOT / 'config.json').read_text())
    regular(PI / 'owner', os.getuid())
    if (PI / 'owner').read_text() != request['sandbox_id']:
        raise ValueError('Foreign runtime identity')
    return request


def token(request):
    regular(PI / 'token', os.getuid())
    secret = (PI / 'token').read_text()
    if not re.fullmatch('[a-f0-9]{64}', secret):
        raise ValueError('Invalid runtime credential')
    url = urllib.parse.urlsplit(request['url'])
    context = ssl.create_default_context(cadata=request['ca'])
    connection = http.client.HTTPSConnection(url.hostname, timeout=15, context=context)
    # Connect to the enrolled Gateway address while verifying its configured TLS name.
    connection.sock = context.wrap_socket(socket.create_connection((request['gateway_address'], 443), timeout=15), server_hostname=url.hostname)
    try:
        connection.request('POST', url.path, headers={'Authorization': 'Bearer ' + secret, 'Accept': 'application/json', 'Content-Length': '0'})
        response = connection.getresponse()
        body = response.read(8193)
        if response.status != 200 or len(body) > 8192:
            raise ValueError('Repository access refused')
        value = json.loads(body).get('token')
        if not isinstance(value, str) or not re.fullmatch('[A-Za-z0-9_]+', value):
            raise ValueError('Invalid repository credential')
        return value
    finally:
        connection.close()


def git(request, operation, stream):
    if operation != 'get':
        return
    fields = {}
    for line in stream:
        line = line.rstrip('\n')
        if not line:
            break
        key, value = line.split('=', 1)
        fields[key] = value
    if fields.get('protocol') != 'https' or fields.get('host') != 'github.com' or fields.get('path', '').removesuffix('.git').lower() != request['repository'].lower():
        return
    print('username=x-access-token\npassword=' + token(request))


def main():
    if sys.argv[1:] == ['install']:
        install(json.load(sys.stdin))
        return
    request = configuration()
    if sys.argv[1:2] == ['git']:
        git(request, sys.argv[2] if len(sys.argv) > 2 else '', sys.stdin)
    elif sys.argv[1:2] == ['gh']:
        binary = '/usr/bin/gh'
        if not os.access(binary, os.X_OK):
            raise ValueError('GitHub CLI is not installed')
        environment = dict(os.environ, GH_TOKEN=token(request), GH_HOST='github.com')
        os.execve(binary, [binary, *sys.argv[2:]], environment)
    else:
        raise ValueError('Unsupported access command')


# The installer receives this source as a fixed program. Its request contains no installation token.
SOURCE = None
if __name__ == '__main__':
    try:
        if sys.argv[1:] == ['install']:
            # PHP appends the same trusted source as data before calling main.
            if SOURCE is None:
                raise ValueError('Missing helper program')
        main()
    except Exception:
        print('Orbit repository access failed.', file=sys.stderr)
        sys.exit(1)
