#!/usr/bin/python3 -I
"""Fixed privileged firewall boundary for opted-in task-owned Incus bridges."""
import fcntl
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import re
import shlex
import stat
import subprocess
import sys
import tempfile
import time
import uuid

CONFIG = Path('/etc/orbit/sandbox-network.json')
ROOT = Path('/var/lib/orbit-sandbox-network')
OWNER = 'orbit-task-sandbox'
UNIT = Path('/etc/systemd/system/orbit-sandbox-host-network.service')
DEPENDENCY = Path('/etc/systemd/system/incus.service.d/orbit-sandbox-network.conf')
UNIT_TEXT = ('[Unit]\nDescription=Restore Orbit sandbox host network boundaries\n'
             'Wants=network-online.target\nAfter=network-online.target local-fs.target nftables.service ufw.service firewalld.service\nBefore=incus.service\n\n'
             '[Service]\nType=oneshot\nExecStart=/usr/local/libexec/orbit-sandbox-network restore\n'
             'TimeoutStartSec=120\nRemainAfterExit=yes\nUMask=0077\n\n[Install]\nWantedBy=multi-user.target\n')
DEPENDENCY_TEXT = '[Unit]\nRequires=orbit-sandbox-host-network.service\nAfter=orbit-sandbox-host-network.service\n'
PRIVATE = ('0.0.0.0/8', '10.0.0.0/8', '100.64.0.0/10', '127.0.0.0/8',
           '169.254.0.0/16', '172.16.0.0/12', '192.0.0.0/24', '192.0.2.0/24',
           '192.168.0.0/16', '198.18.0.0/15', '198.51.100.0/24', '203.0.113.0/24',
           '224.0.0.0/4', '240.0.0.0/4')


def require(condition):
    if not condition:
        raise ValueError('Sandbox host network operation refused')


def run(arguments, data=None):
    result = subprocess.run(arguments, input=data, capture_output=True, text=True,
                            timeout=30, env={'PATH': '/usr/sbin:/usr/bin:/sbin:/bin', 'LANG': 'C'})
    require(result.returncode == 0 and len(result.stdout) <= 8 * 1024 * 1024)
    return result.stdout


def directory(path, create=False):
    if create and not path.exists():
        path.mkdir(mode=0o700)
    for parent in [path, *path.parents]:
        info = parent.lstat()
        require(stat.S_ISDIR(info.st_mode) and info.st_uid == 0 and not info.st_mode & 0o022)


def read(path, mode=0o600):
    directory(path.parent)
    fd = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_CLOEXEC)
    try:
        info = os.fstat(fd)
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and stat.S_IMODE(info.st_mode) == mode
                and info.st_nlink == 1 and info.st_size <= 131072)
        with os.fdopen(fd, 'rb', closefd=False) as source:
            return source.read()
    finally:
        os.close(fd)


def installation():
    require(read(UNIT, 0o644) == UNIT_TEXT.encode() and read(DEPENDENCY, 0o644) == DEPENDENCY_TEXT.encode())
    for root in ('/etc/systemd/system', '/run/systemd/system', '/etc/systemd/system.control', '/run/systemd/system.control'):
        require(not (Path(root) / (UNIT.name + '.d')).exists())
    require(not (Path('/run/systemd/system') / UNIT.name).exists())
    run(['/usr/bin/systemctl', 'is-enabled', '--quiet', UNIT.name])
    ordering = run(['/usr/bin/systemctl', 'show', 'incus.service', '--property=Requires,After', '--value']).splitlines()
    require(len(ordering) == 2 and all(UNIT.name in line.split() for line in ordering))


def put(path, data):
    if path.exists() or path.is_symlink():
        require(read(path) == data)
        return
    # The root-only directory and the global lock prevent a concurrent replacement.
    with tempfile.NamedTemporaryFile(dir=path.parent, prefix='.policy-', delete=False) as target:
        temporary = Path(target.name)
        try:
            target.write(data)
            target.flush()
            os.fsync(target.fileno())
            os.replace(temporary, path)
            sync_directory(path.parent)
        finally:
            temporary.unlink(missing_ok=True)


def sync_directory(path):
    fd = os.open(path, os.O_RDONLY | os.O_DIRECTORY)
    try:
        os.fsync(fd)
    finally:
        os.close(fd)


def validate(request):
    require(isinstance(request, dict) and set(request) == {'operation', 'project', 'sandbox_id'})
    require(request['operation'] in ('enabled', 'project_enabled', 'verify', 'ensure', 'remove'))
    require(isinstance(request['project'], str)
            and re.fullmatch(r'orbit-(?:task-sandboxes|sandbox-proof-[a-z0-9]+)', request['project']))
    require(isinstance(request['sandbox_id'], str) and str(uuid.UUID(request['sandbox_id'])) == request['sandbox_id'])
    return request


def configuration():
    value = json.loads(read(CONFIG, 0o644))
    require(isinstance(value, dict) and set(value) - {'project_bootstrap'} == {'version', 'projects', 'pi_host', 'gateway_address', 'wireguard_interface', 'blocked_networks'})
    require(type(value['version']) is int and value['version'] == 1)
    require(isinstance(value['projects'], list) and 1 <= len(value['projects']) <= 32
            and len(set(value['projects'])) == len(value['projects']))
    for project in value['projects']:
        validate({'operation': 'enabled', 'project': project, 'sandbox_id': '00000000-0000-4000-8000-000000000000'})
    fleet = ipaddress.ip_network('10.44.0.0/16')
    for key in ('pi_host', 'gateway_address'):
        address = ipaddress.ip_address(value[key])
        require(address in fleet and str(address) == value[key])
    require(value['pi_host'] != value['gateway_address'])
    require(isinstance(value['wireguard_interface'], str)
            and re.fullmatch(r'[A-Za-z][A-Za-z0-9_-]{0,14}', value['wireguard_interface']))
    require(isinstance(value['blocked_networks'], list) and 1 <= len(value['blocked_networks']) <= 128)
    for cidr in value['blocked_networks']:
        require(isinstance(cidr, str) and ipaddress.ip_network(cidr, strict=True).version == 4)
    if 'project_bootstrap' in value:
        bootstrap = value['project_bootstrap']
        require(isinstance(bootstrap, dict) and set(bootstrap) == {'projects', 'wireguard_address', 'wireguard_port'})
        projects = bootstrap['projects']
        require(isinstance(projects, list) and 1 <= len(projects) <= 32
                and all(isinstance(project, str) and project in value['projects'] for project in projects)
                and len(set(projects)) == len(projects))
        require(isinstance(bootstrap['wireguard_address'], str))
        hub = ipaddress.ip_address(bootstrap['wireguard_address'])
        require(hub.version == 4 and hub.is_global and not hub.is_multicast and str(hub) == bootstrap['wireguard_address'])
        require(type(bootstrap['wireguard_port']) is int and 1 <= bootstrap['wireguard_port'] <= 65535)
    return value


def policy_configuration(config, bootstrap=None):
    # A new Project opt-in must not change an existing isolated-pair policy.
    value = {key: item for key, item in config.items() if key != 'project_bootstrap'}
    if bootstrap is not None:
        require(isinstance(config.get('project_bootstrap'), dict))
        value['project_bootstrap'] = config['project_bootstrap']
    return value


def project_bootstrap(settings, subnet, config, project):
    marker = settings.get('user.orbit.compute.project_bootstrap')
    if marker is None:
        return None
    approved = config.get('project_bootstrap', {})
    require(project in approved.get('projects', []))
    slug = settings.get('user.orbit.compute.project_slug')
    require(isinstance(slug, str) and slug != 'orbit' and re.fullmatch(r'[a-z0-9]+(?:-[a-z0-9]+)*', slug))
    require(isinstance(marker, str))
    descriptor = json.loads(marker)
    expected = {'ssh_host': config['pi_host'], 'ssh_port': 24000 + int(str(subnet.network_address).split('.')[2]),
                'gateway_address': config['gateway_address'], 'wireguard_address': approved['wireguard_address'],
                'wireguard_port': approved['wireguard_port']}
    require(descriptor == expected and type(descriptor.get('ssh_port')) is int
            and type(descriptor.get('wireguard_port')) is int and 24001 <= descriptor['ssh_port'] <= 24254
            and marker == json.dumps(expected, sort_keys=True, separators=(',', ':')))
    return descriptor


def name(identity):
    return 'ot-' + hashlib.sha256(identity.encode()).hexdigest()[:10]


def incus(path):
    return json.loads(run(['/usr/bin/incus', '--force-local', 'query', path]))


def wireguard(config, interfaces):
    owners = [interface for interface in interfaces if any(
        address.get('local') == config['pi_host'] for address in interface.get('addr_info', []))]
    require(len(owners) == 1 and owners[0].get('ifname') == config['wireguard_interface'])
    links = json.loads(run(['/usr/sbin/ip', '-json', '-details', 'link', 'show', 'dev', config['wireguard_interface']]))
    require(isinstance(links, list) and len(links) == 1
            and links[0].get('ifname') == config['wireguard_interface']
            and links[0].get('linkinfo', {}).get('info_kind') == 'wireguard')


def attest_project(project, identity, bridge, subnet, settings, bootstrap):
    guest_name = bridge + '-operator'
    guest = incus('/1.0/instances/' + guest_name + '?project=' + project)
    config = guest.get('config', {})
    metadata = {'user.orbit.compute.owner': OWNER, 'user.orbit.compute.id': identity,
                'user.orbit.compute.project_slug': settings['user.orbit.compute.project_slug'],
                'user.orbit.compute.project_bootstrap': settings['user.orbit.compute.project_bootstrap']}
    require(guest.get('name') == guest_name and guest.get('type') == 'virtual-machine'
            and guest.get('profiles') == [] and all(config.get(key) == value for key, value in metadata.items())
            and config.get('user.orbit.compute.template') is None)
    image_id = config.get('volatile.base_image')
    require(isinstance(image_id, str) and re.fullmatch(r'[a-f0-9]{64}', image_id))
    image = incus('/1.0/images/' + image_id + '?project=' + project)
    properties = image.get('properties', {})
    require(image.get('type') == 'virtual-machine' and image.get('architecture') == 'x86_64' and image.get('public') is False
            and properties.get('user.orbit.project.owner') == 'orbit-task-project-image'
            and properties.get('user.orbit.project.slug') == metadata['user.orbit.compute.project_slug']
            and properties.get('user.orbit.project.account') == 'orbit'
            and properties.get('user.orbit.project.bootstrap') == 'unenrolled')
    devices = guest.get('devices', {})
    pool = devices.get('root', {}).get('pool')
    require(isinstance(pool, str) and re.fullmatch(r'[A-Za-z0-9][A-Za-z0-9_.-]{0,62}', pool))
    require(devices == {
        'root': {'type': 'disk', 'path': '/', 'pool': pool, 'size': '20GiB'},
        'worktree': {'type': 'disk', 'pool': pool, 'source': bridge + '-worktree', 'path': '/home/orbit/orbit'},
        'eth0': {'type': 'nic', 'network': bridge, 'name': 'eth0', 'ipv4.address': str(subnet.network_address + 10),
                 'security.mac_filtering': 'true', 'security.ipv4_filtering': 'true'},
        'ssh': {'type': 'proxy', 'bind': 'host', 'nat': 'true',
                'listen': 'tcp:' + bootstrap['ssh_host'] + ':' + str(bootstrap['ssh_port']),
                'connect': 'tcp:' + str(subnet.network_address + 10) + ':22'}})
    volume = incus('/1.0/storage-pools/' + pool + '/volumes/custom/' + bridge + '-worktree?project=' + project)
    require(volume.get('content_type') == 'filesystem' and volume.get('type') == 'custom'
            and all(volume.get('config', {}).get(key) == value for key, value in metadata.items())
            and volume.get('config', {}).get('user.orbit.compute.template') is None
            and all(value == '/1.0/instances/' + guest_name + '?project=' + project for value in volume.get('used_by', [])))


def derive(request, config):
    project, identity = request['project'], request['sandbox_id']
    require(project in config['projects'])
    info = incus('/1.0/projects/' + project).get('config', {})
    require(info.get('user.orbit.compute.owner') == OWNER and info.get('features.networks') == 'false')
    bridge = name(identity)
    network = incus('/1.0/networks/' + bridge)
    settings = network.get('config', {})
    metadata = {'user.orbit.compute.owner': OWNER, 'user.orbit.compute.id': identity}
    subnet = ipaddress.ip_network(settings.get('ipv4.address', ''), strict=False)
    require(subnet.version == 4 and subnet.prefixlen == 24 and subnet.subnet_of(ipaddress.ip_network('10.233.0.0/16')))
    expected = {**metadata, 'user.orbit.compute.host_network': '1',
                'ipv4.address': str(subnet.network_address + 1) + '/24', 'ipv4.nat': 'true',
                'ipv6.address': 'none', 'dns.mode': 'none', 'security.acls': bridge,
                'security.acls.default.egress.action': 'reject', 'security.acls.default.ingress.action': 'reject'}
    require(network.get('type') == 'bridge' and network.get('managed') is True
            and all(settings.get(key) == value for key, value in expected.items()))
    acl = incus('/1.0/network-acls/' + bridge).get('config', {})
    require(all(acl.get(key) == value for key, value in metadata.items()))
    interfaces = json.loads(run(['/usr/sbin/ip', '-json', '-4', 'address', 'show']))
    addresses = [address for interface in interfaces for address in interface.get('addr_info', [])]
    wireguard(config, interfaces)
    # Exclude all host-connected networks, including public addresses on the host.
    blocked = sorted(set(config['blocked_networks'] + [str(ipaddress.ip_network(
        address['local'] + '/' + str(address['prefixlen']), strict=False)) for address in addresses]))
    bootstrap = project_bootstrap(settings, subnet, config, project)
    extra = {}
    if bootstrap is not None:
        require(not any(ipaddress.ip_address(bootstrap['wireguard_address']) in ipaddress.ip_network(cidr) for cidr in blocked))
        guest = '/1.0/instances/' + bridge + '-operator?project=' + project
        require(all(value == guest for value in network.get('used_by', [])))
        if network.get('used_by'):
            attest_project(project, identity, bridge, subnet, settings, bootstrap)
        extra = {'project_bootstrap': bootstrap, 'project_slug': settings['user.orbit.compute.project_slug']}
    return {'version': 1, 'project': project, 'sandbox_id': identity, 'subnet': str(subnet),
            'gateway_address': config['gateway_address'], 'wireguard_interface': config['wireguard_interface'],
            'blocked_networks': blocked, 'config': policy_configuration(config, bootstrap), **extra}


def chains(spec, ipv6=False):
    suffix = name(spec['sandbox_id'])[3:]
    names = {hook: 'OT' + hook[0] + '-' + suffix for hook in ('INPUT', 'OUTPUT', 'FORWARD')}
    bridge = name(spec['sandbox_id'])
    rules = {chain: [] for chain in names.values()}
    if not ipv6:
        subnet = ipaddress.ip_network(spec['subnet'], strict=True)
        host, operator = str(subnet.network_address + 1), str(subnet.network_address + 10)
        bootstrap = spec.get('project_bootstrap')
        peers = [str(subnet.network_address + offset) for offset in (range(10, 11) if bootstrap else range(10, 15))]
        gateway = spec['gateway_address']
        interface = spec['wireguard_interface']
        forward = rules[names['FORWARD']]
        # Same-group peers stay within their bridge. NIC filters bind each guest address.
        forward += [f'-i {bridge} -o {bridge} -m iprange --src-range {peers[0]}-{peers[-1]} '
                    f'--dst-range {peers[0]}-{peers[-1]} -j ACCEPT']
        if bootstrap:
            origin = f'--ctorigdst {bootstrap["ssh_host"]}/32 --ctorigdstport {bootstrap["ssh_port"]}'
            forward += [f'-i {interface} -o {bridge} -s {gateway}/32 -d {operator}/32 -p tcp -m tcp --dport 22 '
                        f'-m conntrack --ctstate NEW,ESTABLISHED --ctdir ORIGINAL --ctproto tcp {origin} -j ACCEPT',
                        f'-i {bridge} -o {interface} -s {operator}/32 -d {gateway}/32 -p tcp -m tcp --sport 22 '
                        f'-m conntrack --ctstate ESTABLISHED --ctdir REPLY --ctproto tcp {origin} -j ACCEPT',
                        f'-i {bridge} -s {operator}/32 -d {bootstrap["wireguard_address"]}/32 -p udp -m udp '
                        f'--dport {bootstrap["wireguard_port"]} -m conntrack --ctstate NEW,ESTABLISHED --ctdir ORIGINAL -j ACCEPT',
                        f'-o {bridge} -d {operator}/32 -s {bootstrap["wireguard_address"]}/32 -p udp -m udp '
                        f'--sport {bootstrap["wireguard_port"]} -m conntrack --ctstate ESTABLISHED --ctdir REPLY '
                        f'--ctproto udp --ctorigsrc {operator}/32 --ctorigdst {bootstrap["wireguard_address"]}/32 '
                        f'--ctorigdstport {bootstrap["wireguard_port"]} -j ACCEPT']
        else:
            forward += [f'-i {interface} -o {bridge} -s {gateway}/32 -d {operator}/32 -p tcp -m tcp --dport 3774 '
                        '-m conntrack --ctstate NEW,ESTABLISHED -j ACCEPT',
                        f'-i {bridge} -o {interface} -s {operator}/32 -d {gateway}/32 -p tcp -m tcp --sport 3774 '
                        '-m conntrack --ctstate ESTABLISHED --ctdir REPLY -j ACCEPT']
        for cidr in sorted(set((*PRIVATE, *spec['blocked_networks']))):
            forward += [f'-i {bridge} -d {cidr} -j DROP', f'-o {bridge} -s {cidr} -j DROP']
        # Original-direction egress remains restricted even for established connections.
        for peer in peers:
            for dns in ('1.1.1.1', '9.9.9.9'):
                for protocol in ('udp', 'tcp'):
                    forward += [f'-i {bridge} -s {peer}/32 -d {dns}/32 -p {protocol} -m {protocol} --dport 53 -j ACCEPT']
            forward += [f'-i {bridge} -s {peer}/32 -p tcp -m tcp -m multiport --dports 80,443 -j ACCEPT']
            for port in (80, 443):
                forward += [f'-o {bridge} -d {peer}/32 -m conntrack --ctstate ESTABLISHED --ctdir REPLY '
                            f'--ctproto tcp --ctorigdstport {port} -j ACCEPT']
            for dns in ('1.1.1.1', '9.9.9.9'):
                for protocol in ('udp', 'tcp'):
                    forward += [f'-o {bridge} -d {peer}/32 -m conntrack --ctstate ESTABLISHED --ctdir REPLY '
                                f'--ctproto {protocol} --ctorigdst {dns}/32 --ctorigdstport 53 -j ACCEPT']
        rules[names['INPUT']] += [f'-i {bridge} -s {operator}/32 -d {host}/32 -p tcp -m tcp --dport 8317 -j ACCEPT',
                                 f'-i {bridge} -p udp -m udp --sport 68 --dport 67 -j ACCEPT']
        rules[names['OUTPUT']] += [f'-o {bridge} -s {host}/32 -d {operator}/32 -p tcp -m tcp --sport 8317 '
                                  '-m conntrack --ctstate ESTABLISHED --ctdir REPLY -j ACCEPT',
                                  f'-o {bridge} -p udp -m udp --sport 67 --dport 68 -j ACCEPT']
    for chain in rules:
        rules[chain].append('-j DROP')
    jumps = {hook: [f'-i {bridge} -j {chain}'] if hook == 'INPUT' else [f'-o {bridge} -j {chain}']
             if hook == 'OUTPUT' else [f'-i {bridge} -j {chain}', f'-o {bridge} -j {chain}']
             for hook, chain in names.items()}
    return rules, jumps


def render(spec, ipv6=False, remove=False):
    rules, jumps = chains(spec, ipv6)
    rows = ['*filter']
    if remove:
        rows += [f'-D {hook} {rule}' for hook, values in jumps.items() for rule in values]
        rows += [f'-F {chain}' for chain in rules]
        rows += [f'-X {chain}' for chain in rules]
    else:
        rows += [f':{chain} - [0:0]' for chain in rules]
        rows += [f'-A {chain} {rule}' for chain, values in rules.items() for rule in values]
        # Each hook insertion is one atomic restore transaction, ahead of existing rules.
        rows += [f'-I {hook} 1 {rule}' for hook, values in jumps.items() for rule in reversed(values)]
    return '\n'.join(rows + ['COMMIT', ''])


def snapshot(ipv6=False):
    program = '/usr/sbin/ip6tables-save' if ipv6 else '/usr/sbin/iptables-save'
    rows = run([program, '-t', 'filter'])
    result = {}
    for row in rows.splitlines():
        if row.startswith(':'):
            result[row.split()[0][1:]] = []
        elif row.startswith('-A '):
            tokens = shlex.split(row)
            result[tokens[1]].append(tokens[2:])
    return result


def desired(spec, ipv6=False):
    # Ask the installed netfilter tools to normalize syntax in a fresh namespace.
    script = """import subprocess,sys
v=sys.argv[1]; text=sys.stdin.read()
r=subprocess.run(['/usr/sbin/'+v+'-restore','--noflush'], input=text,text=True,capture_output=True)
if r.returncode: sys.exit(1)
r=subprocess.run(['/usr/sbin/'+v+'-save','-t','filter'],text=True,capture_output=True)
if r.returncode: sys.exit(1)
print(r.stdout)
"""
    program = 'ip6tables' if ipv6 else 'iptables'
    output = run(['/usr/bin/unshare', '--net', '/usr/bin/python3', '-I', '-c', script, program], render(spec, ipv6))
    result = {}
    for row in output.splitlines():
        if row.startswith(':'):
            result[row.split()[0][1:]] = []
        elif row.startswith('-A '):
            tokens = shlex.split(row)
            result[tokens[1]].append(tokens[2:])
    return result


def state(spec, expected, current):
    owned, jumps = chains(spec)
    marker = set(owned)
    present = marker.intersection(current)
    references = {hook: [rule for rule in rows if any(token in marker for token in rule)]
                  for hook, rows in current.items() if hook not in marker}
    if not present and not any(references.values()):
        return 'absent'
    require(present == marker)
    for chain in marker:
        require(current[chain] == expected[chain])
    for hook in references:
        require(references[hook] == expected.get(hook, []) if hook in jumps else not references[hook])
    for hook, values in jumps.items():
        # Other sandboxes may precede this one. Foreign rules must never precede it.
        prefix = []
        for rule in current.get(hook, []):
            target = rule[-1] if len(rule) >= 2 and rule[-2] == '-j' else ''
            if not re.fullmatch(r'OT[IFO]-[a-f0-9]{10}', target):
                break
            prefix.append(rule)
        for rule in prefix:
            suffix = rule[-1].split('-', 1)[1]
            require(rule in [flag + ['ot-' + suffix, '-j', 'OT' + hook[0] + '-' + suffix]
                             for flag in ([['-i']] if hook == 'INPUT' else [['-o']] if hook == 'OUTPUT' else [['-i'], ['-o']])])
        require(all(rule in prefix for rule in expected[hook]))
    return 'present'


def change(spec, remove=False):
    # Validate both families before changing either. A partially completed install is retryable.
    families = [True, False] if not remove else [False, True]
    plans = []
    for ipv6 in families:
        plans.append((ipv6, state(spec, desired(spec, ipv6), snapshot(ipv6))))
    for ipv6, status in plans:
        if (remove and status == 'present') or (not remove and status == 'absent'):
            program = '/usr/sbin/ip6tables-restore' if ipv6 else '/usr/sbin/iptables-restore'
            run([program, '--wait', '10', '--noflush'], render(spec, ipv6, remove))
    for ipv6 in families:
        require(state(spec, desired(spec, ipv6), snapshot(ipv6)) == ('absent' if remove else 'present'))


def manifest_path(identity):
    require(str(uuid.UUID(identity)) == identity)
    return ROOT / (identity + '.json')


def apply(request, config):
    if request['operation'] in ('enabled', 'project_enabled'):
        projects = config['projects'] if request['operation'] == 'enabled' else config.get('project_bootstrap', {}).get('projects', [])
        return {'enabled': request['project'] in projects}
    require(request['project'] in config['projects'])
    path = manifest_path(request['sandbox_id'])
    exists = path.exists() or path.is_symlink()
    if request['operation'] == 'remove':
        if not exists:
            # No recorded policy is safe only when neither family owns this identity.
            spec = {'sandbox_id': request['sandbox_id']}
            for ipv6 in (False, True):
                current = snapshot(ipv6)
                owned = {'OT' + hook + '-' + name(spec['sandbox_id'])[3:] for hook in 'IFO'}
                require(not owned.intersection(current) and not any(
                    token in owned for rules in current.values() for rule in rules for token in rule))
            return {'removed': True}
        spec = json.loads(read(path))
        require(spec['project'] == request['project'] and spec['sandbox_id'] == request['sandbox_id']
                and spec['config'] == policy_configuration(config, spec.get('project_bootstrap')))
        # Compute calls removal only after deleting owned guests. Independently refuse live attachments.
        network = incus('/1.0/networks/' + name(request['sandbox_id']))
        settings = network.get('config', {})
        require(settings.get('user.orbit.compute.owner') == OWNER
                and settings.get('user.orbit.compute.id') == request['sandbox_id']
                and settings.get('user.orbit.compute.host_network') == '1'
                and network.get('type') == 'bridge' and network.get('managed') is True
                and str(ipaddress.ip_network(settings.get('ipv4.address', ''), strict=False)) == spec['subnet']
                and not network.get('used_by'))
        require(project_bootstrap(settings, ipaddress.ip_network(spec['subnet']), config, request['project']) == spec.get('project_bootstrap'))
        if spec.get('project_bootstrap') is not None:
            require(settings.get('user.orbit.compute.project_slug') == spec.get('project_slug'))
        change(spec, remove=True)
        path.unlink()
        sync_directory(ROOT)
        return {'removed': True}
    spec = derive(request, config)
    if exists:
        # Other sandbox bridges can be added since this policy was saved. Only newly
        # connected public networks matter; private ranges are already excluded.
        old = json.loads(read(path))
        require(old['project'] == spec['project'] and old['sandbox_id'] == spec['sandbox_id']
                and old['subnet'] == spec['subnet'] and old['config'] == spec['config']
                and old.get('project_bootstrap') == spec.get('project_bootstrap')
                and old.get('project_slug') == spec.get('project_slug'))
        for value in spec['blocked_networks']:
            network = ipaddress.ip_network(value)
            require(any(network.subnet_of(ipaddress.ip_network(cidr)) for cidr in (*PRIVATE, *old['blocked_networks'])))
        spec = old
    elif request['operation'] == 'verify':
        require(False)
    else:
        # Refuse adoption even when an unrecorded chain happens to match our policy.
        for ipv6 in (False, True):
            require(state(spec, desired(spec, ipv6), snapshot(ipv6)) == 'absent')
        put(path, json.dumps(spec, sort_keys=True).encode())
    if request['operation'] == 'verify':
        for ipv6 in (False, True):
            require(state(spec, desired(spec, ipv6), snapshot(ipv6)) == 'present')
        return {'ready': True}
    change(spec)
    return {'ready': True}


def boot_interfaces(config):
    deadline = time.monotonic() + 60
    while True:
        interfaces = json.loads(run(['/usr/sbin/ip', '-json', '-4', 'address', 'show']))
        if any(address.get('local') == config['pi_host']
               for interface in interfaces for address in interface.get('addr_info', [])):
            return interfaces
        require(time.monotonic() < deadline)
        time.sleep(0.1)


def check_host_exclusions(spec):
    interfaces = boot_interfaces(spec['config'])
    wireguard(spec['config'], interfaces)
    for interface in interfaces:
        for address in interface.get('addr_info', []):
            network = ipaddress.ip_network(address['local'] + '/' + str(address['prefixlen']), strict=False)
            require(any(network.subnet_of(ipaddress.ip_network(cidr)) for cidr in (*PRIVATE, *spec['blocked_networks'])))


def restore(config):
    for path in sorted(ROOT.glob('*.json')):
        spec = json.loads(read(path))
        require(manifest_path(spec['sandbox_id']) == path and spec['config'] == policy_configuration(config, spec.get('project_bootstrap'))
                and spec['project'] in config['projects'] and spec['version'] == 1)
        check_host_exclusions(spec)
        change(spec)


def acquire(fd):
    deadline = time.monotonic() + 60
    while True:
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return
        except BlockingIOError:
            require(time.monotonic() < deadline)
            time.sleep(0.1)


def main():
    require(os.geteuid() == 0)
    config = configuration()
    installation()
    directory(ROOT, create=True)
    fd = os.open(ROOT / 'lock', os.O_RDWR | os.O_CREAT | os.O_NOFOLLOW | os.O_CLOEXEC, 0o600)
    with os.fdopen(fd, 'r+') as lock:
        info = os.fstat(lock.fileno())
        require(stat.S_ISREG(info.st_mode) and info.st_uid == 0 and info.st_nlink == 1 and stat.S_IMODE(info.st_mode) == 0o600)
        acquire(lock.fileno())
        if sys.argv[1:] == ['restore']:
            restore(config)
            return
        require(len(sys.argv) == 1)
        incoming = sys.stdin.buffer.read(4097)
        require(len(incoming) <= 4096)
        print(json.dumps(apply(validate(json.loads(incoming)), config)))


if __name__ == '__main__':
    try:
        main()
    except (ValueError, TypeError, KeyError, OSError, subprocess.SubprocessError):
        print(json.dumps({'error': 'sandbox_host_network_refused'}))
        sys.exit(1)
