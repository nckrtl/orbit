#!/usr/bin/env python3
"""Typed host control for task-owned Incus VMs. Input and output are JSON; secrets use stdin."""
import base64
import hashlib
import fcntl
import ipaddress
import json
import os
import re
import stat
import subprocess
import sys
import uuid

OWNER = 'orbit-task-sandbox'
ROLES = ('operator', 'gateway', 'app-dev', 'app-prod', 'app-prod-2')
PRIVATE = ('0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
           '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
           '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
           '224.0.0.0/4', '240.0.0.0/4')


# This fixed implementation runs on both sides. Host limits still hold if a guest
# replaces its own Python executable or emits output outside the guest envelope.
BOUNDED_PROCESS = r"""
import base64, json, os, selectors, signal, subprocess, tempfile, time

def bounded_process(argv, data, timeout, limit, env=None):
    started = time.monotonic()
    output = [bytearray(), bytearray()]
    truncated, timed_out = False, False
    with tempfile.TemporaryFile() as incoming:
        incoming.write(data)
        incoming.seek(0)
        with subprocess.Popen(argv, stdin=incoming, stdout=subprocess.PIPE,
                              stderr=subprocess.PIPE, start_new_session=True, env=env) as process:
            with selectors.DefaultSelector() as selector:
                for index, pipe in enumerate((process.stdout, process.stderr)):
                    os.set_blocking(pipe.fileno(), False)
                    selector.register(pipe, selectors.EVENT_READ, index)
                while selector.get_map() or process.poll() is None:
                    remaining = timeout - (time.monotonic() - started)
                    if remaining <= 0:
                        timed_out = True
                        try:
                            os.killpg(process.pid, signal.SIGKILL)
                        except ProcessLookupError:
                            pass
                        break
                    for key, _ in selector.select(min(remaining, 0.1)):
                        chunk = os.read(key.fd, 65536)
                        if not chunk:
                            selector.unregister(key.fileobj)
                            continue
                        room = max(0, limit - sum(map(len, output)))
                        output[key.data].extend(chunk[:room])
                        truncated |= len(chunk) > room
            process.wait()
    return {'exit_code': 124 if timed_out else process.returncode,
            'stdout': base64.b64encode(output[0]).decode(),
            'stderr': base64.b64encode(output[1]).decode(),
            'duration_ms': int((time.monotonic() - started) * 1000),
            'truncated': truncated, 'timed_out': timed_out}
"""
exec(BOUNDED_PROCESS)
GUEST_RUNNER = BOUNDED_PROCESS + r"""
import sys
request = json.load(sys.stdin)
environment = {'HOME': '/home/orbit', 'USER': 'orbit', 'LOGNAME': 'orbit',
               'PATH': '/home/orbit/.local/bin:/usr/local/bin:/usr/bin:/bin:/usr/local/sbin:/usr/sbin:/sbin',
               'LANG': 'C.UTF-8'}
print(json.dumps(bounded_process(request['argv'], base64.b64decode(request['stdin'], validate=True),
                                request['timeout'], request['max_output'], environment)))
"""


class Refusal(RuntimeError):
    pass


def public_networks(blocked):
    allowed = [ipaddress.ip_network('0.0.0.0/0')]
    for value in (*PRIVATE, *blocked):
        denied = ipaddress.ip_network(value, strict=False)
        if denied.version != 4:
            raise Refusal('Only IPv4 networks are supported; sandbox IPv6 stays disabled.')
        remaining = []
        for network in allowed:
            if network.subnet_of(denied):
                continue
            remaining.extend(network.address_exclude(denied) if denied.subnet_of(network) else [network])
        allowed = remaining
    return ','.join(map(str, allowed))


def identity(value):
    if not isinstance(value, str) or str(uuid.UUID(value)) != value:
        raise Refusal('Invalid sandbox identity.')
    return 'ot-' + hashlib.sha256(value.encode()).hexdigest()[:10]


def project_bootstrap(spec, subnet, interfaces):
    value = spec.get('project_bootstrap')
    if value is None:
        return None
    fields = {'ssh_host', 'ssh_port', 'gateway_address', 'wireguard_address', 'wireguard_port'}
    if (not isinstance(value, dict) or set(value) != fields or 'project_slug' not in spec
            or spec.get('source_template') is not None or spec.get('pi_host') is not None
            or spec.get('pi_port') is not None or spec.get('gateway_address') is not None
            or type(value['ssh_port']) is not int or not 24001 <= value['ssh_port'] <= 24254
            or value['ssh_port'] != 24000 + int(str(subnet.network_address).split('.')[2])
            or type(value['wireguard_port']) is not int or not 1 <= value['wireguard_port'] <= 65535
            or any(not isinstance(value[key], str) for key in ('ssh_host', 'gateway_address', 'wireguard_address'))):
        raise Refusal('Invalid Project bootstrap descriptor.')
    host = ipaddress.ip_address(value['ssh_host'])
    gateway = ipaddress.ip_address(value['gateway_address'])
    hub = ipaddress.ip_address(value['wireguard_address'])
    fleet = ipaddress.ip_network('10.44.0.0/16')
    addresses = {address['local'] for interface in interfaces for address in interface.get('addr_info', [])}
    if (host not in fleet or gateway not in fleet or host == gateway or str(host) not in addresses
            or hub.version != 4 or not hub.is_global or hub.is_multicast or str(hub) in addresses):
        raise Refusal('Project bootstrap endpoints do not match the host.')
    return json.dumps(value, sort_keys=True, separators=(',', ':'))


def pi_proxy(spec, subnet, interfaces):
    values = [spec.get(key) for key in ('pi_host', 'pi_port', 'gateway_address')]
    if all(value is None for value in values):
        return None
    host, port, gateway = values
    if type(port) is not int or not 20000 <= port <= 60999:
        raise Refusal('Invalid Pi proxy port.')
    fleet = ipaddress.ip_network('10.44.0.0/16')
    if (not isinstance(host, str) or not isinstance(gateway, str)
            or ipaddress.ip_address(host) not in fleet or ipaddress.ip_address(gateway) not in fleet
            or not any(address.get('local') == host for interface in interfaces for address in interface.get('addr_info', []))):
        raise Refusal('Pi proxies require a local WireGuard address and one Gateway address.')
    return {'type': 'proxy', 'bind': 'host', 'nat': 'true',
            'listen': 'tcp:' + host + ':' + str(port),
            'connect': 'tcp:' + str(subnet.network_address + 10) + ':3774'}


import ipaddress
import json
from pathlib import Path
from urllib.parse import urlsplit


def model_relay_config(listen, source, origin):
    endpoint = urlsplit(origin)
    address = ipaddress.ip_address(endpoint.hostname or '')
    if (endpoint.scheme not in ('http', 'https') or endpoint.username is not None
            or endpoint.password is not None or endpoint.path not in ('', '/')
            or endpoint.query or endpoint.fragment or address.version != 4
            or not (address.is_loopback or address in ipaddress.ip_network('10.44.0.0/16'))):
        raise ValueError('A model relay needs a private fixed upstream')
    target_port = endpoint.port or (443 if endpoint.scheme == 'https' else 80)
    bound = ipaddress.ip_address(listen)
    peer = ipaddress.ip_address(source)
    if bound.version != 4 or peer.version != 4:
        raise ValueError('Invalid relay network')
    transport = {'protocol': 'http', 'dial_timeout': 3000000000, 'response_header_timeout': 30000000000}
    if endpoint.scheme == 'https':
        transport['tls'] = {}
    return {'admin': {'disabled': True, 'config': {'persist': False}}, 'apps': {'http': {'servers': {'sandbox': {
        'listen': [str(bound) + ':8317'], 'automatic_https': {'disable': True}, 'routes': [
            {'match': [{'remote_ip': {'ranges': [str(peer) + '/32']},
                        'path': ['/v1/models', '/v1/responses', '/v1/chat/completions', '/v1/completions', '/v1/embeddings'],
                        'method': ['GET', 'POST']}],
             'handle': [{'handler': 'reverse_proxy', 'upstreams': [{'dial': str(address) + ':' + str(target_port)}],
                         'transport': transport,
                         'headers': {'request': {'delete': ['Cookie', 'Proxy-Authorization']}}}], 'terminal': True},
            {'handle': [{'handler': 'static_response', 'status_code': 403}]}]}}}}}

import pwd
import tempfile


class ModelRelay:
    """One unprivileged Caddy service, bound to one owned sandbox bridge."""
    def __init__(self, name, identity):
        if name != 'ot-' + hashlib.sha256(identity.encode()).hexdigest()[:10]:
            raise ValueError('Invalid relay identity')
        self.name, self.id = name, identity
        self.uid = os.geteuid()
        account = pwd.getpwuid(self.uid)
        self.home = Path(account.pw_dir)
        self.gid = account.pw_gid
        if not re.fullmatch(r'/[A-Za-z0-9_./-]+', str(self.home)) or self.home.resolve() != self.home:
            raise ValueError('Unsupported relay account home')
        self.root = self.home / '.orbit-sandbox-model' / name
        self.unit_name = 'orbit-sandbox-model-' + name + '.service'
        self.unit = self.home / '.config/systemd/user' / self.unit_name

    def directories(self, path):
        for parent in reversed([path, *path.parents]):
            if parent == self.home or self.home in parent.parents:
                if not parent.exists() and not parent.is_symlink():
                    parent.mkdir(mode=0o700)
                details = parent.lstat()
                # Existing XDG parents may be writable by the compute account's own group.
                # Do not change their permissions; files and the dedicated state remain private.
                forbidden = 0o022 if parent == path or parent == self.home else 0o002
                if (not stat.S_ISDIR(details.st_mode) or details.st_uid != self.uid
                        or details.st_gid != self.gid or details.st_mode & forbidden):
                    raise ValueError('Unsafe relay state directory')

    def read(self, path):
        details = path.lstat()
        if not stat.S_ISREG(details.st_mode) or details.st_uid != self.uid or details.st_nlink != 1 or details.st_mode & 0o077:
            raise ValueError('Unsafe relay state file')
        return path.read_text()

    def ensure_file(self, path, content):
        if path.exists() or path.is_symlink():
            if self.read(path) != content:
                raise ValueError('Relay state drifted')
            return
        with tempfile.NamedTemporaryFile(mode='w', dir=path.parent, prefix='.orbit-relay-', delete=False) as output:
            temporary = Path(output.name)
            try:
                output.write(content)
                output.flush()
                os.fsync(output.fileno())
                os.replace(temporary, path)
            finally:
                temporary.unlink(missing_ok=True)

    def service(self):
        return ('[Unit]\nDescription=Orbit sandbox model relay ' + self.name + '\n'
                '[Service]\nExecStart=/usr/bin/caddy run --config ' + str(self.root / 'caddy.json') + '\n'
                'Restart=on-failure\nRestartSec=2\nUMask=0077\nNoNewPrivileges=true\n'
                '[Install]\nWantedBy=default.target\n')

    def control(self, *arguments):
        runtime = Path('/run/user') / str(self.uid)
        if runtime.is_symlink() or not runtime.is_dir() or runtime.stat().st_uid != self.uid or not (runtime / 'bus').exists():
            raise ValueError('The compute account needs an existing user service manager')
        result = subprocess.run(['/usr/bin/systemctl', '--user', *arguments],
                                env={'PATH': '/usr/bin:/bin', 'XDG_RUNTIME_DIR': str(runtime),
                                     'DBUS_SESSION_BUS_ADDRESS': 'unix:path=' + str(runtime / 'bus')},
                                capture_output=True, timeout=45)
        if result.returncode:
            raise ValueError('The owned model relay service operation failed')

    def prepare(self, subnet, origin):
        config = json.dumps(model_relay_config(str(subnet.network_address + 1), str(subnet.network_address + 10), origin), sort_keys=True)
        marker = json.dumps({'owner': OWNER, 'sandbox_id': self.id, 'subnet': str(subnet), 'origin': origin}, sort_keys=True)
        if not self.root.exists() and (self.unit.exists() or self.unit.is_symlink()):
            raise ValueError('A foreign service occupies the relay identity')
        self.directories(self.root)
        if not (self.root / 'owner.json').exists() and any(self.root.iterdir()):
            raise ValueError('An unowned directory occupies the relay identity')
        self.ensure_file(self.root / 'owner.json', marker)
        self.ensure_file(self.root / 'caddy.json', config)
        subprocess.run(['/usr/bin/caddy', 'validate', '--config', str(self.root / 'caddy.json')],
                       check=True, capture_output=True, timeout=30)
        self.directories(self.unit.parent)
        self.ensure_file(self.unit, self.service())
        self.control('daemon-reload')
        self.control('enable', '--now', self.unit_name)
        self.control('is-active', '--quiet', self.unit_name)

    def destroy(self):
        if not self.root.exists() and not self.root.is_symlink():
            if self.unit.exists() or self.unit.is_symlink():
                raise ValueError('An unowned model relay service remains')
            return
        if self.root.resolve() != self.root or self.root.stat().st_uid != self.uid or not self.root.is_dir():
            raise ValueError('Unsafe model relay directory')
        marker = json.loads(self.read(self.root / 'owner.json'))
        if marker.get('owner') != OWNER or marker.get('sandbox_id') != self.id:
            raise ValueError('Model relay ownership does not match')
        if set(path.name for path in self.root.iterdir()) - {'owner.json', 'caddy.json'}:
            raise ValueError('Unexpected model relay files')
        if (self.root / 'caddy.json').exists() or (self.root / 'caddy.json').is_symlink():
            subnet = ipaddress.ip_network(marker['subnet'], strict=True)
            expected = json.dumps(model_relay_config(str(subnet.network_address + 1), str(subnet.network_address + 10), marker['origin']), sort_keys=True)
            if self.read(self.root / 'caddy.json') != expected:
                raise ValueError('Model relay configuration drifted')
        if self.unit.exists() or self.unit.is_symlink():
            if self.read(self.unit) != self.service():
                raise ValueError('Model relay service drifted')
            self.control('disable', '--now', self.unit_name)
            self.unit.unlink()
            self.control('daemon-reload')
        (self.root / 'caddy.json').unlink(missing_ok=True)
        (self.root / 'owner.json').unlink()
        self.root.rmdir()


class Host:
    def __init__(self, project, sandbox_id, budget):
        if not isinstance(project, str) or not re.fullmatch(r'orbit-(?:task-sandboxes|sandbox-proof-[a-z0-9]+)', project):
            raise Refusal('Invalid sandbox project.')
        if not isinstance(budget, int) or isinstance(budget, bool) or not 1 <= budget <= 64:
            raise Refusal('Invalid VM budget.')
        self.project, self.id, self.budget = project, sandbox_id, budget
        self.name = identity(sandbox_id)
        project_info = self.json('query', '/1.0/projects/' + project)
        if project_info.get('config', {}).get('user.orbit.compute.owner') != OWNER:
            raise Refusal('Sandbox project ownership does not match.')
        if project_info.get('config', {}).get('features.networks') != 'false':
            raise Refusal('Local sandbox projects must share the host bridge namespace.')

    def run(self, *args, data=None, timeout=180):
        scope = [] if args[0] == 'query' else ['--project', self.project]
        result = subprocess.run(['incus', '--force-local', *scope, *args],
                                input=data, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                timeout=timeout, check=False)
        if result.returncode:
            # Incus errors may echo sensitive config or guest output. Report only the operation.
            raise Refusal('Incus ' + args[0] + ' operation failed.')
        return result.stdout

    def json(self, *args):
        return json.loads(self.run(*args))

    def host_network(self, operation):
        helper = Path('/usr/local/libexec/orbit-sandbox-network')
        config = Path('/etc/orbit/sandbox-network.json')
        if not helper.exists() and not helper.is_symlink():
            if operation != 'enabled' or config.exists() or config.is_symlink():
                raise Refusal('The installed sandbox host network helper is required.')
            return False
        details = helper.lstat()
        if (not stat.S_ISREG(details.st_mode) or details.st_uid != 0 or details.st_nlink != 1
                or details.st_mode & 0o022 or not details.st_mode & 0o111):
            raise Refusal('Unsafe sandbox host network helper.')
        for parent in helper.parents:
            details = parent.lstat()
            if not stat.S_ISDIR(details.st_mode) or details.st_uid != 0 or details.st_mode & 0o022:
                raise Refusal('Unsafe sandbox host network helper directory.')
        result = subprocess.run(['/usr/bin/sudo', '-n', '--', str(helper)],
                                input=json.dumps({'operation': operation, 'project': self.project,
                                                  'sandbox_id': self.id}).encode(),
                                capture_output=True, timeout=120, check=False)
        if result.returncode or len(result.stdout) > 4096:
            raise Refusal('Sandbox host network operation failed.')
        value = json.loads(result.stdout)
        if operation == 'enabled':
            if not isinstance(value, dict) or set(value) != {'enabled'} or type(value['enabled']) is not bool:
                raise Refusal('Sandbox host network returned invalid output.')
            return value['enabled']
        if value != ({'ready': True} if operation == 'ensure' else {'removed': True}):
            raise Refusal('Sandbox host network returned invalid output.')
        return True

    def ensure_host_network(self):
        network = next((row for row in self.json('network', 'list', '--format=json') if row['name'] == self.name), None)
        if network:
            self.own(network)
            marker = network.get('config', {}).get('user.orbit.compute.host_network')
            if marker not in (None, '1'):
                raise Refusal('Unknown sandbox host network policy.')
            if marker == '1':
                self.host_network('ensure')

    def metadata(self):
        return {'user.orbit.compute.owner': OWNER, 'user.orbit.compute.id': self.id}

    def own(self, resource):
        if any(resource.get('config', {}).get(key) != value for key, value in self.metadata().items()):
            raise Refusal('Resource ownership does not match; no mutation performed.')
        return resource

    def instances(self):
        all_instances = self.json('list', '--format=json')
        owned = []
        for row in all_instances:
            if row['name'].startswith(self.name + '-'):
                self.own(row)
                if row['name'][len(self.name) + 1:] not in ROLES or row.get('type') != 'virtual-machine':
                    raise Refusal('Unexpected sandbox resource.')
                owned.append(row)
        return owned

    def volumes(self):
        owned = []
        for pool in self.json('storage', 'list', '--format=json'):
            for volume in self.json('storage', 'volume', 'list', pool['name'], '--format=json'):
                if volume['name'] == self.name + '-worktree' and volume['type'] == 'custom':
                    self.own(volume)
                    if volume.get('content_type') != 'filesystem':
                        raise Refusal('The sandbox worktree is not a filesystem volume.')
                    expected = {'/1.0/instances/' + self.name + '-' + role + '?project=' + self.project for role in ROLES}
                    if any(user not in expected for user in volume.get('used_by', [])):
                        raise Refusal('The sandbox worktree is attached outside its group.')
                    owned.append((pool['name'], volume))
        if len(owned) > 1:
            raise Refusal('The sandbox has ambiguous worktree volume ownership.')
        return owned

    def capacity(self):
        rows = self.json('list', '--format=json')
        used = sum(1 for row in rows if row.get('status') != 'Stopped')
        return {'available': max(0, self.budget - used), 'used': used, 'budget': self.budget}

    def observe(self):
        rows = self.instances()
        return {'name': self.name, 'instances': [{'name': row['name'], 'state': row['status'].lower()} for row in rows],
                'power': 'destroyed' if not rows else ('stopped' if all(row['status'] == 'Stopped' for row in rows) else 'running')}

    def project_identity(self):
        rows = self.instances()
        if len(rows) != 1 or rows[0]['name'] != self.name + '-operator' or rows[0]['status'] != 'Running':
            raise Refusal('Project identity requires one running owned guest.')
        guest = rows[0]
        config = guest.get('config', {})
        slug, image = config.get('user.orbit.compute.project_slug'), config.get('volatile.base_image')
        if not isinstance(image, str) or not re.fullmatch(r'[a-f0-9]{64}', image):
            raise Refusal('The Project image identity is missing.')
        self.project_image({'project_slug': slug, 'images': {'operator': image}})
        volumes = self.volumes()
        if len(volumes) != 1 or volumes[0][1].get('config', {}).get('user.orbit.compute.project_slug') != slug:
            raise Refusal('The Project worktree identity does not match.')
        pool, volume = volumes[0]
        allowed_attachment = '/1.0/instances/' + guest['name'] + '?project=' + self.project
        if any(value != allowed_attachment for value in volume.get('used_by', [])):
            raise Refusal('The Project worktree is attached outside its guest.')
        network = next((row for row in self.json('network', 'list', '--format=json') if row['name'] == self.name), None)
        if network is None:
            raise Refusal('The Project bridge is missing.')
        self.own(network)
        subnet = ipaddress.ip_interface(network.get('config', {}).get('ipv4.address', ''))
        if (subnet.version != 4 or subnet.network.prefixlen != 24
                or not subnet.network.subnet_of(ipaddress.ip_network('10.233.0.0/16'))
                or subnet.ip != subnet.network.network_address + 1):
            raise Refusal('The Project bridge subnet does not match.')
        expected = {'ipv4.nat': 'true', 'ipv6.address': 'none', 'dns.mode': 'none',
                    'security.acls': self.name, 'security.acls.default.egress.action': 'reject',
                    'security.acls.default.ingress.action': 'reject'}
        if network.get('type') != 'bridge' or any(network['config'].get(key) != value for key, value in expected.items()):
            raise Refusal('The Project bridge policy does not match.')
        address = str(subnet.network.network_address + 10)
        expected_devices = {
            'root': {'type': 'disk', 'path': '/', 'pool': pool, 'size': '20GiB'},
            'worktree': {'type': 'disk', 'pool': pool, 'source': self.name + '-worktree', 'path': '/home/orbit/orbit'},
            'eth0': {'type': 'nic', 'network': self.name, 'name': 'eth0', 'ipv4.address': address,
                     'security.mac_filtering': 'true', 'security.ipv4_filtering': 'true'}}
        if (guest.get('profiles') != [] or guest.get('devices') != expected_devices
                or config.get('user.orbit.compute.template') is not None
                or volume.get('config', {}).get('user.orbit.compute.template') is not None):
            raise Refusal('The Project placement does not match.')
        # Read only the public key through the owned guest's Incus channel.
        program = r'''
import os,pwd,stat,sys
from pathlib import Path
pwd.getpwnam('orbit')
try:
    pwd.getpwnam('orbit-worker')
    sys.exit(1)
except KeyError:
    pass
for path in ('/etc/wireguard/wg0.conf', '/etc/orbit/agent/secret'):
    if os.path.lexists(path):sys.exit(1)
path=Path('/etc/ssh/ssh_host_ed25519_key.pub');details=path.lstat()
if not stat.S_ISREG(details.st_mode) or details.st_uid != 0 or details.st_mode & 0o022 or details.st_size > 512:sys.exit(1)
sys.stdout.write(path.read_text())
'''
        result = bounded_process(['incus', '--force-local', '--project', self.project, 'exec', guest['name'],
                                  '--mode=non-interactive', '--', '/usr/bin/python3', '-I', '-c', program], b'', 30, 1024)
        if result['exit_code'] or result['truncated'] or result['timed_out']:
            raise Refusal('The Project SSH public identity is unavailable.')
        key = base64.b64decode(result['stdout'], validate=True).decode().strip().split(maxsplit=2)
        if len(key) not in (2, 3) or key[0] != 'ssh-ed25519':
            raise Refusal('The Project SSH public identity is invalid.')
        raw = base64.b64decode(key[1], validate=True)
        if len(raw) != 51 or raw[:19] != b'\x00\x00\x00\x0bssh-ed25519\x00\x00\x00\x20':
            raise Refusal('The Project SSH public identity is invalid.')
        return {'name': self.name, 'guest': guest['name'], 'project_slug': slug, 'image': image,
                'pool': pool, 'subnet': str(subnet.network), 'address': address,
                'ssh_key': 'ssh-ed25519 ' + base64.b64encode(raw).decode()}

    def guest_command(self, command):
        if (not isinstance(command, dict) or set(command) != {'role', 'argv', 'stdin', 'timeout', 'max_output'}
                or command['role'] not in ROLES or not isinstance(command['argv'], list)
                or not 1 <= len(command['argv']) <= 128
                or any(not isinstance(arg, str) or '\0' in arg or len(arg.encode()) > 65536 for arg in command['argv'])
                or not command['argv'][0] or not isinstance(command['stdin'], str)
                or type(command['timeout']) is not int or not 1 <= command['timeout'] <= 900
                or type(command['max_output']) is not int or not 1 <= command['max_output'] <= 8 * 1024 * 1024):
            raise Refusal('Invalid guest command.')
        incoming = base64.b64decode(command['stdin'], validate=True)
        if len(incoming) > 512 * 1024:
            raise Refusal('Guest input is too large.')
        name = self.name + '-' + command['role']
        guest = next((row for row in self.instances() if row['name'] == name), None)
        if guest is None or guest['status'] != 'Running':
            raise Refusal('The owned sandbox guest is not running.')
        result = bounded_process(
            ['incus', '--force-local', '--project', self.project, 'exec', name, '--mode=non-interactive',
             '--', '/usr/bin/sudo', '-H', '-u', 'orbit', '--', '/usr/bin/python3', '-I', '-c', GUEST_RUNNER],
            json.dumps(command).encode(), command['timeout'] + 15, 12 * 1024 * 1024)
        if result['exit_code'] or result['truncated']:
            raise Refusal('The guest command transport failed.')
        value = json.loads(base64.b64decode(result['stdout'], validate=True))
        if (not isinstance(value, dict) or set(value) != {'exit_code', 'stdout', 'stderr', 'duration_ms', 'truncated', 'timed_out'}
                or type(value['exit_code']) is not int or not -255 <= value['exit_code'] <= 255
                or type(value['duration_ms']) is not int or value['duration_ms'] < 0
                or type(value['truncated']) is not bool or type(value['timed_out']) is not bool
                or not isinstance(value['stdout'], str) or not isinstance(value['stderr'], str)
                or sum(len(base64.b64decode(value[key], validate=True)) for key in ('stdout', 'stderr')) > command['max_output']):
            raise Refusal('The guest command returned invalid output.')
        return {'name': self.name, 'role': command['role'], **value}

    def source_template(self, spec, pool):
        template = spec.get('source_template')
        if template is None:
            return None
        if not isinstance(template, dict) or set(template) != {'id', 'repository', 'base', 'commit'}:
            raise Refusal('Invalid source template descriptor.')
        identity(template['id'])
        if (not isinstance(template['repository'], str)
                or not re.fullmatch(r'https://github\.com/[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+\.git', template['repository'])
                or not isinstance(template['commit'], str) or not re.fullmatch(r'[a-f0-9]{40}(?:[a-f0-9]{24})?', template['commit'])
                or not isinstance(template['base'], str) or not re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9._/-]{0,199}', template['base'])
                or any(part in template['base'] for part in ('..', '//', '@{'))
                or any(part.endswith('.lock') or part.startswith('.') for part in template['base'].split('/'))
                or template['base'] == 'HEAD' or template['base'].endswith(('/', '.'))):
            raise Refusal('Invalid source template identity.')
        expected = {'user.orbit.template.owner': 'orbit-task-template',
                    **{'user.orbit.template.' + key: value for key, value in template.items()}}
        name = 'ot-template-' + hashlib.sha256(template['id'].encode()).hexdigest()[:10]
        path = '/1.0/storage-pools/' + pool + '/volumes/custom/' + name
        volume = self.json('query', path + '?project=' + self.project)
        snapshot = self.json('query', path + '/snapshots/ready?project=' + self.project)
        for resource in (volume, snapshot):
            if (resource.get('content_type') != 'filesystem' or resource.get('used_by', [])
                    or any(resource.get('config', {}).get(key) != value for key, value in expected.items())):
                raise Refusal('Source template ownership or attachments do not match.')
        for role, fingerprint in spec['images'].items():
            image = self.json('query', '/1.0/images/' + fingerprint + '?project=' + self.project)
            properties = image.get('properties', {})
            if (image.get('type') != 'virtual-machine' or image.get('public') is not False
                    or properties.get('user.orbit.template.role') != role
                    or any(properties.get(key) != value for key, value in expected.items())):
                raise Refusal('The image does not belong to the source template.')
        digest = hashlib.sha256(json.dumps(template, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
        return {'name': name, 'digest': digest}

    def project_image(self, spec):
        if 'project_slug' not in spec:
            return None
        slug = spec['project_slug']
        if (not isinstance(slug, str) or not re.fullmatch(r'[a-z0-9][a-z0-9-]{0,62}', slug)
                or slug == 'orbit' or set(spec['images']) != {'operator'}
                or spec.get('source_template') is not None or spec.get('pi_host') is not None
                or spec.get('pi_port') is not None):
            raise Refusal('A Project sandbox needs one independent development image.')
        image = self.json('query', '/1.0/images/' + spec['images']['operator'] + '?project=' + self.project)
        properties = image.get('properties', {})
        required = {'user.orbit.project.owner': 'orbit-task-project-image',
                    'user.orbit.project.slug': slug, 'user.orbit.project.account': 'orbit',
                    'user.orbit.project.bootstrap': 'unenrolled'}
        if (image.get('type') != 'virtual-machine' or image.get('architecture') != 'x86_64'
                or image.get('public') is not False or not isinstance(properties, dict)
                or any(properties.get(key) != value for key, value in required.items())
                or any(key.startswith('user.orbit.template.') for key in properties)):
            raise Refusal('The Project development image provenance does not match.')
        return slug

    def provision(self, spec):
        images = spec.get('images')
        if not isinstance(images, dict) or not images or any(role not in ROLES for role in images):
            raise Refusal('Invalid sandbox images.')
        if 'operator' not in images:
            raise Refusal('The sandbox needs an operator VM.')
        if any(not isinstance(value, str) or not re.fullmatch(r'[a-f0-9]{64}', value) for value in images.values()):
            raise Refusal('Sandbox images must be pinned fingerprints.')
        project_slug = self.project_image(spec)
        pool = spec.get('pool')
        if not isinstance(pool, str) or not re.fullmatch(r'[a-zA-Z0-9][a-zA-Z0-9_-]{0,62}', pool):
            raise Refusal('Invalid sandbox storage pool.')
        subnet = ipaddress.ip_network(spec.get('subnet', ''), strict=True)
        if subnet.version != 4 or subnet.prefixlen != 24 or not subnet.subnet_of(ipaddress.ip_network('10.233.0.0/16')):
            raise Refusal('Invalid sandbox subnet.')
        blocked = spec.get('blocked_networks')
        if not isinstance(blocked, list) or not blocked or not all(isinstance(value, str) for value in blocked):
            raise Refusal('Host and LAN exclusions are required.')
        addresses = subprocess.run(['ip', '-json', '-4', 'address', 'show'], capture_output=True, check=True, timeout=10)
        interfaces = json.loads(addresses.stdout)
        bootstrap = project_bootstrap(spec, subnet, interfaces)
        proxy = pi_proxy(spec, subnet, interfaces)
        relay_origin = spec.get('model_proxy_origin')
        relay = ModelRelay(self.name, self.id)
        if relay_origin is not None:
            model_relay_config(str(subnet.network_address + 1), str(subnet.network_address + 10), relay_origin)
        elif relay.root.exists() or relay.root.is_symlink():
            raise Refusal('An existing model relay cannot be removed by reprovisioning.')
        host_networks = [str(ipaddress.ip_network(address['local'] + '/' + str(address['prefixlen']), strict=False))
                         for interface in interfaces for address in interface.get('addr_info', [])]
        public = public_networks([*blocked, *host_networks])
        current = {row['name']: row for row in self.instances()}
        for name, row in current.items():
            role = name[len(self.name) + 1:]
            if row.get('config', {}).get('user.orbit.compute.project_bootstrap') != bootstrap:
                raise Refusal('The Project bootstrap endpoints cannot change.')
            if row.get('config', {}).get('user.orbit.compute.project_slug') != project_slug:
                raise Refusal('The sandbox Project identity cannot change.')
            if row.get('config', {}).get('user.orbit.compute.model_proxy_origin') != relay_origin:
                raise Refusal('An existing model relay endpoint cannot change.')
            if role not in images or row.get('config', {}).get('volatile.base_image') != images[role]:
                raise Refusal('Existing sandbox images cannot be changed or removed.')
        missing = [role for role in images if self.name + '-' + role not in current]
        stopped = [row for row in current.values() if row.get('status') == 'Stopped']
        if any(row.get('status') not in ('Stopped', 'Running') for row in current.values()):
            raise Refusal('The sandbox has an incomplete power transition; observe it before retrying.')
        if len(missing) + len(stopped) > self.capacity()['available']:
            raise Refusal('The host VM budget is full.')
        for row in stopped:
            snapshots = self.json('query', '/1.0/instances/' + row['name'] + '/snapshots?project=' + self.project)
            if any(value.split('?', 1)[0].endswith('/parked') for value in snapshots):
                raise Refusal('A parked sandbox must be resumed, not provisioned again.')
        for row in current.values():
            role = row['name'][len(self.name) + 1:]
            devices = row.get('devices', {})
            nic = devices.get('eth0', {})
            root = devices.get('root', {})
            expected_devices = {'root', 'eth0', 'worktree'} | ({'pi'} if proxy and role == 'operator' else set())
            if (row.get('profiles') != [] or set(devices) != expected_devices
                    or (devices.get('pi') != (proxy if role == 'operator' else None))
                    or root != {'type': 'disk', 'path': '/', 'pool': pool, 'size': '20GiB'}
                    or devices.get('worktree') != {'type': 'disk', 'pool': pool, 'source': self.name + '-worktree', 'path': '/home/orbit/orbit'}
                    or nic != {'type': 'nic', 'network': self.name, 'name': 'eth0',
                               'ipv4.address': str(subnet.network_address + 10 + ROLES.index(role)),
                               'security.mac_filtering': 'true', 'security.ipv4_filtering': 'true'}):
                raise Refusal('Sandbox devices or profiles drifted; no mutation performed.')
        for fingerprint in images.values():
            if self.json('query', '/1.0/images/' + fingerprint + '?project=' + self.project).get('type') != 'virtual-machine':
                raise Refusal('The operator image must be a VM, never a container.')
        template = self.source_template(spec, pool)
        networks = self.json('network', 'list', '--format=json')
        for network in networks:
            if network['name'] == self.name:
                self.own(network)
                expected = {'ipv4.address': str(subnet.network_address + 1) + '/24',
                            'ipv4.nat': 'true', 'ipv6.address': 'none', 'dns.mode': 'none',
                            'security.acls': self.name, 'security.acls.default.egress.action': 'reject',
                            'security.acls.default.ingress.action': 'reject'}
                if network.get('type') != 'bridge' or any(network.get('config', {}).get(key) != value for key, value in expected.items()):
                    raise Refusal('Sandbox network policy drifted; no mutation performed.')
            else:
                address = network.get('config', {}).get('ipv4.address')
                if address not in (None, '', 'none', 'auto') and subnet.overlaps(ipaddress.ip_network(address, strict=False)):
                    raise Refusal('Sandbox subnet is already allocated.')
        volumes = self.volumes()
        if current and not volumes:
            raise Refusal('The sandbox worktree volume is missing; refusing to replace group data.')
        if volumes and volumes[0][0] != pool:
            raise Refusal('Sandbox worktree pool changed.')
        template_digest = template['digest'] if template else None
        if any(row.get('config', {}).get('user.orbit.compute.template') != template_digest for row in current.values()):
            raise Refusal('The sandbox image template cannot change.')
        if volumes and volumes[0][1].get('config', {}).get('user.orbit.compute.template') != template_digest:
            raise Refusal('The sandbox worktree template cannot change.')
        if volumes and volumes[0][1].get('config', {}).get('user.orbit.compute.project_slug') != project_slug:
            raise Refusal('The worktree Project identity cannot change.')
        if volumes and volumes[0][1].get('config', {}).get('user.orbit.compute.project_bootstrap') != bootstrap:
            raise Refusal('The Project worktree bootstrap endpoints cannot change.')
        acls = self.json('network', 'acl', 'list', '--format=json')
        acl = next((row for row in acls if row['name'] == self.name), None)
        peers = ','.join(str(subnet.network_address + 10 + ROLES.index(role)) for role in images)
        acl_data = {'description': 'Task sandbox egress boundary', 'config': self.metadata(),
                        'ingress': [{'action': 'allow', 'source': peers, 'state': 'enabled'}],
                        'egress': [
                            {'action': 'allow', 'destination': peers, 'state': 'enabled'},
                            {'action': 'allow', 'destination': public, 'protocol': 'tcp', 'destination_port': '80,443', 'state': 'enabled'},
                            {'action': 'allow', 'destination': '1.1.1.1,9.9.9.9', 'protocol': 'udp', 'destination_port': '53', 'state': 'enabled'},
                            {'action': 'allow', 'destination': '1.1.1.1,9.9.9.9', 'protocol': 'tcp', 'destination_port': '53', 'state': 'enabled'},
                        ]}
        if relay_origin is not None:
            acl_data['egress'].append({'action': 'allow', 'source': str(subnet.network_address + 10),
                                       'destination': str(subnet.network_address + 1), 'protocol': 'tcp',
                                       'destination_port': '8317', 'state': 'enabled'})
        if proxy:
            acl_data['ingress'].append({'action': 'allow', 'source': spec['gateway_address'],
                                        'destination': str(subnet.network_address + 10),
                                        'protocol': 'tcp', 'destination_port': '3774', 'state': 'enabled'})
        if acl is None:
            self.run('network', 'acl', 'create', self.name, data=json.dumps(acl_data).encode())
        else:
            self.own(acl)
            self.run('network', 'acl', 'edit', self.name, data=json.dumps(acl_data).encode())
        existing_network = next((row for row in networks if row['name'] == self.name), None)
        network_policy = existing_network.get('config', {}).get('user.orbit.compute.host_network') if existing_network else None
        if network_policy not in (None, '1'):
            raise Refusal('Unknown sandbox host network policy.')
        if existing_network is None:
            network_policy = '1' if self.host_network('enabled') else None
            self.run('network', 'create', self.name, '--type=bridge', 'ipv4.address=' + str(subnet.network_address + 1) + '/24',
                     'ipv4.nat=true', 'ipv6.address=none', 'dns.mode=none', 'security.acls=' + self.name,
                     'security.acls.default.egress.action=reject', 'security.acls.default.ingress.action=reject',
                     *[key + '=' + value for key, value in self.metadata().items()],
                     *(['user.orbit.compute.host_network=1'] if network_policy else []))
        if network_policy:
            self.host_network('ensure')
        metadata = {**self.metadata(), **({'user.orbit.compute.template': template_digest} if template else {})}
        if project_slug is not None:
            metadata['user.orbit.compute.project_slug'] = project_slug
        if bootstrap is not None:
            metadata['user.orbit.compute.project_bootstrap'] = bootstrap
        if not volumes:
            if template:
                self.run('query', '-X', 'POST', '/1.0/storage-pools/' + pool + '/volumes/custom?project=' + self.project,
                         '-d', json.dumps({'name': self.name + '-worktree', 'type': 'custom',
                                           'source': {'type': 'copy', 'name': template['name'] + '/ready', 'pool': pool, 'project': self.project},
                                           'config': {**metadata, 'size': '20GiB'}}), '--wait', timeout=300)
                copied = self.volumes()
                if (len(copied) != 1 or copied[0][0] != pool
                        or copied[0][1].get('config', {}).get('user.orbit.compute.template') != template_digest):
                    raise Refusal('Source copy ownership could not be confirmed.')
            else:
                self.run('storage', 'volume', 'create', pool, self.name + '-worktree', 'size=20GiB',
                         *[key + '=' + value for key, value in metadata.items()])
        for role in missing:
            name = self.name + '-' + role
            config = {**metadata, 'limits.cpu': '2', 'limits.memory': '4GiB'}
            if relay_origin is not None:
                config['user.orbit.compute.model_proxy_origin'] = relay_origin
            address = str(subnet.network_address + 10 + ROLES.index(role))
            payload = {'name': name, 'type': 'virtual-machine', 'profiles': [], 'config': config,
                       'source': {'type': 'image', 'fingerprint': images[role]},
                       'devices': {'worktree': {'type': 'disk', 'pool': pool, 'source': self.name + '-worktree', 'path': '/home/orbit/orbit'},
                                   'root': {'type': 'disk', 'path': '/', 'pool': pool, 'size': '20GiB'},
                                   'eth0': {'type': 'nic', 'network': self.name, 'name': 'eth0', 'ipv4.address': address,
                                            'security.mac_filtering': 'true', 'security.ipv4_filtering': 'true'}}}
            if proxy and role == 'operator':
                payload['devices']['pi'] = proxy
            self.run('query', '-X', 'POST', '/1.0/instances?project=' + self.project,
                     '-d', json.dumps(payload), '--wait', timeout=300)
            self.run('start', name, timeout=180)
        for row in stopped:
            self.run('start', row['name'], timeout=180)
        if relay_origin is not None:
            relay.prepare(subnet, relay_origin)
        return self.observe()

    def park(self):
        rows = self.instances()
        volumes = self.volumes()
        for row in rows:
            if row['status'] != 'Stopped':
                self.run('stop', row['name'], '--timeout=60', timeout=90)
        for pool, volume in volumes:
            self.run('storage', 'volume', 'snapshot', 'create', pool, volume['name'], 'parked', '--reuse')
        for row in rows:
            snapshots = self.json('query', '/1.0/instances/' + row['name'] + '/snapshots?project=' + self.project)
            if any(value.split('?', 1)[0].endswith('/parked') for value in snapshots):
                self.run('snapshot', 'delete', row['name'], 'parked')
            self.run('snapshot', 'create', row['name'], 'parked')
        return self.observe()

    def resume(self):
        rows = self.instances()
        volumes = self.volumes()
        self.ensure_host_network()
        origins = {row.get('config', {}).get('user.orbit.compute.model_proxy_origin') for row in rows}
        if len(origins) > 1:
            raise Refusal('Sandbox model relay identities disagree.')
        if origins and None not in origins:
            relay = ModelRelay(self.name, self.id)
            marker = json.loads(relay.read(relay.root / 'owner.json'))
            if marker.get('sandbox_id') != self.id or marker.get('origin') not in origins:
                raise Refusal('The model relay reservation is unavailable.')
            relay.prepare(ipaddress.ip_network(marker['subnet'], strict=True), marker['origin'])
        stopped = [row for row in rows if row['status'] == 'Stopped']
        if len(stopped) > self.capacity()['available']:
            raise Refusal('The host VM budget is full.')
        if stopped and len(stopped) != len(rows):
            raise Refusal('Stop every sandbox VM before restoring a shared worktree snapshot.')
        if stopped:
            for pool, volume in volumes:
                self.run('storage', 'volume', 'snapshot', 'restore', pool, volume['name'], 'parked')
        for row in stopped:
            self.run('snapshot', 'restore', row['name'], 'parked')
        for row in stopped:
            self.run('start', row['name'])
        return self.observe()

    def destroy(self):
        rows = self.instances()
        volumes = self.volumes()
        networks = self.json('network', 'list', '--format=json')
        acls = self.json('network', 'acl', 'list', '--format=json')
        network = next((row for row in networks if row['name'] == self.name), None)
        acl = next((row for row in acls if row['name'] == self.name), None)
        if network:
            self.own(network)
        if acl:
            self.own(acl)
        ModelRelay(self.name, self.id).destroy()
        for row in rows:
            self.run('delete', row['name'], '--force', timeout=180)
        for pool, volume in volumes:
            self.run('storage', 'volume', 'delete', pool, volume['name'])
        if network:
            marker = network.get('config', {}).get('user.orbit.compute.host_network')
            if marker not in (None, '1'):
                raise Refusal('Unknown sandbox host network policy.')
            if marker == '1':
                self.host_network('remove')
            self.run('network', 'delete', self.name)
        if acl:
            self.run('network', 'acl', 'delete', self.name)
        result = self.observe()
        if result['power'] != 'destroyed' or self.volumes():
            raise Refusal('Sandbox cleanup left instances behind.')
        return result


from contextlib import contextmanager


@contextmanager
def sandbox_lock(path, shared=False):
    descriptor = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(descriptor, 'r+') as lock:
        details = os.fstat(lock.fileno())
        if not stat.S_ISREG(details.st_mode) or details.st_uid != os.geteuid() or details.st_nlink != 1:
            raise Refusal('Unsafe sandbox lock.')
        fcntl.flock(lock, fcntl.LOCK_SH if shared else fcntl.LOCK_EX)
        yield


def main():
    payload = sys.stdin.buffer.read(1024 * 1024 + 1)
    if len(payload) > 1024 * 1024:
        raise Refusal('Sandbox request is too large.')
    request = json.loads(payload)
    host = Host(request['project'], request['sandbox_id'], request['budget'])
    operation = request['operation']
    # Lock this group before the global budget lock. A long guest command never
    # blocks another group's provisioning or holds host capacity serialization.
    with sandbox_lock('/run/lock/orbit-sandbox-' + host.name + '.lock', operation in ('guest_command', 'project_identity')):
        if operation == 'guest_command':
            result = host.guest_command(request['guest'])
        elif operation == 'project_identity':
            result = host.project_identity()
        else:
            with sandbox_lock('/run/lock/orbit-task-sandboxes.lock'):
                if operation == 'provision':
                    result = host.provision(request['spec'])
                elif operation in ('observe', 'capacity', 'park', 'resume', 'destroy'):
                    result = getattr(host, operation)()
                else:
                    raise Refusal('Unknown sandbox operation.')
    print(json.dumps(result))


if __name__ == '__main__':
    try:
        main()
    except Refusal as error:
        print(json.dumps({'error': 'sandbox_operation_refused', 'message': str(error)}))
        sys.exit(1)
    except (ValueError, KeyError, TypeError, OSError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_operation_refused'}))
        sys.exit(1)
