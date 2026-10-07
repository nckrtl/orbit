"""Configure a sandbox-local Pi service. Only group credentials enter this program."""
import json
import ipaddress
import os
from pathlib import Path
import pwd
import re
import stat
import subprocess
import sys
import time
import urllib.error
import urllib.request
import uuid

ROOT = Path('/home/orbit/.orbit-sandbox-pi')
UNIT = Path('/etc/systemd/system/orbit-sandbox-pi.service')
BINARY = Path('/usr/local/bin/orbit-pi-server')


def validate(request):
    if set(request) - {'model_relay_address'} != {'sandbox_id', 'checkout', 'pi_token', 'model_key', 'models'}:
        raise ValueError('Invalid runtime request')
    identity = request['sandbox_id']
    if str(uuid.UUID(identity)) != identity:
        raise ValueError('Invalid sandbox identity')
    if any(not isinstance(request[key], str) or not re.fullmatch('[a-f0-9]{64}', request[key]) for key in ('pi_token', 'model_key')):
        raise ValueError('Invalid credentials')
    # A fixed image path avoids systemd specifier/argument interpolation of checkout paths.
    if request['checkout'] != '/home/orbit/orbit':
        raise ValueError('Unsupported sandbox checkout')
    relay = request.get('model_relay_address')
    if relay is not None:
        address = ipaddress.ip_address(relay)
        if address.version != 4 or address not in ipaddress.ip_network('10.233.0.0/16') or int(address) % 256 != 1:
            raise ValueError('Invalid group model relay')
    models = request['models']
    if not isinstance(models, list) or not 1 <= len(models) <= 100:
        raise ValueError('Configure supported sandbox models')
    seen = set()
    for model in models:
        if not isinstance(model, dict) or set(model) != {'id', 'name', 'reasoning', 'input', 'contextWindow', 'maxTokens'}:
            raise ValueError('Invalid model descriptor')
        if not isinstance(model['id'], str) or not re.fullmatch('[A-Za-z0-9][A-Za-z0-9_.:-]{0,127}', model['id']) or model['id'].startswith('claude') or model['id'] in seen:
            raise ValueError('Invalid model identity')
        if not isinstance(model['name'], str) or not 1 <= len(model['name']) <= 200 or type(model['reasoning']) is not bool:
            raise ValueError('Invalid model metadata')
        if model['input'] not in (['text'], ['text', 'image']):
            raise ValueError('Invalid model inputs')
        if any(type(model[key]) is not int or not 1 <= model[key] <= 4000000 for key in ('contextWindow', 'maxTokens')) or model['maxTokens'] > model['contextWindow']:
            raise ValueError('Invalid model limits')
        seen.add(model['id'])
    return request


def run(*args):
    subprocess.run(args, check=True, capture_output=True, timeout=30)


def regular(path, uid):
    details = path.lstat()
    if not stat.S_ISREG(details.st_mode) or details.st_uid != uid or details.st_nlink != 1 or details.st_mode & 0o022:
        raise ValueError('Unsafe runtime file')


def install(request):
    validate(request)
    if os.geteuid() != 0:
        raise ValueError('Guest runtime preparation requires sudo')
    user = pwd.getpwnam('orbit')
    try:
        pwd.getpwnam('orbit-worker')
    except KeyError:
        pass
    else:
        raise ValueError('The sandbox image contains the shared worker account')
    regular(BINARY, 0)
    if not os.access(BINARY, os.X_OK) or not Path(request['checkout']).is_dir():
        raise ValueError('Image runtime or checkout unavailable')
    # The operator has a private runtime directory, never the shared host's Pi home.
    if ROOT.exists() or ROOT.is_symlink():
        details = ROOT.lstat()
        if not stat.S_ISDIR(details.st_mode) or details.st_uid != user.pw_uid or details.st_mode & 0o077:
            raise ValueError('Unsafe runtime directory')
        regular(ROOT / 'owner', user.pw_uid)
        if (ROOT / 'owner').read_text() != request['sandbox_id']:
            raise ValueError('Foreign runtime directory')
    else:
        if UNIT.exists() or UNIT.is_symlink():
            raise ValueError('Foreign runtime service')
        ROOT.mkdir(mode=0o700)
        os.chown(ROOT, user.pw_uid, user.pw_gid)
        write(ROOT / 'owner', request['sandbox_id'], user.pw_uid, user.pw_gid)
    models = {'providers': {'orbit-sandbox': {'baseUrl': 'http://127.0.0.1:8317/v1', 'api': 'openai-completions',
              'apiKey': request['model_key'], 'models': request['models']}}}
    contents = {'token': request['pi_token'], 'models.json': json.dumps(models, sort_keys=True)}
    unit = '''[Unit]
Description=Orbit sandbox Pi
After=network.target
[Service]
User=orbit
Group=orbit
Environment=HOME=/home/orbit
WorkingDirectory=/home/orbit/orbit
UMask=0077
ExecStart=/usr/local/bin/orbit-pi-server serve --host 0.0.0.0 --port 3774 --token-file /home/orbit/.orbit-sandbox-pi/token --agent-dir /home/orbit/.orbit-sandbox-pi --workspace-root /home/orbit/orbit --allow-provider orbit-sandbox
Restart=on-failure
[Install]
WantedBy=multi-user.target
'''
    units = {UNIT: unit}
    relay = request.get('model_relay_address')
    if relay is not None:
        regular(Path('/usr/lib/systemd/systemd-socket-proxyd'), 0)
        units[Path('/etc/systemd/system/orbit-sandbox-model.socket')] = '''[Unit]
Description=Orbit sandbox loopback model socket
[Socket]
ListenStream=127.0.0.1:8317
NoDelay=true
[Install]
WantedBy=sockets.target
'''
        units[Path('/etc/systemd/system/orbit-sandbox-model.service')] = '''[Unit]
Description=Orbit sandbox loopback model forwarding
Requires=orbit-sandbox-model.socket
After=network.target
[Service]
User=orbit
Group=orbit
ExecStart=/usr/lib/systemd/systemd-socket-proxyd ''' + relay + ''':8317
NoNewPrivileges=true
'''
    # Validate all existing files before adding missing files. A retry never rotates secrets.
    for name, value in contents.items():
        path = ROOT / name
        if path.exists() or path.is_symlink():
            regular(path, user.pw_uid)
            if path.stat().st_mode & 0o077 or path.read_text() != value:
                raise ValueError('Runtime credentials or model catalogue changed')
    for path, value in units.items():
        if path.exists() or path.is_symlink():
            regular(path, 0)
            if path.read_text() != value:
                raise ValueError('Runtime service changed')
    validate_auth(ROOT / 'auth.json', user.pw_uid)
    for name, value in contents.items():
        if not (ROOT / name).exists():
            write(ROOT / name, value, user.pw_uid, user.pw_gid)
    for path, value in units.items():
        if not path.exists():
            write(path, value, 0, 0, 0o644)
    run('systemctl', 'daemon-reload')
    if relay is not None:
        run('systemctl', 'enable', '--now', 'orbit-sandbox-model.socket')
    run('systemctl', 'enable', 'orbit-sandbox-pi.service')
    run('systemctl', 'start', 'orbit-sandbox-pi.service')
    for attempt in range(40):
        if relay is not None:
            model_url = 'http://127.0.0.1:8317/v1/models'
            if status(request['model_key'], model_url) != 200:
                time.sleep(0.25)
                continue
            if status('invalid-model-proof-key', model_url) != 401:
                raise ValueError('Model relay authentication failed')
        if status(request['pi_token']) == 200:
            if status('invalid-sandbox-proof-token') != 401:
                raise ValueError('Pi authentication failed')
            return {'sandbox_id': request['sandbox_id'], 'ready': True}
        time.sleep(0.25)
    raise ValueError('Pi did not become ready')


def validate_auth(path, uid):
    if path.exists() or path.is_symlink():
        regular(path, uid)
        if path.stat().st_mode & 0o077 or json.loads(path.read_text()) != {}:
            raise ValueError('Subscription credentials do not belong in a sandbox')


def write(path, content, uid, gid, mode=0o600):
    descriptor = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, mode)
    with os.fdopen(descriptor, 'w') as output:
        os.fchown(output.fileno(), uid, gid)
        output.write(content)
        output.flush()
        os.fsync(output.fileno())


def status(token, url='http://127.0.0.1:3774/capabilities'):
    request = urllib.request.Request(url, headers={'Authorization': 'Bearer ' + token})
    try:
        with urllib.request.urlopen(request, timeout=2) as response:
            return response.status
    except urllib.error.HTTPError as error:
        return error.code
    except OSError:
        return 0


if __name__ == '__main__':
    try:
        request = json.loads(sys.stdin.buffer.read(128 * 1024))
        print(json.dumps(install(request)))
    except Exception:
        # Guest responses are untrusted; do not echo inputs or exception text.
        print(json.dumps({'error': 'sandbox_pi_setup_failed'}))
        sys.exit(1)
