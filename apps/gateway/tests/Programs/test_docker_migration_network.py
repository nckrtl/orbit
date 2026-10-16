"""Opt-in real-engine regression, never using the host's Docker socket or networks.

Run: sudo env ORBIT_DOCKER_NETWORK_TEST=1 python3 apps/gateway/tests/Programs/test_docker_migration_network.py -v
Requires dockerd (API 1.52), iproute2, ping, util-linux and root namespace/mount capabilities.
The test creates its own network/mount namespaces, daemons, sockets and temporary roots.
"""
import json
import os
from pathlib import Path
import shutil
import signal
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch

from test_docker_migration import module


@unittest.skipUnless(os.environ.get('ORBIT_DOCKER_NETWORK_TEST') == '1', 'opt-in isolated Docker networking test')
class DockerMigrationNetworkTest(unittest.TestCase):
    def test_custom_bridge_remains_reachable_after_fresh_root_and_resume(self):
        if os.environ.get('ORBIT_DOCKER_NETWORK_SANDBOX') != '1':
            self.assertEqual(os.geteuid(), 0, 'Run the opt-in test with sudo')
            for tool in ('unshare', 'mount', 'nsenter', 'ip', 'ping', 'dockerd'):
                self.assertIsNotNone(shutil.which(tool), tool)
            result = subprocess.run(['unshare', '--net', '--mount', '--pid', '--fork', '--kill-child',
                                     '--mount-proc', '--propagation', 'private',
                                     sys.executable, str(Path(__file__).resolve()), '-v'],
                                    env=dict(os.environ, ORBIT_DOCKER_NETWORK_SANDBOX='1',
                                             ORBIT_DOCKER_NETWORK_PARENT_NET=os.readlink('/proc/self/ns/net'),
                                             ORBIT_DOCKER_NETWORK_PARENT_MNT=os.readlink('/proc/self/ns/mnt')),
                                    capture_output=True, text=True, timeout=150)
            self.assertEqual(result.returncode, 0, result.stdout + result.stderr)
            print(result.stdout.strip())
            print(result.stderr.strip())
            return

        # Fail closed before any daemon or interface mutation if isolation was bypassed.
        for namespace in ('net', 'mnt'):
            self.assertNotEqual(os.readlink('/proc/self/ns/' + namespace),
                                os.environ['ORBIT_DOCKER_NETWORK_PARENT_' + namespace.upper()])
        subprocess.run(['mount', '-t', 'tmpfs', '-o', 'mode=755', 'tmpfs', '/run'], check=True)
        subprocess.run(['ip', 'link', 'set', 'lo', 'up'], check=True)
        with tempfile.TemporaryDirectory(prefix='orbit-migration-network-') as directory:
            self.root = Path(directory)
            self.daemon = None
            self.log = None
            self.addCleanup(self.stop_daemon)
            try:
                self.exercise_fallback()
            finally:
                self.stop_daemon()

    def command(self, *argv, **kwargs):
        return subprocess.run(list(argv), check=True, capture_output=True, text=True, timeout=15, **kwargs)

    def stop_daemon(self):
        if self.daemon is not None:
            process = self.daemon
            self.daemon = None
            try:
                os.killpg(process.pid, signal.SIGTERM)
                process.wait(timeout=15)
            except subprocess.TimeoutExpired:
                os.killpg(process.pid, signal.SIGKILL)
                process.wait(timeout=5)
            if self.log:
                self.log.close()
                self.log = None

    def start_daemon(self, data):
        self.stop_daemon()
        self.log = (self.root / (data.name + '.log')).open('a')
        config = self.root / 'daemon.json'
        config.write_text('{}')
        self.daemon = subprocess.Popen(
            ['dockerd', '--config-file=' + str(config), '--host=unix://' + str(self.root / 'docker.sock'),
             '--data-root=' + str(data), '--exec-root=' + str(self.root / 'exec'),
             '--pidfile=' + str(self.root / 'docker.pid'), '--bridge=none', '--iptables=false',
             '--ip6tables=false', '--ip-forward=false', '--ip-masq=false', '--storage-driver=vfs'],
            stdout=self.log, stderr=self.log, start_new_session=True)
        deadline = time.monotonic() + 25
        while time.monotonic() < deadline and self.daemon.poll() is None:
            try:
                module.api('GET', '/info')
                return
            except (OSError, module.RecipeFailure):
                time.sleep(0.1)
        self.fail('Sandbox daemon did not start: ' + (self.root / (data.name + '.log')).read_text()[-4000:])

    def exercise_fallback(self):
        def connect(connection):
            connection.sock = socket.socket(socket.AF_UNIX, socket.SOCK_STREAM)
            connection.sock.settimeout(5)
            connection.sock.connect(str(self.root / 'docker.sock'))

        def service(argv, **kwargs):
            if argv[:2] == ['systemctl', 'stop']:
                self.stop_daemon()
            elif argv[:2] == ['systemctl', 'start']:
                self.start_daemon(self.root / 'engine')
            elif argv != ['systemctl', 'daemon-reload']:
                self.fail('Unexpected host command: ' + repr(argv))
            return subprocess.CompletedProcess(argv, 0, '', '')

        with patch.object(module.DockerSocket, 'connect', connect), \
                patch.object(module, 'JOURNAL', self.root), \
                patch.object(module, 'DROPIN', self.root / 'systemd' / 'migration.conf'), \
                patch.object(module, 'run', service):
            self.start_daemon(self.root / 'original')
            created = module.api('POST', '/networks/create', {
                'Name': 'app-network', 'Driver': 'bridge',
                'IPAM': {'Config': [{'Subnet': '172.30.0.0/24', 'Gateway': '172.30.0.1'}]}})
            network = module.api('GET', '/networks/' + created['Id'])
            bridge = 'br-' + network['Id'][:12]
            original_index = json.loads(self.command('ip', '-j', 'link', 'show', bridge).stdout)[0]['ifindex']
            state = {'volumes': [], 'networks': [network]}
            self.stop_daemon()
            self.assertEqual(json.loads(self.command('ip', '-j', 'link', 'show', bridge).stdout)[0]['ifindex'], original_index)
            self.assertIn(bridge, self.command('ip', 'route', 'show', '172.30.0.0/24').stdout)

            module.fresh_engine(state)
            fresh = module.api('GET', '/networks/app-network')
            # Use the actual fresh network's bridge, including the buggy implementation's
            # generated name, so a regression fails on real packet loss, not a body assertion.
            fresh_bridge = fresh.get('Options', {}).get('com.docker.network.bridge.name') or 'br-' + fresh['Id'][:12]
            peer = subprocess.Popen(['unshare', '--net', 'sleep', '120'])
            try:
                deadline = time.monotonic() + 5
                while os.readlink(f'/proc/{peer.pid}/ns/net') == os.readlink('/proc/self/ns/net'):
                    self.assertLess(time.monotonic(), deadline)
                    time.sleep(0.01)
                self.command('ip', 'link', 'add', 'app-port', 'type', 'veth', 'peer', 'name', 'app-endpoint')
                self.command('ip', 'link', 'set', 'app-port', 'master', fresh_bridge)
                self.command('ip', 'link', 'set', 'app-port', 'up')
                self.command('ip', 'link', 'set', 'app-endpoint', 'netns', str(peer.pid))
                self.command('nsenter', '-t', str(peer.pid), '-n', 'ip', 'addr', 'add', '172.30.0.2/24', 'dev', 'app-endpoint')
                self.command('nsenter', '-t', str(peer.pid), '-n', 'ip', 'link', 'set', 'app-endpoint', 'up')
                for resumed in (False, True):
                    if resumed:
                        module.fresh_engine(state)
                    route = json.loads(self.command('ip', '-j', 'route', 'get', '172.30.0.2').stdout)[0]
                    ping = subprocess.run(['ping', '-n', '-c', '1', '-W', '2', '172.30.0.2'], capture_output=True, text=True)
                    self.assertEqual(ping.returncode, 0, f'resumed={resumed}, route={route}\n{ping.stdout}{ping.stderr}')
                    self.assertEqual(route['dev'], bridge)
                    self.assertEqual(json.loads(self.command('ip', '-j', 'link', 'show', bridge).stdout)[0]['ifindex'], original_index)
                    routes = json.loads(self.command('ip', '-j', 'route', 'show', '172.30.0.0/24').stdout)
                    self.assertEqual([route['dev'] for route in routes], [bridge])
                    print(f'resumed={resumed}: ping 172.30.0.2 succeeded; single route via original {bridge} (ifindex {original_index})')
            finally:
                peer.terminate()
                peer.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
