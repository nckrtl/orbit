"""Narrow privileged protocol and real packets in disposable Linux namespaces."""
import copy
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

source = Path(sys.argv.pop(1))
PACKETS = '--packets' in sys.argv
if PACKETS:
    sys.argv.remove('--packets')
loader = importlib.util.spec_from_file_location('host_network', source)
policy = importlib.util.module_from_spec(loader)
loader.loader.exec_module(policy)
ID = '0bd99b9a-37f1-4872-88b6-4d9b1bee7cce'
PROJECT = 'orbit-sandbox-proof-318a36c8'
CONFIG = {'version': 1, 'projects': [PROJECT], 'pi_host': '10.44.0.7',
          'gateway_address': '10.44.0.1', 'wireguard_interface': 'eh', 'blocked_networks': ['192.168.6.0/24']}
SPEC = {'version': 1, 'project': PROJECT, 'sandbox_id': ID, 'subnet': '10.233.209.0/24',
        'gateway_address': '10.44.0.1', 'wireguard_interface': 'eh', 'blocked_networks': ['192.168.6.0/24', '198.41.0.20/32'], 'config': CONFIG}
REQUEST = {'operation': 'ensure', 'project': PROJECT, 'sandbox_id': ID}
BOOTSTRAP = {'ssh_host': CONFIG['pi_host'], 'ssh_port': 24209, 'gateway_address': CONFIG['gateway_address'],
             'wireguard_address': '93.184.216.34', 'wireguard_port': 51820}
PROJECT_CONFIG = {**CONFIG, 'project_bootstrap': {'projects': [PROJECT],
                  'wireguard_address': BOOTSTRAP['wireguard_address'], 'wireguard_port': 51820}}
PROJECT_SPEC = {**SPEC, 'project_bootstrap': BOOTSTRAP, 'project_slug': 'dlf', 'config': PROJECT_CONFIG}


def network():
    return {'type': 'bridge', 'managed': True, 'used_by': [], 'config': {
        'user.orbit.compute.owner': policy.OWNER, 'user.orbit.compute.id': ID,
        'user.orbit.compute.host_network': '1', 'ipv4.address': '10.233.209.1/24',
        'ipv4.nat': 'true', 'ipv6.address': 'none', 'dns.mode': 'none', 'security.acls': policy.name(ID),
        'security.acls.default.egress.action': 'reject', 'security.acls.default.ingress.action': 'reject'}}


class ContractTests(unittest.TestCase):
    def test_project_bootstrap_requires_its_own_closed_root_opt_in(self):
        self.assertEqual({'enabled': False}, policy.apply({**REQUEST, 'operation': 'project_enabled'}, CONFIG))
        self.assertEqual({'enabled': True}, policy.apply({**REQUEST, 'operation': 'project_enabled'}, PROJECT_CONFIG))
        with patch.object(policy, 'read', return_value=json.dumps(PROJECT_CONFIG)):
            self.assertEqual(PROJECT_CONFIG, policy.configuration())
        for change in [{'projects': ['default']}, {'projects': [PROJECT, PROJECT]}, {'projects': []},
                       {'wireguard_address': '10.44.0.3'}, {'wireguard_address': '100.64.0.1'},
                       {'wireguard_address': '224.0.0.1'}, {'wireguard_address': '2001:4860::1'},
                       {'wireguard_port': True}, {'wireguard_port': 0}, {'wireguard_port': 65536}, {'command': 'id'}]:
            value = {**PROJECT_CONFIG, 'project_bootstrap': {**PROJECT_CONFIG['project_bootstrap'], **change}}
            with self.subTest(change=change), patch.object(policy, 'read', return_value=json.dumps(value)), self.assertRaises(ValueError):
                policy.configuration()

    def test_project_bridge_bootstrap_must_match_the_approved_host_and_hub(self):
        project = {'config': {'user.orbit.compute.owner': policy.OWNER, 'features.networks': 'false'}}
        acl = {'config': {'user.orbit.compute.owner': policy.OWNER, 'user.orbit.compute.id': ID}}
        interfaces = [{'ifname': 'eh', 'addr_info': [{'local': CONFIG['pi_host'], 'prefixlen': 16}]}]
        bridge = network()
        bridge['config'].update({'user.orbit.compute.project_slug': 'dlf',
                                'user.orbit.compute.project_bootstrap': json.dumps(BOOTSTRAP, sort_keys=True, separators=(',', ':'))})
        def derive(value, config=PROJECT_CONFIG):
            with patch.object(policy, 'incus', side_effect=[project, value, acl]), patch.object(policy, 'run', side_effect=[json.dumps(interfaces), json.dumps([{'ifname': 'eh', 'linkinfo': {'info_kind': 'wireguard'}}])]):
                return policy.derive(REQUEST, config)
        self.assertEqual(BOOTSTRAP, derive(bridge)['project_bootstrap'])
        with self.assertRaises(ValueError):
            derive(bridge, CONFIG)
        for field, value in [('ssh_host', '10.44.0.8'), ('ssh_port', 24210), ('gateway_address', '10.44.0.99'),
                             ('wireguard_address', '93.184.216.35'), ('wireguard_port', 51821), ('command', 'id')]:
            changed = copy.deepcopy(bridge)
            changed['config']['user.orbit.compute.project_bootstrap'] = json.dumps({**BOOTSTRAP, field: value}, sort_keys=True, separators=(',', ':'))
            with self.subTest(field=field), self.assertRaises(ValueError):
                derive(changed)
        for slug in (None, 'orbit', '../dlf'):
            changed = copy.deepcopy(bridge); changed['config']['user.orbit.compute.project_slug'] = slug
            with self.subTest(slug=slug), self.assertRaises(ValueError):
                derive(changed)
        changed = copy.deepcopy(bridge); changed['used_by'] = ['/1.0/instances/foreign?project='+PROJECT]
        with self.assertRaises(ValueError):
            derive(changed)

    def test_new_project_opt_in_preserves_existing_pair_policy_identity(self):
        self.assertEqual(CONFIG, policy.policy_configuration(PROJECT_CONFIG))
        self.assertEqual(PROJECT_CONFIG, policy.policy_configuration(PROJECT_CONFIG, BOOTSTRAP))

    def test_root_attests_the_exact_project_guest_image_proxy_and_worktree(self):
        bridge = policy.name(ID)
        marker = json.dumps(BOOTSTRAP, sort_keys=True, separators=(',', ':'))
        metadata = {'user.orbit.compute.owner': policy.OWNER, 'user.orbit.compute.id': ID,
                    'user.orbit.compute.project_slug': 'dlf', 'user.orbit.compute.project_bootstrap': marker}
        guest = {'name': bridge+'-operator', 'type': 'virtual-machine', 'profiles': [],
                 'config': {**metadata, 'volatile.base_image': 'a'*64}, 'devices': {
                     'root': {'type': 'disk', 'path': '/', 'pool': 'proof', 'size': '20GiB'},
                     'worktree': {'type': 'disk', 'pool': 'proof', 'source': bridge+'-worktree', 'path': '/home/orbit/orbit'},
                     'eth0': {'type': 'nic', 'network': bridge, 'name': 'eth0', 'ipv4.address': '10.233.209.10',
                              'security.mac_filtering': 'true', 'security.ipv4_filtering': 'true'},
                     'ssh': {'type': 'proxy', 'bind': 'host', 'nat': 'true', 'listen': 'tcp:10.44.0.7:24209',
                             'connect': 'tcp:10.233.209.10:22'}}}
        image = {'type': 'virtual-machine', 'architecture': 'x86_64', 'public': False, 'properties': {
            'user.orbit.project.owner': 'orbit-task-project-image', 'user.orbit.project.slug': 'dlf',
            'user.orbit.project.account': 'orbit', 'user.orbit.project.bootstrap': 'unenrolled'}}
        volume = {'type': 'custom', 'content_type': 'filesystem', 'config': metadata,
                  'used_by': ['/1.0/instances/'+bridge+'-operator?project='+PROJECT]}
        def attest(values):
            with patch.object(policy, 'incus', side_effect=values):
                policy.attest_project(PROJECT, ID, bridge, policy.ipaddress.ip_network(SPEC['subnet']), metadata, BOOTSTRAP)
        attest([guest, image, volume])
        cases = [lambda g, i, v: g.update(profiles=['default']),
                 lambda g, i, v: g['config'].update({'user.orbit.compute.id': 'foreign'}),
                 lambda g, i, v: g['config'].pop('user.orbit.compute.project_bootstrap'),
                 lambda g, i, v: g['devices']['ssh'].update(listen='tcp:0.0.0.0:24209'),
                 lambda g, i, v: g['devices']['ssh'].update(connect='tcp:10.233.209.11:22'),
                 lambda g, i, v: g['devices']['root'].update(pool='../default'),
                 lambda g, i, v: i.update(public=True),
                 lambda g, i, v: i['properties'].update({'user.orbit.project.slug': 'foreign'}),
                 lambda g, i, v: i['properties'].update({'user.orbit.project.bootstrap': 'enrolled'}),
                 lambda g, i, v: v['config'].pop('user.orbit.compute.project_slug'),
                 lambda g, i, v: v.update(used_by=['/1.0/instances/foreign'])]
        for change in cases:
            values = copy.deepcopy([guest, image, volume]); change(*values)
            with self.subTest(change=change), self.assertRaises(ValueError):
                attest(values)

    def test_an_existing_pair_policy_cannot_be_converted_into_a_project_policy(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)/'policy.json'; path.touch()
            with patch.object(policy, 'manifest_path', return_value=path), patch.object(policy, 'read', return_value=json.dumps(SPEC)), \
                    patch.object(policy, 'derive', return_value=PROJECT_SPEC), patch.object(policy, 'change') as change, self.assertRaises(ValueError):
                policy.apply(REQUEST, PROJECT_CONFIG)
            change.assert_not_called()

    def test_verify_refuses_missing_or_drifted_policy_without_mutation(self):
        with tempfile.TemporaryDirectory() as folder:
            path = Path(folder)/'policy.json'; path.touch()
            with patch.object(policy, 'manifest_path', return_value=path), patch.object(policy, 'read', return_value=json.dumps(PROJECT_SPEC)), \
                    patch.object(policy, 'derive', return_value=PROJECT_SPEC), patch.object(policy, 'desired', return_value={}), \
                    patch.object(policy, 'snapshot', return_value={}), patch.object(policy, 'state', return_value='present') as state, \
                    patch.object(policy, 'put') as put, patch.object(policy, 'change') as change:
                self.assertEqual({'ready': True}, policy.apply({**REQUEST, 'operation': 'verify'}, PROJECT_CONFIG))
                state.return_value = 'absent'
                with self.assertRaises(ValueError):
                    policy.apply({**REQUEST, 'operation': 'verify'}, PROJECT_CONFIG)
                path.unlink()
                with self.assertRaises(ValueError):
                    policy.apply({**REQUEST, 'operation': 'verify'}, PROJECT_CONFIG)
            put.assert_not_called(); change.assert_not_called()

    def test_packaged_boot_units_match_the_fixed_installer_contract(self):
        self.assertEqual(policy.UNIT_TEXT, (source.parent/'orbit-sandbox-host-network.service').read_text())
        self.assertEqual(policy.DEPENDENCY_TEXT, (source.parent/'orbit-sandbox-host-network-incus.conf').read_text())

    def test_request_never_accepts_caller_rules_or_host_commands(self):
        self.assertEqual(REQUEST, policy.validate(REQUEST))
        for change in [{'operation': 'flush'}, {'project': 'default'}, {'sandbox_id': '../../root'},
                       {'rules': 'accept'}, {'command': ['true']}, {'subnet': '0.0.0.0/0'}]:
            with self.subTest(change=change), self.assertRaises((ValueError, TypeError)):
                policy.validate({**REQUEST, **change})

    def test_project_opt_in_is_explicit(self):
        self.assertEqual({'enabled': True}, policy.apply({**REQUEST, 'operation': 'enabled'}, CONFIG))
        self.assertEqual({'enabled': False}, policy.apply({**REQUEST, 'operation': 'enabled', 'project': 'orbit-task-sandboxes'}, CONFIG))
        with self.assertRaises(ValueError):
            policy.apply({**REQUEST, 'project': 'orbit-task-sandboxes'}, CONFIG)

    def test_ownership_and_bridge_policy_checked_before_rules(self):
        project = {'config': {'user.orbit.compute.owner': policy.OWNER, 'features.networks': 'false'}}
        acl = {'config': {'user.orbit.compute.owner': policy.OWNER, 'user.orbit.compute.id': ID}}
        interfaces = [{'ifname': 'eh', 'addr_info': [{'local': '10.44.0.7', 'prefixlen': 24}, {'local': '198.41.0.20', 'prefixlen': 32}]}]
        with patch.object(policy, 'incus', side_effect=[project, network(), acl]), patch.object(policy, 'run', side_effect=[json.dumps(interfaces), json.dumps([{'ifname': 'eh', 'linkinfo': {'info_kind': 'wireguard'}}])]):
            result = policy.derive(REQUEST, CONFIG)
            self.assertEqual('10.233.209.0/24', result['subnet'])
            self.assertIn('198.41.0.20/32', result['blocked_networks'])
        for field, value in [('user.orbit.compute.id', 'foreign'), ('user.orbit.compute.host_network', None),
                             ('ipv6.address', 'auto'), ('security.acls.default.egress.action', 'allow'),
                             ('ipv4.address', '192.168.0.1/24')]:
            bad = network()
            bad['config'][field] = value
            with self.subTest(field=field), patch.object(policy, 'incus', side_effect=[project, bad]), self.assertRaises(ValueError):
                policy.derive(REQUEST, CONFIG)

    def test_pi_host_requires_unique_wireguard_interface_ownership(self):
        project = {'config': {'user.orbit.compute.owner': policy.OWNER, 'features.networks': 'false'}}
        acl = {'config': {'user.orbit.compute.owner': policy.OWNER, 'user.orbit.compute.id': ID}}
        interface = {'ifname': 'eh', 'addr_info': [{'local': '10.44.0.7', 'prefixlen': 16}]}
        link = {'ifname': 'eh', 'linkinfo': {'info_kind': 'wireguard'}}
        cases = [([], [link]), ([{**interface, 'ifname': 'foreign'}], [link]),
                 ([interface, interface], [link]), ([interface], []),
                 ([interface], [{**link, 'ifname': 'foreign'}]),
                 ([interface], [{**link, 'linkinfo': {'info_kind': 'veth'}}])]
        for interfaces, links in cases:
            with self.subTest(interfaces=interfaces, links=links), \
                    patch.object(policy, 'incus', side_effect=[project, network(), acl]), \
                    patch.object(policy, 'run', side_effect=[json.dumps(interfaces), json.dumps(links)]), \
                    self.assertRaises(ValueError):
                policy.derive(REQUEST, CONFIG)

    def test_configuration_refuses_unsafe_or_missing_interface_names(self):
        with patch.object(policy, 'read', return_value=json.dumps(CONFIG)):
            self.assertEqual(CONFIG, policy.configuration())
        for interface in [None, '', '-orbit', 'orbit;id', 'orbit*', 'a'*16, 'orbit:1']:
            with self.subTest(interface=interface), \
                    patch.object(policy, 'read', return_value=json.dumps({**CONFIG, 'wireguard_interface': interface})), \
                    self.assertRaises((ValueError, TypeError)):
                policy.configuration()
        config = {key: value for key, value in CONFIG.items() if key != 'wireguard_interface'}
        with patch.object(policy, 'read', return_value=json.dumps(config)), self.assertRaises(ValueError):
            policy.configuration()

    def test_boot_waits_for_wireguard_address_and_refuses_timeout(self):
        interfaces = [{'ifname': 'eh', 'addr_info': [{'local': '10.44.0.7', 'prefixlen': 16}]}]
        with patch.object(policy, 'run', side_effect=[json.dumps([]), json.dumps(interfaces)]), \
                patch.object(policy.time, 'sleep') as sleep:
            self.assertEqual(interfaces, policy.boot_interfaces(CONFIG))
            sleep.assert_called_once_with(0.1)
        with patch.object(policy, 'run', return_value='[]'), \
                patch.object(policy.time, 'monotonic', side_effect=[0, 61]), self.assertRaises(ValueError):
            policy.boot_interfaces(CONFIG)

    def test_drift_and_jump_order_refuse_changes(self):
        rules, jumps = policy.chains(SPEC)
        expected = {chain: [row.split() for row in rows] for chain, rows in {**rules, **jumps}.items()}
        self.assertEqual('present', policy.state(SPEC, expected, expected))
        self.assertEqual('absent', policy.state(SPEC, expected, {'INPUT': [], 'OUTPUT': [], 'FORWARD': []}))
        for mutate in [lambda value: value['FORWARD'].insert(0, ['-j', 'ACCEPT']),
                       lambda value: value[next(iter(rules))].append(['-j', 'ACCEPT']),
                       lambda value: value['INPUT'].append(value['INPUT'][0]),
                       lambda value: value.pop(next(iter(rules))),
                       lambda value: value['FORWARD'].insert(0, ['-j', 'OTF-123456789a'])]:
            current = copy.deepcopy(expected)
            mutate(current)
            with self.assertRaises(ValueError):
                policy.state(SPEC, expected, current)

    def test_partial_family_failure_keeps_retryable_state(self):
        calls = []
        def command(argv, data=None):
            calls.append(argv[0])
            if argv[0] == '/usr/sbin/iptables-restore':
                raise ValueError('second family failed')
        with patch.object(policy, 'desired', return_value={}), patch.object(policy, 'snapshot', return_value={}), \
                patch.object(policy, 'state', return_value='absent'), patch.object(policy, 'run', side_effect=command):
            with self.assertRaises(ValueError):
                policy.change(SPEC)
        self.assertEqual(['/usr/sbin/ip6tables-restore', '/usr/sbin/iptables-restore'], calls)

    def test_lock_timeout_refuses_concurrent_mutation(self):
        with patch.object(policy.fcntl, 'flock', side_effect=[BlockingIOError(), None]), patch.object(policy.time, 'sleep'):
            policy.acquire(123)
        with patch.object(policy.fcntl, 'flock', side_effect=BlockingIOError()), patch.object(policy.time, 'monotonic', side_effect=[0, 61]):
            with self.assertRaises(ValueError):
                policy.acquire(123)


@unittest.skipUnless(PACKETS, 'requires sudo unshare --net --packets')
class PacketTests(unittest.TestCase):
    @classmethod
    def command(cls, argv, data=None):
        return subprocess.run(argv, input=data, text=True, capture_output=True, check=True, timeout=15).stdout

    @classmethod
    def namespace(cls, tag, address, host, bridge=None):
        child = subprocess.Popen(['unshare', '--net', 'python3', '-u', '-c',
                                  'import sys; print("ready",flush=True); sys.stdin.read()'],
                                 stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        cls.children.append(child)
        assert child.stdout.readline().strip() == 'ready'
        cls.command(['ip', 'link', 'add', tag+'h', 'type', 'veth', 'peer', 'name', tag+'g'])
        cls.command(['ip', 'link', 'set', tag+'g', 'netns', str(child.pid)])
        if bridge:
            cls.command(['ip', 'link', 'set', tag+'h', 'master', bridge])
        else:
            cls.command(['ip', 'address', 'add', host+'/24', 'dev', tag+'h'])
        cls.command(['ip', 'link', 'set', tag+'h', 'up'])
        prefix = ['nsenter', '-t', str(child.pid), '-n']
        for argv in [['ip', 'link', 'set', 'lo', 'up'], ['ip', 'address', 'add', address+'/24', 'dev', tag+'g'],
                     ['ip', 'link', 'set', tag+'g', 'up'], ['ip', 'route', 'add', 'default', 'via', host]]:
            cls.command(prefix+argv)
        return child

    @classmethod
    def listen(cls, pid, address, port, udp=False):
        code = '''import socket,sys
s=socket.socket(socket.AF_INET, socket.SOCK_DGRAM if sys.argv[3]=='udp' else socket.SOCK_STREAM)
s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1); s.bind((sys.argv[1],int(sys.argv[2])))
if sys.argv[3]!='udp': s.listen()
print('ready',flush=True)
while True:
 if sys.argv[3]=='udp':
  _,peer=s.recvfrom(4096); s.sendto(b'yes',peer)
 else:
  c,_=s.accept(); c.sendall(b'yes'); c.close()
'''
        prefix = ['nsenter', '-t', str(pid), '-n'] if pid else []
        process = subprocess.Popen(prefix+['python3', '-u', '-c', code, address, str(port), 'udp' if udp else 'tcp'],
                                   stdout=subprocess.PIPE, text=True)
        cls.children.append(process)
        assert process.stdout.readline().strip() == 'ready'

    @classmethod
    def connect(cls, pid, source, target, port, udp=False, source_port=0):
        code = '''import socket,sys
s=socket.socket(socket.AF_INET, socket.SOCK_DGRAM if sys.argv[4]=='udp' else socket.SOCK_STREAM)
s.settimeout(.25); s.bind((sys.argv[1],int(sys.argv[5])))
try:
 s.connect((sys.argv[2],int(sys.argv[3])))
 if sys.argv[4]=='udp': s.send(b'hi')
 assert s.recv(3)==b'yes'
except (OSError,AssertionError): sys.exit(1)
'''
        prefix = ['nsenter', '-t', str(pid), '-n'] if pid else []
        return subprocess.run(prefix+['python3', '-c', code, source, target, str(port), 'udp' if udp else 'tcp', str(source_port)], timeout=3).returncode == 0

    @classmethod
    def setUpClass(cls):
        assert os.geteuid() == 0
        cls.children = []
        cls.addClassCleanup(cls.cleanup)
        cls.bridge = policy.name(ID)
        cls.command(['ip', 'link', 'set', 'lo', 'up'])
        cls.command(['sysctl', '-q', '-w', 'net.ipv4.ip_forward=1'])
        cls.command(['ip', 'link', 'add', cls.bridge, 'type', 'bridge'])
        cls.command(['ip', 'address', 'add', '10.233.209.1/24', 'dev', cls.bridge])
        cls.command(['ip', 'link', 'set', cls.bridge, 'up'])
        cls.guest = cls.namespace('g', '10.233.209.10', '10.233.209.1', cls.bridge)
        cls.pair = cls.namespace('p', '10.233.209.11', '10.233.209.1', cls.bridge)
        cls.external = cls.namespace('e', '198.19.0.2', '198.19.0.1')
        cls.spoof = cls.namespace('s', '172.20.0.2', '172.20.0.1')
        cls.command(['nsenter', '-t', str(cls.spoof.pid), '-n', 'ip', 'address', 'add', '10.44.0.1/32', 'dev', 'lo'])
        cls.paths = [('93.184.216.34', 80), ('93.184.216.34', 443), ('93.184.216.34', 22),
                     ('1.1.1.1', 53), ('9.9.9.9', 53), ('8.8.8.8', 53),
                     ('10.44.0.1', 443), ('10.44.0.99', 443), ('192.168.6.99', 80),
                     ('169.254.169.254', 80), ('10.233.210.10', 80)]
        for address in set(address for address, _ in cls.paths):
            cls.command(['nsenter', '-t', str(cls.external.pid), '-n', 'ip', 'address', 'add', address+'/32', 'dev', 'lo'])
            cls.command(['ip', 'route', 'add', address+'/32', 'via', '198.19.0.2'])
        for address, port in cls.paths:
            cls.listen(cls.external.pid, address, port)
        for address in ['1.1.1.1', '9.9.9.9', '8.8.8.8']:
            cls.listen(cls.external.pid, address, 53, udp=True)
        for address in ['10.44.0.7', '198.41.0.20']:
            cls.command(['ip', 'address', 'add', address+'/32', 'dev', 'eh' if address == '10.44.0.7' else 'lo'])
        cls.listen(None, '198.41.0.20', 80)
        cls.listen(None, '10.233.209.1', 8317)
        cls.listen(None, '10.233.209.1', 67, udp=True)
        cls.listen(cls.guest.pid, '10.233.209.10', 3774)
        cls.listen(cls.guest.pid, '10.233.209.10', 80)
        cls.listen(cls.pair.pid, '10.233.209.11', 80)
        # Confirm listeners and routing on denied paths before applying policy.
        for address, port in cls.paths:
            assert cls.connect(cls.guest.pid, '10.233.209.10', address, port), (address, port)
        assert cls.connect(cls.guest.pid, '10.233.209.10', '198.41.0.20', 80)
        assert cls.connect(cls.external.pid, '10.44.0.1', '10.233.209.10', 3774)
        cls.command(['ip', 'route', 'replace', '10.44.0.1/32', 'via', '172.20.0.2'])
        assert cls.connect(cls.spoof.pid, '10.44.0.1', '10.233.209.10', 3774)
        cls.command(['ip', 'route', 'replace', '10.44.0.1/32', 'via', '198.19.0.2'])
        cls.streams = []
        for address, port in [('10.44.0.100', 443), ('93.184.216.35', 22)]:
            cls.command(['nsenter', '-t', str(cls.external.pid), '-n', 'ip', 'address', 'add', address+'/32', 'dev', 'lo'])
            cls.command(['ip', 'route', 'add', address+'/32', 'via', '198.19.0.2'])
            server_code = "import socket,sys; s=socket.socket(); s.bind((sys.argv[1],int(sys.argv[2]))); s.listen(); print('ready',flush=True); c,_=s.accept(); c.sendall(b'before'); sys.stdin.readline(); c.sendall(b'after')"
            server = subprocess.Popen(['nsenter', '-t', str(cls.external.pid), '-n', 'python3', '-u', '-c', server_code, address, str(port)], stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
            cls.children.append(server)
            assert server.stdout.readline().strip() == 'ready'
            client_code = '''import socket,sys
s=socket.socket(); s.settimeout(2); s.connect((sys.argv[1],int(sys.argv[2]))); assert s.recv(6)==b'before'
print('ready',flush=True); sys.stdin.readline(); s.settimeout(.5)
try:
 assert not s.recv(5)
except TimeoutError: pass
'''
            client = subprocess.Popen(['nsenter', '-t', str(cls.guest.pid), '-n', 'python3', '-u', '-c', client_code, address, str(port)], stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
            cls.children.append(client)
            assert client.stdout.readline().strip() == 'ready'
            cls.streams.append((server, client))
        cls.command(['iptables', '-A', 'FORWARD', '-j', 'DROP'])
        cls.command(['iptables', '-A', 'INPUT', '-j', 'DROP'])
        cls.command(['iptables', '-A', 'OUTPUT', '-j', 'DROP'])
        cls.before = {ipv6: policy.snapshot(ipv6) for ipv6 in (False, True)}
        policy.change(SPEC)

    def test_00_preexisting_private_and_non_web_connections_cannot_receive_replies(self):
        for server, client in self.streams:
            server.stdin.write('send\n'); server.stdin.flush()
            client.stdin.write('check\n'); client.stdin.flush()
            self.assertEqual(0, client.wait(timeout=3))
            self.assertEqual(0, server.wait(timeout=3))

    def test_dhcp_requests_and_replies_cross_existing_host_drop(self):
        self.assertTrue(self.connect(self.guest.pid, '10.233.209.10', '10.233.209.1', 67, udp=True, source_port=68))

    def test_public_http_dns_and_return_traffic_cross_existing_host_drop(self):
        for address, port in [('93.184.216.34', 80), ('93.184.216.34', 443), ('1.1.1.1', 53), ('9.9.9.9', 53)]:
            self.assertTrue(self.connect(self.guest.pid, '10.233.209.10', address, port), (address, port))
        for address in ['1.1.1.1', '9.9.9.9']:
            self.assertTrue(self.connect(self.guest.pid, '10.233.209.10', address, 53, udp=True))

    def test_private_metadata_other_groups_host_and_other_ports_stay_blocked(self):
        for address, port in [('93.184.216.34', 22), ('8.8.8.8', 53), ('10.44.0.1', 443),
                              ('10.44.0.99', 443), ('192.168.6.99', 80), ('169.254.169.254', 80),
                              ('10.233.210.10', 80), ('198.41.0.20', 80)]:
            self.assertFalse(self.connect(self.guest.pid, '10.233.209.10', address, port), (address, port))
        self.assertFalse(self.connect(self.guest.pid, '10.233.209.10', '8.8.8.8', 53, udp=True))

    def test_only_operator_relay_and_recorded_gateway_pi_are_allowed(self):
        self.assertTrue(self.connect(self.guest.pid, '10.233.209.10', '10.233.209.1', 8317))
        self.assertFalse(self.connect(self.pair.pid, '10.233.209.11', '10.233.209.1', 8317))
        self.assertTrue(self.connect(self.external.pid, '10.44.0.1', '10.233.209.10', 3774))
        self.assertFalse(self.connect(self.external.pid, '10.44.0.99', '10.233.209.10', 3774))
        self.assertFalse(self.connect(self.external.pid, '10.44.0.1', '10.233.209.10', 80))
        self.assertTrue(self.connect(self.guest.pid, '10.233.209.10', '10.233.209.11', 80))

    def test_project_ssh_requires_original_proxy_destination_and_exact_hub_udp(self):
        self.listen(self.guest.pid, '10.233.209.10', 22)
        self.listen(self.external.pid, '93.184.216.34', 51820, udp=True)
        self.listen(self.external.pid, '93.184.216.34', 51821, udp=True)
        self.listen(self.external.pid, '93.184.216.35', 51820, udp=True)
        for address in ('93.184.216.34', '93.184.216.35'):
            self.command(['ip', 'route', 'replace', address+'/32', 'via', '198.19.0.2'])
        nat = ['-d', CONFIG['pi_host'], '-p', 'tcp', '--dport', '24209', '-j', 'DNAT', '--to-destination', '10.233.209.10:22']
        wrong_port = [*nat]; wrong_port[5] = '24210'
        self.command(['iptables', '-t', 'nat', '-A', 'PREROUTING', *nat])
        self.command(['iptables', '-t', 'nat', '-A', 'PREROUTING', *wrong_port])
        policy.change(SPEC, remove=True)
        try:
            policy.change(PROJECT_SPEC)
            self.assertTrue(self.connect(self.external.pid, '10.44.0.1', CONFIG['pi_host'], 24209))
            self.assertFalse(self.connect(self.external.pid, '10.44.0.1', CONFIG['pi_host'], 24210))
            self.assertFalse(self.connect(self.external.pid, '10.44.0.99', CONFIG['pi_host'], 24209))
            self.assertFalse(self.connect(self.external.pid, '10.44.0.1', '10.233.209.10', 22))
            self.assertFalse(self.connect(self.external.pid, '10.44.0.1', '10.233.209.10', 3774))
            self.command(['ip', 'route', 'replace', '10.44.0.1/32', 'via', '172.20.0.2'])
            try:
                self.assertFalse(self.connect(self.spoof.pid, '10.44.0.1', CONFIG['pi_host'], 24209))
            finally:
                self.command(['ip', 'route', 'replace', '10.44.0.1/32', 'via', '198.19.0.2'])
            self.assertTrue(self.connect(self.guest.pid, '10.233.209.10', '93.184.216.34', 51820, udp=True))
            self.assertFalse(self.connect(self.guest.pid, '10.233.209.10', '93.184.216.34', 51821, udp=True))
            self.assertFalse(self.connect(self.guest.pid, '10.233.209.10', '93.184.216.35', 51820, udp=True))
            self.assertFalse(self.connect(self.pair.pid, '10.233.209.11', '93.184.216.34', 51820, udp=True))
            self.assertFalse(self.connect(self.guest.pid, '10.233.209.10', '10.44.0.99', 443))
            self.assertFalse(self.connect(self.guest.pid, '10.233.209.10', '169.254.169.254', 80))
            with tempfile.TemporaryDirectory(dir='/root') as folder, patch.object(policy, 'ROOT', Path(folder)):
                path = policy.manifest_path(ID)
                policy.put(path, json.dumps(PROJECT_SPEC, sort_keys=True).encode())
                policy.change(PROJECT_SPEC, remove=True)
                with patch.object(policy, 'wireguard'):
                    policy.restore(PROJECT_CONFIG)
                before = {ipv6: policy.snapshot(ipv6) for ipv6 in (False, True)}
                content = path.read_bytes()
                with patch.object(policy, 'derive', return_value=PROJECT_SPEC):
                    self.assertEqual({'ready': True}, policy.apply({**REQUEST, 'operation': 'verify'}, PROJECT_CONFIG))
                self.assertEqual(content, path.read_bytes())
                self.assertEqual(before, {ipv6: policy.snapshot(ipv6) for ipv6 in (False, True)})
                self.assertTrue(self.connect(self.external.pid, '10.44.0.1', CONFIG['pi_host'], 24209))
                with self.assertRaises(ValueError):
                    policy.restore(CONFIG)
                retired = network()
                retired['config'].update({'user.orbit.compute.project_slug': 'dlf',
                                          'user.orbit.compute.project_bootstrap': json.dumps(BOOTSTRAP, sort_keys=True, separators=(',', ':'))})
                with patch.object(policy, 'incus', return_value=retired):
                    self.assertEqual({'removed': True}, policy.apply({**REQUEST, 'operation': 'remove'}, PROJECT_CONFIG))
                self.assertFalse(path.exists())
        finally:
            policy.change(PROJECT_SPEC, remove=True)
            self.command(['iptables', '-t', 'nat', '-D', 'PREROUTING', *nat])
            self.command(['iptables', '-t', 'nat', '-D', 'PREROUTING', *wrong_port])
            policy.change(SPEC)

    def test_gateway_source_on_another_interface_cannot_reach_pi(self):
        self.command(['ip', 'route', 'replace', '10.44.0.1/32', 'via', '172.20.0.2'])
        try:
            self.assertFalse(self.connect(self.spoof.pid, '10.44.0.1', '10.233.209.10', 3774))
        finally:
            self.command(['ip', 'route', 'replace', '10.44.0.1/32', 'via', '198.19.0.2'])

    def test_ipv6_forwarding_stays_blocked_when_guest_enables_it(self):
        self.command(['sysctl', '-q', '-w', 'net.ipv6.conf.all.forwarding=1'])
        for pid, interface, address in [(None, self.bridge, 'fd23:209::1/64'),
                                         (self.guest.pid, 'gg', 'fd23:209::10/64'),
                                         (None, 'eh', 'fd19::1/64'),
                                         (self.external.pid, 'eg', 'fd19::2/64'),
                                         (self.external.pid, 'lo', '2606:4700:4700::1111/128')]:
            prefix = ['nsenter', '-t', str(pid), '-n'] if pid else []
            self.command(prefix+['ip', '-6', 'address', 'add', address, 'dev', interface, 'nodad'])
        self.command(['ip', '-6', 'route', 'add', '2606:4700:4700::1111/128', 'via', 'fd19::2'])
        self.command(['nsenter', '-t', str(self.guest.pid), '-n', 'ip', '-6', 'route', 'add', 'default', 'via', 'fd23:209::1'])
        self.command(['nsenter', '-t', str(self.external.pid), '-n', 'ip', '-6', 'route', 'add', 'default', 'via', 'fd19::1'])
        listener = subprocess.Popen(['nsenter', '-t', str(self.external.pid), '-n', 'python3', '-u', '-c',
                                    "import socket; s=socket.socket(socket.AF_INET6); s.bind(('2606:4700:4700::1111',443)); s.listen(); print('ready',flush=True);\nwhile True: c,_=s.accept(); c.sendall(b'yes'); c.close()"], stdout=subprocess.PIPE, text=True)
        self.children.append(listener)
        assert listener.stdout.readline().strip() == 'ready'
        argv = ['nsenter', '-t', str(self.guest.pid), '-n', 'python3', '-c',
                "import socket; s=socket.socket(socket.AF_INET6); s.settimeout(2); s.connect(('2606:4700:4700::1111',443)); assert s.recv(3)==b'yes'"]
        self.command(['ip6tables-restore', '--noflush'], policy.render(SPEC, True, True))
        self.assertEqual(0, subprocess.run(argv, capture_output=True, timeout=4).returncode)
        policy.change(SPEC)
        self.assertNotEqual(0, subprocess.run(argv, capture_output=True, timeout=4).returncode)

    def test_missing_boot_dependency_or_unit_drift_refuses_access(self):
        with tempfile.TemporaryDirectory(dir='/root') as folder:
            unit = Path(folder)/policy.UNIT.name
            dependency = Path(folder)/'incus.conf'
            unit.write_text(policy.UNIT_TEXT); unit.chmod(0o644)
            dependency.write_text(policy.DEPENDENCY_TEXT); dependency.chmod(0o644)
            def systemd(argv, data=None):
                return policy.UNIT.name+'\n'+policy.UNIT.name+'\n' if 'show' in argv else ''
            with patch.object(policy, 'UNIT', unit), patch.object(policy, 'DEPENDENCY', dependency), patch.object(policy, 'run', side_effect=systemd):
                policy.installation()
                unit.write_text(policy.UNIT_TEXT+'Environment=CALLER_SCRIPT=bad\n')
                with self.assertRaises(ValueError):
                    policy.installation()
                unit.write_text(policy.UNIT_TEXT)
                with patch.object(policy, 'run', return_value='incus.socket\nincus.socket\n'), self.assertRaises(ValueError):
                    policy.installation()
                with patch.object(policy, 'run', side_effect=ValueError('disabled')), self.assertRaises(ValueError):
                    policy.installation()

    def test_retry_partial_failure_restore_drift_and_exact_removal(self):
        with tempfile.TemporaryDirectory(dir='/root') as folder, patch.object(policy, 'ROOT', Path(folder)):
            config_file = Path(folder)/'config'
            config_file.write_text(json.dumps(CONFIG)); config_file.chmod(0o644)
            with patch.object(policy, 'CONFIG', config_file):
                self.assertEqual(CONFIG, policy.configuration())
                config_file.chmod(0o666)
                with self.assertRaises(ValueError):
                    policy.configuration()
                config_file.chmod(0o644)
                config_file.write_text(json.dumps({**CONFIG, 'command': 'ignored'}))
                with self.assertRaises(ValueError):
                    policy.configuration()
            config_file.unlink()
            # Matching unrecorded rules must not be adopted.
            with patch.object(policy, 'derive', return_value=SPEC), self.assertRaises(ValueError):
                policy.apply(REQUEST, CONFIG)
            policy.change(SPEC, remove=True)
            self.assertEqual(self.before, {ipv6: policy.snapshot(ipv6) for ipv6 in (False, True)})
            original_run = policy.run
            def fail_ipv4(argv, data=None):
                if argv[0] == '/usr/sbin/iptables-restore':
                    raise ValueError('simulated failure')
                return original_run(argv, data)
            with patch.object(policy, 'derive', return_value=SPEC), patch.object(policy, 'run', side_effect=fail_ipv4), self.assertRaises(ValueError):
                policy.apply(REQUEST, CONFIG)
            manifest = policy.ROOT / (ID+'.json')
            self.assertTrue(manifest.exists())
            self.assertEqual('present', policy.state(SPEC, policy.desired(SPEC, True), policy.snapshot(True)))
            self.assertEqual('absent', policy.state(SPEC, policy.desired(SPEC), policy.snapshot()))
            with patch.object(policy, 'derive', return_value=SPEC):
                policy.apply(REQUEST, CONFIG)
                policy.apply(REQUEST, CONFIG)
            policy.change(SPEC, remove=True)
            with patch.object(policy, 'wireguard', return_value=None):
                policy.restore(CONFIG)
            old_run = policy.run
            def changed_host(argv, data=None):
                if argv[0] == '/usr/sbin/ip':
                    return json.dumps([{'ifname': 'eh', 'addr_info': [{'local': '10.44.0.7', 'prefixlen': 32}, {'local': '93.184.217.10', 'prefixlen': 24}]}])
                return old_run(argv, data)
            with patch.object(policy, 'run', side_effect=changed_host), self.assertRaises(ValueError):
                policy.restore(CONFIG)
            self.assertTrue(self.connect(self.guest.pid, '10.233.209.10', '93.184.216.34', 443))
            forward = next(chain for chain in policy.chains(SPEC)[0] if chain.startswith('OTF'))
            self.command(['iptables', '-A', forward, '-j', 'ACCEPT'])
            with self.assertRaises(ValueError):
                policy.change(SPEC)
            self.command(['iptables', '-D', forward, '-j', 'ACCEPT'])
            bad = network(); bad['used_by'] = ['/1.0/instances/foreign']
            with patch.object(policy, 'incus', return_value=bad), self.assertRaises(ValueError):
                policy.apply({**REQUEST, 'operation': 'remove'}, CONFIG)
            manifest.chmod(0o644)
            with self.assertRaises(ValueError):
                policy.restore(CONFIG)
            manifest.chmod(0o600)
            data = manifest.read_bytes(); manifest.unlink(); manifest.symlink_to(Path(folder)/'foreign')
            with self.assertRaises(OSError):
                policy.restore(CONFIG)
            manifest.unlink(); policy.put(manifest, data)
            with patch.object(policy, 'incus', return_value=network()):
                policy.apply({**REQUEST, 'operation': 'remove'}, CONFIG)
                policy.apply({**REQUEST, 'operation': 'remove'}, CONFIG)
            self.assertFalse(manifest.exists())
            self.assertEqual(self.before, {ipv6: policy.snapshot(ipv6) for ipv6 in (False, True)})
            policy.change(SPEC)

    @classmethod
    def cleanup(cls):
        for child in reversed(cls.children):
            if child.poll() is None:
                child.terminate()
                child.wait(timeout=3)


unittest.main()
