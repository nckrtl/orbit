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
        self.nodes = [{'id': 1, 'name': 'gateway', 'status': 'active', 'wireguard_ip': '10.44.0.1', 'roles': ['gateway', 'vpn']},
                      {'id': 2, 'name': 'operator', 'status': 'active', 'wireguard_ip': '10.44.0.3', 'roles': []}]
        self.version = self.commit
        self.calls = []
        self.doctor_fault = None

    def run_command(self, args, **kwargs):
        self.calls.append(args)
        self.assertNotIn('GITHUB_TOKEN', kwargs['env'])
        if args[0] == 'git':
            return SimpleNamespace(stdout=self.commit)
        if args[-2:] == ('node:list', '--json'):
            return SimpleNamespace(stdout=json.dumps({'nodes': self.nodes}))
        if 'doctor' in args:
            node_id = int(next(arg[7:] for arg in args if arg.startswith('--node=')))
            node = next(node for node in self.nodes if node['id'] == node_id)
            report = {'healthy': True, 'nodes': [{'node_id': node_id, 'node_name': node['name'], 'healthy': True,
                      'families': [{'family': family, 'status': 'healthy'} for family in ('node', 'role', 'firewall')]}]}
            if self.doctor_fault == 'unhealthy': report['healthy'] = False
            if self.doctor_fault == 'wrong node': report['nodes'][0]['node_id'] += 99
            if self.doctor_fault == 'missing family': report['nodes'][0]['families'].pop()
            return SimpleNamespace(stdout=json.dumps(report))
        return SimpleNamespace(stdout=json.dumps({'status': 'ok', 'url': 'https://10.44.0.1', 'version': self.version}))

    def inspect(self):
        with patch('subprocess.run', self.run_command), patch.dict('os.environ', GITHUB_TOKEN='fixture-secret'):
            return check({'commit': self.commit}, self.root, self.home)

    def test_active_isolated_pair_reports_its_branch_version(self):
        result = self.inspect()
        self.assertTrue(result['ready'])
        self.assertEqual(result['gateway_version'], self.commit)
        self.assertEqual(result['nodes'], ['gateway', 'operator'])

    def test_pair_requires_fresh_complete_doctor_for_each_exact_node(self):
        self.inspect()
        self.assertEqual(2, sum('doctor' in call for call in self.calls))
        for fault in ('unhealthy', 'wrong node', 'missing family'):
            self.doctor_fault = fault
            with self.assertRaises(ValueError):
                self.inspect()

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
