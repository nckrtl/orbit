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
        self.calls = []
        self.nodes = [{'name': 'gateway', 'status': 'active'}, {'name': 'operator', 'status': 'active'}]
        self.fail = False
        self.fail_access = False

    def tearDown(self):
        self.scratch.cleanup()

    def git(self, *arguments):
        return subprocess.run(['git', '-C', str(self.root), *arguments], check=True, capture_output=True, text=True).stdout.strip()

    def fake_run(self, arguments, root, home, timeout=120):
        if arguments[0] == 'git':
            return original_run(arguments, root, home, timeout)
        self.calls.append(arguments)
        if self.fail and arguments[0] == 'composer':
            raise subprocess.CalledProcessError(1, ['composer'])
        if self.fail_access and arguments[:2] == ['php', '-r']:
            raise subprocess.CalledProcessError(1, ['php'])
        return json.dumps({'nodes': self.nodes}) if arguments[-2:] == ['node:list', '--json'] else ''

    def call(self, phase, **changes):
        request = {'sandbox_id': self.id, 'branch': 'task-1', 'phase': phase, 'head': self.head, **changes}
        with patch.dict(prepare.__globals__, {'run': self.fake_run}):
            return prepare(request, self.root, self.home)

    def test_refreshes_cached_dependencies_and_gateway_before_confirming_cli(self):
        self.assertTrue(self.call('inspect')['ready'])
        self.assertEqual([], self.calls)
        self.assertTrue(self.call('gateway')['ready'])
        composers = [call for call in self.calls if call[0] == 'composer']
        self.assertEqual(len(module['PROJECTS']), len(composers))
        self.assertTrue(all('dump-autoload' in call and '--no-scripts' in call for call in composers))
        self.assertIn(['php', str(self.root / 'apps/gateway/artisan'), 'migrate', '--no-interaction', '--force'], self.calls)
        self.assertIn('GatewayCheckoutAccessConverger', self.calls[-2][-1])
        self.assertEqual(['sudo', '-n', 'systemctl', 'restart', 'php8.5-fpm'], self.calls[-1])
        self.assertTrue(self.call('operator')['ready'])
        self.assertEqual('cached dependencies', (self.root / 'apps/gateway/vendor/autoload.php').read_text())

    def test_changed_manifests_install_locked_dependencies_and_failure_prevents_migration(self):
        path = self.root / 'apps/gateway/composer.json'
        path.write_text('{"description":"changed"}\n')
        self.git('add', str(path))
        self.git('-c', 'user.name=Proof', '-c', 'user.email=proof@example.invalid', 'commit', '-qm', 'change manifests')
        self.head = self.git('rev-parse', 'HEAD')
        self.call('gateway')
        install = [call for call in self.calls if 'install' in call]
        self.assertEqual(1, len(install))
        self.assertEqual('--working-dir=' + str(self.root / 'apps/gateway'), install[0][1])
        self.calls = []
        self.fail = True
        with self.assertRaises(subprocess.CalledProcessError):
            self.call('gateway')
        self.assertFalse(any('migrate' in call for call in self.calls))

    def test_access_repair_failure_prevents_serving_branch(self):
        self.fail_access = True
        with self.assertRaises(subprocess.CalledProcessError):
            self.call('gateway')
        self.assertFalse(any('systemctl' in call for call in self.calls))

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


unittest.main()
