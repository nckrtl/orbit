import base64
import contextlib
import os
from pathlib import Path
import runpy
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

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

    def test_trusted_fetch_exports_exact_commit_and_removes_credentials(self):
        original = module['fetch'].__globals__['git']
        branch = self.git('branch', '--show-current')
        destination = self.root / 'fetched.bundle'
        temporary = []
        def local_git(repository, *args, environment=None):
            temporary.append(repository)
            self.assertNotIn('repository-secret', args)
            if environment and 'ORBIT_BUNDLE_TOKEN_FILE' in environment:
                token = Path(environment['ORBIT_BUNDLE_TOKEN_FILE'])
                self.assertEqual('repository-secret', token.read_text())
                self.assertEqual(0o600, token.stat().st_mode & 0o777)
            args = tuple(str(self.source) if value == 'https://github.com/acme/orbit.git' else value for value in args)
            return original(repository, *args, environment=environment)
        environment = {'GIT_CONFIG_COUNT': '3', 'GIT_CONFIG_KEY_0': 'http.https://github.com/.extraheader',
                       'GIT_CONFIG_VALUE_0': 'Authorization: Basic ' + base64.b64encode(b'x-access-token:repository-secret').decode(),
                       'GIT_CONFIG_KEY_1': 'url.https://github.com/.insteadOf', 'GIT_CONFIG_VALUE_1': 'git@github.com:',
                       'GIT_CONFIG_KEY_2': 'url.https://github.com/.insteadOf', 'GIT_CONFIG_VALUE_2': 'ssh://git@github.com/'}
        with patch.dict(module['fetch'].__globals__, git=local_git):
            result = module['fetch'](str(destination), 'acme/orbit', branch, environment)
        self.assertEqual(self.sha, result['commit'])
        self.assertEqual(destination.stat().st_size, result['size'])
        self.assertEqual(0o600, destination.stat().st_mode & 0o777)
        self.assertTrue(all(not path.exists() for path in temporary))
        with module['verified_bundle'](str(destination), self.sha):
            pass

    def test_only_successful_remote_listing_can_report_a_missing_optional_branch(self):
        original = module['fetch'].__globals__['git']
        destination = self.root / 'missing.bundle'
        def local_git(repository, *args, environment=None):
            args = tuple(str(self.source) if value == 'https://github.com/acme/orbit.git' else value for value in args)
            return original(repository, *args, environment=environment)
        with patch.dict(module['fetch'].__globals__, git=local_git):
            self.assertEqual({'missing': True}, module['fetch'](str(destination), 'acme/orbit', 'missing', {}, True))
        self.assertFalse(destination.exists())
        def refused(repository, *args, environment=None):
            if args[0] == 'ls-remote':
                raise module['Refusal']('Upstream failed')
            return original(repository, *args, environment=environment)
        with patch.dict(module['fetch'].__globals__, git=refused), self.assertRaises(module['Refusal']):
            module['fetch'](str(destination), 'acme/orbit', 'missing', {}, True)

    def test_read_credentials_cannot_add_git_commands_or_alternate_hosts(self):
        with self.assertRaises(module['Refusal']):
            module['fetch'](str(self.root / 'never.bundle'), 'acme/orbit', 'main', {'GIT_CONFIG_COUNT': '1', 'GIT_CONFIG_KEY_0': 'core.sshCommand', 'GIT_CONFIG_VALUE_0': 'never'})



unittest.main()
