"""Exercise cache publication with real repositories and an isolated test runner."""
import copy
import importlib.machinery
import importlib.util
import json
import os
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
        self.info['runner'] = 'upgraded-patch'

        def record():
            self.assertFalse((Path(self.info['cache']) / 'graph.json').exists())
            self.write_graph()

        self.publish(record)
        self.assertEqual('upgraded-patch', cache.read_publication(self.store, self.project)['runner'])

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
        with patch.object(cache.subprocess, 'Popen') as process:
            cache.start_background(self.common, self.store, [self.project])
        arguments, options = process.call_args
        command = arguments[0]
        self.assertTrue(Path(command[1]).is_relative_to(self.store))
        self.assertEqual(Path(cache.__file__).read_bytes(), Path(command[1]).read_bytes())
        self.assertIn(str(self.common), command)
        self.assertTrue(options['start_new_session'])
        self.assertEqual(subprocess.DEVNULL, options['stdin'])

    def test_refresh_releases_lock_and_preserves_other_project_progress_on_failure(self):
        with patch.object(cache, 'refresh_project', side_effect=[RuntimeError('failed'), None]) as refresh:
            self.assertEqual(1, cache.refresh(self.common, self.store, ['apps/cli', 'apps/docs']))
            self.assertEqual(2, refresh.call_count)
        with patch.object(cache, 'refresh_project'):
            self.assertEqual(0, cache.refresh(self.common, self.store, ['apps/docs']))

    def test_bootstrap_seeds_after_installation_without_running_affected_suites(self):
        root = Path(self.temporary.name) / 'bootstrap'
        (root / 'bin').mkdir(parents=True)
        (root / 'bin/bootstrap').write_bytes(Path(cache.__file__).with_name('bootstrap').read_bytes())
        for project in cache.PROJECTS:
            (root / project).mkdir(parents=True)
        calls = root / 'calls'
        for name, script in {
            'composer': '#!/bin/sh\necho "composer $*" >> "$TIA_TEST_CALLS"\n',
            'tia-cache': '#!/bin/sh\necho "cache $*" >> "$TIA_TEST_CALLS"\nexit 1\n',
        }.items():
            path = root / 'bin' / name
            path.write_text(script)
            path.chmod(0o755)
        result = subprocess.run(['bash', str(root / 'bin/bootstrap')], capture_output=True, text=True,
                                env={**os.environ, 'ORBIT_HOME': str(root / 'orbit-home'),
                                     'PATH': str(root / 'bin') + os.pathsep + os.environ['PATH'],
                                     'TIA_TEST_CALLS': str(calls)})
        self.assertEqual(0, result.returncode, result.stderr)
        lines = calls.read_text().splitlines()
        self.assertEqual(5, sum(line.startswith('composer install') for line in lines))
        self.assertEqual('cache seed --repository=' + str(root), lines[5])
        self.assertNotIn('test:affected', calls.read_text())


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

    def test_gate_runs_all_five_projects_and_records_exact_candidate(self):
        runner = self.gate_fixture()
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        self.assertTrue(report['passed'])
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


if __name__ == '__main__':
    unittest.main(verbosity=2)
