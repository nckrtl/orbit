from pathlib import Path
import os
import json
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

    def test_recovery_never_falls_back_to_default_or_a_different_commit(self):
        self.imported()
        with self.assertRaises(ValueError):
            self.call('checkout', required_commit=self.initial)
        self.assertFalse((self.target / 'file').exists())
        self.git(self.target, 'fetch', '-q', str(self.source), 'main:refs/remotes/origin/task-1')
        with self.assertRaises(ValueError):
            self.call('checkout', required_commit='a' * 40)
        self.assertFalse((self.target / 'file').exists())
        self.assertEqual(self.call('checkout', required_commit=self.initial)['starting_commit'], self.initial)
        self.git(self.target, 'config', 'user.name', 'Proof')
        self.git(self.target, 'config', 'user.email', 'proof@example.invalid')
        (self.target / 'file').write_text('local recovery work')
        self.git(self.target, 'commit', '-qam', 'recovered work')
        local = self.git(self.target, 'rev-parse', 'HEAD')
        (self.target / 'unfinished').write_text('keep')
        self.assertEqual(self.call('inspect', required_commit=self.initial)['head'], local)
        with self.assertRaises(ValueError):
            self.call('inspect', required_commit='a' * 40)
        self.assertEqual((self.target / 'unfinished').read_text(), 'keep')

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

    def seeded(self):
        self.git(self.target, 'init', '-q', '-b', 'main')
        self.git(self.target, 'fetch', '-q', str(self.source), 'main')
        self.git(self.target, 'reset', '--hard', self.initial)
        self.git(self.target, 'config', 'remote.origin.url', self.request['repository'])
        template = {'id': str(uuid.uuid4()), 'repository': self.request['repository'], 'base': 'main', 'commit': self.initial}
        (self.target / '.git/orbit-sandbox-template.json').write_text(json.dumps(template))
        self.request['source_template'] = template
        return template

    def test_adopts_only_the_pinned_template_then_imports_work_and_preserves_dependencies_on_retry(self):
        self.seeded()
        (self.target / '.git/info/exclude').write_text('/vendor/\n')
        (self.target / 'vendor').mkdir()
        (self.target / 'vendor/cache').write_text('prepared dependencies')
        self.assertTrue(self.call('initialize')['initialized'])
        (self.source / 'file').write_text('published task work')
        self.git(self.source, 'commit', '-qam', 'published')
        published = self.git(self.source, 'rev-parse', 'HEAD')
        bundle = self.root / 'published.bundle'
        self.git(self.source, 'bundle', 'create', str(bundle), 'main')
        self.git(self.target, 'fetch', '-q', str(bundle), 'main:refs/remotes/origin/main', 'main:refs/remotes/origin/task-1')
        self.assertEqual(published, self.call('checkout')['starting_commit'])
        (self.target / 'file').write_text('unfinished task work')
        (self.target / '.git/orbit-sandbox-template.json').unlink()
        self.assertTrue(self.call('initialize')['initialized'])
        self.assertEqual(published, self.call('inspect')['head'])
        self.assertEqual('unfinished task work', (self.target / 'file').read_text())
        self.assertEqual('prepared dependencies', (self.target / 'vendor/cache').read_text())
        for changes in ({'source_template': None}, {'source_template': {**self.request['source_template'], 'commit': 'f' * 40}}, {'sandbox_id': str(uuid.uuid4())}):
            with self.assertRaises(ValueError):
                self.call('initialize', **changes)

    def test_template_checks_refuse_before_claiming_or_replacing_anything(self):
        template = self.seeded()
        marker = self.target / '.git/orbit-sandbox-template.json'
        owner = self.target / '.git/orbit-sandbox-source.json'
        marker.write_text(json.dumps({**template, 'commit': 'f' * 40}))
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertFalse(owner.exists())
        self.request['source_template'] = {**template, 'commit': 'f' * 40}
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertFalse(owner.exists())
        self.request['source_template'] = template
        marker.write_text(json.dumps(template))
        (self.target / 'file').write_text('existing unfinished work')
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertFalse(owner.exists())
        self.assertEqual('existing unfinished work', (self.target / 'file').read_text())
        self.git(self.target, 'checkout', '--', 'file')
        self.git(self.target, 'branch', 'task-999')
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertFalse(owner.exists())
        self.git(self.target, 'branch', '-D', 'task-999')
        self.git(self.target, 'config', 'remote.origin.url', 'https://github.com/acme/foreign.git')
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertFalse(owner.exists())
        self.git(self.target, 'config', 'remote.origin.url', template['repository'])
        self.git(self.target, 'config', 'include.path', '/nonexistent/untrusted-config')
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertFalse(owner.exists())
        self.git(self.target, 'config', '--unset', 'include.path')
        marker.unlink()
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertFalse(owner.exists())
        marker.symlink_to(self.root / 'missing-marker')
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertFalse(owner.exists())

    def test_template_does_not_adopt_an_empty_or_already_owned_checkout(self):
        self.request['source_template'] = {'id': str(uuid.uuid4()), 'repository': self.request['repository'], 'base': 'main', 'commit': self.initial}
        with self.assertRaises(ValueError):
            self.call('initialize')
        self.assertFalse((self.target / '.git').exists())
        descriptor = self.request.pop('source_template')
        self.imported()
        self.call('checkout')
        with self.assertRaises(ValueError):
            self.call('initialize', source_template=descriptor)
        self.assertEqual('task-1', self.git(self.target, 'branch', '--show-current'))

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
