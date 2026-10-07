"""Policy regressions; --packets requires root in a disposable network namespace."""
import importlib.util
import json
import os
import pathlib
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

path = pathlib.Path(sys.argv.pop(1))
PACKETS = '--packets' in sys.argv
if PACKETS:
    sys.argv.remove('--packets')
specification = importlib.util.spec_from_file_location('policy', path)
policy = importlib.util.module_from_spec(specification)
specification.loader.exec_module(policy)
SPEC = dict(sandbox_id='12345678-1234-4234-8234-123456789abc', address='10.44.0.50',
            hub='10.44.0.1', gateway='10.44.0.2', router='10.44.0.9', model='10.44.0.3', model_port=8317)


class PolicyTests(unittest.TestCase):
    def test_input_validation(self):
        self.assertEqual(policy.validate(dict(operation='ensure', **SPEC)), SPEC)
        for changed in [dict(operation='flush'), dict(address='169.254.169.254'),
                        dict(address=SPEC['gateway']), dict(model_port=True), dict(extra='bad'),
                        dict(sandbox_id='../../bad')]:
            with self.subTest(changed=changed), self.assertRaises((ValueError, TypeError)):
                policy.validate(dict(operation='ensure', **SPEC, **changed))

    def test_canonical_does_not_ignore_policy_changes(self):
        original = {'nftables': [{'metainfo': {}}, {'rule': {'handle': 1, 'expr': [{'drop': None}]}}]}
        self.assertEqual(policy.canonical(original), {'nftables': [{'rule': {'expr': [{'drop': None}]}}]})
        self.assertNotEqual(policy.canonical(original), policy.canonical({'nftables': [{'rule': {'expr': [{'accept': None}]}}]}))

    def test_lock_waits_for_concurrent_boot_and_bounds_busy_failure(self):
        with patch.object(policy.fcntl, 'flock', side_effect=[BlockingIOError(), None]) as lock, patch.object(policy.time, 'sleep'):
            policy.acquire(123)
            self.assertEqual(lock.call_count, 2)
        with patch.object(policy.fcntl, 'flock', side_effect=BlockingIOError()), patch.object(policy.time, 'monotonic', side_effect=[0, 61]):
            with self.assertRaises(ValueError):
                policy.acquire(123)

    def test_files_refuse_drift_and_symlinks(self):
        if os.geteuid() != 0:
            self.skipTest('root file ownership assertions require root')
        with tempfile.TemporaryDirectory() as directory:
            target = pathlib.Path(directory) / 'owned'
            policy.put(target, b'one')
            policy.put(target, b'one')
            with self.assertRaises(ValueError):
                policy.put(target, b'two')
            link = pathlib.Path(directory) / 'link'
            link.symlink_to(target)
            with self.assertRaises(OSError):
                policy.put(link, b'one')
            target.chmod(0o644)
            with self.assertRaises(ValueError):
                policy.read(target)


@unittest.skipUnless(PACKETS, 'run with --packets inside sudo unshare --net')
class PacketTests(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        if os.geteuid() != 0:
            raise RuntimeError('packet tests require root')
        cls.processes = []
        cls.addClassCleanup(cls.tearDownClass)
        cls.command(['ip', 'link', 'set', 'lo', 'up'])
        cls.command(['sysctl', '-q', '-w', 'net.ipv4.ip_forward=1'])
        cls.guest = cls.namespace('g', '10.44.0.50', '10.44.0.1')
        cls.peer = cls.namespace('p', '10.44.1.9', '10.44.1.1')
        for address in ['10.44.0.2', '10.44.0.3']:
            cls.command(['ip', 'address', 'add', address + '/32', 'dev', 'lo'])
        for address in ['10.44.0.9', '10.44.0.99', '169.254.169.254']:
            cls.command(['nsenter', '-t', str(cls.peer.pid), '-n', 'ip', 'address', 'add', address + '/32', 'dev', 'lo'])
            cls.command(['ip', 'route', 'add', address + '/32', 'via', '10.44.1.9'])
        # Prove there is a reachable listener on every denied path before applying policy.
        cls.listeners = {}
        for pid, address, port in [(None, '10.44.0.2', 443), (None, '10.44.0.2', 22),
                                   (None, '10.44.0.3', 8317), (None, '10.44.0.1', 53),
                                   (cls.peer.pid, '10.44.0.9', 443), (cls.peer.pid, '10.44.0.99', 443),
                                   (cls.peer.pid, '169.254.169.254', 80),
                                   (cls.guest.pid, '10.44.0.50', 3774), (cls.guest.pid, '10.44.0.50', 80)]:
            cls.listen(pid, address, port)
        for address, port in [('10.44.0.2', 22), ('10.44.0.99', 443), ('169.254.169.254', 80)]:
            assert cls.connect(cls.guest.pid, SPEC['address'], address, port)
        cls.command(['nft', '-f', '-'], policy.render(SPEC))

    @classmethod
    def command(cls, arguments, data=None):
        return subprocess.run(arguments, input=data, text=True, check=True, capture_output=True, timeout=15).stdout

    @classmethod
    def namespace(cls, name, address, hub):
        child = subprocess.Popen(['unshare', '--net', 'python3', '-u', '-c',
                                  'import sys; print("ready", flush=True); sys.stdin.read()'],
                                 stdin=subprocess.PIPE, stdout=subprocess.PIPE, text=True)
        cls.processes.append(child)
        assert child.stdout.readline().strip() == 'ready'
        cls.command(['ip', 'link', 'add', name + 'h', 'type', 'veth', 'peer', 'name', name + 'v'])
        cls.command(['ip', 'link', 'set', name + 'v', 'netns', str(child.pid)])
        cls.command(['ip', 'address', 'add', hub + '/24', 'dev', name + 'h'])
        cls.command(['ip', 'link', 'set', name + 'h', 'up'])
        prefix = ['nsenter', '-t', str(child.pid), '-n']
        for arguments in [['ip', 'link', 'set', 'lo', 'up'], ['ip', 'address', 'add', address + '/32', 'dev', name + 'v'],
                          ['ip', 'link', 'set', name + 'v', 'up'], ['ip', 'route', 'add', hub + '/32', 'dev', name + 'v'], ['ip', 'route', 'add', 'default', 'via', hub]]:
            cls.command(prefix + arguments)
        return child

    @classmethod
    def listen(cls, pid, address, port):
        prefix = ['nsenter', '-t', str(pid), '-n'] if pid else []
        code = '''import socket, sys
s=socket.socket(); s.setsockopt(socket.SOL_SOCKET,socket.SO_REUSEADDR,1)
s.bind((sys.argv[1],int(sys.argv[2]))); s.listen(); print('ready',flush=True)
while True:
 c,_=s.accept(); c.sendall(b'yes'); c.close()
'''
        process = subprocess.Popen(prefix + ['python3', '-u', '-c', code, address, str(port)], stdout=subprocess.PIPE, text=True)
        cls.processes.append(process)
        assert process.stdout.readline().strip() == 'ready'

    @classmethod
    def connect(cls, pid, source, target, port):
        prefix = ['nsenter', '-t', str(pid), '-n'] if pid else []
        code = '''import socket, sys
s=socket.socket(); s.settimeout(0.35); s.bind((sys.argv[1],0))
try:
 s.connect((sys.argv[2],int(sys.argv[3]))); assert s.recv(3)==b'yes'
except (OSError,AssertionError): sys.exit(1)
'''
        return subprocess.run(prefix + ['python3', '-c', code, source, target, str(port)], timeout=3).returncode == 0

    def test_outbound_and_return_traffic(self):
        for address, port in [('10.44.0.2', 443), ('10.44.0.3', 8317), ('10.44.0.9', 443), ('10.44.0.1', 53)]:
            with self.subTest(address=address):
                self.assertTrue(self.connect(self.guest.pid, SPEC['address'], address, port))
        for address, port in [('10.44.0.2', 22), ('10.44.0.99', 443), ('169.254.169.254', 80)]:
            with self.subTest(address=address):
                self.assertFalse(self.connect(self.guest.pid, SPEC['address'], address, port))

    def test_inbound_and_return_traffic(self):
        self.assertTrue(self.connect(None, SPEC['gateway'], SPEC['address'], 3774))
        self.assertTrue(self.connect(self.peer.pid, SPEC['router'], SPEC['address'], 80))
        self.assertFalse(self.connect(self.peer.pid, '10.44.0.99', SPEC['address'], 80))
        self.assertFalse(self.connect(None, SPEC['model'], SPEC['address'], 3774))

    def test_live_policy_identity_and_drift(self):
        name = policy.table_name(SPEC)
        desired = policy.expected(name, policy.render(SPEC))
        self.assertEqual(policy.observed(name), desired)
        self.command(['nft', 'add', 'rule', 'inet', name, 'forward', 'accept'])
        self.assertNotEqual(policy.observed(name), desired)
        self.command(['nft', 'delete', 'table', 'inet', name])
        self.command(['nft', '-f', '-'], policy.render(SPEC))

    def test_owned_apply_retry_boot_and_removal(self):
        # Real nft + root-owned files. systemctl is mocked; this is not boot acceptance.
        with tempfile.TemporaryDirectory(dir='/root') as directory:
            root = pathlib.Path(directory)
            units = root / 'units'
            units.mkdir(mode=0o700)
            actual_run = policy.run
            def run(arguments, data=None):
                if arguments[0] == 'systemctl':
                    return ''
                return actual_run(arguments, data)
            with patch.object(policy, 'ROOT', root / 'networks'), patch.object(policy, 'UNITS', units), patch.object(policy, 'run', run):
                # The live table above has no ownership manifest and must not be adopted.
                with self.assertRaises(ValueError):
                    policy.apply(SPEC, 'ensure')
                self.command(['nft', 'delete', 'table', 'inet', policy.table_name(SPEC)])
                policy.apply(SPEC, 'ensure')
                policy.apply(SPEC, 'ensure')
                self.command(['nft', 'delete', 'table', 'inet', policy.table_name(SPEC)])
                policy.apply(SPEC, 'ensure', boot=True)
                manifest = policy.ROOT / (SPEC['sandbox_id'] + '.json')
                manifest.write_text('{}')
                with self.assertRaises(ValueError):
                    policy.apply(SPEC, 'remove')
                manifest.write_bytes(json.dumps(SPEC, sort_keys=True).encode())
                policy.apply(SPEC, 'remove')
                self.assertIsNone(policy.observed(policy.table_name(SPEC)))
                self.assertFalse(manifest.exists())
                # Restore the fixture policy for tests that run afterward.
                self.command(['nft', '-f', '-'], policy.render(SPEC))

    @classmethod
    def tearDownClass(cls):
        for child in reversed(cls.processes):
            if child.poll() is not None:
                continue
            child.terminate()
            child.wait(timeout=3)


unittest.main()
