import json
from pathlib import Path
import runpy
import subprocess
import sys
import tempfile
import unittest
import uuid

module = runpy.run_path(sys.argv.pop(1))


class GuestBundles(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='orbit-guest-bundle-test-')
        self.root = Path(self.scratch.name)
        self.source = self.root / 'source'
        self.source.mkdir()
        self.git(self.source, 'init', '--quiet', '-b', 'main')
        self.git(self.source, 'config', 'user.name', 'Proof')
        self.git(self.source, 'config', 'user.email', 'proof@example.invalid')
        (self.source / 'file').write_text('baseline\n')
        self.git(self.source, 'add', 'file')
        self.git(self.source, 'commit', '--quiet', '-m', 'baseline')
        self.before = self.git(self.source, 'rev-parse', 'HEAD')
        self.target = self.root / 'target'
        self.git(self.root, 'clone', '--quiet', '--no-hardlinks', str(self.source), str(self.target))
        (self.source / 'file').write_text('changed\n')
        self.git(self.source, 'commit', '-qam', 'changed')
        self.commit = self.git(self.source, 'rev-parse', 'HEAD')
        self.ids = []

    def tearDown(self):
        for identity in self.ids:
            path = Path('/tmp/orbit-git-transfer-' + identity)
            if path.exists():
                module['transfer']({'id': identity, 'operation': 'remove'})
            self.assertFalse(path.exists())
        self.scratch.cleanup()

    def git(self, directory, *args):
        return subprocess.check_output(['git', '-C', str(directory), *args], text=True, stderr=subprocess.PIPE).strip()

    def identity(self):
        identity = str(uuid.uuid4())
        self.ids.append(identity)
        return identity

    def test_roundtrip_preserves_head_and_worktree_and_imports_only_the_named_tracking_ref(self):
        outgoing, incoming = self.identity(), self.identity()
        meta = module['transfer']({'operation': 'export', 'id': outgoing, 'checkout': str(self.source), 'commit': self.commit})
        bundle = module['transfer']({'operation': 'read', 'id': outgoing, 'offset': 0, 'length': meta['size']})
        self.assertEqual(meta['size'], len(bundle))
        self.assertEqual({'offset': 0}, module['transfer']({'operation': 'begin', 'id': incoming}))
        import base64
        result = module['transfer']({'operation': 'write', 'id': incoming, 'offset': 0, 'data': base64.b64encode(bundle).decode()})
        self.assertEqual(len(bundle), result['offset'])
        result = module['transfer']({'operation': 'import', 'id': incoming, 'checkout': str(self.target), 'commit': self.commit, 'ref': 'refs/remotes/origin/main'})
        self.assertEqual(self.commit, result['commit'])
        self.assertEqual(self.before, self.git(self.target, 'rev-parse', 'HEAD'))
        self.assertEqual('baseline\n', (self.target / 'file').read_text())
        self.assertEqual(self.commit, self.git(self.target, 'rev-parse', 'refs/remotes/origin/main'))

    def test_rejects_mismatched_offsets_and_local_branch_import(self):
        incoming = self.identity()
        module['transfer']({'operation': 'begin', 'id': incoming})
        with self.assertRaisesRegex(ValueError, 'chunk'):
            module['transfer']({'operation': 'write', 'id': incoming, 'offset': 1, 'data': 'YQ=='})
        with self.assertRaisesRegex(ValueError, 'remote tracking'):
            module['transfer']({'operation': 'import', 'id': incoming, 'checkout': str(self.target), 'commit': self.commit, 'ref': 'refs/heads/main'})
        self.assertEqual(self.before, self.git(self.target, 'rev-parse', 'HEAD'))

    def test_rejects_a_substituted_bundle_commit(self):
        outgoing = self.identity()
        module['transfer']({'operation': 'export', 'id': outgoing, 'checkout': str(self.source), 'commit': self.commit})
        with self.assertRaisesRegex(ValueError, 'does not match'):
            module['transfer']({'operation': 'import', 'id': outgoing, 'checkout': str(self.target), 'commit': self.before, 'ref': 'refs/remotes/origin/main'})
        self.assertEqual(self.before, self.git(self.target, 'rev-parse', 'refs/remotes/origin/main'))


unittest.main()
