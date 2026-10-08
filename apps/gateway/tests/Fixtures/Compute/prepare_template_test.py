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
secure_sources = runpy.run_path(str(Path(module['__file__']).with_name('guest-template-package-sources.py')))['secure_sources']
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
        self.fail_sources = False
        self.fail_agent = False
        self.fail_dns = False

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
        if self.fail_agent and any(isinstance(arg, str) and 'NodeAgentRuntime' in arg for arg in args):
            raise Refusal('Native Gateway agent convergence failed')
        if self.fail_dns and args[-4:] == ('systemctl', 'enable', '--now', 'dnsmasq'):
            raise Refusal('Native VPN DNS enable failed')
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
        if 'sandbox_template_package_sources_failed' in script:
            self.calls.append(('package-sources', role))
            return {'sources_https': not self.fail_sources}
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

    def test_unsafe_sources_prevent_native_convergence_and_readiness(self):
        builder = FakeBuilder()
        builder.apply()
        builder.fail_sources = True
        before = len(builder.calls)
        with self.assertRaises(Refusal):
            builder.converge()
        self.assertFalse(any(call[0] == 'exec' for call in builder.calls[before:]))
        self.assertTrue(all('user.orbit.template.ready' not in value['config'] for value in builder.instances))

    def test_standard_archives_use_https_and_other_source_state_is_preserved_on_retry(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            (root / 'sources.list.d').mkdir()
            legacy = root / 'sources.list'
            legacy.write_text('# http://archive.ubuntu.com/ubuntu stays a comment\ndeb http://archive.ubuntu.com/ubuntu resolute main\ndeb http://custom.example/ubuntu resolute main\n')
            modern = root / 'sources.list.d/ubuntu.sources'
            modern.write_text('Types: deb\nURIs: http://security.ubuntu.com/ubuntu/\nSuites: resolute-security\nComponents: main universe\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n')
            modern.chmod(0o600)
            self.assertEqual({'sources_https': True, 'changed_files': 2}, secure_sources(root))
            self.assertEqual('# http://archive.ubuntu.com/ubuntu stays a comment\ndeb https://archive.ubuntu.com/ubuntu resolute main\ndeb http://custom.example/ubuntu resolute main\n', legacy.read_text())
            self.assertEqual('Types: deb\nURIs: https://security.ubuntu.com/ubuntu/\nSuites: resolute-security\nComponents: main universe\nSigned-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg\n', modern.read_text())
            self.assertEqual(0o600, modern.stat().st_mode & 0o777)
            self.assertEqual({'sources_https': True, 'changed_files': 0}, secure_sources(root))

    def test_failed_atomic_replace_preserves_the_source_and_removes_temporary_files(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            source = root / 'sources.list'
            original = 'deb http://archive.ubuntu.com/ubuntu resolute main\n'
            source.write_text(original)
            with patch('os.replace', side_effect=OSError('Failed replacement')), self.assertRaises(OSError):
                secure_sources(root)
            self.assertEqual(original, source.read_text())
            self.assertEqual([source], list(root.iterdir()))

    def test_symlinked_or_writable_sources_refuse_before_changing_other_sources(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            sources = root / 'sources.list.d'
            sources.mkdir()
            legacy = root / 'sources.list'
            original = 'deb http://archive.ubuntu.com/ubuntu resolute main\n'
            legacy.write_text(original)
            foreign = root / 'foreign'
            foreign.write_text(original)
            unsafe = sources / 'unsafe.sources'
            unsafe.symlink_to(foreign)
            with self.assertRaises(ValueError):
                secure_sources(root)
            self.assertEqual(original, legacy.read_text())
            self.assertEqual(original, foreign.read_text())
            unsafe.unlink()
            unsafe.write_text(original)
            unsafe.chmod(0o666)
            with self.assertRaises(ValueError):
                secure_sources(root)
            self.assertEqual(original, legacy.read_text())
            unsafe.unlink()
            sources.rmdir()
            sources.symlink_to(root)
            with self.assertRaises(ValueError):
                secure_sources(root)
            self.assertEqual(original, legacy.read_text())

    def test_gateway_agent_failure_does_not_mark_the_candidate_ready(self):
        builder = FakeBuilder()
        builder.apply()
        builder.fail_agent = True
        with self.assertRaises(Refusal):
            builder.converge()
        self.assertTrue(all('user.orbit.template.ready' not in value['config'] for value in builder.instances))

    def test_cold_pair_enables_native_vpn_dns_and_failure_refuses_publication_readiness(self):
        builder = FakeBuilder()
        builder.apply()
        builder.converge()
        self.assertTrue(any(call[-4:] == ('systemctl', 'enable', '--now', 'dnsmasq') for call in builder.calls))
        builder = FakeBuilder()
        builder.apply()
        builder.fail_dns = True
        with self.assertRaises(Refusal):
            builder.converge()
        self.assertTrue(all('user.orbit.template.ready' not in value['config'] for value in builder.instances))

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
        self.assertEqual(set(builder.roles), {call[1] for call in builder.calls if call[0] == 'package-sources'})
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

    def runtime_fixture(self, root):
        home, system = root / 'home', root / 'system'
        home.mkdir()
        for relative, text in {
            'opt/orbit-image/node/bin/node': '#!/bin/sh\nprintf "node:%s\\n" "$*"\n',
            'opt/orbit-image/vp/bin/vp': '#!/bin/sh\nprintf "vp:%s\\n" "$*"\n',
            'opt/orbit-image/pnpm/package.json': '{"name":"pnpm","version":"10.33.0"}',
            'opt/orbit-image/pnpm/bin/pnpm.cjs': 'pinned pnpm',
            'usr/local/bin/bun': '#!/bin/sh\nprintf "bun:%s\\n" "$*"\n',
            'usr/local/bin/orbit-agent': 'agent',
            'usr/local/bin/orbit-pi-server': 'pi',
        }.items():
            path = system / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_text(text)
            path.chmod(0o755)
        def bootstrap(*args, **kwargs):
            if args[0] == 'chown':
                return
            if 'env' in args and 'setup' in args:
                vp = home / '.local/share/vite-plus'
                for binary in ('node', 'npm', 'npx'):
                    path = vp / 'bin' / binary
                    path.write_text('#!/bin/sh\nprintf "' + binary + ':%s\\n" "$*"\n')
                    path.chmod(0o755)
            elif 'env' not in args:
                subprocess.run([args[-2], args[-1]], check=True, capture_output=True)
        return home, system, bootstrap

    def test_offline_runtime_has_the_native_entry_points_and_pinned_pnpm_without_downloads(self):
        with tempfile.TemporaryDirectory() as directory:
            home, system, bootstrap = self.runtime_fixture(Path(directory))
            with patch.dict(guest['install_tool_runtime'].__globals__, command=bootstrap):
                guest['install_tool_runtime'](home, system, __import__('os').getuid(), __import__('os').getgid())
            for binary in ('vp', 'node', 'npm', 'npx', 'bun'):
                result = subprocess.run([str(system / 'usr/local/bin' / binary), '--version'], capture_output=True, text=True, check=True)
                self.assertEqual(binary + ':--version\n', result.stdout)
            result = subprocess.run([str(system / 'usr/local/bin/pnpm'), '--version'], capture_output=True, text=True, check=True)
            self.assertEqual('node:' + str(home / '.local/share/vite-plus/package_manager/pnpm/10.33.0/pnpm/bin/pnpm.cjs') + ' --version\n', result.stdout)
            with patch.dict(guest['install_tool_runtime'].__globals__, command=bootstrap), self.assertRaises(ValueError):
                guest['install_tool_runtime'](home, system, __import__('os').getuid(), __import__('os').getgid())

    def test_runtime_audit_uses_managed_home_when_invoked_from_a_root_directory(self):
        checker = runpy.run_path(str(Path(module['__file__']).with_name('guest-template-audit.py')))['tool_runtime_prerequisites']
        with tempfile.TemporaryDirectory() as directory:
            home, system, bootstrap = self.runtime_fixture(Path(directory))
            with patch.dict(guest['install_tool_runtime'].__globals__, command=bootstrap):
                guest['install_tool_runtime'](home, system, __import__('os').getuid(), __import__('os').getgid())
            actual_lstat, actual_run = Path.lstat, subprocess.run
            def owned_launchers(path, *args, **kwargs):
                details = actual_lstat(path, *args, **kwargs)
                if path.parent == system / 'usr/local/bin':
                    fields = list(details)
                    fields[4] = 0
                    return __import__('os').stat_result(fields)
                return details
            def managed_run(args, **kwargs):
                if kwargs.get('cwd') != home:
                    raise PermissionError('The managed user cannot resolve a root-only working directory')
                return actual_run(args[-2:], **kwargs)

            with patch.object(Path, 'lstat', owned_launchers), patch('subprocess.run', side_effect=managed_run) as run:
                checker(home, system)

            self.assertEqual(6, run.call_count)

    def test_missing_wrong_or_linked_pnpm_is_refused_before_runtime_publication(self):
        for condition in ('missing', 'wrong-version', 'symlink'):
            with self.subTest(condition=condition), tempfile.TemporaryDirectory() as directory:
                home, system, bootstrap = self.runtime_fixture(Path(directory))
                manifest = system / 'opt/orbit-image/pnpm/package.json'
                manifest.unlink()
                if condition == 'wrong-version':
                    manifest.write_text('{"name":"pnpm","version":"other"}')
                elif condition == 'symlink':
                    manifest.symlink_to(system / 'usr/local/bin/bun')
                with patch.dict(guest['install_tool_runtime'].__globals__, command=bootstrap), self.assertRaises((ValueError, FileNotFoundError)):
                    guest['install_tool_runtime'](home, system, __import__('os').getuid(), __import__('os').getgid())
                self.assertFalse((home / '.local').exists())
                self.assertFalse((system / 'usr/local/bin/node').exists())

    def test_foreign_runtime_destinations_are_preserved_before_any_copy(self):
        for relative in ('home/.local', 'system/usr/local/bin/node'):
            with self.subTest(relative=relative), tempfile.TemporaryDirectory() as directory:
                root = Path(directory)
                home, system, bootstrap = self.runtime_fixture(root)
                foreign = root / 'foreign'
                foreign.mkdir()
                (root / relative).symlink_to(foreign)
                with patch.dict(guest['install_tool_runtime'].__globals__, command=bootstrap), self.assertRaises(ValueError):
                    guest['install_tool_runtime'](home, system, __import__('os').getuid(), __import__('os').getgid())
                self.assertEqual([], list(foreign.iterdir()))


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
