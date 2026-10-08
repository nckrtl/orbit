"""Real Git, SQLite and guest SSH keys; isolate native fleet and service mutations."""
from contextlib import closing
import copy
import json
from pathlib import Path
import runpy
import sqlite3
import subprocess
import sys
import tempfile
import unittest
import uuid
from unittest.mock import patch

module = runpy.run_path(sys.argv.pop(1))
prepare, original_run = module['prepare'], module['run']


class WorkloadRuntime(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='orbit-workload-runtime-')
        self.home = Path(self.scratch.name)
        self.root = self.home / 'orbit'
        self.root.mkdir()
        self.git('init', '-q', '-b', 'main')
        (self.root / 'branch.txt').write_text('branch source')
        self.git('add', '.')
        self.git('-c', 'user.name=Proof', '-c', 'user.email=proof@example.invalid', 'commit', '-qm', 'seed')
        self.head = self.git('rev-parse', 'HEAD')
        self.git('switch', '-qc', 'task-1')
        self.id = str(uuid.uuid4())
        self.template = {'id': str(uuid.uuid4()), 'repository': 'https://github.com/example/orbit.git', 'base': 'main', 'commit': self.head}
        (self.root / '.git/orbit-sandbox-source.json').write_text(json.dumps({'sandbox_id': self.id, 'branch': 'task-1', 'source_template': self.template}))
        (self.home / '.orbit/ssh').mkdir(parents=True)
        self.gateway_key = self.home / '.orbit/ssh/id_ed25519'
        self.keygen(self.gateway_key)
        self.public_key = subprocess.run(['ssh-keygen', '-y', '-f', str(self.gateway_key)], capture_output=True, text=True, check=True).stdout.strip()
        self.public_key = ' '.join(self.public_key.split()[:2])
        (self.home / '.orbit/config.json').write_text(json.dumps({'active_gateway': 'test', 'gateways': {'test': {'url': 'https://10.44.0.1'}}}))
        self.database = self.home / '.orbit/gateway.sqlite'
        with closing(sqlite3.connect(self.database)) as db, db:
            db.executescript('CREATE TABLE nodes(id INTEGER PRIMARY KEY, name TEXT, status TEXT, user TEXT, architecture TEXT, public_ssh_host TEXT, wireguard_ip TEXT, ssh_host_fingerprint TEXT); CREATE TABLE node_roles(node_id INTEGER, role TEXT, status TEXT);')
            db.executemany('INSERT INTO nodes VALUES(?,?,?,?,?,?,?,?)', [(1, 'gateway', 'active', 'orbit', 'x86_64', '10.233.7.11', '10.44.0.1', None), (2, 'operator', 'active', 'orbit', 'x86_64', '10.233.7.10', '10.44.0.3', None)])
            db.executemany('INSERT INTO node_roles VALUES(?,?,?)', [(1, 'gateway', 'active'), (1, 'vpn', 'active')])
        self.system = self.home / 'system'
        (self.system / 'etc/ssh/sshd_config.d').mkdir(parents=True)
        self.workload_home = self.home / 'workload'
        self.workload_home.mkdir()
        self.calls = []
        self.fail_role = None
        self.incomplete = False
        self.identities = [{'role': name, 'architecture': 'x86_64', 'fingerprint': 'SHA256:' + 'a' * 43} for name in ('app-dev', 'app-prod')]

    def tearDown(self):
        self.scratch.cleanup()

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.root), *args], check=True, capture_output=True, text=True).stdout.strip()

    def keygen(self, path):
        subprocess.run(['ssh-keygen', '-q', '-t', 'ed25519', '-N', '', '-f', str(path)], check=True, capture_output=True)

    def fake_run(self, arguments, root, home, timeout=120):
        if arguments[0] in ('git', 'ssh-keygen', 'uname'):
            return original_run(arguments, root, home, timeout)
        self.calls.append(arguments)
        if arguments[:3] == ['sudo', '-n', 'install'] or arguments[:3] == ['sudo', '-n', 'ssh-keygen']:
            return original_run(arguments[2:], root, home, timeout)
        if arguments[:2] == ['sudo', '-n']:
            return ''
        if 'orbit:node-provision' in arguments:
            name = arguments[3]
            if self.fail_role == name:
                raise subprocess.CalledProcessError(1, arguments, output='private-native-failure')
            role = next(arg[7:] for arg in arguments if arg.startswith('--role='))
            fingerprint = next(arg[23:] for arg in arguments if arg.startswith('--host-key-fingerprint='))
            # This is the externally visible result of native node provisioning.
            with closing(sqlite3.connect(self.database)) as db, db:
                row = db.execute('SELECT id FROM nodes WHERE name=?', [name]).fetchone()
                if row:
                    node_id = row[0]
                    db.execute('UPDATE nodes SET status=?, architecture=?, ssh_host_fingerprint=? WHERE id=?', ['failed' if self.incomplete else 'active', 'x86_64', fingerprint, node_id])
                else:
                    node_id = 3 if name == 'app-dev' else 4
                    db.execute('INSERT INTO nodes VALUES(?,?,?,?,?,?,?,?)', [node_id, name, 'failed' if self.incomplete else 'active', 'orbit', 'x86_64', arguments[4], module['VPN'][name], fingerprint])
                db.execute('DELETE FROM node_roles WHERE node_id=?', [node_id])
                db.execute('INSERT INTO node_roles VALUES(?,?,?)', [node_id, role, 'failed' if self.incomplete else 'active'])
            return 'Node is active'
        if 'node:access:add' in arguments:
            return '{}'
        raise AssertionError(arguments)

    def request(self, phase, **changes):
        return {'sandbox_id': self.id, 'branch': 'task-1', 'head': self.head, 'phase': phase,
                'inventory': ['gateway', 'operator', 'app-dev', 'app-prod'], 'source_template': self.template, 'subnet': '10.233.7.0/24', **({'role': 'app-dev'} if phase == 'enroll' else {}), **changes}

    def call(self, phase, home=None, **changes):
        with patch.dict(prepare.__globals__, {'run': self.fake_run}):
            return prepare(self.request(phase, **changes), self.root, home or self.home, self.system)

    def join(self, identities):
        enrolled = []
        for role in ('app-dev', 'app-prod'):
            enrolled.extend(self.call('enroll', role=role, identities=identities)['enrolled_roles'])
        return {'enrolled_roles': enrolled}

    def test_gateway_identity_is_read_only_and_only_returns_the_public_key(self):
        before = self.database.read_bytes()
        result = self.call('gateway-identity')
        self.assertEqual(self.public_key, result['gateway_public_key'])
        self.assertTrue(result['ready'])
        self.assertEqual(before, self.database.read_bytes())
        self.assertEqual([], self.calls)

    def test_each_clone_gets_a_fresh_key_and_retries_preserve_it_after_enrollment(self):
        host_key = self.system / 'etc/ssh/ssh_host_ed25519_key'
        self.keygen(host_key)
        old_key = host_key.read_bytes()
        first = self.call('workload-identity', home=self.workload_home, role='app-dev', gateway_public_key=self.public_key)
        self.assertNotEqual(old_key, host_key.read_bytes())
        self.assertEqual(self.public_key + '\n', (self.workload_home / '.ssh/authorized_keys').read_text())
        self.assertEqual(0o600, (self.workload_home / '.ssh/authorized_keys').stat().st_mode & 0o777)
        (self.system / 'etc/wireguard').mkdir()
        (self.system / 'etc/wireguard/orbit.conf').write_text('enrolled-private-key')
        before = host_key.read_bytes()
        self.calls = []
        second = self.call('workload-identity', home=self.workload_home, role='app-dev', gateway_public_key=self.public_key)
        self.assertEqual(first, second)
        self.assertEqual(before, host_key.read_bytes())
        self.assertFalse(any('install' in call or 'systemctl' in call for call in self.calls))
        self.assertEqual('enrolled-private-key', (self.system / 'etc/wireguard/orbit.conf').read_text())

    def test_foreign_source_or_guest_identity_is_refused_before_native_mutation(self):
        self.call('workload-identity', home=self.workload_home, role='app-dev', gateway_public_key=self.public_key)
        self.calls = []
        with self.assertRaisesRegex(ValueError, 'another sandbox'):
            self.call('workload-identity', home=self.workload_home, role='app-prod', gateway_public_key=self.public_key)
        with self.assertRaisesRegex(ValueError, 'another group'):
            self.call('gateway-identity', sandbox_id=str(uuid.uuid4()))
        self.assertEqual([], self.calls)

    def test_an_enrolled_or_foreign_role_image_is_not_silently_reset(self):
        (self.workload_home / '.orbit').mkdir()
        (self.workload_home / '.orbit/config.json').write_text('foreign')
        with self.assertRaisesRegex(ValueError, 'enrollment state'):
            self.call('workload-identity', home=self.workload_home, role='app-dev', gateway_public_key=self.public_key)
        self.assertEqual([], self.calls)
        self.assertEqual('foreign', (self.workload_home / '.orbit/config.json').read_text())

    def test_host_key_drift_is_refused_instead_of_regenerating_identity(self):
        self.call('workload-identity', home=self.workload_home, role='app-dev', gateway_public_key=self.public_key)
        (self.system / 'etc/ssh/ssh_host_ed25519_key.pub').write_text('foreign')
        self.calls = []
        with self.assertRaisesRegex(ValueError, 'host key changed'):
            self.call('workload-identity', home=self.workload_home, role='app-dev', gateway_public_key=self.public_key)
        self.assertEqual([], self.calls)

    def test_partial_native_failure_retries_the_same_identities_without_reprovisioning_active_nodes(self):
        self.fail_role = 'app-prod'
        with self.assertRaises(subprocess.CalledProcessError):
            self.join(self.identities)
        with closing(sqlite3.connect(self.database)) as db, db:
            self.assertEqual('active', db.execute('SELECT status FROM nodes WHERE name="app-dev"').fetchone()[0])
        self.calls = []
        self.fail_role = None
        result = self.join(self.identities)
        self.assertEqual(['app-dev', 'app-prod'], result['enrolled_roles'])
        provisions = [call for call in self.calls if 'orbit:node-provision' in call]
        self.assertEqual(['app-prod'], [call[3] for call in provisions])
        self.assertIn('--host-key-fingerprint=SHA256:' + 'a' * 43, provisions[0])
        grants = [call for call in self.calls if 'node:access:add' in call]
        self.assertEqual([['2', '3'], ['2', '4']], [call[2:4] for call in grants])
        self.calls = []
        self.join(self.identities)
        self.assertFalse(any('orbit:node-provision' in call for call in self.calls))

    def test_incomplete_native_result_never_grants_access_or_claims_readiness(self):
        self.incomplete = True
        with self.assertRaisesRegex(ValueError, 'did not confirm'):
            self.join(self.identities)
        self.assertFalse(any('node:access:add' in call for call in self.calls))

    def test_changed_pinned_host_identity_is_refused_before_provision_or_access(self):
        self.join(self.identities)
        identities = copy.deepcopy(self.identities)
        identities[1]['fingerprint'] = 'SHA256:' + 'b' * 43
        self.calls = []
        with self.assertRaisesRegex(ValueError, 'different pinned host identity'):
            self.join(identities)
        self.assertEqual([], self.calls)

    def test_foreign_inventory_or_live_profile_is_refused_before_native_mutation(self):
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute('INSERT INTO nodes SELECT 9, "foreign", status, user, architecture, public_ssh_host, wireguard_ip, ssh_host_fingerprint FROM nodes WHERE id=2')
        with self.assertRaisesRegex(ValueError, 'foreign inventory'):
            self.call('gateway-identity')
        with closing(sqlite3.connect(self.database)) as db, db:
            db.execute('DELETE FROM nodes WHERE id=9')
        (self.home / '.orbit/config.json').write_text(json.dumps({'active_gateway': 'live', 'gateways': {'live': {'url': 'https://10.44.0.20'}}}))
        with self.assertRaisesRegex(ValueError, 'not private'):
            self.join(self.identities)
        self.assertEqual([], self.calls)

    def test_second_production_node_uses_its_own_private_identity_and_the_native_production_role(self):
        identity = {'role': 'app-prod-2', 'architecture': 'x86_64', 'fingerprint': 'SHA256:' + 'a' * 43}
        result = self.call('enroll', role='app-prod-2', inventory=['gateway', 'operator', 'app-prod-2'], identities=[identity])
        self.assertEqual(['app-prod-2'], result['enrolled_roles'])
        provision = next(call for call in self.calls if 'orbit:node-provision' in call)
        self.assertEqual(['app-prod-2', '10.233.7.14'], provision[3:5])
        self.assertIn('--role=app-prod', provision)
        self.assertIn('--wireguard-ip=10.44.0.5', provision)

    def test_changed_network_identity_or_operator_roles_are_refused(self):
        for sql in ('UPDATE nodes SET public_ssh_host="10.44.0.20" WHERE id=2', 'INSERT INTO node_roles VALUES(2,"app-dev","active")'):
            with closing(sqlite3.connect(self.database)) as db, db:
                db.execute(sql)
            with self.assertRaises(ValueError):
                self.call('gateway-identity')
            self.assertEqual([], self.calls)

    def test_identity_list_and_inventory_are_closed_before_enrollment(self):
        for changes in ({'inventory': ['gateway', 'operator', 'app-dev', 'foreign']},
                        {'inventory': ['gateway', 'operator', 'app-dev', 'app-dev']},
                        {'identities': self.identities[::-1]},
                        {'identities': [{**value, 'private_key': 'forbidden'} for value in self.identities]},
                        {'source_template': {**self.template, 'commit': 'b' * 40}}):
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                self.call('enroll', **({'identities': self.identities} | changes))
            self.assertEqual([], self.calls)


if __name__ == '__main__':
    unittest.main()
