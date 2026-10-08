"""Contract checks for ownership and capacity without touching the host daemon."""
import base64
import copy
import hashlib
import ipaddress
import json
import os
from pathlib import Path
import tempfile
from types import SimpleNamespace
import runpy
import sys
import unittest
from unittest.mock import patch

module = runpy.run_path(sys.argv.pop(1))
Host, Refusal = module['Host'], module['Refusal']
ID = 'ca656ccf-240d-476c-90f1-cf70f9dd7a12'


class FakeHost(Host):
    def __init__(self, rows=None, network=None, acl=None):
        self.rows, self.network, self.acl = rows or [], network, acl
        self.calls = []
        self.payloads = []
        super().__init__('orbit-sandbox-proof-test', ID, 2)

    def run(self, *args, data=None, timeout=180):
        self.calls.append(args)
        self.payloads.append((args, data))
        if args[:1] == ('query',) and args[1].startswith('/1.0/projects/'):
            return json.dumps({'config': {'user.orbit.compute.owner': module['OWNER'], 'features.networks': 'false'}}).encode()
        if args[:1] == ('query',) and args[1].startswith('/1.0/images/'):
            return json.dumps(getattr(self, 'template_image', {'type': 'virtual-machine'})).encode()
        if args[:1] == ('query',) and args[1].startswith('/1.0/storage-pools/'):
            return json.dumps(self.template_snapshot if '/snapshots/' in args[1] else self.template_volume).encode()
        if args[:3] == ('query', '-X', 'POST') and '/volumes/custom?' in args[3]:
            payload = json.loads(args[5])
            self.storage_volumes = [{'name': payload['name'], 'type': 'custom', 'content_type': 'filesystem',
                                     'used_by': [], 'config': payload['config']}]
            return b'{}'
        if args[:3] == ('query', '-X', 'POST') and args[3].startswith('/1.0/instances?'):
            payload = json.loads(args[5])
            self.rows.append({**payload, 'status': 'Stopped',
                              'config': {**payload['config'], 'volatile.base_image': payload['source']['fingerprint']}})
            return b'{}'
        if args[:1] == ('query',) and '/snapshots?' in args[1]:
            return json.dumps(getattr(self, 'snapshots', [])).encode()
        if args[:1] == ('stop',):
            next(row for row in self.rows if row['name'] == args[1])['status'] = 'Stopped'
        if args[:1] == ('start',):
            next(row for row in self.rows if row['name'] == args[1])['status'] = 'Running'
        if args[:1] == ('list',):
            return json.dumps(self.rows).encode()
        if args[:2] == ('network', 'list'):
            return json.dumps([self.network] if self.network else []).encode()
        if args[:3] == ('network', 'acl', 'list'):
            return json.dumps([self.acl] if self.acl else []).encode()
        if args[:2] == ('storage', 'list'):
            return b'[{"name":"proof"}]'
        if args[:3] == ('storage', 'volume', 'list'):
            return json.dumps(getattr(self, 'storage_volumes', [])).encode()
        if args[:3] == ('storage', 'volume', 'delete'):
            self.storage_volumes = []
        if args[0] == 'delete':
            self.rows = [row for row in self.rows if row['name'] != args[1]]
        return b'{}'


class Boundary(unittest.TestCase):
    def prepared(self):
        host = FakeHost()
        spec = {'images': {'operator': 'a' * 64}, 'pool': 'proof', 'subnet': '10.233.201.0/24', 'blocked_networks': ['192.168.0.0/16']}
        host.rows = [{'name': host.name + '-operator', 'type': 'virtual-machine', 'status': 'Stopped',
                      'config': {**host.metadata(), 'volatile.base_image': 'a' * 64}, 'profiles': [],
                      'devices': {'worktree': {'type': 'disk', 'pool': 'proof', 'source': host.name + '-worktree', 'path': '/home/orbit/orbit'},
                                  'root': {'type': 'disk', 'path': '/', 'pool': 'proof', 'size': '20GiB'},
                                  'eth0': {'type': 'nic', 'network': host.name, 'name': 'eth0', 'ipv4.address': '10.233.201.10',
                                           'security.mac_filtering': 'true', 'security.ipv4_filtering': 'true'}}}]
        host.network = {'name': host.name, 'type': 'bridge', 'config': {**host.metadata(),
                        'ipv4.address': '10.233.201.1/24', 'ipv4.nat': 'true', 'ipv6.address': 'none', 'dns.mode': 'none',
                        'security.acls': host.name, 'security.acls.default.egress.action': 'reject',
                        'security.acls.default.ingress.action': 'reject'}}
        host.acl = {'name': host.name, 'config': host.metadata()}
        host.storage_volumes = [{'name': host.name + '-worktree', 'type': 'custom', 'content_type': 'filesystem', 'config': host.metadata(), 'used_by': []}]
        return host, spec

    def provision(self, host, spec):
        with patch('subprocess.run') as process:
            process.return_value.stdout = b'[]'
            return host.provision(spec)

    def project_prepared(self):
        host, spec = self.prepared()
        spec['project_slug'] = 'dlf'
        host.template_image = {'type': 'virtual-machine', 'architecture': 'x86_64', 'public': False,
                               'properties': {'user.orbit.project.owner': 'orbit-task-project-image',
                                              'user.orbit.project.slug': 'dlf', 'user.orbit.project.account': 'orbit',
                                              'user.orbit.project.bootstrap': 'unenrolled'}}
        host.rows[0]['config']['user.orbit.compute.project_slug'] = 'dlf'
        host.storage_volumes[0]['config']['user.orbit.compute.project_slug'] = 'dlf'
        return host, spec

    def test_project_identity_reads_only_the_public_key_from_the_owned_guest(self):
        host, spec = self.project_prepared()
        host.rows[0]['status'] = 'Running'
        key = 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIHdUmJNAeflz28V7EadKJL3DLqnMqS6JyEQJmpCPNG5T'
        result = {'exit_code': 0, 'stdout': base64.b64encode((key + ' fixture\n').encode()).decode(),
                  'stderr': '', 'duration_ms': 1, 'truncated': False, 'timed_out': False}
        with patch.dict(host.project_identity.__globals__, bounded_process=lambda argv, data, timeout, limit: result):
            value = host.project_identity()
        self.assertEqual({'name': host.name, 'guest': host.name + '-operator', 'project_slug': 'dlf',
                          'image': 'a' * 64, 'pool': 'proof', 'subnet': spec['subnet'],
                          'address': '10.233.201.10', 'ssh_key': key}, value)
        self.assertFalse(any(call[0] in ('start', 'stop', 'delete') or call[:3] in (
            ('network', 'acl', 'edit'), ('query', '-X', 'POST')) for call in host.calls))

    def test_project_identity_refuses_foreign_or_changed_placement_before_reading_a_key(self):
        changes = [
            lambda h: h.rows[0].update(status='Stopped'),
            lambda h: h.rows.append({**copy.deepcopy(h.rows[0]), 'name': h.name + '-gateway'}),
            lambda h: h.rows[0]['config'].pop('user.orbit.compute.project_slug'),
            lambda h: h.rows[0]['config'].update({'user.orbit.compute.id': 'foreign'}),
            lambda h: h.rows[0]['config'].update({'volatile.base_image': 'unpinned'}),
            lambda h: h.template_image['properties'].update({'user.orbit.template.role': 'operator'}),
            lambda h: h.storage_volumes[0]['config'].update({'user.orbit.compute.project_slug': 'foreign'}),
            lambda h: h.storage_volumes[0].update(used_by=['/1.0/instances/foreign']),
            lambda h: h.rows[0]['devices']['eth0'].update(network='foreign'),
            lambda h: h.rows[0]['devices']['root'].update(source='/'),
            lambda h: h.rows[0].update(profiles=['default']),
            lambda h: h.network['config'].update({'security.acls.default.egress.action': 'allow'}),
            lambda h: h.network['config'].update({'ipv4.address': '10.233.202.2/24'}),
            lambda h: h.network['config'].update({'user.orbit.compute.id': 'foreign'}),
        ]
        for index, change in enumerate(changes):
            with self.subTest(index=index):
                host, spec = self.project_prepared()
                host.rows[0]['status'] = 'Running'
                change(host)
                with patch.dict(host.project_identity.__globals__, bounded_process=lambda *args: self.fail('must not read a guest key')):
                    with self.assertRaises(Refusal):
                        host.project_identity()

    def test_project_identity_refuses_unbounded_or_malformed_public_keys(self):
        for key, changes in [('ssh-ed25519 invalid', {}), ('ssh-rsa AA==', {}),
                             ('ssh-ed25519 AA==', {}), ('', {'truncated': True}),
                             ('', {'timed_out': True}), ('', {'exit_code': 1})]:
            with self.subTest(key=key, changes=changes):
                host, spec = self.project_prepared()
                host.rows[0]['status'] = 'Running'
                result = {'exit_code': 0, 'stdout': base64.b64encode(key.encode()).decode(),
                          'stderr': '', 'duration_ms': 1, 'truncated': False, 'timed_out': False, **changes}
                with patch.dict(host.project_identity.__globals__, bounded_process=lambda *args: result):
                    with self.assertRaises((Refusal, ValueError)):
                        host.project_identity()

    def test_project_image_keeps_one_guest_and_pins_worktree_ownership(self):
        host, spec = self.project_prepared()
        host.rows, host.storage_volumes = [], []
        self.assertEqual('running', self.provision(host, spec)['power'])
        created = [json.loads(args[5]) for args, data in host.payloads
                   if args[:3] == ('query', '-X', 'POST') and '/instances?' in args[3]]
        self.assertEqual(1, len(created))
        self.assertEqual('dlf', created[0]['config']['user.orbit.compute.project_slug'])
        volume = next(args for args in host.calls if args[:3] == ('storage', 'volume', 'create'))
        self.assertIn('user.orbit.compute.project_slug=dlf', volume)

    def test_project_provenance_and_retry_drift_refuse_before_mutation(self):
        changes = [
            lambda h, s: h.template_image['properties'].update({'user.orbit.template.role': 'operator'}),
            lambda h, s: h.template_image['properties'].update({'user.orbit.project.slug': 'foreign'}),
            lambda h, s: h.template_image['properties'].pop('user.orbit.project.owner'),
            lambda h, s: h.template_image['properties'].update({'user.orbit.project.account': 'root'}),
            lambda h, s: h.template_image['properties'].update({'user.orbit.project.bootstrap': 'enrolled'}),
            lambda h, s: h.template_image.update(type='container'),
            lambda h, s: h.template_image.update(architecture='aarch64'),
            lambda h, s: h.template_image.update(public=True),
            lambda h, s: h.template_image.update(properties=[]),
            lambda h, s: s.update(project_slug='orbit'),
            lambda h, s: s.update(project_slug='../foreign'),
            lambda h, s: s.update(project_slug=None),
            lambda h, s: s['images'].update(gateway='b' * 64),
            lambda h, s: s.update(source_template={'id': ID}),
            lambda h, s: s.update(pi_port=23001),
            lambda h, s: h.rows[0]['config'].update({'user.orbit.compute.project_slug': 'foreign'}),
            lambda h, s: h.storage_volumes[0]['config'].pop('user.orbit.compute.project_slug'),
            lambda h, s: s.pop('project_slug'),
        ]
        for index, change in enumerate(changes):
            with self.subTest(index=index):
                host, spec = self.project_prepared()
                change(host, spec)
                with self.assertRaises(Refusal):
                    self.provision(host, spec)
                self.assertFalse(any(call[0] in ('start', 'stop', 'delete') or call[:3] in (
                    ('network', 'acl', 'edit'), ('network', 'acl', 'create'),
                    ('query', '-X', 'POST'), ('storage', 'volume', 'create')) for call in host.calls))

    def test_new_bridge_opts_in_and_installs_policy_before_starting_guests(self):
        host, spec = self.prepared()
        host.rows, host.network = [], None
        operations = []
        def policy(operation):
            operations.append((operation, len(host.calls)))
            return True
        with patch.object(host, 'host_network', side_effect=policy):
            self.provision(host, spec)
        creation = next(call for call in host.calls if call[:2] == ('network', 'create'))
        self.assertIn('user.orbit.compute.host_network=1', creation)
        self.assertEqual(['enabled', 'ensure'], [operation for operation, _ in operations])
        self.assertLess(operations[1][1], next(i for i, call in enumerate(host.calls) if call[0] == 'start'))

    def test_existing_unmarked_bridge_never_adopts_policy(self):
        host, spec = self.prepared()
        with patch.object(host, 'host_network', side_effect=AssertionError('must not adopt')):
            self.provision(host, spec)
            host.resume()
            host.destroy()

    def test_marked_bridge_requires_policy_before_resume_and_retains_resources_on_failure(self):
        host, spec = self.prepared()
        host.network['config']['user.orbit.compute.host_network'] = '1'
        with patch.object(host, 'host_network', side_effect=Refusal('network unavailable')):
            with self.assertRaises(Refusal):
                self.provision(host, spec)
            with self.assertRaises(Refusal):
                host.resume()
        self.assertEqual('Stopped', host.rows[0]['status'])
        self.assertFalse(any(call[0] in ('start', 'delete') for call in host.calls))

    def test_cleanup_removes_owned_guests_then_policy_then_bridge(self):
        host, spec = self.prepared()
        host.network['config']['user.orbit.compute.host_network'] = '1'
        def policy(operation):
            self.assertEqual('remove', operation)
            self.assertEqual([], host.rows)
            self.assertFalse(any(call[:2] == ('network', 'delete') for call in host.calls))
            return True
        with patch.object(host, 'host_network', side_effect=policy):
            host.destroy()
        self.assertTrue(any(call[:2] == ('network', 'delete') for call in host.calls))

    def test_failed_policy_cleanup_retains_bridge_for_retry(self):
        host, spec = self.prepared()
        host.network['config']['user.orbit.compute.host_network'] = '1'
        with patch.object(host, 'host_network', side_effect=Refusal('drift')):
            with self.assertRaises(Refusal):
                host.destroy()
        self.assertFalse(any(call[:2] == ('network', 'delete') for call in host.calls))

    def test_host_policy_control_uses_only_fixed_helper_and_refuses_invalid_enabled_response(self):
        host, _ = self.prepared()
        helper = '/usr/local/libexec/orbit-sandbox-network'
        def details(path):
            return SimpleNamespace(st_uid=0, st_nlink=1, st_mode=0o100755 if str(path) == helper else 0o40755)
        with patch.object(Path, 'exists', return_value=True), patch.object(Path, 'lstat', autospec=True, side_effect=details), patch('subprocess.run') as process:
            for value in [{'enabled': True}, {'enabled': False}]:
                process.return_value = SimpleNamespace(returncode=0, stdout=json.dumps(value).encode())
                self.assertEqual(value['enabled'], host.host_network('enabled'))
                self.assertEqual(['/usr/bin/sudo', '-n', '--', helper], process.call_args.args[0])
                self.assertEqual({'operation': 'enabled', 'project': host.project, 'sandbox_id': host.id}, json.loads(process.call_args.kwargs['input']))
            for value in [{'enabled': 1}, {'removed': True}, {'enabled': True, 'extra': 'ignored'}]:
                process.return_value.stdout = json.dumps(value).encode()
                with self.assertRaises(Refusal):
                    host.host_network('enabled')

    def test_absent_helper_preserves_default_but_refuses_marked_policy(self):
        host, _ = self.prepared()
        with patch.object(Path, 'exists', return_value=False), patch.object(Path, 'is_symlink', return_value=False):
            self.assertFalse(host.host_network('enabled'))
            with self.assertRaises(Refusal):
                host.host_network('ensure')
        with patch.object(Path, 'exists', autospec=True, side_effect=lambda p: str(p) == '/etc/orbit/sandbox-network.json'), patch.object(Path, 'is_symlink', return_value=False):
            with self.assertRaises(Refusal):
                host.host_network('enabled')

    def test_writable_helper_is_refused_before_privileged_execution(self):
        host, _ = self.prepared()
        with patch.object(Path, 'exists', return_value=True), patch.object(Path, 'lstat', return_value=SimpleNamespace(st_uid=0, st_nlink=1, st_mode=0o100777)), patch('subprocess.run') as process:
            with self.assertRaises(Refusal):
                host.host_network('enabled')
            process.assert_not_called()

    def templated(self):
        host, spec = self.prepared()
        template = {'id': '9862e1aa-605c-4b49-a65b-6cf0b3a96dfe', 'repository': 'https://github.com/acme/orbit.git',
                    'base': 'main', 'commit': 'b' * 40}
        spec['source_template'] = template
        metadata = {'user.orbit.template.owner': 'orbit-task-template',
                    **{'user.orbit.template.' + key: value for key, value in template.items()}}
        host.template_image = {'type': 'virtual-machine', 'public': False,
                               'properties': {**metadata, 'user.orbit.template.role': 'operator'}}
        host.template_volume = {'content_type': 'filesystem', 'used_by': [], 'config': metadata.copy()}
        host.template_snapshot = copy.deepcopy(host.template_volume)
        digest = hashlib.sha256(json.dumps(template, sort_keys=True, separators=(',', ':')).encode()).hexdigest()
        host.rows[0]['config']['user.orbit.compute.template'] = digest
        host.storage_volumes[0]['config']['user.orbit.compute.template'] = digest
        return host, spec

    def test_template_retry_preserves_existing_group_volume(self):
        host, spec = self.templated()
        self.assertEqual('running', self.provision(host, spec)['power'])
        self.assertFalse(any(call[:3] == ('query', '-X', 'POST') for call in host.calls))

    def test_template_copy_assigns_group_ownership_in_the_create_request(self):
        host, spec = self.templated()
        host.rows, host.storage_volumes = [], []
        self.provision(host, spec)
        copies = [json.loads(call[5]) for call in host.calls if call[:3] == ('query', '-X', 'POST') and '/volumes/custom?' in call[3]]
        self.assertEqual(1, len(copies))
        self.assertEqual(host.metadata()['user.orbit.compute.id'], copies[0]['config']['user.orbit.compute.id'])
        self.assertEqual(host.name + '-worktree', copies[0]['name'])
        self.assertEqual('ready', copies[0]['source']['name'].split('/')[1])
        self.assertEqual('copy', copies[0]['source']['type'])
        self.assertEqual('proof', copies[0]['source']['pool'])
        self.assertEqual(host.project, copies[0]['source']['project'])
        self.assertEqual(64, len(copies[0]['config']['user.orbit.compute.template']))
        self.provision(host, spec)
        self.assertEqual(1, len([call for call in host.calls if call[:3] == ('query', '-X', 'POST') and '/volumes/custom?' in call[3]]))

    def test_template_mismatches_refuse_before_any_mutation(self):
        changes = [
            lambda h, s: h.template_image.update(public=True),
            lambda h, s: h.template_image['properties'].update({'user.orbit.template.role': 'gateway'}),
            lambda h, s: h.template_image['properties'].update({'user.orbit.template.commit': 'c' * 40}),
            lambda h, s: h.template_volume.update(used_by=['/1.0/instances/foreign']),
            lambda h, s: h.template_snapshot['config'].update({'user.orbit.template.id': ID}),
            lambda h, s: h.storage_volumes[0]['config'].pop('user.orbit.compute.template'),
            lambda h, s: h.rows[0]['config'].pop('user.orbit.compute.template'),
            lambda h, s: s['source_template'].update(base='../../foreign'),
            lambda h, s: s['source_template'].update(extra='not allowed'),
            lambda h, s: s.pop('source_template'),
        ]
        for index, change in enumerate(changes):
            with self.subTest(index=index):
                host, spec = self.templated()
                change(host, spec)
                with self.assertRaises((Refusal, ValueError)):
                    self.provision(host, spec)
                self.assertFalse(any(call[0] in ('start', 'stop') or call[:3] in (
                    ('network', 'acl', 'edit'), ('query', '-X', 'POST'), ('storage', 'volume', 'create')) for call in host.calls))

    def test_model_relay_allows_only_operator_to_its_own_bridge_port(self):
        host, spec = self.prepared()
        spec['model_proxy_origin'] = 'http://10.44.0.3:8317'
        host.rows[0]['config']['user.orbit.compute.model_proxy_origin'] = spec['model_proxy_origin']
        with patch.object(module['ModelRelay'], 'prepare') as prepare:
            self.assertEqual('running', self.provision(host, spec)['power'])
            prepare.assert_called_once_with(ipaddress.ip_network(spec['subnet']), spec['model_proxy_origin'])
        acl = next(json.loads(data) for args, data in host.payloads if args[:3] == ('network', 'acl', 'edit'))
        self.assertIn({'action': 'allow', 'source': '10.233.201.10', 'destination': '10.233.201.1',
                       'protocol': 'tcp', 'destination_port': '8317', 'state': 'enabled'}, acl['egress'])
        self.assertFalse(any('10.44.' in rule.get('destination', '') for rule in acl['egress']))
        spec['model_proxy_origin'] = 'http://10.44.0.4:8317'
        with self.assertRaises(Refusal):
            self.provision(host, spec)

    def test_model_relay_refuses_public_upstream_or_management_path(self):
        for origin in ['http://8.8.8.8:8317', 'http://10.44.0.3/v0/management', 'http://secret@10.44.0.3',
                       'http://10.44.0.3?key=secret', 'file:///etc/passwd', 'http://169.254.169.254']:
            with self.subTest(origin=origin), self.assertRaises(ValueError):
                module['model_relay_config']('10.233.201.1', '10.233.201.10', origin)

    def test_pi_proxy_is_bound_to_local_fleet_address_and_gateway_only_ingress(self):
        host, spec = self.prepared()
        spec.update(pi_host='10.44.0.7', pi_port=23001, gateway_address='10.44.0.1')
        interfaces = [{'addr_info': [{'local': '10.44.0.7', 'prefixlen': 16}]}]
        proxy = module['pi_proxy'](spec, ipaddress.ip_network(spec['subnet']), interfaces)
        host.rows[0]['devices']['pi'] = proxy
        with patch('subprocess.run') as process:
            process.return_value.stdout = json.dumps(interfaces).encode()
            self.assertEqual('running', host.provision(spec)['power'])
        self.assertEqual('tcp:10.44.0.7:23001', proxy['listen'])
        self.assertEqual('tcp:10.233.201.10:3774', proxy['connect'])
        self.assertEqual('true', proxy['nat'])
        acl = next(json.loads(data) for args, data in host.payloads if args[:3] == ('network', 'acl', 'edit'))
        self.assertIn({'action': 'allow', 'source': '10.44.0.1', 'destination': '10.233.201.10',
                       'protocol': 'tcp', 'destination_port': '3774', 'state': 'enabled'}, acl['ingress'])
        self.assertFalse(any('10.44.' in rule.get('destination', '') for rule in acl['egress']))

    def test_pi_proxy_refuses_public_wildcard_nonlocal_partial_or_low_port_configuration(self):
        subnet = ipaddress.ip_network('10.233.201.0/24')
        interfaces = [{'addr_info': [{'local': '10.44.0.7', 'prefixlen': 16}]}]
        good = {'pi_host': '10.44.0.7', 'pi_port': 23001, 'gateway_address': '10.44.0.1'}
        for changes in [{'pi_host': '0.0.0.0'}, {'pi_host': '10.44.0.8'}, {'gateway_address': '8.8.8.8'},
                        {'pi_port': 22}, {'pi_port': True}, {'pi_port': None}, {'gateway_address': None}]:
            with self.subTest(changes=changes), self.assertRaises((Refusal, ValueError)):
                module['pi_proxy']({**good, **changes}, subnet, interfaces)
        self.assertIsNone(module['pi_proxy']({}, subnet, interfaces))

    def test_provision_retries_a_create_that_completed_before_start_failed(self):
        host, spec = self.prepared()
        self.assertEqual('running', self.provision(host, spec)['power'])
        self.assertIn(('start', host.name + '-operator'), host.calls)
        self.assertFalse(any(call[:3] == ('query', '-X', 'POST') for call in host.calls))

    def test_provision_counts_recovery_starts_against_budget(self):
        host, spec = self.prepared()
        host.rows.extend({'name': 'other-' + str(i), 'status': 'Running'} for i in range(2))
        with self.assertRaisesRegex(Refusal, 'budget is full'):
            self.provision(host, spec)
        self.assertFalse(any(call[0] == 'start' or call[:3] == ('network', 'acl', 'edit') for call in host.calls))

    def test_provision_never_bypasses_a_parked_snapshot(self):
        host, spec = self.prepared()
        host.snapshots = ['/1.0/instances/' + host.name + '-operator/snapshots/parked?project=proof']
        with self.assertRaisesRegex(Refusal, 'must be resumed'):
            self.provision(host, spec)
        self.assertFalse(any(call[0] == 'start' for call in host.calls))

    def test_provision_refuses_weakened_network_policy(self):
        host, spec = self.prepared()
        host.network['config']['security.acls.default.egress.action'] = 'allow'
        with self.assertRaisesRegex(Refusal, 'network policy drifted'):
            self.provision(host, spec)
        self.assertFalse(any(call[:3] == ('network', 'acl', 'edit') for call in host.calls))

    def test_provision_refuses_foreign_subnet_overlap(self):
        host, spec = self.prepared()
        host.network = {'name': 'other-group', 'config': {'ipv4.address': '10.233.0.1/16'}}
        with self.assertRaisesRegex(Refusal, 'already allocated'):
            self.provision(host, spec)
        self.assertFalse(any(call[:3] == ('network', 'acl', 'edit') for call in host.calls))

    def test_provision_refuses_a_host_path_attached_to_the_guest(self):
        host, spec = self.prepared()
        host.rows[0]['devices']['host'] = {'type': 'disk', 'source': '/', 'path': '/host'}
        with self.assertRaisesRegex(Refusal, 'devices or profiles drifted'):
            self.provision(host, spec)
        self.assertFalse(any(call[0] == 'start' for call in host.calls))

    def test_missing_worktree_never_becomes_empty_replacement_data(self):
        host, spec = self.prepared()
        host.storage_volumes = []
        with self.assertRaisesRegex(Refusal, 'refusing to replace group data'):
            self.provision(host, spec)
        self.assertFalse(any(call[:3] == ('storage', 'volume', 'create') for call in host.calls))

    def test_foreign_worktree_attachment_refuses_destroy_before_deleting_owned_guest(self):
        host, spec = self.prepared()
        host.storage_volumes[0]['used_by'] = ['/1.0/instances/other-group?project=other']
        with self.assertRaisesRegex(Refusal, 'attached outside its group'):
            host.destroy()
        self.assertFalse(any(call[0] == 'delete' for call in host.calls))

    def test_pair_stops_before_shared_snapshot_and_restores_before_either_start(self):
        host, spec = self.prepared()
        first = host.rows[0]
        first['status'] = 'Running'
        host.rows.append({**first, 'name': host.name + '-gateway'})
        self.assertEqual('stopped', host.park()['power'])
        stops = [i for i, call in enumerate(host.calls) if call[0] == 'stop']
        snapshots = [i for i, call in enumerate(host.calls) if call[:2] == ('snapshot', 'create') or call[:4] == ('storage', 'volume', 'snapshot', 'create')]
        self.assertEqual(2, len(stops))
        self.assertEqual(3, len(snapshots))
        self.assertLess(max(stops), min(snapshots))
        host.calls = []
        self.assertEqual('running', host.resume()['power'])
        restores = [i for i, call in enumerate(host.calls) if call[:2] == ('snapshot', 'restore') or call[:4] == ('storage', 'volume', 'snapshot', 'restore')]
        starts = [i for i, call in enumerate(host.calls) if call[0] == 'start']
        self.assertEqual(3, len(restores))
        self.assertEqual(2, len(starts))
        self.assertLess(max(restores), min(starts))

    def test_public_egress_excludes_fleet_metadata_lan_host_and_other_groups(self):
        networks = list(map(ipaddress.ip_network, module['public_networks'](['93.184.216.34/32']).split(',')))
        for value in ['10.44.0.1', '169.254.169.254', '192.168.1.1', '127.0.0.1', '10.233.9.10', '93.184.216.34']:
            self.assertFalse(any(ipaddress.ip_address(value) in network for network in networks), value)
        self.assertTrue(any(ipaddress.ip_address('1.1.1.1') in network for network in networks))

    def test_bad_identity_never_calls_incus(self):
        with patch('subprocess.run') as process:
            for identity in ['../../x', '', ID.upper()]:
                with self.assertRaises((Refusal, ValueError)):
                    Host('orbit-sandbox-proof-test', identity, 2)
            process.assert_not_called()

    def test_foreign_named_instance_refuses_cleanup_before_any_mutation(self):
        host = FakeHost()
        host.rows = [{'name': host.name + '-operator', 'config': {}, 'type': 'virtual-machine'}]
        with self.assertRaises(Refusal):
            host.destroy()
        self.assertFalse(any(call[0] == 'delete' for call in host.calls))

    def test_foreign_network_refuses_cleanup_before_deleting_owned_guest(self):
        host = FakeHost()
        host.rows = [{'name': host.name + '-operator', 'config': host.metadata(), 'type': 'virtual-machine'}]
        host.network = {'name': host.name, 'config': {}}
        with self.assertRaises(Refusal):
            host.destroy()
        self.assertFalse(any(call[0] == 'delete' for call in host.calls))

    def test_cleanup_leaves_other_groups_untouched(self):
        host = FakeHost()
        foreign = {'name': 'ot-other-operator', 'config': {}, 'type': 'virtual-machine'}
        host.rows = [foreign, {'name': host.name + '-operator', 'config': host.metadata(), 'type': 'virtual-machine'}]
        self.assertEqual('destroyed', host.destroy()['power'])
        self.assertEqual([foreign], host.rows)

    def test_capacity_counts_starting_and_running_guests_but_frees_stopped_ones(self):
        host = FakeHost([{'status': value} for value in ['Running', 'Starting', 'Stopped']])
        self.assertEqual({'available': 0, 'used': 2, 'budget': 2}, host.capacity())

    def test_resume_refuses_over_budget_before_restoring_any_snapshot(self):
        host = FakeHost()
        host.rows = [{'name': 'other-' + str(i), 'status': 'Running'} for i in range(2)]
        host.rows.append({'name': host.name + '-operator', 'status': 'Stopped', 'type': 'virtual-machine', 'config': host.metadata()})
        with self.assertRaises(Refusal):
            host.resume()
        self.assertFalse(any(call[0] in ('snapshot', 'start') for call in host.calls))

    def test_guest_command_refuses_missing_stopped_or_foreign_vm(self):
        host, _ = self.prepared()
        command = {'role': 'operator', 'argv': ['id'], 'stdin': '', 'timeout': 5, 'max_output': 100}
        with patch.dict(Host.guest_command.__globals__, bounded_process=lambda *a: self.fail('No command may run')):
            with self.assertRaisesRegex(Refusal, 'not running'):
                host.guest_command(command)
            host.rows[0]['config'] = {}
            with self.assertRaisesRegex(Refusal, 'ownership'):
                host.guest_command(command)

    def test_guest_command_never_interprets_guest_argv_on_the_host(self):
        host, _ = self.prepared()
        host.rows[0]['status'] = 'Running'
        command = {'role': 'operator', 'argv': ['bash', '-c', 'echo $(whoami)'], 'stdin': '', 'timeout': 5, 'max_output': 100}
        guest = {'exit_code': 7, 'stdout': 'b3JiaXQ=', 'stderr': '', 'duration_ms': 1, 'truncated': False, 'timed_out': False}
        calls = []
        def run(argv, data, timeout, limit):
            calls.append(argv)
            self.assertEqual(command, json.loads(data))
            return {**guest, 'exit_code': 0, 'stdout': base64.b64encode(json.dumps(guest).encode()).decode()}
        with patch.dict(Host.guest_command.__globals__, bounded_process=run):
            result = host.guest_command(command)
        self.assertEqual(7, result['exit_code'])
        self.assertEqual(host.name, result['name'])
        self.assertEqual('operator', result['role'])
        self.assertEqual(['incus', '--force-local', '--project', host.project, 'exec', host.name + '-operator', '--mode=non-interactive'], calls[0][:7])
        self.assertNotIn('echo $(whoami)', calls[0])
        self.assertIn('orbit', calls[0])

    def test_guest_command_rejects_oversized_and_injected_envelopes(self):
        host, _ = self.prepared()
        good = {'role': 'operator', 'argv': ['id'], 'stdin': '', 'timeout': 5, 'max_output': 100}
        for changes in ({'role': 'host'}, {'argv': []}, {'timeout': 901}, {'max_output': 8388609},
                        {'host_command': 'id'}, {'argv': ['x\0y']}, {'stdin': '!invalid!'}):
            with self.subTest(changes=changes), self.assertRaises((Refusal, ValueError)):
                host.guest_command({**good, **changes})

    def test_process_bounds_stdout_stderr_and_preserves_nonzero_exit(self):
        result = module['bounded_process']([sys.executable, '-c', "import sys; print('x' * 100000); sys.stderr.write('y' * 100000); sys.exit(7)"], b'', 5, 100)
        self.assertEqual(7, result['exit_code'])
        self.assertTrue(result['truncated'])
        self.assertEqual(100, sum(len(base64.b64decode(result[k])) for k in ('stdout', 'stderr')))

    def test_process_times_out_even_when_descendant_keeps_output_open(self):
        result = module['bounded_process']([sys.executable, '-c', "import subprocess; subprocess.Popen(['sleep', '30'])"], b'', 1, 100)
        self.assertEqual(124, result['exit_code'])
        self.assertTrue(result['timed_out'])
        self.assertLess(result['duration_ms'], 4000)

    def test_process_transmits_binary_input_without_shell_expansion(self):
        incoming = b'\x00$(not-a-command)\xff'
        result = module['bounded_process']([sys.executable, '-c', 'import sys; sys.stdout.buffer.write(sys.stdin.buffer.read())'], incoming, 5, 100)
        self.assertEqual(incoming, base64.b64decode(result['stdout']))
        self.assertEqual(0, result['exit_code'])



class RelayFiles(unittest.TestCase):
    def test_owned_service_retries_and_cleanup_leave_no_group_files(self):
        with tempfile.TemporaryDirectory() as directory, patch('pwd.getpwuid', return_value=SimpleNamespace(pw_dir=directory, pw_gid=os.getegid())), patch('subprocess.run'):
            relay = module['ModelRelay']('ot-0a68f778a3', ID)
            subnet = ipaddress.ip_network('10.233.201.0/24')
            with patch.object(module['ModelRelay'], 'control') as control:
                relay.prepare(subnet, 'http://10.44.0.3:8317')
                before = (relay.root / 'caddy.json').read_bytes()
                relay.prepare(subnet, 'http://10.44.0.3:8317')
                self.assertEqual(before, (relay.root / 'caddy.json').read_bytes())
                self.assertEqual(0o600, relay.unit.stat().st_mode & 0o777)
                relay.destroy()
                relay.destroy()
                self.assertFalse(relay.root.exists())
                self.assertFalse(relay.unit.exists())
                self.assertIn(unittest.mock.call('disable', '--now', relay.unit_name), control.call_args_list)

    def test_foreign_units_and_drift_refuse_before_service_mutation(self):
        with tempfile.TemporaryDirectory() as directory, patch('pwd.getpwuid', return_value=SimpleNamespace(pw_dir=directory, pw_gid=os.getegid())), patch('subprocess.run'):
            relay = module['ModelRelay']('ot-0a68f778a3', ID)
            relay.unit.parent.mkdir(mode=0o700, parents=True)
            relay.unit.write_text('foreign unit')
            with patch.object(module['ModelRelay'], 'control') as control:
                with self.assertRaises(ValueError):
                    relay.prepare(ipaddress.ip_network('10.233.201.0/24'), 'http://10.44.0.3:8317')
                control.assert_not_called()
                self.assertEqual('foreign unit', relay.unit.read_text())
                relay.unit.unlink()
                relay.prepare(ipaddress.ip_network('10.233.201.0/24'), 'http://10.44.0.3:8317')
                control.reset_mock()
                (relay.root / 'caddy.json').write_text('changed')
                with self.assertRaises(ValueError):
                    relay.destroy()
                control.assert_not_called()
                self.assertTrue(relay.unit.exists())


unittest.main()
