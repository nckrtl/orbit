"""Exercise the signature and package trust chain with a disposable signing key."""
import datetime
import hashlib
import os
from pathlib import Path
import runpy
import shutil
import subprocess
import sys
import tempfile
import unittest

program = runpy.run_path(sys.argv.pop(1))
verify = program['verify']


def run(*args):
    return subprocess.run(args, capture_output=True, text=True, check=True, timeout=30).stdout


class SnapshotTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.temporary = tempfile.TemporaryDirectory(prefix='orbit-caddy-snapshot-test-')
        cls.base = Path(cls.temporary.name)
        cls.home = cls.base / 'gnupg'
        cls.home.mkdir(mode=0o700)
        run('gpg', '--homedir', str(cls.home), '--batch', '--pinentry-mode', 'loopback', '--passphrase', '',
            '--quick-generate-key', 'Orbit snapshot fixture <snapshot@example.test>', 'rsa2048', 'sign', '0')
        listing = run('gpg', '--homedir', str(cls.home), '--batch', '--with-colons', '--list-keys')
        cls.fingerprint = next(line.split(':')[9] for line in listing.splitlines() if line.startswith('fpr:'))
        cls.key = run('gpg', '--homedir', str(cls.home), '--batch', '--armor', '--export', cls.fingerprint).encode()
        cls.key_sha = hashlib.sha256(cls.key).hexdigest()
        cls.architecture = run('dpkg', '--print-architecture').strip()
        package = cls.base / 'package'
        (package / 'DEBIAN').mkdir(parents=True)
        (package / 'DEBIAN/control').write_text('Package: caddy\nVersion: 2.11.7\nArchitecture: ' + cls.architecture
                                               + '\nMaintainer: Fixture <snapshot@example.test>\nDescription: Fixture package\n')
        run('dpkg-deb', '--build', str(package), str(cls.base / 'caddy.deb'))
        cls.package = (cls.base / 'caddy.deb').read_bytes()

    @classmethod
    def tearDownClass(cls):
        cls.temporary.cleanup()

    def setUp(self):
        self.case = tempfile.TemporaryDirectory(dir=self.base)
        self.root = Path(self.case.name) / 'snapshot'
        self.root.mkdir(mode=0o755)
        self.repo = self.root / 'repository'
        self.suite = self.repo / 'dists/any-version'
        self.index = self.suite / ('main/binary-' + self.architecture + '/Packages')
        self.index.parent.mkdir(parents=True)
        self.package_path = self.repo / 'pool/caddy.deb'
        self.package_path.parent.mkdir()
        self.package_path.write_bytes(self.package)
        (self.root / 'gpg.key').write_bytes(self.key)
        self.index.write_text('Package: caddy\nVersion: 2.11.7\nArchitecture: ' + self.architecture
                             + '\nSHA256: ' + hashlib.sha256(self.package).hexdigest()
                             + '\nSize: ' + str(len(self.package)) + '\nFilename: pool/caddy.deb\n\n')
        self.sign()
        for directory, _, files in os.walk(self.root):
            Path(directory).chmod(0o755)
            for name in files:
                (Path(directory) / name).chmod(0o644)

    def tearDown(self):
        self.case.cleanup()

    def sign(self, extra='', origin='cloudsmith/caddy/stable'):
        metadata = (Path(self.case.name) / 'Release')
        issued = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(minutes=1)
        index = self.index.read_bytes()
        metadata.write_text('Origin: ' + origin + '\nSuite: any-version\nCodename: any-version\nComponents: main\nArchitectures: '
                            + self.architecture + '\nDate: ' + issued.strftime('%a, %d %b %Y %H:%M:%S UTC') + '\n' + extra
                            + 'SHA256:\n ' + hashlib.sha256(index).hexdigest() + ' ' + str(len(index))
                            + ' main/binary-' + self.architecture + '/Packages\n')
        run('gpg', '--homedir', str(self.home), '--batch', '--yes', '--clearsign', '--output',
            str(self.suite / 'InRelease'), str(metadata))
        (self.suite / 'InRelease').chmod(0o644)

    def check(self, **kwargs):
        return verify(self.root, self.key_sha, self.fingerprint, '2.9.0', owner=os.getuid(), **kwargs)

    def test_accepts_the_authenticated_package(self):
        result = self.check()
        self.assertTrue(result['verified'])
        self.assertEqual(result['version'], '2.11.7')
        self.assertEqual(result['package_sha256'], hashlib.sha256(self.package).hexdigest())
        self.assertEqual(result['source'], 'file:' + str(self.repo))

    def test_refuses_a_different_signing_pin(self):
        with self.assertRaises(ValueError):
            verify(self.root, '0' * 64, self.fingerprint, '2.9.0', owner=os.getuid())
        with self.assertRaises(ValueError):
            verify(self.root, self.key_sha, '0' * 40, '2.9.0', owner=os.getuid())

    def test_refuses_changed_signed_metadata(self):
        p = self.suite / 'InRelease'
        p.write_bytes(p.read_bytes().replace(b'cloudsmith/caddy/stable', b'cloudsmith/caddy/changed'))
        with self.assertRaises((ValueError, subprocess.CalledProcessError)):
            self.check()

    def test_refuses_a_changed_package_index(self):
        self.index.write_bytes(self.index.read_bytes() + b'\n')
        with self.assertRaises(ValueError):
            self.check()

    def test_refuses_a_changed_package(self):
        self.package_path.write_bytes(self.package + b'changed')
        with self.assertRaises(ValueError):
            self.check()

    def test_refuses_a_package_whose_control_identity_differs(self):
        self.index.write_bytes(self.index.read_bytes().replace(b'Version: 2.11.7', b'Version: 2.11.8'))
        self.sign()
        with self.assertRaises(ValueError):
            self.check()

    def test_refuses_package_path_traversal(self):
        self.index.write_bytes(self.index.read_bytes().replace(b'pool/caddy.deb', b'../caddy.deb'))
        self.sign()
        with self.assertRaises(ValueError):
            self.check()

    def test_refuses_ambiguous_candidates(self):
        self.index.write_bytes(self.index.read_bytes() * 2)
        self.sign()
        with self.assertRaises(ValueError):
            self.check()

    def test_preserves_an_existing_snapshot_on_install(self):
        destination = Path(self.case.name) / 'existing'
        destination.mkdir()
        sentinel = destination / 'sentinel'
        sentinel.write_text('preserve')
        with self.assertRaises(ValueError):
            program['install'](self.root, destination, self.key_sha, self.fingerprint, '2.9.0', owner=os.getuid())
        self.assertEqual(sentinel.read_text(), 'preserve')

    def test_does_not_create_a_destination_for_an_invalid_snapshot(self):
        destination = Path(self.case.name) / 'absent'
        self.package_path.write_bytes(self.package + b'changed')
        with self.assertRaises(ValueError):
            program['install'](self.root, destination, self.key_sha, self.fingerprint, '2.9.0', owner=os.getuid())
        self.assertFalse(destination.exists())

    def test_refuses_the_wrong_repository_and_expired_metadata(self):
        self.sign(origin='another/repository')
        with self.assertRaises(ValueError):
            self.check()
        expired = datetime.datetime.now(datetime.timezone.utc) - datetime.timedelta(days=1)
        self.sign(extra='Valid-Until: ' + expired.strftime('%a, %d %b %Y %H:%M:%S UTC') + '\n')
        with self.assertRaises(ValueError):
            self.check()

    def test_refuses_unsafe_modes_links_and_extra_files(self):
        self.package_path.chmod(0o666)
        with self.assertRaises(ValueError):
            self.check()
        self.package_path.chmod(0o644)
        outside = Path(self.case.name) / 'outside.deb'
        self.package_path.rename(outside)
        self.package_path.symlink_to(outside)
        with self.assertRaises(ValueError):
            self.check()
        self.package_path.unlink()
        shutil.copyfile(outside, self.package_path)
        (self.root / 'unexpected').write_text('extra')
        with self.assertRaises(ValueError):
            self.check()

    def test_refuses_an_unsupported_floor_and_architecture(self):
        with self.assertRaises(subprocess.CalledProcessError):
            verify(self.root, self.key_sha, self.fingerprint, '99.0.0', owner=os.getuid())
        with self.assertRaises(ValueError):
            self.check(architecture='invalid')


unittest.main()
