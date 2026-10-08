"""Run source checks with real Git; isolate package/service/HTTP side effects."""
import json
from pathlib import Path
import runpy
import subprocess
import sys
import tempfile
import unittest
import uuid
from unittest.mock import patch

module = runpy.run_path(sys.argv.pop(1))
prepare = module['prepare']
original_run = module['run']


class PairRuntime(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='orbit-pair-runtime-')
        self.home = Path(self.scratch.name)
        self.root = self.home / 'orbit'
        self.root.mkdir()
        self.git('init', '-q', '-b', 'main')
        for project in module['PROJECTS']:
            path = self.root / project
            (path / 'vendor').mkdir(parents=True)
            (path / 'composer.json').write_text('{}\n')
            (path / 'composer.lock').write_text('{}\n')
            (path / 'vendor/autoload.php').write_text('cached dependencies')
            self.git('add', project + '/composer.json', project + '/composer.lock')
        self.git('-c', 'user.name=Proof', '-c', 'user.email=proof@example.invalid', 'commit', '-qm', 'seed')
        self.head = self.git('rev-parse', 'HEAD')
        self.git('switch', '-qc', 'task-1')
        self.id = str(uuid.uuid4())
        self.owner = {'sandbox_id': self.id, 'branch': 'task-1', 'source_template': {'commit': self.head}}
        (self.root / '.git/orbit-sandbox-source.json').write_text(json.dumps(self.owner))
        (self.home / '.orbit').mkdir()
        (self.home / '.orbit/gateway.sqlite').touch()
        (self.home / '.orbit/config.json').write_text(json.dumps({'active_gateway': 'test', 'gateways': {'test': {'url': 'https://10.44.0.1'}}}))
        (self.root / 'apps/gateway/.env').write_text('APP_KEY=private-test-key\nAPP_VERSION=old-template\n')
        self.api_version = None
        self.calls = []
        self.nodes = [{'name': 'gateway', 'status': 'active'}, {'name': 'operator', 'status': 'active', 'roles': []}]
        self.doctor_fault = None
        self.fail_composer = False
        self.fail_access = False
        self.fail_agent = False
        self.fail_dns = False

    def tearDown(self):
        self.scratch.cleanup()

    def git(self, *arguments):
        return subprocess.run(['git', '-C', str(self.root), *arguments], check=True, capture_output=True, text=True).stdout.strip()

    def fake_run(self, arguments, root, home, timeout=120):
        if arguments[0] == 'git':
            return original_run(arguments, root, home, timeout)
        self.calls.append(arguments)
        if self.fail_composer and arguments[0] == 'composer':
            raise subprocess.CalledProcessError(1, ['composer'])
        if self.fail_access and arguments[:2] == ['php', '-r']:
            raise subprocess.CalledProcessError(1, ['php'])
        if self.fail_agent and arguments[:2] == ['php', '-r'] and 'NodeAgentRuntime' in arguments[-1]:
            raise subprocess.CalledProcessError(1, ['php'])
        if self.fail_dns and arguments == ['sudo', '-n', 'systemctl', 'enable', '--now', 'dnsmasq']:
            raise subprocess.CalledProcessError(1, arguments)
        if 'doctor' in arguments:
            node_id = int(next(arg[7:] for arg in arguments if arg.startswith('--node=')))
            node = next(node for node in self.nodes if node['id'] == node_id)
            report = {'healthy': True, 'nodes': [{'node_id': node_id, 'node_name': node['name'], 'healthy': True,
                      'families': [{'family': family, 'status': 'healthy'} for family in ('node', 'role', 'firewall')]}]}
            if self.doctor_fault == 'unhealthy': report['healthy'] = False
            if self.doctor_fault == 'wrong node': report['nodes'][0]['node_id'] += 99
            if self.doctor_fault == 'missing family': report['nodes'][0]['families'].pop()
            return json.dumps(report)
        if arguments[-2:] == ['gateway:status', '--json']:
            return json.dumps({'status': 'ok', 'url': 'https://10.44.0.1', 'version': self.api_version or self.head})
        return json.dumps({'nodes': self.nodes}) if arguments[-2:] == ['node:list', '--json'] else ''

    def call(self, phase, **changes):
        request = {'sandbox_id': self.id, 'branch': 'task-1', 'phase': phase, 'head': self.head, **changes}
        with patch.dict(prepare.__globals__, {'run': self.fake_run}):
            return prepare(request, self.root, self.home)

    def test_refreshes_cached_dependencies_and_gateway_before_confirming_cli(self):
        self.assertTrue(self.call('inspect')['ready'])
        self.assertEqual([], self.calls)
        self.assertTrue(self.call('gateway')['ready'])
        composers = [call for call in self.calls if call[0] == 'composer' and 'validate' not in call]
        self.assertEqual(len(module['PROJECTS']), len(composers))
        self.assertTrue(all('dump-autoload' in call and '--no-scripts' in call for call in composers))
        self.assertIn(['php', str(self.root / 'apps/gateway/artisan'), 'migrate', '--no-interaction', '--force'], self.calls)
        self.assertTrue(any('GatewayCheckoutAccessConverger' in call[-1] for call in self.calls))
        self.assertEqual(['sudo', '-n', 'systemctl', 'restart', 'php8.5-fpm'], self.calls[-1])
        self.assertTrue(self.call('operator')['ready'])
        self.assertEqual('cached dependencies', (self.root / 'apps/gateway/vendor/autoload.php').read_text())

    def test_operator_exposes_the_branch_cli_on_path_and_preserves_argv(self):
        cli = self.root / 'apps/cli/orbit'
        cli.write_text('#!/bin/sh\nprintf "%s\\n" "$@"\n')
        cli.chmod(0o755)
        self.call('operator')
        launcher = self.home / '.local/bin/orbit'
        self.assertTrue(launcher.is_file())
        result = subprocess.run([str(launcher), 'node:list', '--json', 'literal $(false)'], capture_output=True, text=True, check=True)
        self.assertEqual('node:list\n--json\nliteral $(false)\n', result.stdout)
        before = launcher.stat().st_mtime_ns
        self.call('operator')
        self.assertEqual(before, launcher.stat().st_mtime_ns)

    def test_foreign_or_linked_operator_cli_is_never_replaced(self):
        launchers = self.home / '.local/bin'
        launchers.mkdir(parents=True)
        launcher = launchers / 'orbit'
        launcher.write_text('foreign')
        with self.assertRaises(ValueError):
            self.call('operator')
        self.assertEqual('foreign', launcher.read_text())
        launcher.unlink()
        target = self.home / 'foreign'
        target.write_text('unchanged')
        launcher.symlink_to(target)
        with self.assertRaises(ValueError):
            self.call('operator')
        self.assertEqual('unchanged', target.read_text())
        launcher.unlink()
        launchers.rmdir()
        launchers.symlink_to(self.home)
        with self.assertRaises(ValueError):
            self.call('operator')
        self.assertFalse((self.home / 'orbit').is_file())

    def test_reports_branch_version_and_preserves_the_private_gateway_environment(self):
        self.call('gateway')
        self.assertEqual('APP_KEY=private-test-key\nAPP_VERSION=' + self.head + '\n', (self.root / 'apps/gateway/.env').read_text())
        self.api_version = 'old-template'
        with self.assertRaisesRegex(ValueError, 'branch version'):
            self.call('operator')

    def test_version_probe_reports_stale_gateway_without_runtime_mutation(self):
        self.api_version = 'b' * 40
        environment = self.root / 'apps/gateway/.env'
        before = environment.read_text()
        report = self.call('version')
        self.assertEqual(self.head, report['head'])
        self.assertEqual(self.api_version, report['gateway_head'])
        self.assertEqual('https://10.44.0.1', report['gateway_url'])
        self.assertEqual(before, environment.read_text())
        self.assertEqual([[str(self.root / 'apps/cli/orbit'), 'gateway:status', '--json']], self.calls)

    def test_refuses_a_linked_gateway_environment_without_modifying_its_target(self):
        environment = self.root / 'apps/gateway/.env'
        environment.unlink()
        target = self.home / 'unrelated.env'
        target.write_text('untouched')
        environment.symlink_to(target)
        with self.assertRaisesRegex(ValueError, 'environment is not local'):
            self.call('gateway')
        self.assertEqual('untouched', target.read_text())

    def test_changed_manifests_install_locked_dependencies_and_failure_prevents_migration(self):
        path = self.root / 'apps/gateway/composer.lock'
        path.write_text('{"description":"changed"}\n')
        self.git('add', str(path))
        self.git('-c', 'user.name=Proof', '-c', 'user.email=proof@example.invalid', 'commit', '-qm', 'change manifests')
        self.head = self.git('rev-parse', 'HEAD')
        self.call('gateway')
        install = [call for call in self.calls if 'install' in call]
        self.assertEqual(1, len(install))
        self.assertEqual('--working-dir=' + str(self.root / 'apps/gateway'), install[0][1])
        self.calls = []
        self.fail_composer = True
        with self.assertRaises(subprocess.CalledProcessError):
            self.call('gateway')
        self.assertFalse(any('migrate' in call for call in self.calls))

    def test_script_only_manifest_change_refreshes_autoload_without_install(self):
        path = self.root / 'apps/gateway/composer.json'
        path.write_text('{"scripts":{"check":"true"}}\n')
        self.git('add', str(path))
        self.git('-c', 'user.name=Proof', '-c', 'user.email=proof@example.invalid', 'commit', '-qm', 'change check script')
        self.head = self.git('rev-parse', 'HEAD')
        self.call('gateway')
        self.assertFalse(any('install' in call for call in self.calls))
        self.assertEqual(len(module['PROJECTS']), sum('validate' in call and '--check-lock' in call for call in self.calls))

    def test_access_repair_failure_prevents_serving_branch(self):
        self.fail_access = True
        with self.assertRaises(subprocess.CalledProcessError):
            self.call('gateway')
        self.assertFalse(any('systemctl' in call for call in self.calls))

    def test_gateway_agent_is_converged_before_serving_and_failure_refuses_readiness(self):
        self.call('gateway')
        self.assertTrue(any(call[:2] == ['php', '-r'] and 'NodeAgentRuntime' in call[-1] for call in self.calls))
        self.calls = []
        self.fail_agent = True
        with self.assertRaises(subprocess.CalledProcessError):
            self.call('gateway')
        self.assertFalse(any('systemctl' in call for call in self.calls))

    def test_gateway_vpn_dns_survives_boot_and_enable_failure_refuses_runtime_readiness(self):
        self.call('gateway')
        self.assertIn(['sudo', '-n', 'systemctl', 'enable', '--now', 'dnsmasq'], self.calls)
        self.calls = []
        self.fail_dns = True
        with self.assertRaises(subprocess.CalledProcessError):
            self.call('gateway')
        self.assertNotIn(['sudo', '-n', 'systemctl', 'restart', 'php8.5-fpm'], self.calls)

    def test_missing_vendor_installs_even_when_manifests_are_unchanged(self):
        (self.root / 'apps/cli/vendor/autoload.php').unlink()
        self.call('gateway')
        installs = [call for call in self.calls if 'install' in call]
        self.assertEqual(1, len(installs))
        self.assertEqual('--working-dir=' + str(self.root / 'apps/cli'), installs[0][1])

    def test_foreign_group_branch_and_commit_never_change_runtime(self):
        for changes in ({'sandbox_id': str(uuid.uuid4())}, {'branch': 'task-2'}, {'head': 'f' * 40}):
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                self.call('gateway', **changes)
        self.assertEqual([], self.calls)

    def test_operator_refuses_malformed_profile_without_contact(self):
        for configuration in (None, [], {'active_gateway': 'test', 'gateways': {'test': None}}):
            (self.home / '.orbit/config.json').write_text(json.dumps(configuration))
            with self.subTest(configuration=configuration), self.assertRaises(ValueError):
                self.call('operator')
        self.assertEqual([], self.calls)

    def test_operator_refuses_live_profiles_and_incomplete_topology(self):
        (self.home / '.orbit/config.json').write_text(json.dumps({'active_gateway': 'live', 'gateways': {'live': {'url': 'https://10.44.0.2'}}}))
        with self.assertRaises(ValueError):
            self.call('operator')
        self.assertEqual([], self.calls)
        (self.home / '.orbit/config.json').write_text(json.dumps({'active_gateway': 'test', 'gateways': {'test': {'url': 'https://10.44.0.1'}}}))
        self.nodes[1]['status'] = 'failed'
        with self.assertRaises(ValueError):
            self.call('operator')


    def test_expanded_inventory_matches_recorded_roles_and_keeps_pair_strict(self):
        for roles in (['app-dev'], ['app-dev', 'app-prod'], ['app-dev', 'app-prod', 'app-prod-2']):
            with self.subTest(roles=roles):
                self.nodes = [{'name': 'gateway', 'status': 'active'}, {'name': 'operator', 'status': 'active', 'roles': []}]
                self.nodes += [{'name': name, 'status': 'active', 'roles': ['app-prod' if name == 'app-prod-2' else name]} for name in roles]
                self.assertTrue(self.call('operator', inventory=['gateway', 'operator', *roles])['ready'])
                with self.assertRaises(ValueError):
                    self.call('operator')
                self.nodes[-1]['roles'] = []
                with self.assertRaisesRegex(ValueError, 'workload role'):
                    self.call('operator', inventory=['gateway', 'operator', *roles])

    def test_foreign_or_duplicate_recorded_inventory_never_contacts_gateway(self):
        for inventory in (None, [], ['gateway', 'operator', 'operator'], ['gateway', 'app-dev'], ['gateway', 'operator', 'foreign']):
            with self.subTest(inventory=inventory), self.assertRaises(ValueError):
                self.call('operator', inventory=inventory)
        self.assertEqual([], self.calls)

    def test_operator_role_and_extra_node_refuse_readiness(self):
        self.nodes[1]['roles'] = ['app-dev']
        with self.assertRaisesRegex(ValueError, 'roleless'):
            self.call('operator')
        self.nodes[1]['roles'] = []
        self.nodes += [{'name': 'app-dev', 'status': 'active', 'roles': ['app-dev']}]
        with self.assertRaises(ValueError):
            self.call('operator', inventory=['gateway', 'operator', 'app-prod'])


    def test_fresh_doctor_checks_every_exact_node_and_refuses_wrong_or_incomplete_results(self):
        self.nodes[0]['id'] = 1
        self.nodes[1]['id'] = 2
        self.nodes += [{'id': 3, 'name': 'app-dev', 'status': 'active', 'roles': ['app-dev']}]
        inventory = ['gateway', 'operator', 'app-dev']
        report = self.call('operator', inventory=inventory, doctor=True)
        self.assertEqual(inventory, report['doctor_nodes'])
        self.assertEqual('https://10.44.0.1', report['gateway_url'])
        self.assertEqual(3, sum('doctor' in call for call in self.calls))
        for fault in ('unhealthy', 'wrong node', 'missing family'):
            self.doctor_fault = fault
            with self.subTest(fault=fault), self.assertRaisesRegex(ValueError, 'doctor readiness'):
                self.call('operator', inventory=inventory, doctor=True)


unittest.main()
