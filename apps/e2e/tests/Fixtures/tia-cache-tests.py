"""Exercise cache publication with real repositories and an isolated test runner."""
import copy
import fcntl
import importlib.machinery
import importlib.util
import json
import os
import re
import signal
import time
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

loader = importlib.machinery.SourceFileLoader('tia_cache', sys.argv.pop(1))
spec = importlib.util.spec_from_loader(loader.name, loader)
cache = importlib.util.module_from_spec(spec)
loader.exec_module(cache)


class MainCacheTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='orbit tia ')
        self.addCleanup(self.temporary.cleanup)
        self.root = Path(self.temporary.name) / 'main'
        self.root.mkdir()
        cache.git(self.root, 'init', '-b', 'main')
        cache.git(self.root, 'config', 'user.name', 'Orbit')
        cache.git(self.root, 'config', 'user.email', 'orbit@example.test')
        cache.git(self.root, 'remote', 'add', 'origin', str(self.root))
        (self.root / 'source.php').write_text('base')
        self.commit = self.commit_change('initial')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', self.commit)
        self.common = cache.common_directory(self.root)
        self.store = cache.cache_store(self.common)
        self.project = 'apps/docs'
        self.info = {
            'cache': str(Path(self.temporary.name) / 'main-cache'),
            'runner': 'pinned-runner', 'coverage': True,
            'fingerprint': {'structural': {'schema': 18}, 'environmental': {'php_minor': '8.5'}},
        }
        self.graph = {
            'schema': 1, 'fingerprint': self.info['fingerprint'],
            'files': ['src/Example.php'], 'edges': {'tests/ExampleTest.php': [0]},
            'baselines': {'main': {'sha': self.commit, 'tree': [], 'results': {
                'example': {'status': 0, 'file': 'tests/ExampleTest.php'},
            }}},
        }

    def commit_change(self, message):
        cache.git(self.root, 'add', '.')
        cache.git(self.root, 'commit', '-m', message)
        return cache.git(self.root, 'rev-parse', 'HEAD')

    def write_graph(self):
        destination = Path(self.info['cache']) / 'graph.json'
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(json.dumps(self.graph))

    def publish(self, effect=None, commit=None):
        original_run = cache.run

        def run(root, *command, **kwargs):
            if command[0] == 'composer':
                return (effect or self.write_graph)()
            return original_run(root, *command, **kwargs)

        with patch.object(cache, 'install_project'), patch.object(cache, 'metadata', return_value=self.info), \
                patch.object(cache, 'run', side_effect=run):
            cache.refresh_project(self.root, self.store, self.project, commit or self.commit)

    def feature(self, name='feature'):
        root = Path(self.temporary.name) / name
        cache.git(self.root, 'worktree', 'add', '-b', name, str(root), 'main')
        return root

    def seed(self, root, info=None):
        info = info or {**self.info, 'cache': str(root / '.private-cache')}
        with patch.object(cache, 'metadata', return_value=info):
            cache.seed(root, self.store, [self.project])
        return Path(info['cache'])

    def test_runner_identity_tracks_locked_releases_and_project_bootstrap(self):
        project = self.root / self.project
        (project / 'tests').mkdir(parents=True)
        lock = project / 'composer.lock'
        bootstrap = project / 'tests/Pest.php'
        lock.write_text(json.dumps({'packages-dev': [{
            'name': 'nckrtl/pestphp-monorepo', 'version': 'v1.0.0',
            'source': {'reference': 'first-release'},
        }]}))
        bootstrap.write_text('<?php\n')
        with patch.object(cache, 'run', return_value=json.dumps(self.info)):
            initial = cache.metadata(self.root, self.project)['runner']
            self.assertEqual(initial, cache.metadata(self.root, self.project)['runner'])
            lock.write_text(lock.read_text().replace('first-release', 'next-release'))
            upgraded = cache.metadata(self.root, self.project)['runner']
            self.assertNotEqual(initial, upgraded)
            bootstrap.write_text('<?php pest()->tia()->directory("custom");\n')
            self.assertNotEqual(upgraded, cache.metadata(self.root, self.project)['runner'])

    def test_main_graph_is_copied_without_transient_state_and_writes_stay_private(self):
        self.publish()
        (Path(self.info['cache']) / 'affected.json').write_text('do not copy')
        first = self.seed(self.feature())
        second = self.seed(self.feature('other'))
        self.assertEqual(['graph.json'], [path.name for path in first.iterdir()])
        self.assertEqual(self.graph, json.loads((first / 'graph.json').read_text()))
        (first / 'graph.json').write_text('private update')
        self.assertEqual(self.graph, json.loads((second / 'graph.json').read_text()))
        self.assertEqual(self.graph, json.loads(cache.read_publication(self.store, self.project)['graph']))

    def test_existing_worktree_cache_is_never_replaced(self):
        self.publish()
        root = self.feature()
        directory = self.seed(root)
        (directory / 'graph.json').write_text('feature progress')
        self.seed(root)
        self.assertEqual('feature progress', (directory / 'graph.json').read_text())

    def test_separate_clone_seeds_from_transported_main_publications(self):
        self.publish()
        clone = Path(self.temporary.name) / 'independent'
        cache.git(self.root, 'clone', str(self.root), str(clone))
        directory = clone / self.project / '.orbit-tia'
        with patch.dict(os.environ, {'ORBIT_MAIN_CACHE_STORE': str(self.store)}), \
                patch.object(sys, 'argv', ['tia-cache', 'seed', '--repository', str(clone), '--project', self.project]), \
                patch.object(cache, 'metadata', return_value={**self.info, 'cache': str(directory)}):
            self.assertEqual(0, cache.main())
        self.assertEqual(self.graph, json.loads((directory / 'graph.json').read_text()))
        (directory / 'graph.json').write_text('private clone progress')
        self.assertEqual(self.graph, json.loads(cache.read_publication(self.store, self.project)['graph']))

    def test_failed_run_keeps_last_successful_publication(self):
        self.publish()
        original = cache.publication_path(self.store, self.project).read_bytes()
        (self.root / 'source.php').write_text('next main')
        commit = self.commit_change('next')

        def failed():
            self.graph['baselines']['main']['results']['example']['status'] = 7
            self.write_graph()
            raise RuntimeError('tests failed')

        with self.assertRaisesRegex(RuntimeError, 'tests failed'):
            self.publish(failed, commit)
        self.assertEqual(original, cache.publication_path(self.store, self.project).read_bytes())

    def test_empty_failed_dirty_feature_and_nonportable_graphs_are_not_published(self):
        invalid = [
            {'baselines': {'feature': self.graph['baselines']['main']}},
            {'edges': {}}, {'fingerprint': {}}, {'files': ['/another/checkout/source.php']},
        ]
        for changes in invalid:
            with self.subTest(changes=changes):
                original = copy.deepcopy(self.graph)
                self.graph.update(changes)
                with self.assertRaises(ValueError):
                    self.publish()
                self.assertFalse(cache.publication_path(self.store, self.project).exists())
                self.graph = original
        for field, value in [('tree', {'source.php': 'dirty'}), ('results', {}),
                             ('results', {'failed': {'status': 8}})]:
            with self.subTest(field=field, value=value):
                original = copy.deepcopy(self.graph)
                self.graph['baselines']['main'][field] = value
                with self.assertRaises(ValueError):
                    self.publish()
                self.graph = original

    def test_changed_checkout_or_missing_driver_prevents_publication(self):
        (self.root / 'source.php').write_text('unexpected edit')
        with self.assertRaisesRegex(RuntimeError, 'changed during testing'):
            self.publish()
        self.info['coverage'] = False
        with self.assertRaisesRegex(RuntimeError, 'coverage must be enabled'):
            self.publish()
        self.assertFalse(cache.publication_path(self.store, self.project).exists())

    def test_runner_change_discards_runtime_graph_before_recording_again(self):
        self.publish()
        self.info['runner'] = 'upgraded-release'

        def record():
            self.assertFalse((Path(self.info['cache']) / 'graph.json').exists())
            self.write_graph()

        self.publish(record)
        self.assertEqual('upgraded-release', cache.read_publication(self.store, self.project)['runner'])

    def test_refresh_restores_successful_graph_before_retrying_failed_runtime_state(self):
        self.publish()
        (Path(self.info['cache']) / 'graph.json').write_text('failed runtime state')
        (self.root / 'source.php').write_text('new main')
        current = self.commit_change('new main')

        def retry():
            self.assertEqual(self.graph, json.loads((Path(self.info['cache']) / 'graph.json').read_text()))
            self.write_graph()

        self.publish(retry, current)

    def test_missing_incompatible_corrupt_or_future_publication_is_a_cache_miss(self):
        root = self.feature()
        self.assertFalse(self.seed(root).exists())
        self.publish()
        path = cache.publication_path(self.store, self.project)
        snapshot = json.loads(path.read_text())
        (self.root / 'source.php').write_text('future main')
        future = self.commit_change('future')
        for changes in [{'runner': 'other'}, {'fingerprint': {}}, {'sha256': 'broken'},
                        {'tested_commit': future}, {'project': 'apps/cli'}, {'schema': 2}]:
            with self.subTest(changes=changes):
                path.write_text(json.dumps({**snapshot, **changes}))
                self.assertFalse(self.seed(root).exists())
        path.write_text('incomplete JSON')
        self.assertFalse(self.seed(root).exists())
        path.write_text('[]')
        self.assertFalse(self.seed(root).exists())

    def test_no_affected_run_can_publish_an_ancestor_graph_at_new_main(self):
        self.publish()
        (self.root / 'source.php').write_text('unaffected documentation')
        current = self.commit_change('main advances')
        self.publish(commit=current)
        snapshot = cache.read_publication(self.store, self.project)
        self.assertEqual(current, snapshot['tested_commit'])
        self.assertEqual(self.commit, snapshot['graph_commit'])
        self.assertTrue((self.seed(self.feature()) / 'graph.json').exists())

    def test_owned_main_checkout_advances_without_touching_dirty_primary(self):
        (self.root / 'source.php').write_text('user edits')
        checkout = cache.prepare_checkout(self.common, self.store)
        self.assertEqual(self.commit, cache.git(checkout, 'rev-parse', 'HEAD'))
        self.assertEqual('main', cache.git(checkout, 'branch', '--show-current'))
        self.assertEqual('user edits', (self.root / 'source.php').read_text())
        (checkout / 'source.php').write_text('keep worker edits too')
        with self.assertRaisesRegex(RuntimeError, 'contains edits'):
            cache.prepare_checkout(self.common, self.store)

    def test_background_runner_survives_caller_removal_and_does_not_hold_closeout(self):
        with patch.object(cache, 'known_main', return_value=self.commit), \
                patch.object(cache, 'worker_key', return_value='runner'), \
                patch.object(cache.subprocess, 'Popen') as process:
            cache.start_background(self.common, self.store, [self.project])
        arguments, options = process.call_args
        command = arguments[0]
        self.assertTrue(Path(command[1]).is_relative_to(self.store))
        self.assertEqual(Path(cache.__file__).read_bytes(), Path(command[1]).read_bytes())
        self.assertIn(str(self.common), command)
        self.assertTrue(options['start_new_session'])
        self.assertEqual(subprocess.DEVNULL, options['stdin'])

    def test_native_background_checks_isolate_setup_settings_and_keep_dependency_access(self):
        project = self.root / self.project
        (project / 'tests').mkdir(parents=True)
        (self.root / '.gitignore').write_text('**/vendor/\n')
        (project / 'tests/Pest.php').write_text('<?php\n')
        (project / 'composer.lock').write_text('{"packages":[]}\n')
        (project / 'pint.json').write_text('{"cache-file":"vendor/pint.cache"}\n')
        (project / 'phpstan.neon').write_text('parameters:\n    level: 6\n')
        self.commit = self.commit_change('background environment fixture')

        fake_bin = Path(self.temporary.name) / 'fake-bin'
        fake_bin.mkdir()
        observations = Path(self.temporary.name) / 'child-environment.jsonl'
        runtime_cache = Path(self.temporary.name) / 'worker-test-cache'
        composer = fake_bin / 'composer'
        composer.write_text(r'''#!/usr/bin/env python3
import json
import os
from pathlib import Path
import sys
import time
from urllib.parse import urlparse

blocked = (
    'ORBIT_HOME', 'APP_CONFIG_CACHE', 'APP_BASE_PATH', 'DB_URL', 'DB_DATABASE',
    'DATABASE_URL', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION',
    'TMPDIR', 'TMP', 'TEMP',
)
observation = {
    'command': sys.argv[1:],
    'blocked_present': [name for name in blocked if name in os.environ],
    'path_finds_fixture': os.environ.get('PATH', '').split(os.pathsep)[0] == os.environ['TIA_CACHE_FIXTURE_BIN'],
    'composer_auth_available': os.environ.get('COMPOSER_AUTH') == 'disposable-composer-auth',
    'github_token_available': os.environ.get('GITHUB_TOKEN') == 'disposable-github-token',
    'composer_home_available': os.environ.get('COMPOSER_HOME') == os.environ['TIA_CACHE_FIXTURE_COMPOSER_HOME'],
}
with open(os.environ['TIA_CACHE_FIXTURE_OBSERVATIONS'], 'a') as stream:
    stream.write(json.dumps(observation) + '\n')

for name in ('ORBIT_HOME', 'APP_BASE_PATH', 'TMPDIR', 'TMP', 'TEMP'):
    if name in os.environ:
        destination = Path(os.environ[name]) / 'worker-touched'
        destination.parent.mkdir(parents=True, exist_ok=True)
        destination.write_text(name)
for name in ('APP_CONFIG_CACHE', 'DB_DATABASE'):
    if name in os.environ:
        Path(os.environ[name]).write_text(name)
for name in ('DB_URL', 'DATABASE_URL'):
    if name in os.environ:
        database = Path(urlparse(os.environ[name]).path)
        database.write_text(name)

command = sys.argv[1]
if command == 'install':
    Path(os.environ['TIA_CACHE_FIXTURE_READY']).touch()
    deadline = time.monotonic() + 10
    while not Path(os.environ['TIA_CACHE_FIXTURE_RELEASE']).exists() and time.monotonic() < deadline:
        time.sleep(0.01)
elif command == 'test:affected':
    graph = {
        'schema': 1,
        'fingerprint': {'structural': {'schema': 18}, 'environmental': {'php_minor': '8.5'}},
        'files': ['src/Example.php'],
        'edges': {'tests/ExampleTest.php': [0]},
        'baselines': {'main': {
            'sha': os.environ['TIA_CACHE_FIXTURE_COMMIT'],
            'tree': [],
            'results': {'example': {'status': 0, 'file': 'tests/ExampleTest.php'}},
        }},
    }
    destination = Path(os.environ['TIA_CACHE_FIXTURE_CACHE']) / 'graph.json'
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text(json.dumps(graph))
    sys.exit(int(os.environ['TIA_CACHE_FIXTURE_TEST_EXIT']))
elif command == 'format:check':
    destination = Path('vendor/pint.cache')
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text('portable pint result')
elif command == 'analyse':
    destination = Path('vendor/phpstan/cache/resultCache.php')
    destination.parent.mkdir(parents=True, exist_ok=True)
    destination.write_text('portable phpstan result')
''')
        composer.chmod(0o755)
        php = fake_bin / 'php'
        php.write_text(r'''#!/usr/bin/env python3
import json
import os
import sys

if 'PHP_MAJOR_VERSION' in sys.argv[2]:
    print('8.5')
else:
    print(json.dumps({
        'cache': os.environ['TIA_CACHE_FIXTURE_CACHE'],
        'fingerprint': {'structural': {'schema': 18}, 'environmental': {'php_minor': '8.5'}},
        'coverage': True,
    }))
''')
        php.chmod(0o755)

        sentinels = Path(self.temporary.name) / 'setup-sentinels'
        orbit_home = sentinels / 'orbit-home'
        app_base = sentinels / 'app-base'
        temporary = sentinels / ('setup-temporary-' + ('long-' * 30))
        short_temporary = sentinels / 'short-temporary'
        legacy_temporary = sentinels / 'legacy-temporary'
        for directory in (orbit_home, app_base, temporary, short_temporary, legacy_temporary):
            directory.mkdir(parents=True)
            (directory / 'state').write_text('unchanged')
        app_config = sentinels / 'config.php'
        database = sentinels / 'database.sqlite'
        url_database = sentinels / 'url-database.sqlite'
        database_url = sentinels / 'database-url.sqlite'
        for path in (app_config, database, url_database, database_url):
            path.write_text('unchanged')
        original_sentinels = {
            str(path.relative_to(sentinels)): path.read_bytes()
            for path in sentinels.rglob('*') if path.is_file()
        }
        composer_home = Path(self.temporary.name) / 'composer-home'
        composer_home.mkdir()
        worker_ready = Path(self.temporary.name) / 'worker-ready'
        worker_release = Path(self.temporary.name) / 'worker-release'
        environment = {
            **os.environ,
            'PATH': str(fake_bin) + os.pathsep + os.environ['PATH'],
            'ORBIT_HOME': str(orbit_home),
            'APP_CONFIG_CACHE': str(app_config),
            'APP_BASE_PATH': str(app_base),
            'DB_DATABASE': str(database),
            'DB_URL': 'sqlite:///' + str(url_database),
            'DATABASE_URL': 'sqlite:///' + str(database_url),
            'CACHE_STORE': 'array',
            'SESSION_DRIVER': 'array',
            'QUEUE_CONNECTION': 'sync',
            'TMPDIR': str(temporary),
            'TMP': str(short_temporary),
            'TEMP': str(legacy_temporary),
            'COMPOSER_AUTH': 'disposable-composer-auth',
            'GITHUB_TOKEN': 'disposable-github-token',
            'COMPOSER_HOME': str(composer_home),
            'TIA_CACHE_FIXTURE_BIN': str(fake_bin),
            'TIA_CACHE_FIXTURE_CACHE': str(runtime_cache),
            'TIA_CACHE_FIXTURE_COMMIT': self.commit,
            'TIA_CACHE_FIXTURE_COMPOSER_HOME': str(composer_home),
            'TIA_CACHE_FIXTURE_OBSERVATIONS': str(observations),
            'TIA_CACHE_FIXTURE_READY': str(worker_ready),
            'TIA_CACHE_FIXTURE_RELEASE': str(worker_release),
            'TIA_CACHE_FIXTURE_TEST_EXIT': '7',
        }
        result = subprocess.run(
            [sys.executable, str(cache.__file__), 'refresh', '--background',
             '--repository', str(self.root), '--project', self.project],
            cwd=self.root, env=environment, capture_output=True, text=True,
        )
        worker = re.search(r'pid (\d+)', result.stdout)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertIsNotNone(worker, result.stdout)

        deadline = time.monotonic() + 10
        while not worker_ready.exists() and cache.active_worker(self.store) and time.monotonic() < deadline:
            time.sleep(0.01)
        process_environment = {}
        if worker_ready.exists():
            entries = Path(f'/proc/{worker.group(1)}/environ').read_bytes().split(b'\0')
            process_environment = {
                entry.split(b'=', 1)[0].decode(): entry.split(b'=', 1)[1].decode()
                for entry in entries if b'=' in entry
            }
        worker_release.touch()
        deadline = time.monotonic() + 15
        while (cache.active_worker(self.store) or cache.load_requests(self.store)['pending']) \
                and time.monotonic() < deadline:
            time.sleep(0.01)
        if cache.active_worker(self.store):
            os.killpg(int(worker.group(1)), signal.SIGKILL)
        self.assertFalse(cache.active_worker(self.store), (self.store / 'refresh.log').read_text())
        state = cache.load_requests(self.store)
        self.assertTrue(worker_ready.exists(), (self.store / 'refresh.log').read_text())
        self.assertEqual([], [name for name in (
            'ORBIT_HOME', 'APP_CONFIG_CACHE', 'APP_BASE_PATH', 'DB_URL', 'DB_DATABASE',
            'DATABASE_URL', 'CACHE_STORE', 'SESSION_DRIVER', 'QUEUE_CONNECTION',
            'TMPDIR', 'TMP', 'TEMP',
        ) if name in process_environment])
        self.assertEqual(str(fake_bin) + os.pathsep + os.environ['PATH'], process_environment['PATH'])
        self.assertTrue(process_environment.get('COMPOSER_AUTH') == environment['COMPOSER_AUTH'])
        self.assertTrue(process_environment.get('GITHUB_TOKEN') == environment['GITHUB_TOKEN'])
        self.assertTrue(process_environment.get('COMPOSER_HOME') == environment['COMPOSER_HOME'])
        self.assertEqual({}, state['pending'])
        self.assertFalse(state['results'][self.project]['success'])
        self.assertEqual(7, state['correctness_failures'][self.project]['tia']['exit_code'])
        self.assertEqual('check_failure', state['correctness_failures'][self.project]['tia']['kind'])
        self.assertEqual(
            [('install', 0), ('tia', 7), ('pint', 0), ('phpstan', 0)],
            [(check['tool'], check['exit_code']) for check in state['results'][self.project]['checks']],
        )

        child_environments = [json.loads(line) for line in observations.read_text().splitlines()]
        self.assertEqual(
            [['install', '--no-interaction', '--prefer-dist'], ['test:affected'],
             ['format:check'], ['analyse']],
            [observation['command'] for observation in child_environments],
        )
        for observation in child_environments:
            self.assertEqual([], observation['blocked_present'])
            self.assertTrue(observation['path_finds_fixture'])
            self.assertTrue(observation['composer_auth_available'])
            self.assertTrue(observation['github_token_available'])
            self.assertTrue(observation['composer_home_available'])
        final_sentinels = {
            str(path.relative_to(sentinels)): path.read_bytes()
            for path in sentinels.rglob('*') if path.is_file()
        }
        self.assertEqual(original_sentinels, final_sentinels)

        evidence = os.environ.get('TIA_CACHE_EVIDENCE_DIR')
        if evidence:
            evidence_directory = Path(evidence)
            evidence_directory.mkdir(parents=True, exist_ok=True)
            retained_logs = {}
            logs = {'refresh': self.store / 'refresh.log'}
            logs.update({check['tool']: Path(check['log'])
                         for check in state['results'][self.project]['checks']})
            for name, source in logs.items():
                destination = evidence_directory / f'background-environment-{name}.log'
                destination.write_bytes(source.read_bytes())
                retained_logs[name] = str(destination)
            report = {
                'scenario': 'native-background-environment-boundary',
                'worker_blocked_present': [name for name in (
                    'ORBIT_HOME', 'APP_CONFIG_CACHE', 'APP_BASE_PATH',
                    'DB_URL', 'DB_DATABASE', 'DATABASE_URL', 'CACHE_STORE',
                    'SESSION_DRIVER', 'QUEUE_CONNECTION', 'TMPDIR', 'TMP', 'TEMP',
                ) if name in process_environment],
                'worker_dependency_access': {
                    'path': process_environment.get('PATH') == environment['PATH'],
                    'composer_auth': process_environment.get('COMPOSER_AUTH') == environment['COMPOSER_AUTH'],
                    'github_token': process_environment.get('GITHUB_TOKEN') == environment['GITHUB_TOKEN'],
                    'composer_home': process_environment.get('COMPOSER_HOME') == environment['COMPOSER_HOME'],
                },
                'project_commands': child_environments,
                'sentinels_unchanged': final_sentinels == original_sentinels,
                'pending': state['pending'],
                'result': state['results'][self.project],
                'correctness_failures': state['correctness_failures'],
                'retained_logs': retained_logs,
            }
            (evidence_directory / 'background-environment-boundary.json').write_text(
                json.dumps(report, indent=2) + '\n'
            )

    def test_refresh_releases_lock_and_preserves_other_project_progress_on_failure(self):
        failed = {'commit': self.commit, 'success': False, 'checks': [{'tool': 'tia', 'exit_code': 1}]}
        passed = {'commit': self.commit, 'success': True, 'checks': [{'tool': 'tia', 'exit_code': 0}]}
        with patch.object(cache, 'project_checks', side_effect=[failed, passed]) as refresh:
            self.assertEqual(1, cache.refresh(self.common, self.store, ['apps/cli', 'apps/docs']))
            self.assertEqual(2, refresh.call_count)
        self.assertFalse(cache.load_requests(self.store)['results']['apps/cli']['success'])
        self.assertTrue(cache.load_requests(self.store)['results']['apps/docs']['success'])
        with patch.object(cache, 'project_checks', return_value=passed):
            self.assertEqual(0, cache.refresh(self.common, self.store, ['apps/docs']))

    def test_bootstrap_seeds_before_affected_tests_and_quality_checks_and_propagates_failures(self):
        root = Path(self.temporary.name) / 'bootstrap'
        (root / 'bin').mkdir(parents=True)
        (root / 'bin/bootstrap').write_bytes(Path(cache.__file__).with_name('bootstrap').read_bytes())
        for project in cache.PROJECTS:
            (root / project).mkdir(parents=True)
        calls = root / 'calls'
        for name, script in {
            'composer': '#!/bin/sh\necho "composer $*" >> "$TIA_TEST_CALLS"\nif [ "$1" = "$TIA_TEST_FAIL" ]; then exit 7; fi\nif [ "$1" != install ]; then test -z "$ORBIT_MAIN_CACHE_STORE"; fi\n',
            'worktree-cache': '#!/bin/sh\ntest "$ORBIT_MAIN_CACHE_STORE" = "$TIA_TEST_STORE"\n',
            'tia-cache': '#!/bin/sh\necho "cache $*" >> "$TIA_TEST_CALLS"\ntest "$ORBIT_MAIN_CACHE_STORE" = "$TIA_TEST_STORE" || exit 9\nexit 1\n',
        }.items():
            path = root / 'bin' / name
            path.write_text(script)
            path.chmod(0o755)
        environment = {**os.environ, 'ORBIT_HOME': str(root / 'orbit-home'),
                       'ORBIT_MAIN_CACHE_STORE': str(root / 'transported-main'),
                       'TIA_TEST_STORE': str(root / 'transported-main'),
                       'PATH': str(root / 'bin') + os.pathsep + os.environ['PATH'],
                       'TIA_TEST_CALLS': str(calls), 'TIA_TEST_FAIL': ''}
        result = subprocess.run(['bash', str(root / 'bin/bootstrap')], capture_output=True, text=True, env=environment)
        self.assertEqual(0, result.returncode, result.stderr)
        lines = calls.read_text().splitlines()
        self.assertEqual(5, sum(line.startswith('composer install') for line in lines))
        self.assertEqual('cache seed --repository=' + str(root), lines[5])
        self.assertEqual(['composer test:affected', 'composer check'] * 5, lines[-10:])
        calls.unlink()
        result = subprocess.run(['bash', str(root / 'bin/bootstrap'), '--skip-checks'],
                                capture_output=True, text=True, env=environment)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertNotIn('test:affected', calls.read_text())
        for failure in ('install', 'guidance:check', 'test:affected', 'check'):
            with self.subTest(failure=failure):
                calls.unlink()
                result = subprocess.run(['bash', str(root / 'bin/bootstrap')], capture_output=True, text=True,
                                        env={**environment, 'TIA_TEST_FAIL': failure})
                self.assertNotEqual(0, result.returncode)
                if failure in ('install', 'guidance:check'):
                    self.assertNotIn('composer test:affected', calls.read_text())


class MaintenanceQueueTest(unittest.TestCase):
    setUp = MainCacheTest.setUp
    commit_change = MainCacheTest.commit_change

    def submit(self, projects=None):
        with cache.queue_lock(self.store):
            return cache.enqueue(self.common, self.store, projects or [self.project])

    def test_duplicate_requests_share_one_worker_and_keep_all_projects(self):
        self.submit()
        lock = cache.acquire_worker(self.store)
        try:
            cache.start_background(self.common, self.store, [self.project])
            cache.start_background(self.common, self.store, ['apps/cli'])
            state = cache.load_requests(self.store)
            self.assertEqual({'apps/cli', 'apps/docs'}, set(state['pending']))
            self.assertTrue(cache.active_worker(self.store))
            self.assertIsNone(cache.acquire_worker(self.store))
        finally:
            lock.close()
        calls = []

        def check(root, store, project, commit, directory):
            calls.append(project)
            return {'commit': commit, 'success': True, 'checks': []}

        with patch.object(cache, 'project_checks', side_effect=check):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual(['apps/docs', 'apps/cli'], calls)
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_refresh_fetches_an_external_merge_even_with_current_local_publications(self):
        key = cache.worker_key(self.common)
        with cache.queue_lock(self.store):
            cache.save_requests(self.store, {'schema': 1, 'generation': 1, 'pending': {}, 'results': {
                self.project: {'commit': self.commit, 'success': True, 'checks': [], 'worker_key': key}}})
        (self.root / 'source.php').write_text('external merge')
        remote = self.commit_change('merged outside closeout')
        self.assertEqual(self.commit, cache.known_main(self.common))
        with patch.object(cache, 'publications_current', side_effect=lambda store, project, commit: commit == self.commit), \
                patch.object(cache, 'project_checks', return_value={'commit': remote, 'success': True, 'checks': []}) as checks:
            self.assertEqual(0, cache.refresh(self.common, self.store, [self.project]))
        self.assertEqual(remote, checks.call_args.args[3])
        self.assertEqual(remote, cache.known_main(self.common))

    def test_partial_request_upgrades_all_retained_projects(self):
        with patch.object(cache, 'worker_key', return_value='old-runner'):
            self.submit(['apps/cli', 'apps/docs'])
        with patch.object(cache, 'worker_key', return_value='new-runner'):
            state = self.submit(['apps/docs'])
            self.assertEqual({'new-runner'}, {request['worker_key'] for request in state['pending'].values()})
            with patch.object(cache, 'project_checks', return_value={'commit': self.commit, 'success': True, 'checks': []}) as checks:
                self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
            self.assertEqual(2, checks.call_count)
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_command_timeout_stops_descendants_before_returning(self):
        marker = self.root / 'descendant-ready'
        script = '''import fcntl, os, signal, time
signal.signal(signal.SIGTERM, signal.SIG_IGN)
if os.fork() == 0:
    with open('descendant.lock', 'w') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        with open('descendant-ready', 'w') as marker:
            marker.write(str(os.getpid()))
        time.sleep(30)
        with open('escaped-child', 'w') as escaped:
            escaped.write('still running')
else:
    time.sleep(30)
'''
        try:
            with patch.object(cache, 'COMMAND_TIMEOUT', 0.5):
                with self.assertRaisesRegex(cache.CommandFailure, 'timed out'):
                    cache.run(self.root, sys.executable, '-c', script, capture=False)

            self.assertTrue(marker.exists(), 'The descendant must start before timeout.')
            with (self.root / 'descendant.lock').open() as lock:
                # A live descendant still owns this independent lock, even after
                # its direct parent has exited. Allow bounded kernel teardown
                # after SIGKILL; a surviving child retains the lock for 30 s.
                deadline = time.monotonic() + 1
                while True:
                    try:
                        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
                        break
                    except BlockingIOError:
                        if time.monotonic() >= deadline:
                            self.fail('A descendant survived the command timeout.')
                        time.sleep(0.01)
            self.assertFalse((self.root / 'escaped-child').exists())
        finally:
            if marker.exists():
                try:
                    os.kill(int(marker.read_text()), signal.SIGKILL)
                except ProcessLookupError:
                    pass

    def test_interrupted_worker_command_retains_exclusive_checkout_ownership(self):
        self.store.mkdir(parents=True)
        marker = self.root / 'command-ready'
        release = self.root / 'release-command'
        os.mkfifo(release)
        release_fd = os.open(release, os.O_RDWR)
        script = '''import os
with open('release-command') as release:
    with open('command-ready', 'w') as marker:
        marker.write(str(os.getpid()))
    release.read(1)
'''
        worker = os.fork()
        if worker == 0:
            try:
                os.close(release_fd)
                lock = cache.acquire_worker(self.store)
                cache.COMMAND_LOCK = lock.fileno()
                cache.run(self.root, sys.executable, '-c', script, capture=False)
            except BaseException:
                os._exit(1)
            os._exit(0)
        try:
            deadline = time.monotonic() + 5
            while not marker.exists() and time.monotonic() < deadline:
                time.sleep(0.01)
            self.assertTrue(marker.exists(), 'The maintenance command must start.')

            os.kill(worker, signal.SIGKILL)
            os.waitpid(worker, 0)
            worker = None
            contender = cache.acquire_worker(self.store)
            if contender is not None:
                contender.close()
            self.assertIsNone(contender, 'The surviving command must exclude another worker.')

            os.write(release_fd, b'1')
            deadline = time.monotonic() + 5
            while cache.active_worker(self.store) and time.monotonic() < deadline:
                time.sleep(0.01)
            self.assertFalse(cache.active_worker(self.store))
        finally:
            os.close(release_fd)
            if worker is not None:
                os.kill(worker, signal.SIGKILL)
                os.waitpid(worker, 0)
            if marker.exists():
                try:
                    os.kill(int(marker.read_text()), signal.SIGKILL)
                except ProcessLookupError:
                    pass

    def test_main_request_arriving_during_refresh_is_not_acknowledged_by_old_batch(self):
        self.submit()
        commits = []

        def check(root, store, project, commit, directory):
            commits.append(commit)
            if len(commits) == 1:
                (self.root / 'source.php').write_text('next merged feature')
                self.next_commit = self.commit_change('next main')
                cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', self.next_commit)
                self.submit()
            return {'commit': commit, 'success': True, 'checks': []}

        with patch.object(cache, 'project_checks', side_effect=check):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual([self.commit, self.next_commit], commits)
        state = cache.load_requests(self.store)
        self.assertEqual({}, state['pending'])
        self.assertEqual(self.next_commit, state['results'][self.project]['commit'])

    def test_interrupted_worker_leaves_request_for_a_later_worker(self):
        self.submit()
        with patch.object(cache, 'project_checks', side_effect=SystemExit('interrupted')):
            with self.assertRaises(SystemExit):
                cache.drain(self.common, self.store, cache.acquire_worker(self.store))
        self.assertFalse(cache.active_worker(self.store))
        self.assertIn(self.project, cache.load_requests(self.store)['pending'])
        with patch.object(cache, 'project_checks', return_value={'commit': self.commit, 'success': True, 'checks': []}):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_launch_failure_keeps_a_durable_request_without_an_active_owner(self):
        with patch.object(cache, 'known_main', return_value=self.commit), \
                patch.object(cache, 'worker_key', return_value='runner'), \
                patch.object(cache.subprocess, 'Popen', side_effect=OSError('spawn failed')):
            with self.assertRaisesRegex(OSError, 'spawn failed'):
                cache.start_background(self.common, self.store, [self.project])
        self.assertIn(self.project, cache.load_requests(self.store)['pending'])
        self.assertFalse(cache.active_worker(self.store))

    def test_old_worker_leaves_upgraded_request_for_the_new_runner(self):
        with patch.object(cache, 'worker_key', return_value='new-runner'):
            self.submit()
        with patch.object(cache, 'worker_key', return_value='old-runner'), \
                patch.object(cache, 'project_checks') as checks:
            cache.drain(self.common, self.store, cache.acquire_worker(self.store))
            checks.assert_not_called()
        self.assertFalse(cache.active_worker(self.store))
        self.assertIn(self.project, cache.load_requests(self.store)['pending'])
        with patch.object(cache, 'worker_key', return_value='new-runner'), \
                patch.object(cache, 'project_checks', return_value={'commit': self.commit, 'success': True, 'checks': []}):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_correctness_hold_survives_environment_and_cached_results_until_executed_recovery(self):
        outcomes = [
            {'commit': self.commit, 'success': False, 'checks': [
                {'tool': 'tia', 'kind': 'check_failure', 'exit_code': 2}]},
            {'commit': self.commit, 'success': False, 'checks': [
                {'tool': 'install', 'kind': 'maintenance_failure', 'exit_code': 1}]},
            {'commit': self.commit, 'success': True, 'checks': [
                {'tool': 'install', 'exit_code': 0}, {'tool': 'tia', 'exit_code': 0}]},
            {'commit': self.commit, 'success': True, 'checks': [
                {'tool': 'install', 'exit_code': 0},
                {'tool': 'tia', 'exit_code': 0, 'recovery': 'executed'}]},
        ]
        for index, outcome in enumerate(outcomes):
            self.submit()
            with patch.object(cache, 'project_checks', return_value=outcome):
                cache.drain(self.common, self.store, cache.acquire_worker(self.store))
            unresolved = cache.status(self.common, self.store)['correctness_failures']
            self.assertEqual(index < 3, bool(unresolved))
            if unresolved:
                self.assertEqual(2, unresolved[self.project]['tia']['exit_code'])

    def test_check_failure_is_distinct_from_install_failure_and_does_not_skip_quality_checks(self):
        self.store.mkdir(parents=True)
        with patch.object(cache, 'install_project'), \
                patch.object(cache, 'refresh_project', side_effect=cache.CommandFailure('test failed', 2)), \
                patch.object(cache, 'refresh_quality') as quality:
            result = cache.project_checks(self.root, self.store, self.project, self.commit, self.store)
        self.assertFalse(result['success'])
        self.assertEqual('check_failure', result['checks'][1]['kind'])
        self.assertEqual(2, quality.call_count)
        with patch.object(cache, 'install_project', side_effect=cache.CommandFailure('download failed', 1)):
            result = cache.project_checks(self.root, self.store, self.project, self.commit, self.store)
        self.assertEqual(1, len(result['checks']))
        self.assertEqual('maintenance_failure', result['checks'][0]['kind'])

    def test_status_reads_remote_main_without_advancing_primary_or_queueing_work(self):
        old = self.commit
        (self.root / 'source.php').write_text('new main')
        current = self.commit_change('next')
        self.assertEqual(old, cache.known_main(self.common))
        result = cache.status(self.common, self.store, remote=True)
        self.assertEqual(current, result['main'])
        self.assertTrue(result['needed'])
        self.assertEqual({}, result['pending'])
        self.assertFalse((self.store / 'requests.json').exists())
        self.assertEqual(old, cache.known_main(self.common))


class QualityPublicationTest(unittest.TestCase):
    commit_change = MainCacheTest.commit_change
    feature = MainCacheTest.feature

    def setUp(self):
        MainCacheTest.setUp(self)
        project = self.root / self.project
        project.mkdir(parents=True)
        (self.root / '.gitignore').write_text('**/vendor/\n')
        (project / 'composer.lock').write_text('{"packages":[]}')
        (project / 'pint.json').write_text('{"cache-file":"vendor/pint.cache"}')
        (project / 'phpstan.neon').write_text('parameters:\n    level: 6\n')
        self.commit = self.commit_change('quality configuration')

    def publish(self, tool='pint', failure=None):
        original = cache.run

        def run(root, *args, **kwargs):
            if args[0] == 'composer':
                if failure:
                    raise cache.CommandFailure(failure, 1)
                path = self.root / self.project / cache.QUALITY[tool][1]
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_bytes(b'portable tool result')
                return
            return original(root, *args, **kwargs)

        with patch.object(cache, 'run', side_effect=run):
            cache.refresh_quality(self.root, self.store, self.project, self.commit, tool)

    def seed(self, worktree):
        return subprocess.run([sys.executable, str(Path(cache.__file__).with_name('worktree-cache')),
                               '--worktree', str(worktree)], capture_output=True, text=True)

    def test_published_quality_caches_seed_without_a_live_donor_and_writes_stay_private(self):
        for tool in cache.QUALITY:
            self.publish(tool)
            (self.root / self.project / cache.QUALITY[tool][1]).unlink()
        feature = self.feature()
        result = self.seed(feature)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('published main', result.stdout)
        for tool in cache.QUALITY:
            path = feature / self.project / cache.QUALITY[tool][1]
            self.assertEqual(b'portable tool result', path.read_bytes())
            path.write_bytes(b'private edit')
            publication = json.loads(cache.quality_path(self.store, self.project, tool).read_text())
            self.assertEqual(b'portable tool result', cache.quality_data(publication, self.project, tool))
        self.seed(feature)
        self.assertEqual(b'private edit', (feature / self.project / cache.QUALITY['pint'][1]).read_bytes())
        self.assertFalse((feature / self.project / 'vendor/phpstan/cache/cache').exists())

    def test_failed_quality_check_retains_successful_publication(self):
        self.publish()
        path = cache.quality_path(self.store, self.project, 'pint')
        original = path.read_bytes()
        (self.root / 'source.php').write_text('bad formatting')
        self.commit = self.commit_change('new main')
        with self.assertRaisesRegex(cache.CommandFailure, 'format failed'):
            self.publish(failure='format failed')
        self.assertEqual(original, path.read_bytes())

    def test_corrupt_incompatible_and_future_quality_publications_are_not_seeded(self):
        self.publish()
        (self.root / self.project / cache.QUALITY['pint'][1]).unlink()
        feature = self.feature()
        path = cache.quality_path(self.store, self.project, 'pint')
        original = json.loads(path.read_text())
        (self.root / 'source.php').write_text('future main')
        future = self.commit_change('future')
        for change in [{'sha256': 'corrupt'}, {'fingerprint': 'wrong'}, {'tested_commit': future},
                       {'project': 'apps/cli'}, {'tool': 'phpstan'}, {'data': 'invalid base64'}]:
            with self.subTest(change=change):
                path.write_text(json.dumps({**original, **change}))
                self.assertEqual(0, self.seed(feature).returncode)
                self.assertFalse((feature / self.project / cache.QUALITY['pint'][1]).exists())


class ReviewGateTest(unittest.TestCase):
    setUp = MainCacheTest.setUp
    commit_change = MainCacheTest.commit_change
    # Reuse repository setup, without repeating the cache test cases.
    def gate_fixture(self):
        (self.root / 'bin').mkdir()
        runner = self.root / 'bin/review-check'
        runner.write_bytes(Path(cache.__file__).with_name('review-check').read_bytes())
        runner.chmod(0o755)
        seed = self.root / 'bin/tia-cache'
        seed.write_text('#!/bin/sh\nexit 0\n')
        seed.chmod(0o755)
        for project in cache.PROJECTS:
            (self.root / project).mkdir(parents=True)
        profile = self.root / 'apps/gateway/resources/tasks/check.py'
        profile.parent.mkdir(parents=True)
        profile.write_bytes((Path(cache.__file__).parent.parent / 'apps/gateway/resources/tasks/check.py').read_bytes())
        self.commit_change('gate fixture')
        fake_bin = Path(self.temporary.name) / 'fake-bin'
        fake_bin.mkdir()
        composer = fake_bin / 'composer'
        composer.write_text("""#!/bin/sh
printf '%s|%s\\n' "$PWD" "$*" >> "$GATE_TEST_CALLS"
if [ "$1" = check ] && [ "${PWD##*/}" = e2e ]; then
    case "$GATE_TEST_MODE" in
        fail) exit 7 ;;
        mutate) echo changed > "$GATE_TEST_ROOT/source.php" ;;
    esac
fi
""")
        composer.chmod(0o755)
        self.gate_env = {**os.environ, 'PATH': str(fake_bin) + os.pathsep + os.environ['PATH'],
                         'GATE_TEST_CALLS': str(self.common / 'calls'), 'GATE_TEST_ROOT': str(self.root)}
        return runner

    def test_gate_runs_all_five_projects_and_records_builder_and_exact_candidate(self):
        runner = self.gate_fixture()
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        self.assertTrue(report['passed'])
        self.assertEqual('builder', report['role'])
        self.assertEqual(cache.git(self.root, 'rev-parse', 'HEAD'), report['candidate'])
        self.assertEqual([(project, command) for project in cache.PROJECTS
                          for command in [['composer', 'validate', '--strict'], ['composer', 'check'],
                                          ['composer', 'test:affected']]],
                         [(item['project'], item['command']) for item in report['checks']])
        self.assertEqual(15, len((self.common / 'calls').read_text().splitlines()))

    def test_gate_rejects_failed_checks_and_candidate_mutation(self):
        runner = self.gate_fixture()
        for mode in ['fail', 'mutate']:
            with self.subTest(mode=mode):
                result = subprocess.run([str(runner)], env={**self.gate_env, 'GATE_TEST_MODE': mode},
                                        capture_output=True, text=True)
                self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        for path in (self.common / 'orbit-checks').glob('*/*/result.json'):
            self.assertFalse(json.loads(path.read_text())['passed'])

    def test_gate_refuses_dirty_input_without_running_checks(self):
        runner = self.gate_fixture()
        (self.root / 'source.php').write_text('uncommitted')
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(1, result.returncode)
        self.assertIn('clean candidate', result.stderr)
        self.assertFalse((self.common / 'calls').exists())


class TiaRecoveryTest(unittest.TestCase):
    setUp = MainCacheTest.setUp
    commit_change = MainCacheTest.commit_change
    write_graph = MainCacheTest.write_graph
    publish = MainCacheTest.publish

    def observed_failure(self):
        self.publish()
        self.original_publication = cache.publication_path(self.store, self.project).read_bytes()
        self.original_graph = json.loads(cache.read_publication(self.store, self.project)['graph'])
        (self.root / 'source.php').write_text('main with an observed failing behavior')
        self.failed_commit = self.commit_change('observed failure on main')
        self.execution_count = 0
        self.calls = []
        self.run_number = 0
        outcome = self.check('executed-failure', self.failed_commit)
        self.assertFalse(outcome['success'])
        self.assertEqual(1, self.execution_count)
        self.assertEqual(['composer', 'test:affected'], self.calls[-1]['argv'])
        state = cache.load_requests(self.store)
        cache.record_outcome(state, self.project, outcome)
        cache.save_requests(self.store, state)
        self.original_failure = copy.deepcopy(state['correctness_failures'][self.project]['tia'])
        self.original_failure_log = Path(self.original_failure['log']).read_bytes()
        self.assertEqual(2, self.original_failure['exit_code'])
        self.assertEqual(self.failed_commit, self.original_failure['commit'])
        self.assertEqual(self.original_publication,
                         cache.publication_path(self.store, self.project).read_bytes())
        return state

    def check(self, mode, commit, pint_environment_failure=False):
        original_run = cache.run
        self.run_number += 1
        directory = self.store / 'fixture-runs' / str(self.run_number)
        directory.mkdir(parents=True)

        def runner(root, *command, **kwargs):
            if command[0] != 'composer':
                return original_run(root, *command, **kwargs)
            self.assertEqual('test:affected', command[1])
            restored = json.loads((Path(self.info['cache']) / 'graph.json').read_text())
            self.assertEqual(self.original_graph, restored)
            observation = {'argv': list(command), 'mode': mode,
                           'restored_graph_commit': restored['baselines']['main']['sha']}
            self.calls.append(observation)
            with cache.COMMAND_LOG.open('a') as output:
                output.write(json.dumps(observation) + '\n')
            if mode == 'cached-success':
                return None
            self.execution_count += 1
            self.graph = copy.deepcopy(self.original_graph)
            self.graph['baselines']['main']['sha'] = commit
            if mode == 'executed-failure':
                self.graph['baselines']['main']['results']['example']['status'] = 8
                self.write_graph()
                raise cache.CommandFailure('fixture behavior is still broken', 2)
            self.assertEqual('executed-success', mode)
            self.write_graph()

        def quality(root, store, project, checked_commit, tool, force=False):
            if pint_environment_failure and tool == 'pint':
                raise RuntimeError('fixture Pint environment is unavailable')

        with patch.object(cache, 'install_project'), \
                patch.object(cache, 'metadata', return_value=self.info), \
                patch.object(cache, 'refresh_quality', side_effect=quality), \
                patch.object(cache, 'run', side_effect=runner):
            return cache.project_checks(self.root, self.store, self.project, commit, directory)

    def installation_failure(self, commit):
        self.run_number += 1
        directory = self.store / 'fixture-runs' / str(self.run_number)
        directory.mkdir(parents=True)
        with patch.object(cache, 'install_project',
                          side_effect=cache.CommandFailure('fixture download failed', 1)):
            return cache.project_checks(self.root, self.store, self.project, commit, directory)

    def persist(self, outcome):
        state = cache.load_requests(self.store)
        cache.record_outcome(state, self.project, outcome)
        cache.save_requests(self.store, state)
        return cache.load_requests(self.store)

    def assert_original_failure_is_retained(self, state):
        self.assertEqual(self.original_failure,
                         state['correctness_failures'][self.project]['tia'])
        self.assertEqual(self.original_failure_log, Path(self.original_failure['log']).read_bytes())
        self.assertEqual(self.original_publication,
                         cache.publication_path(self.store, self.project).read_bytes())

    def retain_evidence(self, name, before, after, outcome):
        snapshot = cache.read_publication(self.store, self.project)
        report = {
            'scenario': name,
            'failed_commit': self.failed_commit,
            'checked_commit': outcome['commit'],
            'total_executed_fixture_checks': self.execution_count,
            'invoked_commands': self.calls,
            'pending': after['pending'],
            'hold_before': before['correctness_failures'],
            'hold_after': after['correctness_failures'],
            'latest_result': after['results'][self.project],
            'published_tested_commit': snapshot['tested_commit'],
            'published_graph_commit': snapshot['graph_commit'],
            'published_graph_unchanged': json.loads(snapshot['graph']) == self.original_graph,
        }
        destination = os.environ.get('TIA_CACHE_EVIDENCE_DIR')
        if destination:
            directory = Path(destination)
            directory.mkdir(parents=True, exist_ok=True)
            retained_logs = {}
            logs = {'original-failure': self.original_failure['log']}
            logs.update({check['tool']: check['log'] for check in outcome['checks']})
            for label, source in logs.items():
                retained = directory / f'{name}-{label}.log'
                retained.write_bytes(Path(source).read_bytes())
                retained_logs[label] = str(retained)
            report['retained_logs'] = retained_logs
            (directory / f'{name}.json').write_text(json.dumps(report, indent=2) + '\n')
        print('TIA_RECOVERY_OBSERVATION ' + json.dumps(report), flush=True)

    def test_zero_execution_retry_at_failed_main_retains_original_failure_and_diagnostic(self):
        before = self.observed_failure()
        outcome = self.check('cached-success', self.failed_commit)
        after = self.persist(outcome)

        self.assertFalse(outcome['success'])
        self.assertEqual('not_executed', outcome['checks'][1]['recovery'])
        self.assertEqual(['composer', 'test:affected', '--', '--fresh'], self.calls[-1]['argv'])
        self.assertEqual(1, self.execution_count)
        self.assert_original_failure_is_retained(after)
        self.retain_evidence('zero-execution-same-main', before, after, outcome)

    def test_zero_execution_retry_at_unrelated_successor_retains_original_failure_and_diagnostic(self):
        before = self.observed_failure()
        (self.root / 'notes.md').write_text('unrelated notes; no repair')
        current = self.commit_change('unrelated main advancement')
        outcome = self.check('cached-success', current)
        after = self.persist(outcome)

        self.assertFalse(outcome['success'])
        self.assertEqual('not_executed', outcome['checks'][1]['recovery'])
        self.assertEqual(['composer', 'test:affected', '--', '--fresh'], self.calls[-1]['argv'])
        self.assertEqual(1, self.execution_count)
        self.assert_original_failure_is_retained(after)
        self.retain_evidence('zero-execution-later-main', before, after, outcome)

    def test_failing_executed_recovery_retains_failure_and_successful_publication(self):
        before = self.observed_failure()
        outcome = self.check('executed-failure', self.failed_commit)
        after = self.persist(outcome)

        self.assertFalse(outcome['success'])
        self.assertEqual('failed', outcome['checks'][1]['recovery'])
        self.assertEqual('check_failure', outcome['checks'][1]['kind'])
        self.assertEqual(['composer', 'test:affected', '--', '--fresh'], self.calls[-1]['argv'])
        self.assertEqual(2, self.execution_count)
        self.assertIn('tia', after['correctness_failures'][self.project])
        self.assertEqual(self.original_publication,
                         cache.publication_path(self.store, self.project).read_bytes())
        self.retain_evidence('failing-executed-recovery', before, after, outcome)

    def test_successful_executed_recovery_clears_only_recovered_project_tia_failure(self):
        before = self.observed_failure()
        other_project_failure = copy.deepcopy(self.original_failure)
        pint_log = self.store / 'original-pint-failure.log'
        pint_log.write_text('apps/docs: pint failed before TIA recovery\n')
        pint_failure = {**self.original_failure, 'tool': 'pint', 'exit_code': 3,
                        'log': str(pint_log)}
        before['correctness_failures']['apps/cli'] = {'tia': other_project_failure}
        before['correctness_failures'][self.project]['pint'] = pint_failure
        cache.save_requests(self.store, before)
        (self.root / 'source.php').write_text('reviewed repair fixture')
        current = self.commit_change('repair fixture')
        outcome = self.check('executed-success', current, pint_environment_failure=True)
        after = self.persist(outcome)

        self.assertEqual('executed', outcome['checks'][1]['recovery'])
        self.assertEqual(['composer', 'test:affected', '--', '--fresh'], self.calls[-1]['argv'])
        self.assertEqual(2, self.execution_count)
        self.assertNotIn('tia', after['correctness_failures'][self.project])
        self.assertEqual(pint_failure, after['correctness_failures'][self.project]['pint'])
        self.assertEqual(other_project_failure, after['correctness_failures']['apps/cli']['tia'])
        self.assertEqual(current, outcome['commit'])
        self.assertEqual(current, cache.read_publication(self.store, self.project)['tested_commit'])
        self.assertEqual(current, cache.read_publication(self.store, self.project)['graph_commit'])
        self.retain_evidence('successful-executed-recovery', before, after, outcome)

    def test_installation_failure_preserves_original_failure_and_successful_publication(self):
        before = self.observed_failure()
        outcome = self.installation_failure(self.failed_commit)
        after = self.persist(outcome)

        self.assertFalse(outcome['success'])
        self.assertEqual('maintenance_failure', outcome['checks'][0]['kind'])
        self.assert_original_failure_is_retained(after)
        self.retain_evidence('installation-failure', before, after, outcome)

    def test_interrupted_recovery_preserves_request_failure_and_successful_publication(self):
        before = self.observed_failure()
        with cache.queue_lock(self.store):
            cache.enqueue(self.common, self.store, [self.project])
        with patch.object(cache, 'project_checks', side_effect=SystemExit('fixture interruption')):
            with self.assertRaises(SystemExit):
                cache.drain(self.common, self.store, cache.acquire_worker(self.store))
        after = cache.load_requests(self.store)

        self.assertIn(self.project, after['pending'])
        self.assert_original_failure_is_retained(after)
        self.retain_evidence('interrupted-recovery', before, after,
                             after['results'][self.project])


if __name__ == '__main__':
    unittest.main(verbosity=2)
