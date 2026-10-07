import contextlib
import os
from pathlib import Path
import runpy
import subprocess
import sys
import tempfile
import unittest

module = runpy.run_path(sys.argv.pop(1))


class BundleBoundary(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='orbit-bundle-test-')
        self.root = Path(self.scratch.name)
        self.source = self.root / 'source'
        self.source.mkdir()
        self.git('init', '--quiet')
        self.git('config', 'user.name', 'Disposable')
        self.git('config', 'user.email', 'proof@example.test')
        (self.source / 'file').write_text('approved\n')
        self.git('add', 'file')
        self.git('commit', '--quiet', '-m', 'approved')
        self.sha = self.git('rev-parse', 'HEAD')
        self.git('update-ref', 'refs/heads/orbit-approved', self.sha)
        self.bundle = self.root / 'received.bundle'
        self.git('bundle', 'create', str(self.bundle), 'refs/heads/orbit-approved')

    def tearDown(self):
        self.scratch.cleanup()

    def git(self, *args):
        return subprocess.check_output(['git', '-C', str(self.source), *args], stderr=subprocess.PIPE, text=True).strip()

    def test_only_verified_commit_reaches_a_fresh_trusted_clone_and_cleanup_removes_it(self):
        with module['verified_bundle'](str(self.bundle), self.sha) as trusted:
            self.assertEqual(self.sha, module['git'](trusted, 'rev-parse', 'FETCH_HEAD'))
            self.assertEqual('approved', module['git'](trusted, 'show', self.sha + ':file'))
            self.assertFalse((trusted / 'config').read_text().find('example.test') >= 0)
        self.assertFalse(trusted.exists())

    def test_rejects_a_different_commit_even_if_the_bundle_is_valid(self):
        with self.assertRaises(module['Refusal']):
            with module['verified_bundle'](str(self.bundle), 'a' * 40):
                self.fail('Unapproved commit was accepted')

    def test_rejects_additional_refs(self):
        self.git('update-ref', 'refs/heads/extra', self.sha)
        self.bundle.unlink()
        self.git('bundle', 'create', str(self.bundle), '--all')
        with self.assertRaises(module['Refusal']):
            with module['verified_bundle'](str(self.bundle), self.sha):
                self.fail('Additional refs were accepted')

    def test_rejects_a_corrupt_pack(self):
        self.bundle.write_bytes(self.bundle.read_bytes()[:-20])
        with self.assertRaises(module['Refusal']):
            with module['verified_bundle'](str(self.bundle), self.sha):
                self.fail('Corrupt pack was accepted')

    def test_rejects_transfer_symlinks(self):
        link = self.root / 'link.bundle'
        link.symlink_to(self.bundle)
        with self.assertRaises(module['Refusal']):
            with module['verified_bundle'](str(link), self.sha):
                self.fail('Transfer symlink was accepted')


unittest.main()
