from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
import importlib.util
import json
import os
import stat
import subprocess
import sys
import tempfile
import unittest
import uuid

spec = importlib.util.spec_from_file_location('github_dns', sys.argv.pop(1))
dns = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dns)


class GitHubDns(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='orbit-github-dns-')
        self.root = Path(self.scratch.name)
        self.checkout = self.root / 'checkout'
        metadata = self.checkout / '.git'
        metadata.mkdir(parents=True)
        self.directory = self.root / 'resolved.conf.d'
        self.directory.mkdir(mode=0o755)
        self.foreign = self.directory / 'private-topology.conf'
        self.foreign.write_text('[Resolve]\nDNS=10.44.0.1\nDomains=~orbit\n')
        self.private = self.foreign.read_bytes()
        self.request = {'operation': 'github_dns', 'sandbox_id': str(uuid.uuid4()), 'checkout': str(self.checkout),
                        'repository': 'https://github.com/acme/orbit.git', 'branch': 'task-1', 'base': 'main'}
        self.marker = metadata / 'orbit-sandbox-source.json'
        owner = {k: self.request[k] for k in ('sandbox_id', 'repository', 'branch', 'base')}
        owner['source_template'] = None
        self.marker.write_text(json.dumps(owner))
        self.patches = [patch.object(dns, 'DIRECTORY', self.directory), patch.object(dns, 'CHECKOUT', self.checkout),
                        patch.object(dns, 'ROOT_UID', os.geteuid()),
                        patch.object(dns.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=os.geteuid())),
                        patch.object(dns, 'control')]
        for p in self.patches: p.start()
        self.target = self.directory / 'orbit-sandbox-github.conf'

    def tearDown(self):
        for p in reversed(self.patches): p.stop()
        self.scratch.cleanup()

    def test_routes_github_only_keeps_private_dns_and_reuses_without_restart(self):
        self.assertEqual(dns.install(self.request), {'ready': True})
        text = self.target.read_text()
        self.assertIn('DNS=1.1.1.1 9.9.9.9\n', text)
        self.assertIn('Domains=~github.com ~githubusercontent.com ~githubassets.com\n', text)
        self.assertNotIn('~.\n', text)
        self.assertEqual(stat.S_IMODE(self.target.stat().st_mode), 0o644)
        self.assertEqual(self.foreign.read_bytes(), self.private)
        dns.control.reset_mock()
        self.assertEqual(dns.install(self.request), {'ready': True})
        dns.control.assert_called_once_with('is-active', '--quiet', 'systemd-resolved')

    def test_foreign_source_is_refused_before_any_mutation(self):
        owner = json.loads(self.marker.read_text()); owner['sandbox_id'] = str(uuid.uuid4())
        self.marker.write_text(json.dumps(owner))
        with self.assertRaises(ValueError): dns.install(self.request)
        self.assertFalse(self.target.exists()); dns.control.assert_not_called()

    def test_foreign_resolver_is_preserved(self):
        self.target.write_text('[Resolve]\nDNS=192.0.2.3\n')
        before = self.target.read_bytes()
        with self.assertRaises(ValueError): dns.install(self.request)
        self.assertEqual(self.target.read_bytes(), before); dns.control.assert_not_called()

    def test_linked_resolver_is_refused(self):
        self.target.symlink_to(self.foreign)
        with self.assertRaises(ValueError): dns.install(self.request)
        self.assertEqual(self.foreign.read_bytes(), self.private); dns.control.assert_not_called()

    def test_writable_directory_is_refused(self):
        self.directory.chmod(0o777)
        with self.assertRaises(ValueError): dns.install(self.request)
        self.assertFalse(self.target.exists()); dns.control.assert_not_called()

    def test_partial_write_failure_removes_only_the_new_config(self):
        original = os.fdopen
        class FailingWrite:
            def __init__(self, output): self.output = output
            def __enter__(self): return self
            def __exit__(self, *args): self.output.close()
            def write(self, content):
                self.output.write(content[:8]); self.output.flush()
                raise OSError('fixture disk failure')
        with patch.object(dns.os, 'fdopen', side_effect=lambda fd, mode: FailingWrite(original(fd, mode))):
            with self.assertRaises(OSError): dns.install(self.request)
        self.assertFalse(self.target.exists())
        self.assertEqual(self.foreign.read_bytes(), self.private)

    def test_service_failure_restores_absence_and_previous_resolver(self):
        dns.control.side_effect = [None, subprocess.CalledProcessError(1, 'systemctl'), None]
        with self.assertRaises(subprocess.CalledProcessError): dns.install(self.request)
        self.assertFalse(self.target.exists())
        self.assertEqual(self.foreign.read_bytes(), self.private)
        self.assertEqual(dns.control.call_count, 3)


unittest.main()
