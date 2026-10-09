import base64
from contextlib import contextmanager
import os
from pathlib import Path
import runpy
import shlex
import stat
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

module = runpy.run_path(sys.argv.pop(1))
prepare, bootstrap, restore_recovery = (module[name] for name in ('prepare', 'bootstrap', 'restore_recovery'))
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

    def test_completed_enrollment_does_not_reopen_firewall(self):
        with patch.dict(bootstrap.__globals__, restore_recovery=lambda port: self.fail('Recovery must stay closed')):
            self.assertEqual({'ready': True}, bootstrap({'public_key': KEY, 'recovery_port': None},
                                                       self.home, os.getuid(), os.getgid()))

    def test_invalid_recovery_endpoint_refuses_before_key_mutation(self):
        for port in (22, 24000, 24255, '24201', True):
            with self.subTest(port=port), self.assertRaises(ValueError):
                bootstrap({'public_key': KEY, 'recovery_port': port}, self.home, os.getuid(), os.getgid())
        self.assertFalse((self.home / '.ssh').exists())


class RecoveryFirewall(unittest.TestCase):
    LEGACY = "ufw allow 24210/tcp comment 'orbit:public-ssh-recovery'"
    DESIRED = "ufw allow from 10.233.210.1 to 10.233.210.10 port 22 proto tcp comment 'orbit:public-ssh-recovery'"
    FOREIGN = "ufw allow 443/tcp comment 'preserve-me'"

    def execute(self, rows, fail_delete=False):
        mutations = []

        def command(argv, **options):
            if argv[1:] == ['show', 'added']:
                return SimpleNamespace(returncode=0, stdout='Added user rules:\n' + '\n'.join(rows), stderr='')
            mutations.append(argv[1:])
            if argv[1:3] == ['--force', 'delete']:
                if fail_delete:
                    return SimpleNamespace(returncode=1, stdout='', stderr='')
                rows.remove(self.LEGACY)
            elif argv[1:] == shlex.split(self.DESIRED)[1:]:
                rows.append(self.DESIRED)
            else:
                self.fail('Unexpected UFW mutation')
            return SimpleNamespace(returncode=0, stdout='', stderr='')

        return command, mutations

    @contextmanager
    def contexts(self, command):
        with patch('os.path.lexists', return_value=True), \
                patch.object(Path, 'lstat', return_value=SimpleNamespace(st_mode=stat.S_IFREG | 0o755, st_uid=0)), \
                patch('subprocess.run', side_effect=command):
            yield

    def test_failed_bootstrap_repairs_only_the_recorded_proxy_rule_and_retries_without_mutation(self):
        rows = [self.LEGACY, self.FOREIGN]
        command, mutations = self.execute(rows)
        with self.contexts(command):
            restore_recovery(24210)
            self.assertEqual([self.FOREIGN, self.DESIRED], rows)
            self.assertEqual(2, len(mutations))
            restore_recovery(24210)
            self.assertEqual(2, len(mutations))

    def test_interrupted_upgrade_keeps_exact_recovery_and_finishes_on_retry(self):
        rows = [self.LEGACY, self.FOREIGN]
        command, mutations = self.execute(rows, fail_delete=True)
        with self.contexts(command):
            with self.assertRaises(ValueError):
                restore_recovery(24210)
        self.assertEqual([self.LEGACY, self.FOREIGN, self.DESIRED], rows)
        command, mutations = self.execute(rows)
        with self.contexts(command):
            restore_recovery(24210)
        self.assertEqual([self.FOREIGN, self.DESIRED], rows)
        self.assertEqual([['--force', 'delete', *shlex.split(self.LEGACY)[1:]]], mutations)

    def test_foreign_recovery_shapes_or_duplicates_refuse_before_mutation(self):
        for owned in ([self.LEGACY, self.LEGACY], [self.LEGACY.replace('24210', '24211')],
                      [self.DESIRED.replace('210.1', '210.2')]):
            rows = [self.FOREIGN, *owned]
            before = list(rows)
            command, mutations = self.execute(rows)
            with self.subTest(rows=rows), self.contexts(command):
                with self.assertRaises(ValueError):
                    restore_recovery(24210)
            self.assertEqual(before, rows)
            self.assertEqual([], mutations)

    def test_new_guest_without_ufw_leaves_enablement_to_native_bootstrap(self):
        with patch('os.path.lexists', return_value=False), patch('subprocess.run') as command:
            restore_recovery(24210)
        command.assert_not_called()


unittest.main()
