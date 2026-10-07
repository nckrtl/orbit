from pathlib import Path
import os
import runpy
import subprocess
import sys
import tempfile
import unittest
import uuid

prepare = runpy.run_path(sys.argv.pop(1))['prepare']


class GuestSource(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='orbit-source-test-')
        self.root = Path(self.scratch.name)
        self.source = self.root / 'upstream'
        self.source.mkdir()
        self.git(self.source, 'init', '-q', '-b', 'main')
        self.git(self.source, 'config', 'user.name', 'Proof')
        self.git(self.source, 'config', 'user.email', 'proof@example.invalid')
        (self.source / 'file').write_text('baseline')
        self.git(self.source, 'add', '.')
        self.git(self.source, 'commit', '-qm', 'initial')
        self.initial = self.git(self.source, 'rev-parse', 'HEAD')
        self.target = self.root / 'checkout'
        self.target.mkdir()
        self.request = {'sandbox_id': str(uuid.uuid4()), 'checkout': str(self.target), 'repository': 'https://github.com/acme/orbit.git', 'branch': 'task-1', 'base': 'main'}

    def tearDown(self):
        self.scratch.cleanup()

    def git(self, root, *args):
        return subprocess.run(['git', '-C', str(root), *args], capture_output=True, text=True, check=True).stdout.strip()

    def call(self, operation, **changes):
        return prepare({**self.request, 'operation': operation, **changes})

    def imported(self):
        self.call('initialize')
        # The production broker imports this ref through a verified bundle; no checkout operation fetches a URL.
        self.git(self.target, 'fetch', '-q', str(self.source), 'main:refs/remotes/origin/main')

    def test_default_branch_and_retry_preserve_dirty_files_and_local_commits(self):
        self.imported()
        self.assertEqual(self.call('checkout')['starting_commit'], self.initial)
        self.assertEqual(self.git(self.target, 'symbolic-ref', 'refs/remotes/origin/HEAD'), 'refs/remotes/origin/main')
        self.git(self.target, 'config', 'user.name', 'Proof')
        self.git(self.target, 'config', 'user.email', 'proof@example.invalid')
        (self.target / 'file').write_text('local commit')
        self.git(self.target, 'commit', '-qam', 'work')
        local = self.git(self.target, 'rev-parse', 'HEAD')
        (self.target / 'file').write_text('unfinished work')
        self.call('initialize')
        self.assertEqual(self.call('checkout'), {'head': local, 'starting_commit': self.initial})
        self.assertEqual(self.call('inspect')['head'], local)
        self.assertEqual((self.target / 'file').read_text(), 'unfinished work')

    def test_restores_the_published_task_branch_instead_of_default(self):
        self.imported()
        (self.source / 'file').write_text('published work')
        self.git(self.source, 'commit', '-qam', 'published')
        commit = self.git(self.source, 'rev-parse', 'HEAD')
        self.git(self.target, 'fetch', '-q', str(self.source), 'main:refs/remotes/origin/task-1')
        self.assertEqual(self.call('checkout')['starting_commit'], commit)
        self.assertEqual((self.target / 'file').read_text(), 'published work')

    def test_binds_default_branch_and_refuses_default_ref_drift(self):
        self.imported()
        self.call('checkout')
        with self.assertRaises(ValueError):
            self.call('initialize', base='changed-default')
        self.git(self.target, 'symbolic-ref', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/other')
        for operation in ['checkout', 'inspect']:
            with self.assertRaises(ValueError):
                self.call(operation)
        self.assertEqual(self.git(self.target, 'symbolic-ref', 'refs/remotes/origin/HEAD'), 'refs/remotes/origin/other')

    def test_refuses_foreign_ownership_origin_and_branch_without_replacement(self):
        self.imported()
        self.call('checkout')
        with self.assertRaises(ValueError):
            self.call('initialize', sandbox_id=str(uuid.uuid4()))
        self.git(self.target, 'config', 'remote.origin.url', 'https://github.com/acme/other.git')
        with self.assertRaises(ValueError):
            self.call('initialize')
        for operation in ['checkout', 'inspect']:
            with self.assertRaises(ValueError):
                self.call(operation)
        self.git(self.target, 'config', 'remote.origin.url', self.request['repository'])
        self.git(self.target, 'checkout', '-qb', 'human-work')
        with self.assertRaises(ValueError):
            self.call('checkout')
        self.assertEqual(self.git(self.target, 'branch', '--show-current'), 'human-work')
        self.assertEqual((self.target / 'file').read_text(), 'baseline')

    def test_refuses_nonempty_unowned_checkouts_and_untracked_work_before_first_checkout(self):
        (self.target / 'keep').write_text('human work')
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertEqual((self.target / 'keep').read_text(), 'human work')
        (self.target / 'keep').unlink()
        self.imported()
        (self.target / 'file').write_text('do not replace')
        with self.assertRaises(ValueError):
            self.call('checkout')
        self.assertEqual((self.target / 'file').read_text(), 'do not replace')

    @unittest.skipUnless(os.environ.get('ORBIT_TEST_ROOT_VOLUME') == '1', 'Requires the privileged test lane')
    def test_claims_only_empty_root_owned_volumes(self):
        self.target.chmod(0o700)
        subprocess.run(['sudo', '-n', 'chown', '0:0', str(self.target)], check=True)
        try:
            self.assertTrue(self.call('initialize')['initialized'])
            self.assertEqual(self.target.stat().st_uid, os.geteuid())
            subprocess.run(['sudo', '-n', 'chown', '0:0', str(self.target)], check=True)
            with self.assertRaises(subprocess.CalledProcessError):
                self.call('initialize')
            self.assertEqual(self.target.stat().st_uid, 0)
        finally:
            subprocess.run(['sudo', '-n', 'chown', f'{os.geteuid()}:{os.getegid()}', str(self.target)], check=True)


unittest.main()
