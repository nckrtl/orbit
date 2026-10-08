import importlib.util
import json
import os
from pathlib import Path
import subprocess
import sys
import tempfile
from types import SimpleNamespace
import unittest
from unittest.mock import patch

spec = importlib.util.spec_from_file_location('network', sys.argv.pop(1))
network = importlib.util.module_from_spec(spec)
spec.loader.exec_module(network)
REQUEST = {'sandbox_id': '0bd99b9a-37f1-4872-88b6-4d9b1bee7cce', 'address': '10.233.209.10',
           'bridge': '10.233.209.1', 'gateway': '10.44.0.2'}


class PiNetwork(unittest.TestCase):
    def test_endpoint_contract_refuses_foreign_and_extra_values(self):
        network.validate(REQUEST)
        for change in ({'gateway': '169.254.169.254'}, {'bridge': '10.233.209.2'},
                       {'address': '10.233.210.10'}, {'command': 'anything'}, {'sandbox_id': 'foreign'}):
            with self.subTest(change=change), self.assertRaises(ValueError):
                network.validate({**REQUEST, **change})

    def test_vm_interface_is_selected_by_address_and_ambiguity_is_refused(self):
        row = {'ifname': 'enp5s0', 'addr_info': [{'local': '10.233.209.10'}]}
        with patch.object(network, 'run', return_value=json.dumps([row])):
            self.assertEqual(network.interface(REQUEST['address']), 'enp5s0')
        for rows in ([], [row, {**row, 'ifname': 'enp6s0'}], [{**row, 'ifname': 'orbit'}]):
            with patch.object(network, 'run', return_value=json.dumps(rows)), self.assertRaises(ValueError):
                network.interface(REQUEST['address'])

    def inspect(self, routes, rules, owned=True):
        with patch.object(network, 'interface', return_value='enp5s0'), \
                patch.object(network, 'command', return_value=SimpleNamespace(returncode=0, stdout=json.dumps(routes))), \
                patch.object(network, 'run', return_value=json.dumps(rules)), \
                patch.object(network, 'firewall', return_value=([], False)):
            return network.inspect(REQUEST, owned)

    def test_foreign_route_and_rule_are_refused_before_mutation(self):
        route = {'dst': '10.44.0.2', 'gateway': '10.233.209.1', 'dev': 'enp5s0',
                 'prefsrc': '10.233.209.10', 'protocol': 'static', 'flags': []}
        rule = {'priority': 3774, 'src': '10.233.209.10', 'dst': '10.44.0.2', 'table': 3774, 'protocol': 'static'}
        self.inspect([route], [rule])
        for routes, rules in (([{**route, 'gateway': '10.233.209.2'}], []),
                              ([{**route, 'dst': 'default'}], []), ([], [{**rule, 'src': 'all'}]),
                              ([], [{**rule, 'fwmark': '0x1'}]), ([route, route], [])):
            with self.subTest(routes=routes, rules=rules), self.assertRaises(ValueError):
                self.inspect(routes, rules)
        with self.assertRaises(ValueError):
            self.inspect([route], [rule], owned=False)

    def test_ready_retry_makes_no_kernel_changes(self):
        with patch.object(network, 'inspect', return_value=('enp5s0', True, True, [], True)), \
                patch.object(network, 'run') as execute:
            network.configure(REQUEST)
            execute.assert_not_called()

    def test_changed_or_duplicate_marked_firewall_is_refused(self):
        marker = 'orbit:sandbox-pi:' + REQUEST['sandbox_id']
        row = '-A INPUT --comment ' + marker + ' -j ACCEPT'
        for text, found in ((row, 1), (row + '\n' + row, 0), (row.replace(REQUEST['sandbox_id'], 'foreign'), 0)):
            with patch.object(network, 'run', return_value=text), \
                    patch.object(network, 'command', return_value=SimpleNamespace(returncode=found)), \
                    self.assertRaises(ValueError):
                network.firewall(REQUEST, 'enp5s0')

    def test_owned_files_are_persistent_and_foreign_state_is_kept(self):
        with tempfile.TemporaryDirectory() as scratch:
            root = Path(scratch)
            checkout = root / 'checkout'
            (checkout / '.git').mkdir(parents=True)
            (checkout / '.git/orbit-sandbox-source.json').write_text(json.dumps({'sandbox_id': REQUEST['sandbox_id']}))
            config, program, unit = (root / name for name in ('network.json', 'network.py', 'network.service'))
            with patch.object(network, 'ROOT_UID', os.geteuid()), patch.object(network, 'CHECKOUT', checkout), \
                    patch.object(network, 'CONFIG', config), patch.object(network, 'PROGRAM', program), \
                    patch.object(network, 'UNIT', unit), \
                    patch.object(network.pwd, 'getpwnam', return_value=SimpleNamespace(pw_uid=os.geteuid())), \
                    patch.object(network, 'inspect'), patch.object(network, 'configure'), patch.object(network, 'run'):
                self.assertTrue(network.install(REQUEST, 'owned program')['ready'])
                before = {p: (p.stat().st_ino, p.read_bytes()) for p in (config, program, unit)}
                network.install(REQUEST, 'owned program')
                self.assertEqual(before, {p: (p.stat().st_ino, p.read_bytes()) for p in before})
                self.assertIn('Before=orbit-sandbox-pi.service', unit.read_text())
                unit.write_text('foreign service')
                with self.assertRaises(ValueError):
                    network.install(REQUEST, 'owned program')
                self.assertEqual(unit.read_text(), 'foreign service')
                unit.unlink()
                unit.symlink_to(root / 'absent')
                with self.assertRaises(ValueError):
                    network.install(REQUEST, 'owned program')

    def test_interrupted_write_removes_only_its_created_file(self):
        with tempfile.TemporaryDirectory() as scratch:
            path = Path(scratch) / 'new'
            with patch.object(network.os, 'fsync', side_effect=OSError('interrupted')), self.assertRaises(OSError):
                network.write(path, 'owned', 0o600)
            self.assertFalse(path.exists())

    def test_interrupted_write_keeps_a_replacement_inode(self):
        with tempfile.TemporaryDirectory() as scratch:
            path = Path(scratch) / 'new'
            def replace(fd):
                path.unlink()
                path.write_text('replacement')
                raise OSError('interrupted')
            with patch.object(network.os, 'fsync', side_effect=replace), self.assertRaises(OSError):
                network.write(path, 'owned', 0o600)
            self.assertEqual(path.read_text(), 'replacement')


def routing_namespace():
    assert os.geteuid() == 0
    for device, address in (('eth0', '10.233.209.10/24'), ('orbit', '10.44.0.3/24')):
        network.run('ip', 'link', 'add', device, 'type', 'dummy')
        network.run('ip', 'address', 'add', address, 'dev', device)
        network.run('ip', 'link', 'set', device, 'up')
    network.configure(REQUEST)
    network.configure(REQUEST)
    pi = json.loads(network.run('ip', '-j', 'route', 'get', '10.44.0.2', 'from', '10.233.209.10'))[0]
    native = json.loads(network.run('ip', '-j', 'route', 'get', '10.44.0.2', 'from', '10.44.0.3'))[0]
    assert pi['dev'] == 'eth0' and pi['gateway'] == '10.233.209.1'
    assert native['dev'] == 'orbit'
    # The overlapping private Gateway keeps its native path; only bridge-source replies move.
    overlap = {**REQUEST, 'gateway': '10.44.0.1'}
    network.run('ip', 'rule', 'del', 'priority', '3774')
    network.run('ip', 'route', 'flush', 'table', '3774')
    network.run('iptables', '-F', 'INPUT')
    network.configure(overlap)
    private = json.loads(network.run('ip', '-j', 'route', 'get', '10.44.0.1', 'from', '10.44.0.3'))[0]
    assert private['dev'] == 'orbit'
    print(json.dumps({'source_specific_routing': True, 'native_overlap_preserved': True, 'retry_idempotent': True}))


if sys.argv[1:] == ['--routes']:
    routing_namespace()
else:
    unittest.main()
