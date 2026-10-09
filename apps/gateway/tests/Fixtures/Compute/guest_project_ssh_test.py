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
UTC = '1791547200.000000'


class BootstrapKeys(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory(dir=Path.home())
        self.addCleanup(self.directory.cleanup)
        self.home = Path(self.directory.name)
        clock = patch.dict(bootstrap.__globals__, align_clock=lambda *args: None)
        clock.start()
        self.addCleanup(clock.stop)

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
            self.assertEqual({'ready': True}, bootstrap({'public_key': KEY, 'recovery_port': None, 'gateway_time': UTC},
                                                       self.home, os.getuid(), os.getgid()))

    def test_invalid_recovery_endpoint_refuses_before_key_mutation(self):
        for port in (22, 24000, 24255, '24201', True):
            with self.subTest(port=port), self.assertRaises(ValueError):
                bootstrap({'public_key': KEY, 'recovery_port': port, 'gateway_time': UTC}, self.home, os.getuid(), os.getgid())
        self.assertFalse((self.home / '.ssh').exists())

    def test_invalid_gateway_time_refuses_before_key_mutation(self):
        for value in (None, True, 1791547200, 'nan', '1791547200; touch /tmp/unsafe',
                      '0000000000.000000', '9999999999.000000'):
            with self.subTest(value=value), self.assertRaises(ValueError):
                bootstrap({'public_key': KEY, 'recovery_port': None, 'gateway_time': value},
                          self.home, os.getuid(), os.getgid())
            self.assertFalse((self.home / '.ssh').exists())


class GuestClock(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.root = Path(self.directory.name).resolve()
        self.receipt = self.root / 'clock'
        self.boot = self.root / 'boot'
        self.boot.write_text('a2250998-e1c8-4d77-8940-358a80dc1e50\n')

    def align(self):
        return module['align_clock'](1791547200.0, 100.0, state=self.receipt, boot=self.boot, owner=os.getuid())

    def test_aligns_once_per_boot_and_preserves_same_boot_receipt(self):
        with patch('time.monotonic', return_value=102.5), patch('subprocess.run') as command:
            self.align()
            command.assert_called_once()
            self.assertEqual(command.call_args.args[0], ['/usr/bin/date', '--utc', '--set', '@1791547202.500000'])
            before = self.receipt.stat()
            self.align()
            command.assert_called_once()
            self.assertEqual(self.receipt.stat().st_ino, before.st_ino)
            self.assertEqual(self.receipt.stat().st_mode & 0o777, 0o600)
            self.boot.write_text('b2250998-e1c8-4d77-8940-358a80dc1e50\n')
            self.align()
            self.assertEqual(command.call_count, 2)

    def test_failed_clock_set_keeps_alignment_retryable(self):
        import subprocess
        with patch('time.monotonic', return_value=102.5), patch('subprocess.run', side_effect=subprocess.CalledProcessError(1, ['date'])):
            with self.assertRaises(subprocess.CalledProcessError):
                self.align()
        self.assertFalse(self.receipt.exists())

    def test_expired_request_and_invalid_boot_identity_refuse_before_setting_time(self):
        with patch('time.monotonic', return_value=161.0), patch('subprocess.run') as command:
            with self.assertRaises(ValueError):
                self.align()
            command.assert_not_called()
        self.boot.write_text('invalid\n')
        with patch('subprocess.run') as command, self.assertRaises(ValueError):
            self.align()
        command.assert_not_called()
        self.assertFalse(self.receipt.exists())

    def test_writable_receipt_directory_and_untrusted_clock_program_refuse_before_setting_time(self):
        self.root.chmod(0o777)
        with patch('subprocess.run') as command, self.assertRaises(ValueError):
            self.align()
        command.assert_not_called()
        self.root.chmod(0o700)
        clock = self.root / 'date'
        clock.write_text('preserve')
        clock.chmod(0o666)
        with patch('subprocess.run') as command, self.assertRaises(ValueError):
            module['align_clock'](1791547200.0, 100.0, state=self.receipt, boot=self.boot,
                                  clock=clock, owner=os.getuid())
        command.assert_not_called()
        self.assertFalse(self.receipt.exists())

    def test_refuses_foreign_receipts_without_setting_time(self):
        for fault in ('symlink', 'mode', 'hardlink', 'content'):
            with self.subTest(fault=fault):
                outside = self.root / 'outside'
                outside.write_text('preserve')
                if fault == 'symlink':
                    self.receipt.symlink_to(outside)
                elif fault == 'hardlink':
                    os.link(outside, self.receipt)
                else:
                    self.receipt.write_text('invalid\n' if fault == 'content' else self.boot.read_text())
                    self.receipt.chmod(0o666 if fault == 'mode' else 0o600)
                with patch('subprocess.run') as command, self.assertRaises(ValueError):
                    self.align()
                command.assert_not_called()
                self.assertEqual(outside.read_text(), 'preserve')
                self.receipt.unlink()


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
