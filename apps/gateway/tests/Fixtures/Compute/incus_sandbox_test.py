"""Contract checks for ownership and capacity without touching the host daemon."""
import base64
import ipaddress
import json
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
        super().__init__('orbit-sandbox-proof-test', ID, 2)

    def run(self, *args, data=None, timeout=180):
        self.calls.append(args)
        if args[:1] == ('query',) and args[1].startswith('/1.0/projects/'):
            return json.dumps({'config': {'user.orbit.compute.owner': module['OWNER'], 'features.networks': 'false'}}).encode()
        if args[:1] == ('query',) and args[1].startswith('/1.0/images/'):
            return b'{"type":"virtual-machine"}'
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



unittest.main()
