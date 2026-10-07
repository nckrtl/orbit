import copy
import json
from pathlib import Path
import runpy
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
import uuid
from unittest.mock import patch

module = runpy.run_path(sys.argv.pop(1))
Builder, Refusal = module['Builder'], module['Refusal']
guest = runpy.run_path(str(Path(module['__file__']).with_name('guest-template-install.py')))
locked = runpy.run_path(str(Path(module['__file__']).with_name('template-lock.py')))['locked']


def request():
    projects = {name: {'composer_lock_sha256': 'a' * 64, **({} if name == '.' else {'tia_sha256': 'b' * 64})} for name in guest['PHP_PROJECTS']}
    projects.update({name: {'bun_lock_sha256': 'c' * 64} for name in guest['JS_PROJECTS']})
    descriptor = lambda name: {'file': name, 'sha256': 'd' * 64}
    return {'project': 'orbit-sandbox-proof-tests', 'pool': 'proof', 'sandbox_id': str(uuid.uuid4()), 'budget': 2,
            'subnet': '10.233.210.0/24', 'blocked_networks': ['10.44.0.0/16', '192.168.0.0/16'], 'base_image': 'e' * 64,
            'inputs': {'root': '/private-inputs', 'packages': [descriptor('package.deb')], 'tools': descriptor('tools.tar.gz'),
                       'source': descriptor('source.tar.gz'), 'composer': descriptor('composer.phar')},
            'source_manifest': {'source_template': {'id': str(uuid.uuid4()), 'repository': 'https://github.com/example/orbit.git',
                                'base': 'main', 'commit': 'f' * 40}, 'ci_run': 42, 'projects': projects, 'sha256': 'd' * 64}}


class FakeBuilder(Builder):
    def __init__(self, workload_roles=None):
        value = request()
        if workload_roles is not None:
            value.update(workload_roles=workload_roles, budget=2 + len(workload_roles))
        super().__init__(value)
        self.calls, self.instances, self.volumes = [], [], []
        self.fail_install = False
        self.fail_health = False

    def query(self, path, method='GET', data=None):
        self.calls.append(('read', path))
        if path == '/1.0/images?recursion=1':
            return []
        if path == '/1.0/images/' + self.build['base_image']:
            return {'type': 'virtual-machine', 'architecture': 'x86_64'}
        if path == '/1.0/instances?recursion=1':
            return copy.deepcopy(self.instances)
        if path.startswith('/1.0/instances/'):
            return copy.deepcopy(next(value for value in self.instances if path.endswith('/' + value['name'])))
        if path.endswith('/volumes/custom?recursion=1'):
            return copy.deepcopy(self.volumes)
        if path == self.volume_path(self.name + '-worktree'):
            return copy.deepcopy(self.volumes[0])
        raise AssertionError(path)

    def provision(self, args, **kwargs):
        self.calls.append(('allocate', args))
        assert Path(args[1]).is_file() and args[1].endswith('/apps/agent/resources/incus-sandbox.py')
        value = json.loads(kwargs['input'])
        assert value['spec']['images'] == dict.fromkeys(('operator', 'gateway', *self.build.get('workload_roles', [])), self.build['base_image'])
        owner = {'user.orbit.compute.owner': 'orbit-task-sandbox', 'user.orbit.compute.id': self.request['sandbox_id']}
        for role in ('operator', 'gateway', *self.build.get('workload_roles', [])):
            self.instances.append({'name': self.name + '-' + role, 'type': 'virtual-machine', 'status': 'Running', 'profiles': [],
                                   'config': {**owner, 'volatile.base_image': self.build['base_image']}, 'expanded_devices': {
                                       'root': {'type': 'disk', 'pool': self.pool, 'path': '/'},
                                       'eth0': {'type': 'nic', 'network': self.name},
                                       'worktree': {'type': 'disk', 'pool': self.pool, 'source': self.name + '-worktree', 'path': '/home/orbit/orbit'}}})
        self.volumes = [{'name': self.name + '-worktree', 'config': owner, 'content_type': 'filesystem',
                         'used_by': ['/1.0/instances/' + row['name'] + '?project=' + self.project for row in self.instances]}]
        return SimpleNamespace(returncode=0, stdout=json.dumps({'power': 'running'}))

    def run(self, *args, data=None, timeout=1200):
        self.calls.append(args)
        if args[0] == 'query':
            return json.dumps({'config': {'user.orbit.compute.owner': 'orbit-task-sandbox', 'features.networks': 'false'}} if '/projects/' in args[1] else [])
        if args[:2] == ('config', 'set'):
            key, value = args[3].split('=', 1)
            next(row for row in self.instances if row['name'] == args[2])['config'][key] = value
        elif args[:3] == ('storage', 'volume', 'set'):
            key, value = args[5].split('=', 1)
            self.volumes[0]['config'][key] = value
        elif args[:3] == ('config', 'template', 'show'):
            return '127.0.0.1 localhost\n'
        elif args[0] == 'exec' and args[-1] == '/root/orbit-template-inputs/guest-template-install.py':
            if self.fail_install:
                raise Refusal('Guest preparation failed')
            return json.dumps({'prepared': True, 'role': json.loads(data)['role'], 'source_template': self.template})
        elif args[0] == 'exec' and args[-1] == '/home/orbit/.orbit/ssh/id_ed25519.pub':
            return 'ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAIFixture'
        return ''

    def guest(self, role, script, data=None, user='root'):
        self.calls.append(('guest', role, user))
        if 'sandbox_template_native_health_failed' in script:
            return {'ready': not self.fail_health, 'head': self.template['commit'], 'gateway_version': self.template['commit']}
        return {'head': self.template['commit'], 'source_template': self.template, 'ready': True, 'role': role}

    def apply(self):
        with patch.dict(self.new_candidate.__globals__, verify_inputs=lambda _: {'verified': True}), patch('subprocess.run', self.provision):
            return self.prepare()


class BuilderTest(unittest.TestCase):
    def test_plan_reads_without_allocating_and_requires_new_resources(self):
        builder = FakeBuilder()
        with patch.dict(builder.new_candidate.__globals__, verify_inputs=lambda _: {'verified': True}):
            self.assertEqual(builder.name, builder.new_candidate()['candidate'])
            self.assertFalse(any(call[0] == 'allocate' for call in builder.calls))
            builder.instances = [{'name': builder.name + '-operator', 'config': {}}]
            with self.assertRaises(Refusal):
                builder.new_candidate()

    def test_invalid_inputs_never_reach_the_allocator(self):
        builder = FakeBuilder()
        def refuse(_):
            raise ValueError('Input hash mismatch')
        with patch.dict(builder.new_candidate.__globals__, verify_inputs=refuse), patch('subprocess.run') as allocate:
            with self.assertRaises(ValueError):
                builder.prepare()
            allocate.assert_not_called()
        self.assertEqual([], builder.calls)

    def test_prepare_uses_production_allocator_and_records_complete_pair(self):
        builder = FakeBuilder()
        result = builder.apply()
        self.assertTrue(result['prepared'])
        self.assertFalse(result['native_bootstrap'])
        self.assertEqual(1, sum(call[0] == 'allocate' for call in builder.calls))
        builder.prepared()
        self.assertEqual(2, len(builder.instances))
        self.assertTrue(all(value['config']['user.orbit.template.prepared'] == builder.prepared_digest for value in builder.instances))

    def test_failed_guest_is_retained_without_a_completion_receipt(self):
        builder = FakeBuilder()
        builder.fail_install = True
        with self.assertRaises(Refusal):
            builder.apply()
        self.assertEqual(2, len(builder.instances))
        self.assertTrue(all('user.orbit.template.prepared' not in value['config'] for value in builder.instances))
        self.assertFalse(any(call[0] == 'delete' for call in builder.calls))

    def test_convergence_requires_receipt_then_checks_real_pair_contract(self):
        builder = FakeBuilder()
        builder.apply()
        self.assertTrue(builder.converge()['converged'])
        self.assertEqual(builder.prepared_digest, builder.volumes[0]['config']['user.orbit.template.ready'])
        builder.volumes[0]['config']['user.orbit.template.prepared'] = 'changed'
        before = len(builder.calls)
        with self.assertRaises(Refusal):
            builder.converge()
        self.assertFalse(any(call[0] == 'exec' for call in builder.calls[before:]))

    def test_native_failure_does_not_mark_the_candidate_ready(self):
        builder = FakeBuilder()
        builder.apply()
        builder.fail_health = True
        with self.assertRaises(Refusal):
            builder.converge()
        self.assertTrue(all('user.orbit.template.ready' not in value['config'] for value in builder.instances))

    def test_workload_images_are_prepared_with_the_pair_and_never_enrolled_during_convergence(self):
        builder = FakeBuilder(['app-dev', 'app-prod', 'app-prod-2'])
        self.assertTrue(builder.apply()['prepared'])
        self.assertEqual(5, len(builder.instances))
        self.assertTrue(builder.converge()['converged'])
        self.assertTrue(all(row['config']['user.orbit.template.ready'] == builder.prepared_digest for row in builder.instances))
        native = [call for call in builder.calls if call[0] == 'exec' and any(isinstance(argument, str) and argument.endswith('/converge-operator.sh') for argument in call)]
        self.assertEqual(1, len(native))
        self.assertFalse(any('orbit:node-provision' in call for call in builder.calls))
        builder.instances[-1]['config']['user.orbit.template.prepared'] = 'foreign'
        with self.assertRaises(Refusal):
            builder.converge()

    def test_workload_roles_and_budget_are_closed_before_allocating(self):
        for roles in (None, 'app-dev', ['gateway'], ['app-dev', 'app-dev'], ['app-prod', 'app-dev'], [True]):
            value = request()
            value['workload_roles'] = roles
            with self.subTest(roles=roles), self.assertRaises(ValueError):
                Builder(value)
        value = request()
        value.update(workload_roles=['app-dev'], budget=2)
        with self.assertRaises(ValueError):
            Builder(value)

    def test_closed_schema_and_complete_provenance_are_required(self):
        for mutate in (lambda r: r.update(extra=True), lambda r: r.update(project='orbit-task-sandboxes'),
                       lambda r: r.update(subnet='10.44.0.0/24'), lambda r: r.update(budget=True),
                       lambda r: r['source_manifest']['projects'].pop('apps/gateway'),
                       lambda r: r['source_manifest'].update(sha256='0' * 64)):
            value = request()
            mutate(value)
            with self.assertRaises((ValueError, Refusal)):
                Builder(value)

    def test_package_failure_removes_only_its_temporary_service_policy(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            policy = root / 'policy'
            with patch.dict(guest['packages'].__globals__, ROOT=root), patch('subprocess.run', side_effect=subprocess.CalledProcessError(1, ['apt-get'])) as run:
                with self.assertRaises(subprocess.CalledProcessError):
                    guest['packages'](request(), policy)
                self.assertFalse(policy.exists())
                args = run.call_args.args[0]
                self.assertIn('--no-remove', args)
                self.assertIn('Dir::State::lists=' + str(root / 'empty-lists'), args)
            policy.write_text('foreign policy')
            with self.assertRaises(FileExistsError):
                guest['packages'](request(), policy)
            self.assertEqual('foreign policy', policy.read_text())

    def test_pair_and_template_locks_exclude_concurrent_operations(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with locked({'ot-1234567890', 'ot-template-1234567890'}, root):
                with self.assertRaises(BlockingIOError):
                    with locked({'ot-1234567890'}, root):
                        self.fail('Concurrent operation admitted')
            with locked({'ot-1234567890'}, root):
                pass
            next(root.iterdir()).chmod(0o666)
            with self.assertRaises(ValueError):
                with locked({'ot-1234567890', 'ot-template-1234567890'}, root):
                    self.fail('Unsafe lock admitted')


if __name__ == '__main__':
    unittest.main()
