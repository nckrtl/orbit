"""Exercise cache publication with real repositories and an isolated test runner."""
import copy
import fcntl
import importlib.machinery
import importlib.util
import json
import os
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

    def test_bootstrap_seeds_after_installation_without_running_affected_suites(self):
        root = Path(self.temporary.name) / 'bootstrap'
        (root / 'bin').mkdir(parents=True)
        (root / 'bin/bootstrap').write_bytes(Path(cache.__file__).with_name('bootstrap').read_bytes())
        for project in cache.PROJECTS:
            (root / project).mkdir(parents=True)
        calls = root / 'calls'
        for name, script in {
            'composer': '#!/bin/sh\necho "composer $*" >> "$TIA_TEST_CALLS"\n',
            'worktree-cache': '#!/bin/sh\nexit 0\n',
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

    def test_correctness_hold_survives_environment_failure_until_the_failed_check_passes(self):
        outcomes = [
            {'commit': self.commit, 'success': False, 'checks': [
                {'tool': 'tia', 'kind': 'check_failure', 'exit_code': 2}]},
            {'commit': self.commit, 'success': False, 'checks': [
                {'tool': 'install', 'kind': 'maintenance_failure', 'exit_code': 1}]},
            {'commit': self.commit, 'success': True, 'checks': [
                {'tool': 'install', 'exit_code': 0}, {'tool': 'tia', 'exit_code': 0}]},
        ]
        for index, outcome in enumerate(outcomes):
            self.submit()
            with patch.object(cache, 'project_checks', return_value=outcome):
                cache.drain(self.common, self.store, cache.acquire_worker(self.store))
            unresolved = cache.status(self.common, self.store)['correctness_failures']
            self.assertEqual(index < 2, bool(unresolved))
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


if __name__ == '__main__':
    unittest.main(verbosity=2)
