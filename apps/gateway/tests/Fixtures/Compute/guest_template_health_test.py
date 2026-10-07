import json
from pathlib import Path
import runpy
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

check = runpy.run_path(sys.argv.pop(1))['check']


class HealthTest(unittest.TestCase):
    def setUp(self):
        self.scratch = tempfile.TemporaryDirectory()
        self.addCleanup(self.scratch.cleanup)
        self.home = Path(self.scratch.name).resolve()
        self.root = self.home / 'orbit'
        (self.root / '.git').mkdir(parents=True)
        (self.home / '.orbit').mkdir()
        self.profile = self.home / '.orbit/config.json'
        self.profile.write_text(json.dumps({'active_gateway': 'test', 'gateways': {'test': {'url': 'https://10.44.0.1'}}}))
        self.commit = 'a' * 40
        self.nodes = [{'name': 'gateway', 'status': 'active', 'wireguard_ip': '10.44.0.1', 'roles': ['gateway', 'vpn']},
                      {'name': 'operator', 'status': 'active', 'wireguard_ip': '10.44.0.3', 'roles': []}]
        self.version = self.commit
        self.calls = []

    def run_command(self, args, **kwargs):
        self.calls.append(args)
        self.assertNotIn('GITHUB_TOKEN', kwargs['env'])
        if args[0] == 'git':
            return SimpleNamespace(stdout=self.commit)
        if args[-2:] == ('node:list', '--json'):
            return SimpleNamespace(stdout=json.dumps({'nodes': self.nodes}))
        return SimpleNamespace(stdout=json.dumps({'status': 'ok', 'url': 'https://10.44.0.1', 'version': self.version}))

    def inspect(self):
        with patch('subprocess.run', self.run_command), patch.dict('os.environ', GITHUB_TOKEN='fixture-secret'):
            return check({'commit': self.commit}, self.root, self.home)

    def test_active_isolated_pair_reports_its_branch_version(self):
        result = self.inspect()
        self.assertTrue(result['ready'])
        self.assertEqual(result['gateway_version'], self.commit)
        self.assertEqual(result['nodes'], ['gateway', 'operator'])

    def test_live_gateway_profile_is_refused_before_any_command(self):
        self.profile.write_text(json.dumps({'active_gateway': 'live', 'gateways': {'live': {'url': 'https://10.44.0.2'}}}))
        with self.assertRaises(ValueError):
            self.inspect()
        self.assertEqual(self.calls, [])

    def test_workload_operator_stale_version_or_partial_inventory_is_refused(self):
        self.nodes[1]['roles'] = ['app-dev']
        with self.assertRaises(ValueError):
            self.inspect()
        self.nodes[1]['roles'] = []
        self.version = 'old-template'
        with self.assertRaises(ValueError):
            self.inspect()
        self.version = self.commit
        self.nodes.pop()
        with self.assertRaises(ValueError):
            self.inspect()

    def test_linked_profile_is_refused(self):
        other = self.home / 'other.json'
        self.profile.rename(other)
        self.profile.symlink_to(other)
        with self.assertRaises(ValueError):
            self.inspect()
        self.assertEqual(self.calls, [])


if __name__ == '__main__':
    unittest.main()
