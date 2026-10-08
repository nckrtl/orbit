from pathlib import Path
from types import SimpleNamespace
from unittest.mock import patch
import importlib.util
import json
import os
import subprocess
import sys
import tempfile
import unittest
import uuid

spec = importlib.util.spec_from_file_location('pair_dns', sys.argv.pop(1))
dns = importlib.util.module_from_spec(spec)
spec.loader.exec_module(dns)


class PairDns(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory(prefix='orbit-pair-dns-')
        self.root = Path(self.scratch.name)
        self.checkout = self.root / 'checkout'
        metadata = self.checkout / '.git'
        metadata.mkdir(parents=True)
        self.directory = self.root / 'dnsmasq.d'
        self.directory.mkdir(mode=0o755)
        self.private = self.directory / 'orbit-private.conf'
        self.private.write_text('address=/gateway.orbit/10.44.0.1\n')
        self.database = self.root / 'gateway.sqlite'
        self.database.touch()
        self.request = {'operation': 'pair_dns', 'sandbox_id': str(uuid.uuid4()), 'checkout': str(self.checkout),
                        'repository': 'https://github.com/acme/orbit.git', 'branch': 'task-1', 'base': 'main'}
        self.marker = metadata / 'orbit-sandbox-source.json'
        self.owner = {k: self.request[k] for k in ('sandbox_id', 'repository', 'branch', 'base')}
        self.owner['source_template'] = None
        self.marker.write_text(json.dumps(self.owner))
        self.addresses = [{'ifname': 'orbit', 'addr_info': [{'local': '10.44.0.1'}]}]
        self.commands = []
        self.failure = None
        self.inactive = False
        self.patches = [patch.object(dns, 'DIRECTORY', self.directory), patch.object(dns, 'CHECKOUT', self.checkout),
                        patch.object(dns, 'DATABASE', self.database), patch.object(dns, 'ROOT_UID', os.geteuid()),
                        patch.object(dns.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=os.geteuid())),
                        patch.object(dns, 'run', side_effect=self.command)]
        for p in self.patches: p.start()
        self.target = self.directory / 'orbit-sandbox-upstream.conf'

    def command(self, args):
        self.commands.append(args)
        if args == self.failure:
            self.failure = None
            raise subprocess.CalledProcessError(1, args)
        if self.inactive and args == ['systemctl', 'is-active', '--quiet', 'dnsmasq']:
            raise subprocess.CalledProcessError(3, args)
        return json.dumps(self.addresses) if args[0] == 'ip' else ''

    def tearDown(self):
        for p in reversed(self.patches): p.stop()
        self.scratch.cleanup()

    def test_public_upstreams_preserve_private_records_and_reuse_without_restart(self):
        before = self.private.read_bytes()
        self.assertEqual(dns.install(self.request), {'ready': True})
        self.assertEqual(self.target.read_text(), '# Orbit sandbox ' + self.request['sandbox_id'] + '\nno-resolv\nserver=1.1.1.1\nserver=9.9.9.9\n')
        self.assertEqual(self.target.stat().st_mode & 0o777, 0o644)
        self.assertEqual(self.private.read_bytes(), before)
        self.commands.clear()
        dns.install(self.request)
        self.assertNotIn(['systemctl', 'restart', 'dnsmasq'], self.commands)

    def test_foreign_source_is_refused_before_remote_mutation(self):
        self.owner['sandbox_id'] = str(uuid.uuid4())
        self.marker.write_text(json.dumps(self.owner))
        with self.assertRaises(ValueError): dns.install(self.request)
        self.assertEqual(self.commands, [])
        self.assertFalse(self.target.exists())

    def test_inactive_backend_keeps_its_state_until_native_runtime_activation(self):
        self.inactive = True
        self.assertEqual(dns.install(self.request), {'ready': True})
        self.assertTrue(self.target.exists())
        self.assertNotIn(['systemctl', 'restart', 'dnsmasq'], self.commands)
        self.assertEqual(dns.install(self.request), {'ready': True})
        self.target.unlink()
        self.failure = ['dnsmasq', '--test']
        with self.assertRaises(subprocess.CalledProcessError): dns.install(self.request)
        self.assertFalse(self.target.exists())
        self.assertNotIn(['systemctl', 'restart', 'dnsmasq'], self.commands)

    def test_live_or_operator_address_is_refused(self):
        for address in ['10.44.0.2', '10.44.0.3']:
            self.addresses[0]['addr_info'][0]['local'] = address
            with self.assertRaises(ValueError): dns.install(self.request)
            self.assertFalse(self.target.exists())
        self.assertTrue(all(args[0] == 'ip' for args in self.commands))

    def test_duplicate_gateway_address_is_refused(self):
        self.addresses.append({'ifname': 'foreign', 'addr_info': [{'local': '10.44.0.1'}]})
        with self.assertRaises(ValueError): dns.install(self.request)
        self.assertFalse(self.target.exists())

    def test_foreign_or_linked_upstream_is_preserved(self):
        self.target.write_text('server=192.0.2.1\n')
        with self.assertRaises(ValueError): dns.install(self.request)
        self.assertEqual(self.target.read_text(), 'server=192.0.2.1\n')
        self.target.unlink()
        self.target.symlink_to(self.private)
        with self.assertRaises(ValueError): dns.install(self.request)
        self.assertEqual(self.private.read_text(), 'address=/gateway.orbit/10.44.0.1\n')

    def test_writable_directory_or_database_is_refused(self):
        for path in [self.directory, self.database]:
            mode = path.stat().st_mode & 0o777
            path.chmod(0o777)
            with self.assertRaises(ValueError): dns.install(self.request)
            path.chmod(mode)
        self.assertFalse(self.target.exists())

    def test_unexpected_upstream_request_is_refused(self):
        with self.assertRaises(ValueError): dns.install({**self.request, 'server': '192.0.2.1'})
        self.assertEqual(self.commands, [])

    def test_validation_and_restart_failures_remove_only_the_new_file(self):
        for failed in [['dnsmasq', '--test'], ['systemctl', 'restart', 'dnsmasq']]:
            self.failure = failed
            with self.assertRaises(subprocess.CalledProcessError): dns.install(self.request)
            self.assertFalse(self.target.exists())
            self.assertEqual(self.private.read_text(), 'address=/gateway.orbit/10.44.0.1\n')
            self.assertEqual(self.commands[-1], ['systemctl', 'restart', 'dnsmasq'])


unittest.main()
