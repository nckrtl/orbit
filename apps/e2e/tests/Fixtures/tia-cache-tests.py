"""Exercise main cache import, publication, and seeding with real repositories and a fake GitHub CLI."""
import copy
from contextlib import contextmanager
import fcntl
import importlib.machinery
import importlib.util
import json
import os
import signal
import shutil
import time
from pathlib import Path
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import Mock, patch

loader = importlib.machinery.SourceFileLoader('tia_cache', sys.argv.pop(1))
spec = importlib.util.spec_from_loader(loader.name, loader)
cache = importlib.util.module_from_spec(spec)
loader.exec_module(cache)
# Publication registers stores under XDG_STATE_HOME; keep that out of the developer's own state.
STATE_HOME = tempfile.TemporaryDirectory(prefix='orbit-tia-state-')
os.environ['XDG_STATE_HOME'] = STATE_HOME.name
REPOSITORY = 'nckrtl/orbit'
# Answers the gh calls of the worker from files in $GH_FIXTURE and records every call.
FAKE_GH = r'''#!/usr/bin/env python3
import json, os, shutil, sys
from pathlib import Path
fixture = Path(os.environ['GH_FIXTURE'])
with (fixture / 'calls').open('a') as calls:
    calls.write(json.dumps(sys.argv[1:]) + '\n')
if (fixture / 'fail').exists():
    sys.exit('HTTP 503: GitHub is unavailable')
arguments = sys.argv[1:]
if arguments[0] == 'api':
    path = arguments[1].split('?')[0]
    if path == 'repos/nckrtl/orbit/actions/workflows/ci.yml/runs':
        print((fixture / 'runs.json').read_text())
    elif path.startswith('repos/nckrtl/orbit/actions/runs/') and path.endswith('/jobs'):
        jobs = fixture / ('jobs-' + path.split('/')[5] + '.json')
        if not jobs.exists():
            sys.exit('HTTP 404: Not Found')
        print(jobs.read_text())
    else:
        sys.exit('HTTP 404: Not Found')
elif arguments[:2] == ['run', 'download']:
    run_id, destination = arguments[2], arguments[arguments.index('-D') + 1]
    assert arguments[arguments.index('-R') + 1] == 'nckrtl/orbit'
    assert arguments[arguments.index('-p') + 1] == 'sandbox-tia-*'
    source = fixture / ('artifacts-' + run_id)
    if not source.is_dir():
        sys.exit('no valid artifacts found to download')
    for artifact in source.iterdir():
        shutil.copytree(artifact, Path(destination) / artifact.name)
else:
    sys.exit('unexpected gh call')
'''


def php_minor():
    return subprocess.run(['php', '-r', 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;'],
                          check=True, capture_output=True, text=True).stdout


@contextmanager
def launched_workers(failure=None):
    """Record background worker launches and let every other command run."""
    real = subprocess.Popen
    launches = []

    def popen(command, *arguments, **options):
        if len(command) > 2 and command[2] == 'work':
            if failure is not None:
                raise failure
            launches.append((command, options))
            return Mock(pid=4242)
        return real(command, *arguments, **options)

    with patch.object(cache.subprocess, 'Popen', side_effect=popen):
        yield launches


def repository(test, project_files=True):
    test.temporary = tempfile.TemporaryDirectory(prefix='orbit tia ')
    test.addCleanup(test.temporary.cleanup)
    test.root = Path(test.temporary.name) / 'main'
    test.root.mkdir()
    cache.git(test.root, 'init', '-b', 'main')
    cache.git(test.root, 'config', 'user.name', 'Orbit')
    cache.git(test.root, 'config', 'user.email', 'orbit@example.test')
    cache.git(test.root, 'remote', 'add', 'origin', str(test.root))
    (test.root / 'source.php').write_text('base')
    test.project = 'apps/docs'
    if project_files:
        project = test.root / test.project
        (project / 'tests').mkdir(parents=True)
        (test.root / '.gitignore').write_text('**/vendor/\n**/.orbit-tia/\n')
        (project / 'composer.lock').write_text('{"packages":[]}')
        (project / 'tests/Pest.php').write_text('<?php\n')
        (project / 'pint.json').write_text('{"cache-file":"vendor/pint.cache"}')
        (project / 'phpstan.neon').write_text('parameters:\n    level: 6\n')
        # The background worker runs the tool as main holds it.
        (test.root / 'bin').mkdir()
        shutil.copyfile(cache.__file__, test.root / 'bin/tia-cache')
    test.commit = test.commit_change('initial')
    cache.git(test.root, 'update-ref', 'refs/remotes/origin/main', test.commit)
    test.common = cache.common_directory(test.root)
    test.store = cache.cache_store(test.common)
    test.info = {
        'cache': str(Path(test.temporary.name) / 'main-cache'),
        'runner': cache.runner_identity(b'{"packages":[]}', b'<?php\n'),
        'fingerprint': {'structural': {'schema': 18}, 'environmental': {'php_minor': '8.5'}},
    }
    test.graph = {
        'schema': 1, 'fingerprint': test.info['fingerprint'],
        'files': ['src/Example.php'], 'edges': {'tests/ExampleTest.php': [0]},
        'baselines': {'main': {'sha': test.commit, 'tree': [], 'results': {
            'example': {'status': 0, 'file': 'tests/ExampleTest.php'},
        }}},
    }


class CacheFixture(unittest.TestCase):
    def setUp(self):
        repository(self)

    def commit_change(self, message):
        cache.git(self.root, 'add', '.')
        cache.git(self.root, 'commit', '-m', message)
        return cache.git(self.root, 'rev-parse', 'HEAD')

    def advance(self, message):
        (self.root / 'source.php').write_text(message)
        return self.commit_change(message)

    def graph_at(self, commit):
        graph = copy.deepcopy(self.graph)
        graph['baselines']['main']['sha'] = commit
        return graph

    def artifact(self, commit, run_id=123, graph=None, quality=None, php=None, manifest=None):
        """Write one extracted CI artifact, as the Export main caches step of ci.yml does."""
        directory = Path(tempfile.mkdtemp(dir=self.temporary.name, prefix='artifact-')) / f'sandbox-tia-1-{commit}'
        directory.mkdir()
        encoded = json.dumps(self.graph_at(commit) if graph is None else graph).encode()
        (directory / 'graph.json').write_bytes(encoded)
        inputs = {}
        for name in cache.TEST_INPUTS:
            data = cache.blob(self.root, commit, f'{self.project}/{name}')
            if data is not None:
                inputs[name] = cache.digest(data)
        content = {'schema': 1, 'project': self.project, 'commit': commit, 'run_id': str(run_id),
                   'graph_sha256': cache.digest(encoded), 'inputs': inputs}
        if quality is not None:
            (directory / 'quality').mkdir()
            content['quality'] = {}
            for tool, data in quality.items():
                (directory / 'quality' / tool).write_bytes(data)
                content['quality'][tool] = cache.digest(data)
            content['php'] = php or php_minor()
        (directory / 'manifest.json').write_text(json.dumps({**content, **(manifest or {})}))
        return directory

    def publish(self, commit=None, run_id=123, **options):
        commit = commit or self.commit
        artifact = self.artifact(commit, run_id, **options)
        return cache.publish_artifact(self.common, self.store, self.project, artifact, commit, run_id)

    def drain(self, projects=None):
        """Queue and run the worker in this process, as bin/tia-cache refresh does in main's frozen copy."""
        projects = projects or [self.project]
        with cache.queue_lock(self.store):
            cache.enqueue(self.common, self.store, projects)
        cache.drain(self.common, self.store, cache.acquire_worker(self.store, blocking=True))
        results = cache.load_requests(self.store)['results']
        return int(any(not results.get(project, {}).get('success') for project in projects))

    def feature(self, name='feature'):
        root = Path(self.temporary.name) / name
        cache.git(self.root, 'worktree', 'add', '-b', name, str(root), 'main')
        return root

    def seed(self, root, info=None):
        info = info or {**self.info, 'cache': str(root / '.private-cache')}
        with patch.object(cache, 'metadata', return_value=info):
            cache.seed(root, self.store, [self.project])
        return Path(info['cache'])


class MainCacheTest(CacheFixture):
    def ci_artifact(self):
        artifact = self.artifact(self.commit)
        return artifact, json.loads((artifact / 'manifest.json').read_text())

    def test_ci_baseline_import_keeps_private_results_and_never_publishes(self):
        artifact, _ = self.ci_artifact()
        with patch.object(cache, 'metadata', return_value=self.info):
            self.assertTrue(cache.import_ci(self.root, self.project, artifact, self.commit))
            destination = self.root / self.project / '.orbit-tia/graph.json'
            self.assertEqual(json.loads(destination.read_text()), self.graph)
            self.assertEqual(destination.stat().st_mode & 0o777, 0o600)
            destination.write_text('newer private results')
            self.assertFalse(cache.import_ci(self.root, self.project, artifact, self.commit))
            self.assertEqual(destination.read_text(), 'newer private results')
        self.assertFalse(self.store.exists())

    def test_ci_baseline_rejects_tampering_and_configuration_drift_before_writing(self):
        artifact, manifest = self.ci_artifact()
        for field, value in [('project', 'apps/gateway'), ('commit', 'a' * 40),
                             ('graph_sha256', 'b' * 64), ('inputs', {}), ('run_id', '')]:
            with self.subTest(field=field), patch.object(cache, 'metadata', return_value=self.info):
                (artifact / 'manifest.json').write_text(json.dumps({**manifest, field: value}))
                with self.assertRaises(ValueError):
                    cache.import_ci(self.root, self.project, artifact, self.commit)
                self.assertFalse((self.root / self.project / '.orbit-tia').exists())

    def test_ci_baseline_refuses_failed_results_nonportable_paths_and_symlink_destinations(self):
        artifact, manifest = self.ci_artifact()
        failed = copy.deepcopy(self.graph)
        failed['baselines']['main']['results']['example']['status'] = 8
        nonportable = copy.deepcopy(self.graph)
        nonportable['files'] = ['/home/runner/repository/file.php']
        for graph in [failed, nonportable]:
            encoded = json.dumps(graph).encode()
            (artifact / 'graph.json').write_bytes(encoded)
            (artifact / 'manifest.json').write_text(json.dumps({**manifest, 'graph_sha256': cache.digest(encoded)}))
            with patch.object(cache, 'metadata', return_value=self.info), self.assertRaises(ValueError):
                cache.import_ci(self.root, self.project, artifact, self.commit)
        (artifact / 'graph.json').write_text(json.dumps(self.graph))
        (artifact / 'manifest.json').write_text(json.dumps(manifest))
        outside = Path(self.temporary.name) / 'outside'
        outside.mkdir()
        (self.root / self.project / '.orbit-tia').symlink_to(outside)
        with patch.object(cache, 'metadata', return_value=self.info), self.assertRaises(ValueError):
            cache.import_ci(self.root, self.project, artifact, self.commit)
        self.assertEqual(list(outside.iterdir()), [])

    def test_runner_identity_tracks_locked_releases_and_project_bootstrap(self):
        project = self.root / self.project
        lock = project / 'composer.lock'
        bootstrap = project / 'tests/Pest.php'
        lock.write_text(json.dumps({'packages-dev': [{
            'name': 'nckrtl/pestphp-monorepo', 'version': 'v1.0.0',
            'source': {'reference': 'first-release'},
        }]}))
        with patch.object(cache, 'run', return_value=json.dumps(self.info)):
            initial = cache.metadata(self.root, self.project)['runner']
            self.assertEqual(initial, cache.metadata(self.root, self.project)['runner'])
            lock.write_text(lock.read_text().replace('first-release', 'next-release'))
            upgraded = cache.metadata(self.root, self.project)['runner']
            self.assertNotEqual(initial, upgraded)
            bootstrap.write_text('<?php pest()->tia()->directory("custom");\n')
            self.assertNotEqual(upgraded, cache.metadata(self.root, self.project)['runner'])

    def test_published_ci_graph_matches_the_runner_identity_of_its_commit(self):
        self.publish()
        snapshot = cache.read_publication(self.store, self.project)
        self.assertEqual(self.info['runner'], snapshot['runner'])
        self.assertEqual(self.graph['fingerprint'], snapshot['fingerprint'])
        self.assertEqual({'run_id': 123}, snapshot['source'])
        self.assertEqual((self.commit, self.commit), (snapshot['tested_commit'], snapshot['graph_commit']))

    def test_main_graph_seeds_each_worktree_and_writes_stay_private(self):
        self.publish()
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
                patch.object(cache, 'metadata', return_value={**self.info, 'cache': str(directory)}), \
                patch.object(cache, 'start_background') as background:
            self.assertEqual(0, cache.main())
        background.assert_not_called()
        self.assertEqual(self.graph, json.loads((directory / 'graph.json').read_text()))
        (directory / 'graph.json').write_text('private clone progress')
        self.assertEqual(self.graph, json.loads(cache.read_publication(self.store, self.project)['graph']))

    def test_separate_clone_seeds_from_the_store_registered_for_its_origin(self):
        self.publish()
        self.assertEqual(self.store.resolve(), cache.registry_path(self.root).resolve())
        clone = Path(self.temporary.name) / 'task workspace'
        cache.git(self.root, 'clone', str(self.root), str(clone))
        directory = clone / self.project / '.orbit-tia'
        with patch.dict(os.environ, {'ORBIT_MAIN_CACHE_STORE': ''}), \
                patch.object(sys, 'argv', ['tia-cache', 'seed', '--repository', str(clone), '--project', self.project]), \
                patch.object(cache, 'metadata', return_value={**self.info, 'cache': str(directory)}), \
                patch.object(cache, 'start_background') as background:
            self.assertEqual(0, cache.main())
        self.assertEqual(self.graph, json.loads((directory / 'graph.json').read_text()))
        # The clone's own store stays empty: its private runs never become the shared baseline.
        self.assertFalse(cache.has_publications(cache.cache_store(cache.common_directory(clone))))
        self.assertEqual(sorted(set(cache.PROJECTS) - {self.project}), sorted(background.call_args.args[2]))

    def test_linked_worktree_seeding_queues_an_import_for_its_own_lagging_store(self):
        self.publish()
        worktree = self.feature()
        self.advance('merged elsewhere')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', cache.git(self.root, 'rev-parse', 'HEAD'))
        with patch.object(sys, 'argv', ['tia-cache', 'seed', '--repository', str(worktree), '--project', self.project]), \
                patch.object(cache, 'metadata', return_value={**self.info, 'cache': str(worktree / '.private')}), \
                patch.object(cache, 'start_background') as background:
            self.assertEqual(0, cache.main())
        self.assertIn(self.project, background.call_args.args[2])

    def test_registration_keeps_the_first_live_store_until_forced(self):
        self.publish()
        other = Path(self.temporary.name) / 'other-store'
        (other / 'published').mkdir(parents=True)
        self.assertFalse(cache.register_store(self.root, other))
        self.assertEqual(self.store.resolve(), cache.registry_path(self.root).resolve())
        self.assertTrue(cache.register_store(self.root, other, force=True))
        self.assertEqual(other.resolve(), cache.registry_path(self.root).resolve())
        shutil.rmtree(other)
        # A removed store no longer holds the registration.
        self.assertTrue(cache.register_store(self.root, self.store))
        self.assertEqual(self.store.resolve(), cache.registry_path(self.root).resolve())

    def test_origin_key_matches_https_and_ssh_urls_of_one_repository(self):
        keys = set()
        for url in ['https://github.com/nckrtl/orbit.git', 'git@github.com:nckrtl/orbit.git',
                    'ssh://git@GitHub.com/nckrtl/orbit', 'https://github.com/nckrtl/orbit/']:
            cache.git(self.root, 'remote', 'set-url', 'origin', url)
            keys.add(cache.origin_key(self.root))
            self.assertEqual(REPOSITORY, cache.github_repository(self.root))
        self.assertEqual(1, len(keys))
        cache.git(self.root, 'remote', 'set-url', 'origin', 'https://github.com/nckrtl/other.git')
        self.assertNotIn(cache.origin_key(self.root), keys)
        cache.git(self.root, 'remote', 'set-url', 'origin', 'https://gitlab.com/nckrtl/orbit.git')
        with self.assertRaisesRegex(ValueError, 'not a GitHub repository'):
            cache.github_repository(self.root)

    def test_lagging_registered_store_is_refreshed_once_per_failed_target(self):
        self.publish()
        clone = Path(self.temporary.name) / 'task workspace'
        cache.git(self.root, 'clone', str(self.root), str(clone))
        newer = self.advance('merged feature')
        cache.git(clone, 'fetch', 'origin')
        with patch.object(cache, 'start_background') as background:
            cache.request_refresh(clone, self.store)
        self.assertIn(self.project, background.call_args.args[2])
        self.assertEqual(self.common, background.call_args.args[0])
        with cache.queue_lock(self.store):
            state = cache.load_requests(self.store)
            for project in cache.PROJECTS:
                state['results'][project] = {'commit': newer, 'success': False, 'checks': []}
            cache.save_requests(self.store, state)
        with patch.object(cache, 'start_background') as background:
            cache.request_refresh(clone, self.store)
        background.assert_not_called()

    def test_worker_prunes_run_logs_that_no_result_names(self):
        self.store.mkdir(parents=True, exist_ok=True)
        named, stale, current = (self.store / name for name in ('run-named', 'run-stale', 'run-current'))
        for directory in (named, stale, current):
            directory.mkdir()
            (directory / 'check.log').write_text('log')
        state = {'results': {self.project: {'checks': [{'log': str(named / 'check.log')}]}}}
        cache.prune_runs(self.store, state, current)
        self.assertTrue(named.is_dir())
        self.assertTrue(current.is_dir())
        self.assertFalse(stale.exists())

    def test_maintenance_commands_run_without_agent_output_only_for_the_worker(self):
        with patch.dict(os.environ, {'CLAUDECODE': '1', 'AI_AGENT': 'claude', 'PAO_DISABLE': '0'}):
            environment = cache.maintenance_environment()
            self.assertEqual('1', environment['PAO_DISABLE'])
            self.assertEqual('0', os.environ['PAO_DISABLE'])

    def test_maintenance_environment_keeps_temporary_directories(self):
        with patch.dict(os.environ, {
            'TMPDIR': '/worktree-tmp', 'TMP': '/short-tmp', 'TEMP': '/legacy-tmp',
            'DATABASE_URL': 'sqlite:///setup.sqlite',
        }):
            environment = cache.maintenance_environment()
            self.assertEqual('/worktree-tmp', environment['TMPDIR'])
            self.assertEqual('/short-tmp', environment['TMP'])
            self.assertEqual('/legacy-tmp', environment['TEMP'])
            self.assertNotIn('DATABASE_URL', environment)

    def test_nested_command_uses_caller_temporary_directory(self):
        private = self.root / 'private-tmp'
        private.mkdir()
        with patch.dict(os.environ, {'TMPDIR': str(private)}):
            observed = cache.run(
                self.root, sys.executable, '-c', 'import os; print(os.environ["TMPDIR"], end="")',
            )
        self.assertEqual(str(private), observed)

    def test_invalid_main_graphs_are_not_published(self):
        older = self.commit
        commit = self.advance('next main')
        invalid = [
            {'baselines': {'feature': self.graph_at(commit)['baselines']['main']}},
            {'edges': {}}, {'files': ['/another/checkout/source.php']},
        ]
        for changes in invalid:
            with self.subTest(changes=changes), self.assertRaises(ValueError):
                self.publish(commit, graph={**self.graph_at(commit), **changes})
        for field, value in [('tree', {'source.php': 'dirty'}), ('results', {}),
                             ('results', {'failed': {'status': 8}}), ('sha', older)]:
            with self.subTest(field=field, value=value), self.assertRaises(ValueError):
                graph = self.graph_at(commit)
                graph['baselines']['main'][field] = value
                self.publish(commit, graph=graph)
        self.assertFalse(cache.publication_path(self.store, self.project).exists())

    def test_artifact_must_match_the_commit_run_and_test_configuration(self):
        commit = self.advance('next main')
        for manifest in [{'run_id': '999'}, {'commit': self.commit}, {'graph_sha256': 'b' * 64},
                         {'inputs': {'composer.lock': 'other'}}, {'project': 'apps/cli'}]:
            with self.subTest(manifest=manifest), self.assertRaises(ValueError):
                self.publish(commit, manifest=manifest)
        (self.root / self.project / 'composer.lock').write_text('{"packages":["changed"]}')
        changed = self.commit_change('dependencies change')
        # The artifact names the new commit, but CI tested it with the old lock file.
        artifact = self.artifact(commit, manifest={'commit': changed})
        with self.assertRaisesRegex(ValueError, 'test configuration differs'):
            cache.publish_artifact(self.common, self.store, self.project, artifact, changed, 123)
        self.assertFalse(cache.publication_path(self.store, self.project).exists())

    def test_publications_only_advance_along_main(self):
        newer = self.advance('next main')
        self.publish(newer)
        published = cache.publication_path(self.store, self.project).read_bytes()
        # A delayed older artifact leaves the newer publication in place.
        self.publish(self.commit, run_id=122)
        self.assertEqual(published, cache.publication_path(self.store, self.project).read_bytes())
        cache.git(self.root, 'checkout', '-q', '-b', 'side', self.commit)
        side = self.advance('side history')
        with self.assertRaises(cache.CommandFailure):
            self.publish(side, run_id=124)
        self.assertEqual(published, cache.publication_path(self.store, self.project).read_bytes())

    def test_missing_incompatible_corrupt_or_future_publication_is_a_cache_miss(self):
        root = self.feature()
        self.assertFalse(self.seed(root).exists())
        self.publish()
        path = cache.publication_path(self.store, self.project)
        snapshot = json.loads(path.read_text())
        future = self.advance('future')
        for changes in [{'runner': 'other'}, {'fingerprint': {}}, {'sha256': 'broken'},
                        {'tested_commit': future}, {'project': 'apps/cli'}, {'schema': 2}]:
            with self.subTest(changes=changes):
                path.write_text(json.dumps({**snapshot, **changes}))
                self.assertFalse(self.seed(root).exists())
        path.write_text('incomplete JSON')
        self.assertFalse(self.seed(root).exists())
        path.write_text('[]')
        self.assertFalse(self.seed(root).exists())

    def test_background_runner_survives_caller_removal_and_does_not_hold_closeout(self):
        with launched_workers() as launches:
            cache.start_background(self.common, self.store, [self.project])
        [(command, options)] = launches
        self.assertTrue(Path(command[1]).is_relative_to(self.store))
        self.assertEqual(Path(cache.__file__).read_bytes(), Path(command[1]).read_bytes())
        self.assertIn(str(self.common), command)
        self.assertTrue(options['start_new_session'])
        self.assertEqual(subprocess.DEVNULL, options['stdin'])

    def test_the_worker_always_runs_mains_copy_of_the_tool(self):
        main_source = Path(cache.__file__).read_bytes() + b'\n# main version\n'
        (self.root / 'bin/tia-cache').write_bytes(main_source)
        main = self.commit_change('main version of the tool')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', main)
        with launched_workers() as launches:
            cache.start_background(self.common, self.store, [self.project])
        self.assertEqual(main_source, Path(launches[0][0][1]).read_bytes())
        self.assertEqual(cache.digest(main_source), cache.load_requests(self.store)['pending'][self.project]['worker_key'])
        # Without the tool on main, no worker starts and no request is recorded.
        cache.git(self.root, 'rm', '-q', 'bin/tia-cache')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', self.commit_change('no tool'))
        with launched_workers() as launches, self.assertRaisesRegex(RuntimeError, 'no bin/tia-cache'):
            cache.start_background(self.common, self.store, ['apps/cli'])
        self.assertEqual([], launches)
        self.assertNotIn('apps/cli', cache.load_requests(self.store)['pending'])

    def test_a_foreground_refresh_also_runs_mains_copy(self):
        marker = Path(self.temporary.name) / 'worker-ran'
        (self.root / 'bin/tia-cache').write_text(
            f'#!/usr/bin/env python3\nimport sys\nopen({str(marker)!r}, "w").write(" ".join(sys.argv[1:]))\n')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', self.commit_change('main worker'))
        # main's stand-in worker records no result, so the requested project counts as failed.
        self.assertEqual(1, cache.refresh(self.common, self.store, [self.project]))
        self.assertTrue(marker.read_text().startswith('work --repository ' + str(self.common)))
        self.assertFalse(cache.active_worker(self.store))

    def test_a_branch_clone_seeding_from_the_registered_store_starts_only_mains_worker(self):
        self.publish()
        marker = Path(self.temporary.name) / 'worker-ran'
        main_worker = f'#!/usr/bin/env python3\nimport sys\nopen({str(marker)!r}, "w").write(" ".join(sys.argv[1:]))\n'
        (self.root / 'bin/tia-cache').write_text(main_worker)
        main = self.commit_change('main worker')
        cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', main)
        clone = Path(self.temporary.name) / 'branch clone'
        cache.git(self.root, 'clone', '-q', str(self.root), str(clone))
        cache.git(clone, 'checkout', '-q', '-b', 'unmerged-change')
        # The clone's branch carries another copy of the tool, as an unmerged pull request does.
        shutil.copyfile(cache.__file__, clone / 'bin/tia-cache')
        result = subprocess.run([sys.executable, str(clone / 'bin/tia-cache'), 'seed', '--repository', str(clone),
                                 '--project', self.project], capture_output=True, text=True,
                                env={**os.environ, 'ORBIT_MAIN_CACHE_STORE': ''})
        self.assertEqual(0, result.returncode, result.stdout + result.stderr)
        self.assertIn('Seeding from the main cache store registered for this origin', result.stdout)
        deadline = time.monotonic() + 10
        while not marker.exists() and time.monotonic() < deadline:
            time.sleep(0.05)
        self.assertTrue(marker.exists(), result.stdout + result.stderr)
        self.assertTrue(marker.read_text().startswith('work --repository ' + str(self.common)))
        self.assertEqual([main_worker], [runner.read_text() for runner in (self.store / 'runners').iterdir()])

    def test_removed_local_check_actions_are_rejected(self):
        for action in ('warm', 'publish'):
            with self.subTest(action=action), patch.object(sys, 'argv', ['tia-cache', action]), \
                    self.assertRaises(SystemExit):
                cache.main()
        with patch.object(sys, 'argv', ['tia-cache', 'register', '--development-instance=303']), \
                self.assertRaises(SystemExit):
            cache.main()

    def test_bootstrap_seeds_before_affected_tests_and_quality_checks_and_never_publishes(self):
        root = Path(self.temporary.name) / 'bootstrap'
        (root / 'bin').mkdir(parents=True)
        (root / 'bin/bootstrap').write_bytes(Path(cache.__file__).with_name('bootstrap').read_bytes())
        for project in cache.PROJECTS:
            (root / project).mkdir(parents=True)
        calls = root / 'calls'
        for name, script in {
            'composer': '#!/bin/sh\nif [ "$1" = install ]; then test "$COMPOSER_CACHE_DIR" = "$TIA_TEST_COMPOSER_CACHE" || exit 8; fi\necho "composer $*" >> "$TIA_TEST_CALLS"\nif [ "$1" = "$TIA_TEST_FAIL" ]; then exit 7; fi\nif [ "$1" != install ]; then test -z "$ORBIT_MAIN_CACHE_STORE"; fi\n',
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
                       'TIA_TEST_CALLS': str(calls), 'TIA_TEST_FAIL': '',
                       'COMPOSER_CACHE_DIR': str(root / 'downloads'),
                       'TIA_TEST_COMPOSER_CACHE': str(root / 'downloads')}
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

        # A clean bootstrap at fetched main checks everything and still leaves the main caches to CI.
        (root / '.gitignore').write_text('/calls\n/orbit-home/\n')
        cache.git(root, 'init', '-b', 'main')
        cache.git(root, 'config', 'user.name', 'Orbit')
        cache.git(root, 'config', 'user.email', 'orbit@example.test')
        cache.git(root, 'add', '.')
        cache.git(root, 'commit', '-m', 'bootstrap fixture')
        cache.git(root, 'update-ref', 'refs/remotes/origin/main', cache.git(root, 'rev-parse', 'HEAD'))
        local = {key: value for key, value in environment.items() if key != 'ORBIT_MAIN_CACHE_STORE'}
        local['TIA_TEST_STORE'] = ''
        calls.unlink(missing_ok=True)
        result = subprocess.run(['bash', str(root / 'bin/bootstrap')], capture_output=True, text=True, env=local)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertEqual(['cache seed --repository=' + str(root)],
                         [line for line in calls.read_text().splitlines() if line.startswith('cache ')])


class CiImportTest(CacheFixture):
    """The worker reads main CI through gh and publishes CI artifacts. It never runs a project command."""

    def setUp(self):
        super().setUp()
        self.fixture = Path(self.temporary.name) / 'gh-fixture'
        self.fixture.mkdir()
        tools = Path(self.temporary.name) / 'tools'
        tools.mkdir()
        (tools / 'gh').write_text(FAKE_GH)
        for name in ('composer', 'php', 'pest'):
            (tools / name).write_text(f'#!/bin/sh\necho "{name} $*" >> "$GH_FIXTURE/forbidden"\nexit 1\n')
        for tool in tools.iterdir():
            tool.chmod(0o755)
        self.runs = []
        self.clock = 0
        environment = patch.dict(os.environ, {'GH_FIXTURE': str(self.fixture),
                                              'PATH': str(tools) + os.pathsep + os.environ['PATH']})
        environment.start()
        self.addCleanup(environment.stop)
        repository_name = patch.object(cache, 'github_repository', return_value=REPOSITORY)
        repository_name.start()
        self.addCleanup(repository_name.stop)

    def ci_run(self, commit, docs='success', event='push', repository=REPOSITORY, jobs=None, artifact=True):
        """Record one finished main CI run. Each new run finishes after the previous ones."""
        self.clock += 1
        run_id = 1000 + len(self.runs)
        jobs = {'Web': 'success', 'Docs': docs, **(jobs or {})}
        (self.fixture / f'jobs-{run_id}.json').write_text(json.dumps({'jobs': [
            {'name': name, 'conclusion': conclusion, 'html_url': f'https://github.test/job/{name}'}
            for name, conclusion in jobs.items()]}))
        if artifact and jobs['Docs'] == 'success':
            artifact = self.artifact(commit, run_id) if artifact is True else artifact(commit, run_id)
            shutil.copytree(artifact, self.fixture / f'artifacts-{run_id}' / artifact.name)
        self.runs.append({
            'id': run_id, 'head_sha': commit, 'event': event, 'head_branch': 'main',
            'head_repository': {'full_name': repository}, 'conclusion': 'failure' if 'failure' in jobs.values() else 'success',
            'updated_at': f'2026-10-09T10:{self.clock:02d}:00Z', 'html_url': f'https://github.test/run/{run_id}',
        })
        (self.fixture / 'runs.json').write_text(json.dumps({'workflow_runs': list(reversed(self.runs))}))
        return run_id

    def refresh(self):
        return self.drain()

    def calls(self):
        path = self.fixture / 'calls'
        return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []

    def state(self):
        return cache.load_requests(self.store)

    def test_worker_publishes_the_newest_successful_main_artifact_without_running_tests(self):
        self.ci_run(self.commit)
        newer = self.advance('next main')
        run_id = self.ci_run(newer)
        self.assertEqual(0, self.refresh())
        snapshot = cache.read_publication(self.store, self.project)
        self.assertEqual((newer, {'run_id': run_id}), (snapshot['tested_commit'], snapshot['source']))
        self.assertFalse((self.fixture / 'forbidden').exists())
        result = self.state()['results'][self.project]
        self.assertTrue(result['success'])
        self.assertEqual((newer, newer, run_id), (result['commit'], result['published'], result['run_id']))
        report = cache.status(self.common, self.store)
        self.assertTrue(report['current'][self.project])
        self.assertEqual({}, report['correctness_failures'])
        self.assertEqual(self.graph_at(newer), json.loads((self.seed(self.feature()) / 'graph.json').read_text()))
        # Downloads are inputs only; a current store asks GitHub for the run list and nothing more.
        self.assertEqual([], list(self.store.glob('run-*/artifacts-*')))
        downloads = sum(call[:2] == ['run', 'download'] for call in self.calls())
        self.assertEqual(0, self.refresh())
        self.assertEqual(downloads, sum(call[:2] == ['run', 'download'] for call in self.calls()))

    def test_status_keeps_the_keys_that_merge_gates_read(self):
        failed = self.advance('broken main')
        self.ci_run(failed, docs='failure')
        self.assertEqual(1, self.refresh())
        report = cache.status(self.common, self.store)
        self.assertEqual({'schema', 'main', 'worker_active', 'pending', 'current', 'results', 'failures',
                          'correctness_failures', 'needed', 'refresh_log'}, set(report))
        failure = report['correctness_failures'][self.project]['ci']
        self.assertEqual(('ci', 'check_failure', failed), (failure['tool'], failure['kind'], failure['commit']))
        self.assertEqual(['Docs'], failure['jobs'])
        self.assertIn(self.project, report['failures'])

    def test_failed_main_run_holds_until_a_later_run_passes_on_that_commit_or_a_descendant(self):
        self.ci_run(self.commit)
        broken = self.advance('broken main')
        failed_run = self.ci_run(broken, docs='failure')
        self.assertEqual(1, self.refresh())
        failure = self.state()['correctness_failures'][self.project]['ci']
        self.assertEqual((broken, failed_run, 'https://github.test/run/%d' % failed_run),
                         (failure['commit'], failure['run_id'], failure['run_url']))
        # The newest successful commit stays published while main is broken.
        self.assertEqual(self.commit, cache.read_publication(self.store, self.project)['tested_commit'])
        fixed = self.advance('fix main')
        self.ci_run(fixed)
        self.assertEqual(0, self.refresh())
        self.assertEqual({}, self.state()['correctness_failures'])
        self.assertEqual(fixed, cache.read_publication(self.store, self.project)['tested_commit'])

    def test_a_full_run_failure_that_finishes_after_a_newer_success_stays_open(self):
        nightly = self.commit
        newer = self.advance('merged during the nightly run')
        self.ci_run(newer)
        self.ci_run(nightly, docs='failure', event='schedule')
        self.assertEqual(1, self.refresh())
        self.assertEqual(nightly, self.state()['correctness_failures'][self.project]['ci']['commit'])
        self.assertEqual(newer, cache.read_publication(self.store, self.project)['tested_commit'])
        self.ci_run(nightly, event='workflow_dispatch')
        self.assertEqual(0, self.refresh())
        self.assertEqual({}, self.state()['correctness_failures'])
        self.assertEqual(newer, cache.read_publication(self.store, self.project)['tested_commit'])

    def test_pull_requests_forks_unfinished_and_other_jobs_are_not_main_results(self):
        self.publish()
        self.ci_run(self.commit)
        newer = self.advance('next main')
        self.ci_run(newer, docs='failure', event='pull_request')
        self.ci_run(newer, docs='failure', repository='someone/orbit')
        self.ci_run(newer, docs='cancelled')
        self.ci_run(newer, jobs={'Web': 'failure'}, artifact=False)
        self.assertEqual(1, self.refresh())
        self.assertEqual({}, self.state()['correctness_failures'])
        # The run passed for Docs but published no artifact, so the import fails without a correctness hold.
        self.assertEqual('maintenance_failure', self.state()['results'][self.project]['checks'][-1]['kind'])
        self.assertEqual(self.commit, cache.read_publication(self.store, self.project)['tested_commit'])
        self.assertEqual(('failure', ['Gateway privileged']), cache.job_result(
            [{'name': 'Gateway', 'conclusion': 'success'}, {'name': 'Gateway privileged', 'conclusion': 'failure'}],
            'apps/gateway'))
        self.assertEqual((None, []), cache.job_result([{'name': 'Gateway privileged', 'conclusion': 'success'}],
                                                      'apps/gateway'))
        self.assertEqual(('failure', ['Gateway']), cache.job_result(
            [{'name': 'Gateway', 'conclusion': 'timed_out'}], 'apps/gateway'))

    def test_unreadable_ci_keeps_earlier_failures_and_publications(self):
        self.publish()
        legacy = {'tool': 'tia', 'kind': 'check_failure', 'exit_code': 2, 'commit': self.commit, 'error': 'beast only'}
        with cache.queue_lock(self.store):
            state = self.state()
            state['correctness_failures'] = {self.project: {'tia': legacy}}
            cache.save_requests(self.store, state)
        published = cache.publication_path(self.store, self.project).read_bytes()
        newer = self.advance('next main')
        self.ci_run(newer)
        (self.fixture / 'fail').write_text('')
        self.assertEqual(1, self.refresh())
        state = self.state()
        self.assertEqual({self.project: {'tia': legacy}}, state['correctness_failures'])
        self.assertEqual('setup', state['results'][self.project]['checks'][0]['tool'])
        self.assertIn('HTTP 503', state['results'][self.project]['checks'][0]['error'])
        self.assertEqual(published, cache.publication_path(self.store, self.project).read_bytes())
        # Once CI is readable, a passing run on a descendant replaces the failure that local checks recorded.
        (self.fixture / 'fail').unlink()
        self.assertEqual(0, self.refresh())
        self.assertEqual({}, self.state()['correctness_failures'])
        self.assertEqual(newer, cache.read_publication(self.store, self.project)['tested_commit'])

    def test_no_finished_project_job_keeps_the_correctness_state(self):
        self.ci_run(self.commit, docs='cancelled')
        self.assertEqual(1, self.refresh())
        result = self.state()['results'][self.project]
        self.assertEqual('maintenance_failure', result['checks'][0]['kind'])
        self.assertNotIn('ci', result)
        self.assertEqual({}, self.state()['correctness_failures'])

    def test_a_refused_artifact_is_a_maintenance_failure_and_keeps_the_publication(self):
        self.publish()
        published = cache.publication_path(self.store, self.project).read_bytes()
        newer = self.advance('next main')
        self.ci_run(newer, artifact=lambda commit, run_id: self.artifact(commit, run_id, manifest={'run_id': '1'}))
        self.assertEqual(1, self.refresh())
        check = self.state()['results'][self.project]['checks'][-1]
        self.assertEqual(('import', 'maintenance_failure'), (check['tool'], check['kind']))
        self.assertIn('another run', Path(check['log']).read_text())
        self.assertEqual({}, self.state()['correctness_failures'])
        self.assertEqual(published, cache.publication_path(self.store, self.project).read_bytes())


class RealPestCacheTest(unittest.TestCase):
    def test_real_pest_main_graph_from_ci_selects_tests_in_the_next_worktrees(self):
        repository = Path(cache.__file__).resolve().parent.parent
        sdk = repository / 'packages/php-sdk'
        self.assertTrue((sdk / 'vendor/autoload.php').is_file(), 'Run bin/bootstrap --skip-checks first.')
        coverage = subprocess.run(
            ['php', '-r', 'exit(extension_loaded("pcov") || extension_loaded("xdebug") ? 0 : 1);'],
            check=False,
        )
        self.assertEqual(
            0,
            coverage.returncode,
            'Real Pest TIA records a graph only with PCOV or Xdebug. Install one for the PHP on PATH, '
            'on macOS with `brew install shivammathur/extensions/pcov@8.5`.',
        )
        with tempfile.TemporaryDirectory(prefix='orbit-real-tia-') as temporary:
            root = Path(temporary).resolve() / 'primary'
            project = 'apps/docs'
            directory = root / project
            (directory / 'src').mkdir(parents=True)
            (directory / 'tests').mkdir()
            (root / '.gitignore').write_text('**/vendor/\n**/.orbit-tia/\n**/.executed*\n')
            manifest = json.loads((sdk / 'composer.json').read_text())
            manifest['scripts'] = {'test:affected': 'vendor/bin/pest --parallel --processes=2 --tia --compact'}
            (directory / 'composer.json').write_text(json.dumps(manifest))
            shutil.copyfile(sdk / 'composer.lock', directory / 'composer.lock')
            (directory / 'phpunit.xml').write_text(
                '<phpunit bootstrap="vendor/autoload.php"><testsuites><testsuite name="unit">'
                '<directory>tests</directory></testsuite></testsuites><source><include>'
                '<directory>src</directory></include></source></phpunit>')
            (directory / 'tests/Pest.php').write_text(
                "<?php\n\npest()->tia()->defaultBranch('main')->locally()->filtered()"
                "->directory(dirname(__DIR__).'/.orbit-tia');\n"
                "require_once dirname(__DIR__).'/src/Value.php';\n")
            value = directory / 'src/Value.php'
            value.write_text("<?php\n\nfunction cacheValue(string $value): string\n{\n    return $value;\n}\n")
            (directory / 'tests/ValueTest.php').write_text(
                "<?php\n\nit('keeps dataset bytes', function (string $value): void {\n"
                "    file_put_contents(dirname(__DIR__).'/.executed', 'run\\n', FILE_APPEND);\n"
                "    expect(cacheValue($value))->toBe($value);\n})->with(['text', \"\\xff\"]);\n")
            (directory / 'tests/OtherTest.php').write_text(
                "<?php\n\nit('keeps another result', function (): void {\n"
                "    file_put_contents(dirname(__DIR__).'/.executed-other', 'run');\n"
                "    expect(1 + 1)->toBe(2);\n});\n")
            for args in (('init', '-b', 'main'), ('config', 'user.name', 'Orbit'),
                         ('config', 'user.email', 'orbit@example.test'), ('remote', 'add', 'origin', str(root))):
                cache.git(root, *args)
            common = cache.common_directory(root)
            store = cache.cache_store(common)

            def commit(message):
                cache.git(root, 'add', '.')
                cache.git(root, 'commit', '-m', message)
                sha = cache.git(root, 'rev-parse', 'HEAD')
                cache.git(root, 'update-ref', 'refs/remotes/origin/main', sha)
                cache.git(root, 'symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main')
                return sha

            def install(checkout):
                shutil.copytree(sdk / 'vendor', checkout / project / 'vendor',
                                ignore=shutil.ignore_patterns('cache', 'pint.cache'))

            def ci(sha, run_id):
                """Run the project on main as CI does, then publish its graph as the worker does."""
                cache.run(directory, 'composer', 'test:affected')
                artifact = Path(temporary) / f'artifact-{run_id}'
                artifact.mkdir()
                graph = (directory / '.orbit-tia/graph.json').read_bytes()
                (artifact / 'graph.json').write_bytes(graph)
                (artifact / 'manifest.json').write_text(json.dumps({
                    'schema': 1, 'project': project, 'commit': sha, 'run_id': str(run_id),
                    'graph_sha256': cache.digest(graph),
                    'inputs': {name: cache.digest((directory / name).read_bytes())
                               for name in cache.TEST_INPUTS if (directory / name).is_file()},
                }))
                cache.publish_artifact(common, store, project, artifact, sha, run_id)
                (directory / '.executed').unlink(missing_ok=True)
                (directory / '.executed-other').unlink(missing_ok=True)
                return cache.read_publication(store, project)

            old = commit('older main')
            install(root)
            snapshot = ci(old, 1)
            self.assertEqual(3, len(json.loads(snapshot['graph'])['baselines']['main']['results']))
            value.write_text(value.read_text().replace('return $value;', 'return substr($value, 0);'))
            current = commit('newer main')

            # A worktree seeded from the older main graph runs only the tests that the change affects.
            first = Path(temporary).resolve() / 'first'
            cache.git(root, 'worktree', 'add', '-b', 'first-task', str(first), current)
            install(first)
            cache.seed(first, store, [project])
            self.assertEqual(snapshot['graph'], (first / project / '.orbit-tia/graph.json').read_text())
            output = cache.run(first / project, 'composer', 'test:affected')
            self.assertEqual('run\\nrun\\n', (first / project / '.executed').read_text())
            self.assertFalse((first / project / '.executed-other').exists(), output)

            # After CI records the newer main, the next worktree starts at that commit and runs nothing.
            snapshot = ci(current, 2)
            self.assertEqual(current, snapshot['tested_commit'])
            self.assertEqual(3, len(json.loads(snapshot['graph'])['baselines']['main']['results']))
            second = Path(temporary).resolve() / 'second'
            cache.git(root, 'worktree', 'add', '-b', 'second-task', str(second), current)
            install(second)
            cache.seed(second, store, [project])
            output = cache.run(second / project, 'composer', 'test:affected')
            self.assertFalse((second / project / '.executed').exists(), output)
            self.assertFalse((second / project / '.executed-other').exists(), output)


class MaintenanceQueueTest(CacheFixture):
    def setUp(self):
        super().setUp()
        for name, value in (('github_repository', REPOSITORY),
                            ('ci_history', None)):
            patcher = patch.object(cache, name, return_value=value) if value else patch.object(
                cache, name, side_effect=lambda common, repository, projects: {
                    project: {'success': None, 'failure': None} for project in projects})
            patcher.start()
            self.addCleanup(patcher.stop)

    def submit(self, projects=None):
        with cache.queue_lock(self.store):
            return cache.enqueue(self.common, self.store, projects or [self.project])

    @staticmethod
    def passed(common, store, project, commit, *arguments):
        return {'commit': commit, 'success': True, 'checks': []}

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

        def check(common, store, project, commit, *arguments):
            calls.append(project)
            return {'commit': commit, 'success': True, 'checks': []}

        with patch.object(cache, 'refresh_project', side_effect=check):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual(['apps/docs', 'apps/cli'], calls)
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_refresh_fetches_an_external_merge_before_reading_ci(self):
        remote = self.advance('merged outside closeout')
        self.assertEqual(self.commit, cache.known_main(self.common))
        with patch.object(cache, 'refresh_project', side_effect=self.passed) as checks:
            self.assertEqual(0, self.drain())
        self.assertEqual(remote, checks.call_args.args[3])
        self.assertEqual(remote, cache.known_main(self.common))

    def test_refresh_releases_lock_and_preserves_other_project_progress_on_failure(self):
        failed = {'commit': self.commit, 'success': False, 'checks': [{'tool': 'import', 'exit_code': 1}]}
        passed = {'commit': self.commit, 'success': True, 'checks': [{'tool': 'import', 'exit_code': 0}]}
        with patch.object(cache, 'refresh_project', side_effect=[failed, passed]) as refresh:
            self.assertEqual(1, self.drain(['apps/cli', 'apps/docs']))
            self.assertEqual(2, refresh.call_count)
        self.assertFalse(cache.load_requests(self.store)['results']['apps/cli']['success'])
        self.assertTrue(cache.load_requests(self.store)['results']['apps/docs']['success'])
        self.assertFalse(cache.active_worker(self.store))
        with patch.object(cache, 'refresh_project', return_value=passed):
            self.assertEqual(0, self.drain(['apps/docs']))

    def test_partial_request_upgrades_all_retained_projects(self):
        with patch.object(cache, 'worker_key', return_value='old-runner'):
            self.submit(['apps/cli', 'apps/docs'])
        with patch.object(cache, 'worker_key', return_value='new-runner'):
            state = self.submit(['apps/docs'])
            self.assertEqual({'new-runner'}, {request['worker_key'] for request in state['pending'].values()})
            with patch.object(cache, 'refresh_project', side_effect=self.passed) as checks:
                self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
            self.assertEqual(2, checks.call_count)
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_failed_command_includes_stdout_in_the_failure(self):
        with self.assertRaises(cache.CommandFailure) as raised:
            cache.run(
                self.root, sys.executable, '-c',
                'import sys; sys.stdout.write("gh failed on stdout\\n"); '
                'sys.stderr.write("hint on stderr\\n"); sys.exit(2)',
            )
        self.assertIn('gh failed on stdout', str(raised.exception))
        self.assertIn('hint on stderr', str(raised.exception))
        self.assertEqual(2, raised.exception.exit_code)

    def test_failed_command_keeps_the_tail_of_huge_stdout(self):
        with self.assertRaises(cache.CommandFailure) as raised:
            cache.run(
                self.root, sys.executable, '-c',
                'import sys; sys.stdout.write("head-marker\\n" + ("x" * 80000) + "\\ntail-marker\\n"); '
                'sys.exit(1)',
            )
        message = str(raised.exception)
        self.assertIn('tail-marker', message)
        self.assertNotIn('head-marker', message)
        self.assertLessEqual(len(message), cache.FAILURE_OUTPUT_LIMIT)

    def test_command_timeout_stops_descendants_before_returning(self):
        marker = self.root / 'descendant-ready'
        script = '''import fcntl, os, signal, sys, time
signal.signal(signal.SIGTERM, signal.SIG_IGN)
if os.fork() == 0:
    # Leave the output pipes, so only the process group ties this descendant to the command.
    devnull = os.open(os.devnull, os.O_WRONLY)
    os.dup2(devnull, 1)
    os.dup2(devnull, 2)
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
                    cache.run(self.root, sys.executable, '-c', script)

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

    def test_main_request_arriving_during_refresh_is_not_acknowledged_by_old_batch(self):
        self.submit()
        commits = []

        def check(common, store, project, commit, *arguments):
            commits.append(commit)
            if len(commits) == 1:
                self.next_commit = self.advance('next main')
                cache.git(self.root, 'update-ref', 'refs/remotes/origin/main', self.next_commit)
                self.submit()
            return {'commit': commit, 'success': True, 'checks': []}

        with patch.object(cache, 'refresh_project', side_effect=check):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual([self.commit, self.next_commit], commits)
        state = cache.load_requests(self.store)
        self.assertEqual({}, state['pending'])
        self.assertEqual(self.next_commit, state['results'][self.project]['commit'])

    def test_interrupted_worker_leaves_request_for_a_later_worker(self):
        self.submit()
        with patch.object(cache, 'refresh_project', side_effect=SystemExit('interrupted')):
            with self.assertRaises(SystemExit):
                cache.drain(self.common, self.store, cache.acquire_worker(self.store))
        self.assertFalse(cache.active_worker(self.store))
        self.assertIn(self.project, cache.load_requests(self.store)['pending'])
        with patch.object(cache, 'refresh_project', side_effect=self.passed):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_launch_failure_keeps_a_durable_request_without_an_active_owner(self):
        with launched_workers(OSError('spawn failed')):
            with self.assertRaisesRegex(OSError, 'spawn failed'):
                cache.start_background(self.common, self.store, [self.project])
        self.assertIn(self.project, cache.load_requests(self.store)['pending'])
        self.assertFalse(cache.active_worker(self.store))

    def test_old_worker_leaves_upgraded_request_for_the_new_runner(self):
        with patch.object(cache, 'worker_key', return_value='new-runner'):
            self.submit()
        with patch.object(cache, 'worker_key', return_value='old-runner'), \
                patch.object(cache, 'refresh_project') as checks:
            cache.drain(self.common, self.store, cache.acquire_worker(self.store))
            checks.assert_not_called()
        self.assertFalse(cache.active_worker(self.store))
        self.assertIn(self.project, cache.load_requests(self.store)['pending'])
        with patch.object(cache, 'worker_key', return_value='new-runner'), \
                patch.object(cache, 'refresh_project', side_effect=self.passed):
            self.assertEqual(0, cache.drain(self.common, self.store, cache.acquire_worker(self.store)))
        self.assertEqual({}, cache.load_requests(self.store)['pending'])

    def test_status_reads_remote_main_without_advancing_primary_or_queueing_work(self):
        old = self.commit
        current = self.advance('next')
        self.assertEqual(old, cache.known_main(self.common))
        result = cache.status(self.common, self.store, remote=True)
        self.assertEqual(current, result['main'])
        self.assertTrue(result['needed'])
        self.assertEqual({}, result['pending'])
        self.assertFalse((self.store / 'requests.json').exists())
        self.assertEqual(old, cache.known_main(self.common))


class QualityPublicationTest(CacheFixture):
    def publish_quality(self, commit=None, run_id=123, data=b'portable tool result', **options):
        return self.publish(commit, run_id, quality={tool: data for tool in cache.QUALITY}, **options)

    def seed_quality(self, worktree):
        return subprocess.run([sys.executable, str(Path(cache.__file__).with_name('worktree-cache')),
                               '--worktree', str(worktree)], capture_output=True, text=True)

    def test_ci_quality_caches_seed_without_a_live_donor_and_writes_stay_private(self):
        self.publish_quality()
        feature = self.feature()
        result = self.seed_quality(feature)
        self.assertEqual(0, result.returncode, result.stderr)
        self.assertIn('published main', result.stdout)
        for tool in cache.QUALITY:
            path = feature / self.project / cache.QUALITY[tool][1]
            self.assertEqual(b'portable tool result', path.read_bytes())
            path.write_bytes(b'private edit')
            publication = json.loads(cache.quality_path(self.store, self.project, tool).read_text())
            self.assertEqual(b'portable tool result', cache.quality_data(publication, self.project, tool))
            self.assertEqual({'run_id': 123}, publication['source'])
        self.seed_quality(feature)
        self.assertEqual(b'private edit', (feature / self.project / cache.QUALITY['pint'][1]).read_bytes())

    def test_a_corrupt_ci_quality_cache_keeps_the_previous_publication(self):
        self.publish_quality()
        path = cache.quality_path(self.store, self.project, 'pint')
        original = path.read_bytes()
        newer = self.advance('new main')
        artifact = self.artifact(newer, quality={'pint': b'new result'})
        (artifact / 'quality/pint').write_bytes(b'tampered')
        with self.assertRaisesRegex(ValueError, 'pint cache checksum differs'):
            cache.publish_artifact(self.common, self.store, self.project, artifact, newer, 123)
        self.assertEqual(original, path.read_bytes())

    def test_corrupt_incompatible_and_future_quality_publications_are_not_seeded(self):
        self.publish_quality()
        feature = self.feature()
        path = cache.quality_path(self.store, self.project, 'pint')
        original = json.loads(path.read_text())
        future = self.advance('future')
        for change in [{'sha256': 'corrupt'}, {'fingerprint': 'wrong'}, {'tested_commit': future},
                       {'project': 'apps/cli'}, {'tool': 'phpstan'}, {'data': 'invalid base64'}]:
            with self.subTest(change=change):
                path.write_text(json.dumps({**original, **change}))
                self.assertEqual(0, self.seed_quality(feature).returncode)
                self.assertFalse((feature / self.project / cache.QUALITY['pint'][1]).exists())

    def test_a_ci_runtime_of_another_php_minor_is_not_seeded(self):
        self.publish_quality(php='7.4')
        feature = self.feature()
        self.assertEqual(0, self.seed_quality(feature).returncode)
        self.assertFalse((feature / self.project / cache.QUALITY['pint'][1]).exists())


class ReviewGateTest(unittest.TestCase):
    setUp = lambda self: repository(self, project_files=False)
    commit_change = CacheFixture.commit_change

    # Reuse repository setup, without repeating the cache test cases.
    def gate_fixture(self):
        (self.root / 'bin').mkdir()
        runner = self.root / 'bin/review-check'
        runner.write_bytes(Path(cache.__file__).with_name('review-check').read_bytes())
        runner.chmod(0o755)
        for name in ('tia-cache', 'worktree-cache'):
            seed = self.root / 'bin' / name
            seed.write_text(f'#!/bin/sh\nprintf "{name} %s\\n" "$*" >> "$GATE_TEST_SEEDS"\n')
            seed.chmod(0o755)
        docs_impact = self.root / 'bin/docs-impact'
        docs_impact.write_text("""#!/bin/sh
printf '%s|%s\\n' "$PWD" "$*" >> "$GATE_TEST_DOCS"
if [ "$GATE_TEST_MODE" = docs ]; then exit 5; fi
""")
        docs_impact.chmod(0o755)
        vocabulary = self.root / 'bin/project-vocabulary'
        vocabulary.write_text('#!/bin/sh\nexit 0\n')
        vocabulary.chmod(0o755)
        for project in cache.PROJECTS:
            directory = self.root / project
            directory.mkdir(parents=True)
            (directory / '.gitkeep').write_text('')
            pest = directory / 'vendor/bin/pest'
            pest.parent.mkdir(parents=True)
            pest.write_text('#!/bin/sh\nexit 0\n')
            pest.chmod(0o755)
        (self.root / '.gitignore').write_text('.orbit-tia/\n')
        self.commit_change('gate fixture')
        fake_bin = Path(self.temporary.name) / 'fake-bin'
        fake_bin.mkdir()
        composer = fake_bin / 'composer'
        composer.write_text("""#!/bin/sh
printf '%s|%s\\n' "$PWD" "$*" >> "$GATE_TEST_CALLS"
if [ "$1" = test:affected ]; then
    tia_directory="${ORBIT_TIA_DIRECTORY:-.orbit-tia}"
    mkdir -p "$tia_directory"
    printf '[]\n' > "$tia_directory/affected.json"
fi
if [ "$1" = check ] && [ "${PWD##*/}" = e2e ]; then
    case "$GATE_TEST_MODE" in
        fail) exit 7 ;;
        mutate) echo changed > "$GATE_TEST_ROOT/source.php" ;;
    esac
fi
""")
        composer.chmod(0o755)
        self.gate_env = {**os.environ, 'PATH': str(fake_bin) + os.pathsep + os.environ['PATH'],
                         'GATE_TEST_CALLS': str(self.common / 'calls'), 'GATE_TEST_ROOT': str(self.root),
                         'GATE_TEST_SEEDS': str(self.common / 'seeds'), 'GATE_TEST_DOCS': str(self.common / 'docs')}
        return runner

    def test_gate_seeds_quality_and_test_caches_before_checks(self):
        runner = self.gate_fixture()
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        root = self.root.resolve()
        self.assertEqual([f'worktree-cache --worktree {root}', f'tia-cache seed --repository {root}'],
                         (self.common / 'seeds').read_text().splitlines())

    def test_gate_runs_all_five_projects_and_records_builder_and_exact_candidate(self):
        runner = self.gate_fixture()
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        self.assertTrue(report['passed'])
        self.assertEqual('builder', report['role'])
        self.assertEqual(cache.git(self.root, 'rev-parse', 'HEAD'), report['candidate'])
        architecture_tests = {
            'apps/cli': ['tests/Feature/CommandSurfaceTest.php'],
            'apps/gateway': ['tests/Unit/Architecture',
                             'tests/Feature/Infrastructure/Instances/ConfiguredOriginReadTest.php',
                             'tests/Feature/Infrastructure/Caddy/CaddyPublicationLockTest.php'],
            'apps/e2e': ['tests/Unit/E2E/ProofFixtureContractTest.php',
                         'tests/Unit/E2E/ProofFixtureShellContractTest.php'],
            'packages/php-sdk': ['tests/Unit/SuccessRequestIdBoundaryTest.php',
                                 'tests/Unit/RepositoryGuidanceTest.php',
                                 'tests/Unit/Requests/Workspaces/WorkspaceRequestsTest.php',
                                 'tests/Unit/Requests/Deployments/DeploymentRequestsTest.php'],
        }
        self.assertEqual(self.commit, report['base'])
        expected = [('repository', ['bin/docs-impact', '--gate', '--base', self.commit]),
                    ('repository', ['bin/project-vocabulary'])]
        for project in cache.PROJECTS:
            expected.extend((project, command) for command in [
                ['composer', 'validate', '--strict'], ['composer', 'check'], ['composer', 'test:affected']])
            expected.extend((project, ['vendor/bin/pest', path])
                            for path in architecture_tests.get(project, []))
        self.assertEqual(expected, [(item['project'], item['command']) for item in report['checks']])
        self.assertEqual([f'{self.root.resolve()}|--gate --base {self.commit}'],
                         (self.common / 'docs').read_text().splitlines())
        self.assertEqual(15, len((self.common / 'calls').read_text().splitlines()))

    def test_gate_rejects_failed_checks_and_candidate_mutation(self):
        runner = self.gate_fixture()
        for mode in ['docs', 'fail', 'mutate']:
            with self.subTest(mode=mode):
                result = subprocess.run([str(runner)], env={**self.gate_env, 'GATE_TEST_MODE': mode},
                                        capture_output=True, text=True)
                self.assertEqual(1, result.returncode, result.stdout + result.stderr)
        for path in (self.common / 'orbit-checks').glob('*/*/result.json'):
            self.assertFalse(json.loads(path.read_text())['passed'])

    def test_gate_checks_uncommitted_input_as_it_is(self):
        runner = self.gate_fixture()
        (self.root / 'source.php').write_text('uncommitted')
        status = cache.git(self.root, 'status', '--porcelain')
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        self.assertIn('with uncommitted changes', result.stdout)
        report = json.loads(next((self.common / 'orbit-checks').glob('*/*/result.json')).read_text())
        self.assertTrue(report['passed'])
        self.assertFalse(report['committed'])
        self.assertNotEqual(cache.git(self.root, 'rev-parse', 'HEAD^{tree}'), report['tree'])
        self.assertEqual(15, len((self.common / 'calls').read_text().splitlines()))
        self.assertEqual(status, cache.git(self.root, 'status', '--porcelain'))

    @unittest.skipUnless(shutil.which('setfacl') and shutil.which('getfacl'), 'needs setfacl and getfacl')
    def test_review_directory_keeps_named_acl_entries_effective(self):
        runner = self.gate_fixture()
        # Like the check host, where the Git common dir gives orbit-worker d:u:orbit-worker:rwX.
        subprocess.run(['setfacl', '-d', '-m', 'u:nobody:rwX,m::rwx', str(self.common)], check=True)
        result = subprocess.run([str(runner)], env=self.gate_env, capture_output=True, text=True)
        self.assertEqual(0, result.returncode, result.stderr + result.stdout)
        report = next((self.common / 'orbit-checks').glob('*/review-*/result.json'))
        directory = report.parent
        self.assertNotEqual('---', acl_entry(directory.parent, 'mask::'))
        self.assertNotEqual('---', acl_entry(directory, 'mask::'), 'mkdtemp mode 0700 set the ACL mask to ---')
        self.assertEqual('---', acl_entry(directory, 'other::'))
        rights = effective_named_rights(directory, 'nobody')
        self.assertEqual(('r', 'x'), (rights[0], rights[2]), rights)
        logs = list(directory.glob('*.log'))
        self.assertTrue(logs)
        for path in [report, *logs]:
            self.assertEqual('r', effective_named_rights(path, 'nobody')[0], path.name)


def acl_entry(path, prefix):
    """Return the rights of the first getfacl entry that starts with prefix."""
    lines = subprocess.run(['getfacl', '-cp', str(path)], capture_output=True, text=True, check=True).stdout.splitlines()
    return next(line.split()[0][len(prefix):] for line in lines if line.startswith(prefix))


def effective_named_rights(path, user):
    """A named user entry's rights after the ACL mask, such as r-x."""
    named, mask = acl_entry(path, f'user:{user}:'), acl_entry(path, 'mask::')
    return ''.join(right if limit != '-' else '-' for right, limit in zip(named, mask))


if __name__ == '__main__':
    unittest.main(verbosity=2)
