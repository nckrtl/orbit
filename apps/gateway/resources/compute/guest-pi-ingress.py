"""Restore only the owned operator's Pi ingress and source-specific return path."""
import base64
import ipaddress
import json
import os
from pathlib import Path
import pwd
import shlex
import stat
import subprocess
import sys
import uuid

CONFIG = Path('/etc/orbit-sandbox-pi-network.json')
PROGRAM = Path('/etc/orbit-sandbox-pi-network.py')
UNIT = Path('/etc/systemd/system/orbit-sandbox-pi-network.service')
CHECKOUT = Path('/home/orbit/orbit')
TABLE = 3774
ROOT_UID = 0


def command(*args):
    return subprocess.run(args, capture_output=True, text=True, timeout=30)


def run(*args):
    result = command(*args)
    if result.returncode:
        raise ValueError('Pi network operation failed')
    return result.stdout


def validate(request):
    if set(request) != {'sandbox_id', 'address', 'bridge', 'gateway'}:
        raise ValueError('Invalid Pi network contract')
    if str(uuid.UUID(request['sandbox_id'])) != request['sandbox_id']:
        raise ValueError('Invalid Pi network identity')
    address, bridge, gateway = (ipaddress.IPv4Address(request[k]) for k in ('address', 'bridge', 'gateway'))
    network = ipaddress.IPv4Network(str(bridge) + '/24', strict=False)
    if (bridge not in ipaddress.IPv4Network('10.233.0.0/16') or bridge != network.network_address + 1
            or address != network.network_address + 10 or gateway not in ipaddress.IPv4Network('10.44.0.0/16')):
        raise ValueError('Foreign Pi network endpoint')
    return request


def regular(path, uid):
    details = path.lstat()
    if not stat.S_ISREG(details.st_mode) or details.st_uid != uid or details.st_nlink != 1 or details.st_mode & 0o022:
        raise ValueError('Foreign Pi network file')


def interface(address):
    rows = json.loads(run('ip', '-j', '-4', 'address', 'show'))
    matches = [row['ifname'] for row in rows if any(a.get('local') == address for a in row.get('addr_info', []))]
    if len(matches) != 1 or not isinstance(matches[0], str) or matches[0] in ('lo', 'orbit'):
        raise ValueError('The reserved Pi interface is unavailable')
    return matches[0]


def firewall(request, device):
    marker = 'orbit:sandbox-pi:' + request['sandbox_id']
    args = ['-i', device, '-s', request['gateway'] + '/32', '-d', request['address'] + '/32',
            '-p', 'tcp', '--dport', '3774', '-m', 'comment', '--comment', marker, '-j', 'ACCEPT']
    marked = []
    for line in run('iptables-save').splitlines():
        if 'orbit:sandbox-pi:' in line:
            tokens = shlex.split(line)
            if '--comment' not in tokens or tokens[tokens.index('--comment') + 1] != marker:
                raise ValueError('Foreign Pi firewall ownership')
            marked.append(tokens)
    result = command('iptables', '-w', '-C', 'INPUT', *args)
    if result.returncode not in (0, 1) or len(marked) > 1 or bool(marked) != (result.returncode == 0):
        raise ValueError('Foreign Pi firewall rule')
    return args, bool(marked)


def inspect(request, allow_existing):
    device = interface(request['address'])
    observed = command('ip', '-j', '-4', 'route', 'show', 'table', str(TABLE))
    if observed.returncode == 2 and observed.stdout.strip() in ('', '[]') and 'FIB table does not exist' in observed.stderr:
        routes = []
    elif observed.returncode == 0:
        routes = json.loads(observed.stdout)
    else:
        raise ValueError('Pi return routes are unavailable')
    rules = [r for r in json.loads(run('ip', '-j', '-4', 'rule', 'show'))
             if r.get('priority') == TABLE or r.get('table') in (TABLE, str(TABLE))]
    if len(routes) > 1 or len(rules) > 1:
        raise ValueError('Foreign Pi return policy')
    if routes:
        route = routes[0]
        if (set(route) - {'dst', 'gateway', 'dev', 'prefsrc', 'protocol', 'scope', 'flags', 'table'}
                or route.get('dst') not in (request['gateway'], request['gateway'] + '/32')
                or route.get('gateway') != request['bridge'] or route.get('dev') != device
                or route.get('prefsrc') != request['address'] or route.get('protocol') != 'static'
                or route.get('scope', 'global') != 'global' or route.get('flags', []) != []
                or route.get('table', TABLE) not in (TABLE, str(TABLE))):
            raise ValueError('Foreign Pi return route')
    if rules:
        rule = rules[0]
        if (set(rule) - {'priority', 'src', 'dst', 'table', 'protocol'}
                or rule.get('priority') != TABLE or rule.get('table') not in (TABLE, str(TABLE))
                or rule.get('src') not in (request['address'], request['address'] + '/32')
                or rule.get('dst') not in (request['gateway'], request['gateway'] + '/32')
                or rule.get('protocol') != 'static'):
            raise ValueError('Foreign Pi return rule')
    args, present = firewall(request, device)
    if not allow_existing and (routes or rules or present):
        raise ValueError('Unowned Pi network state')
    return device, bool(routes), bool(rules), args, present


def configure(request, allow_existing=True):
    validate(request)
    device, route, rule, args, present = inspect(request, allow_existing)
    if not route:
        run('ip', 'route', 'add', request['gateway'] + '/32', 'via', request['bridge'], 'dev', device,
            'src', request['address'], 'proto', 'static', 'table', str(TABLE))
    if not rule:
        run('ip', 'rule', 'add', 'priority', str(TABLE), 'from', request['address'] + '/32',
            'to', request['gateway'] + '/32', 'lookup', str(TABLE), 'protocol', 'static')
    if not present:
        run('iptables', '-w', '-I', 'INPUT', '1', *args)


def write(path, content, mode):
    fd = os.open(path, os.O_CREAT | os.O_EXCL | os.O_WRONLY | os.O_NOFOLLOW, mode)
    created = os.fstat(fd)
    try:
        with os.fdopen(fd, 'w') as output:
            output.write(content)
            output.flush()
            os.fsync(output.fileno())
    except BaseException:
        try:
            current = path.lstat()
            if (current.st_dev, current.st_ino) == (created.st_dev, created.st_ino):
                path.unlink()
        except FileNotFoundError:
            pass
        raise


def install(request, source):
    validate(request)
    if os.geteuid() != ROOT_UID:
        raise ValueError('Pi network setup requires root')
    uid = pwd.getpwnam('orbit').pw_uid
    for directory in (CHECKOUT, CHECKOUT / '.git'):
        details = directory.lstat()
        if not stat.S_ISDIR(details.st_mode) or details.st_uid != uid:
            raise ValueError('Foreign Pi checkout')
    marker = CHECKOUT / '.git/orbit-sandbox-source.json'
    regular(marker, uid)
    if marker.stat().st_size > 8192 or json.loads(marker.read_text()).get('sandbox_id') != request['sandbox_id']:
        raise ValueError('Foreign Pi source identity')
    unit = '''[Unit]
Description=Orbit sandbox Pi network
Wants=network-online.target
After=network-online.target
Before=orbit-sandbox-pi.service
[Service]
Type=oneshot
ExecStart=/usr/bin/python3 -I /etc/orbit-sandbox-pi-network.py --apply
RemainAfterExit=yes
[Install]
WantedBy=multi-user.target
'''
    contents = {CONFIG: json.dumps(request, sort_keys=True) + '\n', PROGRAM: source, UNIT: unit}
    owned = CONFIG.exists() or CONFIG.is_symlink()
    for path, content in contents.items():
        parent = path.parent.lstat()
        if not stat.S_ISDIR(parent.st_mode) or parent.st_uid != ROOT_UID or parent.st_mode & 0o022:
            raise ValueError('Unsafe Pi network directory')
        if path.exists() or path.is_symlink():
            regular(path, ROOT_UID)
            if not owned or path.read_text() != content:
                raise ValueError('Foreign Pi network intent')
    inspect(request, owned)
    for path, content in contents.items():
        if not path.exists():
            write(path, content, 0o600 if path == CONFIG else 0o644)
    configure(request)
    run('systemctl', 'daemon-reload')
    run('systemctl', 'enable', '--now', UNIT.name)
    return {'sandbox_id': request['sandbox_id'], 'ready': True}


if __name__ == '__main__':
    try:
        if sys.argv[1:] == ['--apply']:
            if os.geteuid() != ROOT_UID:
                raise ValueError('Pi network setup requires root')
            regular(CONFIG, ROOT_UID)
            configure(json.loads(CONFIG.read_text()))
        else:
            print(json.dumps(install(json.loads(sys.stdin.buffer.read(8192)), base64.b64decode(SOURCE).decode())))
    except Exception:
        print(json.dumps({'error': 'sandbox_pi_network_failed'}))
        sys.exit(1)
