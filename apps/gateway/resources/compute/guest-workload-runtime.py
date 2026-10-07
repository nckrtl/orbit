"""Enroll owned workload guests with their private Gateway through native Orbit actions."""
from contextlib import closing
import base64
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import sqlite3
import subprocess
import sys
import tempfile
import uuid

ROLES = ('gateway', 'operator', 'app-dev', 'app-prod', 'app-prod-2')
OFFSETS = {'gateway': 11, 'operator': 10, 'app-dev': 12, 'app-prod': 13, 'app-prod-2': 14}
VPN = {'gateway': '10.44.0.1', 'operator': '10.44.0.3', 'app-dev': '10.44.0.2', 'app-prod': '10.44.0.4', 'app-prod-2': '10.44.0.5'}


def run(arguments, root, home, timeout=120):
    environment = {key: value for key, value in os.environ.items()
                   if not key.startswith(('GIT_', 'ORBIT_')) and key not in ('GH_TOKEN', 'GITHUB_TOKEN', 'COMPOSER_AUTH')}
    environment.update(HOME=str(home), ORBIT_HOME=str(home / '.orbit'),
                       ORBIT_GATEWAY_CHECKOUT=str(root / 'apps/gateway'), DB_CONNECTION='sqlite',
                       DB_DATABASE=str(home / '.orbit/gateway.sqlite'), GIT_CONFIG_GLOBAL='/dev/null',
                       GIT_CONFIG_NOSYSTEM='1', GIT_TERMINAL_PROMPT='0')
    return subprocess.run(arguments, cwd=root, env=environment, capture_output=True, text=True,
                          check=True, timeout=timeout).stdout.strip()


def local(path, directory=False):
    if path.resolve() != path or (not path.is_dir() if directory else not path.is_file()) or path.stat().st_uid != os.geteuid():
        raise ValueError('Workload state is not owned and local')
    return path


def public_key(value):
    return isinstance(value, str) and re.fullmatch(r'ssh-ed25519 [A-Za-z0-9+/]+={0,2}', value) is not None


def fingerprint(value):
    return isinstance(value, str) and re.fullmatch(r'SHA256:[A-Za-z0-9+/]{43}', value) is not None


def git(arguments, root, home):
    return run(['git', '-c', 'core.hooksPath=/dev/null', '-c', 'core.fsmonitor=false', *arguments], root, home)


def validate(request, root, home):
    common = {'sandbox_id', 'branch', 'head', 'phase', 'inventory', 'source_template', 'subnet'}
    if not isinstance(request, dict) or request.get('phase') not in ('gateway-identity', 'workload-identity', 'enroll'):
        raise ValueError('Invalid workload request')
    extra = {'gateway-identity': set(), 'workload-identity': {'role', 'gateway_public_key'}, 'enroll': {'identities', 'role'}}[request['phase']]
    if (set(request) != common | extra or not isinstance(request['sandbox_id'], str)
            or str(uuid.UUID(request['sandbox_id'])) != request['sandbox_id']
            or not isinstance(request['branch'], str) or not re.fullmatch(r'task-[1-9][0-9]*', request['branch'])
            or not isinstance(request['head'], str) or not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', request['head'])):
        raise ValueError('Invalid workload scope')
    inventory = request['inventory']
    if (not isinstance(inventory, list) or any(not isinstance(role, str) for role in inventory)
            or inventory != [role for role in ROLES if role in inventory] or len(inventory) < 3
            or inventory[:2] != ['gateway', 'operator']):
        raise ValueError('Invalid workload inventory')
    template = request['source_template']
    if (not isinstance(template, dict) or set(template) != {'id', 'repository', 'base', 'commit'}
            or not isinstance(template['id'], str) or str(uuid.UUID(template['id'])) != template['id']
            or not isinstance(template['repository'], str)
            or not re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git', template['repository'])
            or not isinstance(template['commit'], str) or not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', template['commit'])
            or not isinstance(template['base'], str) or not template['base']):
        raise ValueError('Invalid workload source template')
    subnet = ipaddress.ip_network(request['subnet'], strict=True)
    if subnet.version != 4 or subnet.prefixlen != 24 or not subnet.subnet_of(ipaddress.ip_network('10.233.0.0/16')):
        raise ValueError('Invalid workload subnet')
    local(root, directory=True)
    local(home, directory=True)
    marker = local(root / '.git/orbit-sandbox-source.json')
    if marker.stat().st_size > 8192:
        raise ValueError('Invalid workload source marker')
    owner = json.loads(marker.read_text())
    if (not isinstance(owner, dict) or owner.get('sandbox_id') != request['sandbox_id']
            or owner.get('branch') != request['branch'] or owner.get('source_template') != template
            or git(['symbolic-ref', 'HEAD'], root, home) != 'refs/heads/' + request['branch']
            or git(['rev-parse', '--verify', 'HEAD^{commit}'], root, home) != request['head']):
        raise ValueError('Workload source belongs to another group or commit')
    return subnet


def inventory(request, root, home):
    database = local(home / '.orbit/gateway.sqlite')
    with closing(sqlite3.connect(database.as_uri() + '?mode=ro', uri=True)) as db:
        db.row_factory = sqlite3.Row
        records = list(db.execute('SELECT id, name, status, user, architecture, public_ssh_host, wireguard_ip, ssh_host_fingerprint FROM nodes'))
        nodes = {row['name']: dict(row) for row in records}
        if len(nodes) != len(records):
            raise ValueError('The private Gateway has duplicate Nodes')
        roles = {name: [dict(row) for row in db.execute('SELECT role, status FROM node_roles WHERE node_id = ?', [node['id']])] for name, node in nodes.items()}
    if set(nodes) - set(request['inventory']) or not {'gateway', 'operator'} <= set(nodes):
        raise ValueError('The private Gateway has foreign inventory')
    subnet = ipaddress.ip_network(request['subnet'])
    for name, node in nodes.items():
        if (node['user'] != 'orbit' or node['public_ssh_host'] != str(subnet.network_address + OFFSETS[name])
                or node['wireguard_ip'] != VPN[name] or node['status'] not in ('active', 'provisioning', 'failed')):
            raise ValueError('The private Node network identity changed')
        required = 'app-prod' if name == 'app-prod-2' else name
        if name == 'operator' and (node['status'] != 'active' or roles[name] != []):
            raise ValueError('The operator is not active and roleless')
        if name == 'gateway' and (node['status'] != 'active' or not {'gateway', 'vpn'} <= {row['role'] for row in roles[name] if row['status'] == 'active'}):
            raise ValueError('The private Gateway roles are unavailable')
        if name not in ('gateway', 'operator') and (len(roles[name]) > 1 or any(row['role'] != required for row in roles[name])):
            raise ValueError('The private workload roles changed')
        node['roles'] = roles[name]
    return nodes


def write_json(path, value):
    with path.open('x') as output:
        json.dump(value, output)
    path.chmod(0o600)


def atomic(path, text):
    temporary = None
    try:
        with tempfile.NamedTemporaryFile(mode='w', prefix='.sandbox-', dir=path.parent, delete=False) as output:
            temporary = Path(output.name)
            output.write(text)
        os.replace(temporary, path)
    finally:
        if temporary is not None:
            temporary.unlink(missing_ok=True)


def identity_locked(request, root, home, system):
    role = request['role']
    key = request['gateway_public_key']
    if role not in request['inventory'][2:] or not public_key(key):
        raise ValueError('Invalid workload enrollment identity')
    state = home / '.orbit'
    if not state.exists():
        state.mkdir(mode=0o700)
    local(state, directory=True)
    directory = state / 'sandbox-workload-identity'
    if not directory.exists():
        # A role image contains prerequisites, never an enrolled identity.
        if (any((system / 'etc/wireguard').glob('*.conf')) or (system / 'etc/orbit/agent/secret').exists()
                or any((state / name).exists() for name in ('config.json', 'gateway.sqlite', 'ssh/id_ed25519'))):
            raise ValueError('The workload image contains enrollment state')
        directory.mkdir(mode=0o700)
    local(directory, directory=True)
    intent = {'sandbox_id': request['sandbox_id'], 'role': role, 'source_template': request['source_template'], 'gateway_public_key': key}
    marker = directory / 'owner.json'
    if marker.exists():
        if json.loads(local(marker).read_text()) != intent:
            raise ValueError('The workload identity belongs to another sandbox')
    else:
        if any(directory.iterdir()):
            raise ValueError('The workload identity has incomplete foreign state')
        write_json(marker, intent)
    private = directory / 'host_key'
    public = directory / 'host_key.pub'
    if not private.exists() and not public.exists():
        run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(private)], root, home)
    local(private)
    local(public)
    if private.stat().st_mode & 0o077:
        raise ValueError('The workload SSH private key is not protected')
    host_key = ' '.join(run(['ssh-keygen', '-y', '-f', str(private)], root, home).split()[:2])
    if not public_key(host_key) or public.read_text().strip().split()[:2] != host_key.split():
        raise ValueError('The workload host key is incomplete')
    identity_fingerprint = 'SHA256:' + base64.b64encode(hashlib.sha256(base64.b64decode(host_key.split()[1], validate=True)).digest()).decode().rstrip('=')
    installed = directory / 'installed.json'
    host_private = system / 'etc/ssh/ssh_host_ed25519_key'
    host_public = system / 'etc/ssh/ssh_host_ed25519_key.pub'
    if installed.exists():
        if (json.loads(local(installed).read_text()) != {'fingerprint': identity_fingerprint}
                or host_public.is_symlink() or host_public.read_text().strip().split()[:2] != host_key.split()
                or ' '.join(run(['sudo', '-n', 'ssh-keygen', '-y', '-f', str(host_private)], root, home).split()[:2]) != host_key):
            raise ValueError('The installed workload host key changed')
    else:
        run(['sudo', '-n', 'install', '-m', '0600', str(private), str(host_private)], root, home)
        run(['sudo', '-n', 'install', '-m', '0644', str(public), str(host_public)], root, home)
        config = directory / 'sshd.conf'
        if config.exists():
            local(config)
            if config.read_text() != 'HostKey /etc/ssh/ssh_host_ed25519_key\n':
                raise ValueError('The workload SSH configuration changed')
        else:
            config.write_text('HostKey /etc/ssh/ssh_host_ed25519_key\n')
        run(['sudo', '-n', 'install', '-m', '0644', str(config), str(system / 'etc/ssh/sshd_config.d/orbit-sandbox-host-key.conf')], root, home)
        run(['sudo', '-n', 'sshd', '-t'], root, home)
        run(['sudo', '-n', 'systemctl', 'enable', '--now', 'ssh'], root, home)
        run(['sudo', '-n', 'systemctl', 'restart', 'ssh'], root, home)
        write_json(installed, {'fingerprint': identity_fingerprint})
    authorized = home / '.ssh'
    if not authorized.exists():
        authorized.mkdir(mode=0o700)
    local(authorized, directory=True)
    path = authorized / 'authorized_keys'
    if path.exists() or path.is_symlink():
        local(path)
    atomic(path, key + '\n')
    architecture = run(['uname', '-m'], root, home)
    if architecture not in ('x86_64', 'aarch64'):
        raise ValueError('Invalid workload architecture')
    return {'role': role, 'architecture': architecture, 'fingerprint': identity_fingerprint}


def identity(request, root, home, system):
    state = home / '.orbit'
    if not state.exists():
        state.mkdir(mode=0o700)
    local(state, directory=True)
    descriptor = os.open(state / 'sandbox-workload.lock', os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'r+') as lock:
        details = os.fstat(lock.fileno())
        if details.st_uid != os.geteuid() or details.st_mode & 0o077:
            raise ValueError('The workload identity lock is not protected')
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
        return identity_locked(request, root, home, system)


def enroll(request, root, home):
    identities = request['identities']
    expected = request['inventory'][2:]
    if (not isinstance(identities, list) or len(identities) != len(expected)
            or any(not isinstance(value, dict) or set(value) != {'role', 'architecture', 'fingerprint'} for value in identities)
            or [value['role'] for value in identities] != expected
            or any(value['architecture'] not in ('x86_64', 'aarch64') or not fingerprint(value['fingerprint']) for value in identities)):
        raise ValueError('Invalid workload guest identities')
    if request['role'] not in expected:
        raise ValueError('Invalid native enrollment role')
    nodes = inventory(request, root, home)
    for value in identities:
        node = nodes.get(value['role'])
        if node and (node['ssh_host_fingerprint'] not in (None, value['fingerprint']) or node['architecture'] not in (None, value['architecture'])):
            raise ValueError('The workload Node has a different pinned host identity')
    profile = json.loads(local(home / '.orbit/config.json').read_text())
    active = profile.get('active_gateway') if isinstance(profile, dict) else None
    gateways = profile.get('gateways') if isinstance(profile, dict) else None
    if (not isinstance(active, str) or not isinstance(gateways, dict) or set(gateways) != {active}
            or not isinstance(gateways[active], dict) or gateways[active].get('url') != 'https://10.44.0.1'):
        raise ValueError('The workload enrollment profile is not private')
    subnet = ipaddress.ip_network(request['subnet'])
    for value in [value for value in identities if value['role'] == request['role']]:
        name = value['role']
        node = nodes.get(name)
        required = 'app-prod' if name == 'app-prod-2' else name
        ready = (node and node['status'] == 'active' and node['ssh_host_fingerprint'] == value['fingerprint']
                 and node['roles'] == [{'role': required, 'status': 'active'}])
        if not ready:
            run(['php', str(root / 'apps/gateway/artisan'), 'orbit:node-provision', name, str(subnet.network_address + OFFSETS[name]),
                 '--user=orbit', '--orbit-user=orbit', '--architecture=' + value['architecture'], '--role=' + required,
                 '--tld=' + ('beast' if name == 'app-dev' else name), '--wireguard-ip=' + VPN[name],
                 '--host-key-fingerprint=' + value['fingerprint'], '--no-interaction'], root, home, timeout=840)
        nodes = inventory(request, root, home)
        node = nodes.get(name)
        if (not node or node['status'] != 'active' or node['architecture'] != value['architecture']
                or node['ssh_host_fingerprint'] != value['fingerprint'] or node['roles'] != [{'role': required, 'status': 'active'}]):
            raise ValueError('Native workload enrollment did not confirm its pinned identity')
        run([str(root / 'apps/cli/orbit'), 'node:access:add', str(nodes['operator']['id']), str(node['id']), '--json'], root, home)
    return {'enrolled_roles': [request['role']]}


def prepare(request, root=Path('/home/orbit/orbit'), home=Path('/home/orbit'), system=Path('/')):
    validate(request, root, home)
    if request['phase'] == 'gateway-identity':
        inventory(request, root, home)
        value = ' '.join(run(['ssh-keygen', '-y', '-f', str(local(home / '.orbit/ssh/id_ed25519'))], root, home).split()[:2])
        if not public_key(value):
            raise ValueError('Invalid private Gateway SSH key')
        result = {'gateway_public_key': value}
    elif request['phase'] == 'workload-identity':
        result = identity(request, root, home, system)
    else:
        result = enroll(request, root, home)
    if git(['rev-parse', '--verify', 'HEAD^{commit}'], root, home) != request['head']:
        raise ValueError('The workload source changed during enrollment')
    return {'sandbox_id': request['sandbox_id'], 'head': request['head'], 'ready': True, **result}


if __name__ == '__main__':
    try:
        raw = sys.stdin.buffer.read(16385)
        if len(raw) > 16384:
            raise ValueError('Workload request is too large')
        print(json.dumps(prepare(json.loads(raw))))
    except (ValueError, KeyError, TypeError, OSError, sqlite3.Error, subprocess.SubprocessError):
        print('Sandbox workload enrollment failed.', file=sys.stderr)
        sys.exit(1)
