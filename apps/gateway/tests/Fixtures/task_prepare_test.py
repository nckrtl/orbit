"""Task preparation boundaries with real Git repositories and subprocesses."""
import fcntl
import base64
import gzip
import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

sys.dont_write_bytecode = True
spec = importlib.util.spec_from_file_location('prepare', sys.argv.pop(1))
runner = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runner)


class PreparationTest(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory(prefix='orbit preparation ')
        self.addCleanup(self.temp.cleanup)
        self.root = Path(self.temp.name) / 'task'
        (self.root / 'bin').mkdir(parents=True)
        self.bootstrap = self.root / 'bin/bootstrap'
        self.bootstrap.write_text('''#!/usr/bin/env python3
import json, os
from pathlib import Path
assert len(__import__('sys').argv) == 1
assert 'DB_DATABASE' not in os.environ and 'APP_KEY' not in os.environ
assert 'ORBIT_TIA_DIRECTORY' not in os.environ
state=Path(os.environ['ORBIT_HOME'])
assert state == Path.cwd()/'.git/orbit-task-setup/runtime'
state.mkdir(parents=True, exist_ok=True)
store=Path(os.environ['ORBIT_MAIN_CACHE_STORE'])
files={str(p.relative_to(store)):p.read_text() for p in store.rglob('*.json')}
(state/'observed.json').write_text(json.dumps(files))
print('setup ran')
''')
        self.bootstrap.chmod(0o755)
        for command in (['init', '-b', 'task-1'], ['add', '.'],
                        ['-c', 'user.name=Tests', '-c', 'user.email=test@example.test', 'commit', '-m', 'fixture']):
            subprocess.run(['git', '-C', str(self.root), *command], check=True, capture_output=True)
        self.commit = runner.git(self.root, 'rev-parse', 'HEAD')
        self.steps = [{'name': 'bootstrap', 'command': 'bin/bootstrap', 'timeout_seconds': 845}]
        self.lifecycle = (Path(runner.__file__).parent.parent / 'instances/lifecycle.py').read_text()

    def run_setup(self, publications=None, **options):
        with patch.dict(os.environ, {'DB_DATABASE': '/live/database', 'APP_KEY': 'private',
                                     'ORBIT_HOME': '/live/runtime', 'ORBIT_TIA_DIRECTORY': '/shared/cache'}):
            return runner.prepare(self.root, 'task-1', self.commit, publications or {}, self.steps, self.lifecycle, **options)

    def test_copies_only_publications_and_consumes_bootstrap_success_with_isolated_state(self):
        publications = {'published/apps-cli.json': '{"graph":"main"}'}
        result = self.run_setup(publications)
        self.assertTrue(result['prepared'])
        state = self.root / '.git/orbit-task-setup'
        self.assertEqual(publications, json.loads((state / 'runtime/observed.json').read_text()))
        self.assertEqual('Running setup step: bootstrap\nsetup ran\n', (state / 'bootstrap.log').read_text())
        self.assertEqual([], list(state.glob('main-cache-*')))
        self.assertEqual(0o600, (state / 'bootstrap.log').stat().st_mode & 0o777)

    def test_missing_caches_do_not_block_setup(self):
        self.assertEqual(0, self.run_setup()['cache_files'])

    def test_failure_is_not_accepted_and_retry_uses_same_checkout(self):
        original = self.bootstrap.read_text()
        self.bootstrap.write_text('#!/bin/sh\necho failed\nexit 9\n')
        with self.assertRaisesRegex(ValueError, 'Setup step failed'):
            self.run_setup()
        self.bootstrap.write_text(original)
        self.assertTrue(self.run_setup()['prepared'])

    def test_timeout_releases_lock_and_never_reports_success(self):
        self.bootstrap.write_text('#!/bin/sh\nsleep 10\n')
        self.steps[0]['timeout_seconds'] = 0.1
        with self.assertRaisesRegex(ValueError, 'Setup step failed'):
            self.run_setup()
        self.bootstrap.write_text('#!/bin/sh\nexit 0\n')
        self.assertTrue(self.run_setup()['prepared'])

    def test_concurrent_setup_is_refused(self):
        state = self.root / '.git/orbit-task-setup'
        state.mkdir()
        with (state / 'setup.lock').open('w') as lock:
            fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
            with self.assertRaises(BlockingIOError):
                self.run_setup()

    def test_signal_interrupts_the_bootstrap_child(self):
        marker = self.root / '.git/child.pid'
        self.bootstrap.write_text('#!/bin/sh\necho $$ > .git/child.pid\nsleep 10\n')
        code = json.dumps({'publications': base64.b64encode(gzip.compress(b'{}')).decode(),
                           'steps': self.steps, 'lifecycle': self.lifecycle})
        process = subprocess.Popen([sys.executable, '-c', Path(runner.__file__).read_text(), str(self.root), 'task-1', self.commit],
                                   stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE, text=True)
        process.stdin.write(code)
        process.stdin.close()
        process.stdin = None
        try:
            deadline = time.monotonic() + 3
            while not marker.exists() and time.monotonic() < deadline:
                time.sleep(0.01)
            self.assertTrue(marker.exists())
            pid = int(marker.read_text())
            process.terminate()
            stdout, stderr = process.communicate(timeout=3)
            self.assertNotEqual(0, process.returncode)
            self.assertFalse(json.loads(stdout)['prepared'])
            with self.assertRaises(ProcessLookupError):
                os.kill(pid, 0)
        finally:
            if process.poll() is None:
                process.kill()
                process.communicate()

    def test_configured_steps_run_in_order_and_stop_at_failure(self):
        self.steps = [
            {'name': 'first', 'command': 'printf first > .git/order', 'timeout_seconds': 10},
            {'name': 'second', 'command': 'printf second >> .git/order; exit 3', 'timeout_seconds': 10},
            {'name': 'third', 'command': 'touch .git/third', 'timeout_seconds': 10},
        ]
        with self.assertRaisesRegex(ValueError, 'Setup step failed'):
            self.run_setup()
        self.assertEqual('firstsecond', (self.root / '.git/order').read_text())
        self.assertFalse((self.root / '.git/third').exists())
        self.steps[1]['command'] = 'printf second >> .git/order'
        self.assertTrue(self.run_setup()['prepared'])
        self.assertTrue((self.root / '.git/third').exists())

    def test_no_setup_and_exhausted_total_budget_never_report_success(self):
        with self.assertRaisesRegex(ValueError, 'deadline exceeded'):
            self.run_setup(timeout=0)
        self.steps = []
        with self.assertRaisesRegex(ValueError, 'configured setup steps'):
            self.run_setup()

    def test_output_log_remains_private_when_reused(self):
        self.run_setup()
        log = self.root / '.git/orbit-task-setup/bootstrap.log'
        log.chmod(0o644)
        self.run_setup()
        self.assertEqual(0o600, log.stat().st_mode & 0o777)

    def test_cache_paths_cannot_escape_or_import_mutable_feature_state(self):
        for path in ('../escape', 'private/graph.json', 'published/unknown.json'):
            with self.subTest(path=path), self.assertRaisesRegex(ValueError, 'Unexpected cache'):
                self.run_setup({path: 'data'})
        self.assertFalse((self.root / '.git/orbit-task-setup/bootstrap.log').exists())

    def test_changed_branch_is_refused_before_bootstrap(self):
        subprocess.run(['git', '-C', str(self.root), 'checkout', '-b', 'another'], check=True, capture_output=True)
        with self.assertRaisesRegex(ValueError, 'identity differs'):
            self.run_setup()

    def test_source_change_during_bootstrap_is_refused(self):
        self.bootstrap.write_text('#!/bin/sh\ngit checkout -b changed\n')
        with self.assertRaisesRegex(ValueError, 'identity changed'):
            self.run_setup()

    def test_symlinked_state_cannot_overwrite_another_file(self):
        state = self.root / '.git/orbit-task-setup'
        state.mkdir()
        protected = Path(self.temp.name) / 'protected'
        protected.write_text('preserve')
        (state / 'bootstrap.log').symlink_to(protected)
        with self.assertRaisesRegex(ValueError, 'symlink'):
            self.run_setup()
        self.assertEqual('preserve', protected.read_text())


if __name__ == '__main__':
    unittest.main()
