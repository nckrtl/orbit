import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import stat
import sys
import tempfile
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('artifact', sys.argv.pop(1))
artifact = importlib.util.module_from_spec(spec)
spec.loader.exec_module(artifact)


class ArtifactBoundary(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        artifact.ROOT = Path(self.directory.name) / 'receipt'
        artifact.BINARY = Path(self.directory.name) / 'pi'
        self.bytes = b'\x7fELF\x02\x01' + b'\x00' * 10 + b'\x03\x00\x3e\x00' + b'\x00' * 108
        self.header = {'sandbox_id': 'ca656ccf-240d-476c-90f1-cf70f9dd7a12',
                       'sha256': hashlib.sha256(self.bytes).hexdigest(), 'size': len(self.bytes)}
        self.addCleanup(patch.stopall)
        patch.object(artifact.os, 'geteuid', return_value=0).start()
        # Execute publication on unprivileged test hosts; check owner rejection separately.
        patch.object(artifact, 'regular', side_effect=self.regular).start()
        original_lstat = Path.lstat
        def lstat(path):
            details = original_lstat(path)
            if path == artifact.ROOT:
                values = list(details)
                values[4] = 0
                return os.stat_result(values)
            return details
        patch.object(Path, 'lstat', lstat).start()

    def regular(self, path):
        details = path.lstat()
        if not stat.S_ISREG(details.st_mode) or details.st_nlink != 1 or details.st_mode & 0o022:
            raise ValueError('Unsafe file')

    def stream(self, payload=None):
        return io.BytesIO(json.dumps(self.header).encode() + b'\n' + (self.bytes if payload is None else payload))

    def test_publish_retry_preserves_inode_and_refuses_drift(self):
        artifact.install(self.stream())
        inode = artifact.BINARY.stat().st_ino
        artifact.install(self.stream())
        self.assertEqual(inode, artifact.BINARY.stat().st_ino)
        self.assertEqual(artifact.BINARY.stat().st_mode & 0o777, 0o755)
        artifact.BINARY.write_bytes(b'changed')
        with self.assertRaises(ValueError):
            artifact.install(self.stream())

    def test_truncated_or_corrupt_transfer_keeps_receipt_and_retry_works(self):
        for payload in [self.bytes[:-1], b'x' * len(self.bytes), self.bytes + b'x']:
            with self.assertRaises(ValueError):
                artifact.install(self.stream(payload))
            self.assertFalse(artifact.BINARY.exists())
            self.assertTrue((artifact.ROOT / 'owner.json').exists())
        artifact.install(self.stream())

    def test_foreign_binary_and_symlinks_are_not_adopted(self):
        artifact.BINARY.write_bytes(self.bytes)
        with self.assertRaises(ValueError):
            artifact.install(self.stream())
        artifact.BINARY.unlink()
        artifact.BINARY.symlink_to(Path(self.directory.name) / 'other')
        with self.assertRaises(ValueError):
            artifact.install(self.stream())

    def test_receipt_drift_and_wrong_architecture_are_refused(self):
        wrong = bytearray(self.bytes)
        wrong[18:20] = b'\xb7\x00'
        self.header['sha256'] = hashlib.sha256(wrong).hexdigest()
        with self.assertRaises(ValueError):
            artifact.install(self.stream(wrong))
        self.assertFalse(artifact.BINARY.exists())
        self.header['sandbox_id'] = '00000000-0000-4000-8000-000000000000'
        with self.assertRaises(ValueError):
            artifact.install(self.stream(wrong))


unittest.main()
