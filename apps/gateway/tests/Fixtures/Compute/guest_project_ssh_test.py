import base64
import os
from pathlib import Path
import runpy
import sys
import tempfile
import unittest

prepare = runpy.run_path(sys.argv.pop(1))['prepare']
KEY = 'ssh-ed25519 ' + base64.b64encode(b'\x00\x00\x00\x0bssh-ed25519\x00\x00\x00\x20' + b'x' * 32).decode()


class BootstrapKeys(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(dir=Path.home())
        self.addCleanup(self.directory.cleanup)
        self.home = Path(self.directory.name)

    def test_installs_public_key_and_preserves_retry_bytes(self):
        request = {'public_key': KEY + ' gateway'}
        self.assertEqual(prepare(request, self.home, os.getuid(), os.getgid()), {'ready': True})
        path = self.home / '.ssh/authorized_keys'
        self.assertEqual(path.read_text(), KEY + '\n')
        self.assertEqual(path.stat().st_mode & 0o777, 0o600)
        path.write_text(KEY + ' native-bootstrap\n')
        before = path.stat()
        self.assertEqual(prepare(request, self.home, os.getuid(), os.getgid()), {'ready': True})
        self.assertEqual(path.read_text(), KEY + ' native-bootstrap\n')
        self.assertEqual(path.stat().st_ino, before.st_ino)

    def test_refuses_symlinks_foreign_keys_and_writable_paths_without_replacing_them(self):
        for kind in ('directory-link', 'file-link', 'hard-link', 'foreign-key', 'writable'):
            with self.subTest(kind=kind), tempfile.TemporaryDirectory(dir=self.home) as location:
                home = Path(location)
                outside = home / 'outside'
                outside.write_text('preserve\n')
                directory = home / '.ssh'
                if kind == 'directory-link':
                    directory.symlink_to(home, target_is_directory=True)
                else:
                    directory.mkdir(mode=0o700)
                    path = directory / 'authorized_keys'
                    if kind == 'file-link':
                        path.symlink_to(outside)
                    elif kind == 'hard-link':
                        os.link(outside, path)
                    else:
                        path.write_text(KEY + '\n' if kind == 'writable' else 'foreign-key\n')
                        path.chmod(0o666 if kind == 'writable' else 0o600)
                with self.assertRaises(ValueError):
                    prepare({'public_key': KEY}, home, os.getuid(), os.getgid())
                self.assertEqual(outside.read_text(), 'preserve\n')

    def test_refuses_malformed_key_before_creating_ssh_directory(self):
        for key in ('ssh-ed25519 eA==', KEY + '\nforeign', 'ssh-rsa AAAA'):
            with self.assertRaises(ValueError):
                prepare({'public_key': key}, self.home, os.getuid(), os.getgid())
            self.assertFalse((self.home / '.ssh').exists())


unittest.main()
