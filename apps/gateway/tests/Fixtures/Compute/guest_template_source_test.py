from pathlib import Path
import json
import os
import runpy
import subprocess
import sys
import tempfile
import unittest
import uuid

helper = Path(sys.argv.pop(1))
prepare = runpy.run_path(str(helper))['prepare']
adopt = runpy.run_path(str(helper.with_name('guest-workspace-source.py')))['prepare']


class TemplateSource(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='orbit-template-test-')
        self.root = Path(self.scratch.name)
        self.git('init', '-q', '-b', 'candidate')
        self.git('config', 'user.name', 'Builder')
        self.git('config', 'user.email', 'builder@example.invalid')
        self.git('config', 'remote.origin.url', 'https://github.com/acme/orbit.git')
        (self.root / '.gitignore').write_text('/vendor/\n')
        (self.root / 'source').write_text('pinned source\n')
        self.git('add', '.')
        self.git('commit', '-qm', 'source')
        self.head = self.git('rev-parse', 'HEAD')
        self.git('branch', 'obsolete')
        self.git('tag', 'old-tag')
        self.template = {'id': str(uuid.uuid4()), 'repository': 'https://github.com/acme/orbit.git', 'base': 'main', 'commit': self.head}
        self.request = {'checkout': str(self.root), 'source_template': self.template}
        (self.root / '.git/orbit-template-candidate.json').write_text(json.dumps(self.template))
        (self.root / 'vendor').mkdir()
        (self.root / 'vendor/cache').write_text('dependencies')

    def tearDown(self):
        self.scratch.cleanup()

    def git(self, *args):
        return subprocess.run(['git', '-C', str(self.root), *args], capture_output=True, text=True, check=True).stdout.strip()

    def state(self):
        return {str(path.relative_to(self.root)): ('link', os.readlink(path)) if path.is_symlink() else path.read_bytes()
                for path in self.root.rglob('*') if path.is_symlink() or path.is_file()}

    def refuses_unchanged(self, request=None):
        before = self.state()
        with self.assertRaises((ValueError, subprocess.SubprocessError)):
            prepare(request or self.request)
        self.assertEqual(before, self.state())

    def test_prepares_default_source_and_preserves_dependencies_for_real_consumer(self):
        result = prepare(self.request)
        self.assertEqual(result, {'head': self.head, 'source_template': self.template})
        self.assertEqual(self.git('for-each-ref', '--format=%(refname)').splitlines(),
                         ['refs/heads/main', 'refs/remotes/origin/HEAD', 'refs/remotes/origin/main'])
        self.assertEqual(prepare(self.request), result)
        self.assertEqual((self.root / 'vendor/cache').read_text(), 'dependencies')
        owner = {'operation': 'initialize', 'sandbox_id': str(uuid.uuid4()), 'checkout': str(self.root),
                 'repository': self.template['repository'], 'base': 'main', 'branch': 'task-1', 'source_template': self.template}
        self.assertEqual(adopt(owner), {'initialized': True})
        self.assertEqual(adopt({**owner, 'operation': 'checkout'})['head'], self.head)
        self.refuses_unchanged()

    def test_requires_exact_candidate_before_mutation(self):
        marker = self.root / '.git/orbit-template-candidate.json'
        marker.unlink()
        self.refuses_unchanged()
        marker.write_text(json.dumps({**self.template, 'id': str(uuid.uuid4())}))
        self.refuses_unchanged()
        marker.write_text(json.dumps(self.template))
        self.refuses_unchanged({**self.request, 'extra': True})
        self.refuses_unchanged({**self.request, 'source_template': {**self.template, 'commit': 'f' * 40}})
        self.refuses_unchanged({**self.request, 'source_template': {**self.template, 'base': 'bad\nbranch'}})

    def test_refuses_dirty_tracked_and_untracked_work(self):
        (self.root / 'source').write_text('unfinished work')
        self.refuses_unchanged()
        self.git('checkout', '--', 'source')
        (self.root / 'unfinished').write_text('untracked work')
        self.refuses_unchanged()

    def test_refuses_execution_config_before_running_status(self):
        config = self.root / '.git/config'
        original = config.read_bytes()
        for key in ('core.fsmonitor', 'filter.bad.clean', 'include.path', 'core.worktree', 'credential.helper'):
            with self.subTest(key=key):
                self.git('config', key, 'unsafe-secret-value')
                self.refuses_unchanged()
                config.write_bytes(original)
        self.git('config', '--add', 'remote.origin.url', self.template['repository'])
        self.refuses_unchanged()

    def test_refuses_foreign_metadata_links_and_history(self):
        for relative in ('objects/info/alternates', 'objects/info/http-alternates', 'info/grafts', 'commondir',
                         'shallow', 'orbit-sandbox-source.json'):
            with self.subTest(path=relative):
                path = self.root / '.git' / relative
                path.write_text('foreign')
                self.refuses_unchanged()
                path.unlink()
        path = self.root / '.git/unsafe'
        path.symlink_to(self.root / 'absent')
        self.refuses_unchanged()
        path.unlink()
        os.link(self.root / '.git/config', path)
        self.refuses_unchanged()
        path.unlink()
        self.git('update-ref', 'refs/replace/' + self.head, self.head)
        self.refuses_unchanged()

    def test_never_repairs_changed_published_source(self):
        prepare(self.request)
        self.git('branch', 'later-work')
        self.refuses_unchanged()
        self.git('branch', '-D', 'later-work')
        self.git('update-ref', '-d', 'refs/remotes/origin/main')
        self.refuses_unchanged()

    def test_redacts_subprocess_errors_at_stdin_boundary(self):
        self.git('config', 'remote.origin.url', 'https://secret@example.invalid/private.git')
        result = subprocess.run(['python3', '-I', str(helper)], input=json.dumps(self.request), text=True, capture_output=True)
        self.assertEqual(result.returncode, 1)
        self.assertEqual(result.stdout, '')
        self.assertEqual(result.stderr, 'Sandbox template source preparation failed.\n')


unittest.main()
