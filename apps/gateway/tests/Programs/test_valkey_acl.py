"""database-servers.write-valkey-acl against a fake Docker: sudo and docker are shell fakes on PATH. The fake docker
records its arguments and standard input, then runs the container's `sh -c` script with /data in a temporary
directory, so the umask and the rename run for real."""
import os
import stat
import tempfile
import unittest
from pathlib import Path

from harness import execute, result

NAME = 'database-servers.write-valkey-acl'
ACL = 'user default on #' + '0123456789abcdef' * 4 + ' ~* &* +@all\n'
FAKES = {
    'sudo': '[ "$1" = -n ] || exit 64; shift; exec "$@"',
    'docker': 'printf "%s\\n" "$@" > "$STATE/argv"; '
              '[ -f "$STATE/fail" ] && { echo "docker: Error response from daemon: pull access denied" >&2; exit 125; }; '
              'volume=""; script=""; previous=""; '
              'for argument in "$@"; do case "$previous" in '
              '--mount) volume="${argument#type=volume,source=}"; volume="${volume%%,*}";; -c) script="$argument";; '
              'esac; previous="$argument"; done; '
              'mkdir -p "$STATE/volumes/$volume"; tee "$STATE/stdin" | sh -c "${script//\\/data/$STATE/volumes/$volume}"',
}


class WriteValkeyAclTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory(prefix='orbit-valkey-acl-')
        self.addCleanup(directory.cleanup)
        self.state = Path(directory.name) / 'state'
        self.state.mkdir()
        bin_directory = Path(directory.name) / 'bin'
        bin_directory.mkdir()
        for name, body in FAKES.items():
            (bin_directory / name).write_text(f'#!/usr/bin/env bash\n{body}\n')
            (bin_directory / name).chmod(0o755)
        self.environment = {'PATH': f'{bin_directory}:{os.environ["PATH"]}', 'STATE': str(self.state)}
        self.volume = self.state / 'volumes' / 'orbit-beast-valkey-data'

    def write(self, **payload):
        return result(NAME, {'volume': 'orbit-beast-valkey-data', 'image': 'valkey/valkey:8.1', 'acl': ACL, **payload},
                      environment=self.environment)

    def test_writes_the_file_through_a_container_without_a_network_from_standard_input(self):
        self.assertEqual(self.write(), ('written', {}))

        self.assertEqual((self.state / 'argv').read_text().splitlines(), [
            'run', '--rm', '-i', '--network', 'none',
            '--mount', 'type=volume,source=orbit-beast-valkey-data,target=/data',
            '--entrypoint', 'sh', 'valkey/valkey:8.1', '-c',
            'umask 077 && cat > /data/users.acl.orbit-new && mv -f /data/users.acl.orbit-new /data/users.acl',
        ])
        self.assertNotIn('0123456789abcdef', (self.state / 'argv').read_text())
        self.assertEqual((self.state / 'stdin').read_text(), ACL)
        self.assertEqual((self.volume / 'users.acl').read_text(), ACL)
        self.assertEqual(stat.S_IMODE((self.volume / 'users.acl').stat().st_mode), 0o600)
        self.assertEqual(sorted(path.name for path in self.volume.iterdir()), ['users.acl'])

    def test_preserves_an_explicitly_adopted_open_default(self):
        acl = 'user default on nopass ~* &* +@all\n'
        self.assertEqual(self.write(acl=acl), ('written', {}))
        self.assertEqual((self.volume / 'users.acl').read_text(), acl)

    def test_replaces_an_existing_file(self):
        self.volume.mkdir(parents=True)
        (self.volume / 'users.acl').write_text('user default on nopass ~* &* +@all\n')

        self.assertEqual(self.write(), ('written', {}))

        self.assertEqual((self.volume / 'users.acl').read_text(), ACL)

    def test_a_failed_docker_run_is_failed_and_logs_the_docker_error(self):
        (self.state / 'fail').touch()

        completed = execute(NAME, {'volume': 'orbit-beast-valkey-data', 'image': 'valkey/valkey:8.1', 'acl': ACL},
                            environment=self.environment)

        self.assertEqual(completed.returncode, 0)
        self.assertEqual(completed.stdout, b'{"outcome":"failed","data":{}}\n')
        self.assertIn(b'docker run exited 125', completed.stderr)
        self.assertFalse(self.volume.exists())

    def test_refuses_input_outside_the_contract_before_docker_runs(self):
        invalid = {
            'a path as volume': {'volume': '/srv/valkey'},
            'a mount option in the volume': {'volume': 'data,readonly'},
            'a volume that is not a string': {'volume': 5},
            'another image': {'image': 'redis:8'},
            'an image without a tag': {'image': 'valkey/valkey'},
            'an argument in the image': {'image': 'valkey/valkey:8.1 --privileged'},
            'a second user': {'acl': ACL + 'user queue on #' + 'a' * 64 + ' ~queue:* +@list\n'},
            'a file without its newline': {'acl': ACL.rstrip('\n')},
            'an uppercase hash': {'acl': ACL.upper()},
        }
        for case, payload in invalid.items():
            with self.subTest(case):
                self.assertEqual(self.write(**payload), ('failed', {}))
                self.assertFalse((self.state / 'argv').exists())

@unittest.skipUnless(os.environ.get('ORBIT_TEST_DOCKER') == '1', 'Set ORBIT_TEST_DOCKER=1 for disposable Docker verification')
class RealValkeyBootstrapTest(unittest.TestCase):
    def test_bootstrapped_volume_starts_with_the_managed_command_and_preserves_aof(self):
        import hashlib
        import socket
        import subprocess
        import time
        import uuid

        name = 'orbit-valkey-proof-' + uuid.uuid4().hex[:12]
        volume = name + '-data'
        password = 'orbit-disposable-test-secret'
        acl = 'user default on #' + hashlib.sha256(password.encode()).hexdigest() + ' ~* &* +@all\n'

        def docker(*args):
            return subprocess.check_output(['docker', *args], text=True).strip()

        def command(*args):
            with socket.create_connection(('127.0.0.1', port), timeout=3) as connection:
                stream = connection.makefile('rb')
                for values in [('AUTH', 'default', password), args]:
                    encoded = [str(value).encode() for value in values]
                    connection.sendall(b'*' + str(len(encoded)).encode() + b'\r\n' + b''.join(
                        b'$' + str(len(value)).encode() + b'\r\n' + value + b'\r\n' for value in encoded))
                    reply = stream.readline()
                    if reply.startswith(b'$'):
                        reply = stream.read(int(reply[1:-2]) + 2)[:-2]
                return reply

        def wait_ready():
            for _attempt in range(30):
                try:
                    if command('PING') == b'+PONG\r\n':
                        return
                except (OSError, ValueError):
                    pass
                time.sleep(0.1)
            self.fail('Valkey did not become ready')

        try:
            self.assertEqual(result(NAME, {'volume': volume, 'image': 'valkey/valkey:8.1', 'acl': acl}), ('written', {}))
            docker('run', '--detach', '--name', name, '--publish', '127.0.0.1::6379',
                   '--mount', f'type=volume,source={volume},target=/data', 'valkey/valkey:8.1',
                   'valkey-server', '--appendonly', 'yes', '--dir', '/data', '--aclfile', '/data/users.acl')
            port = int(docker('port', name, '6379').rsplit(':', 1)[1])
            wait_ready()
            self.assertEqual(command('SET', 'orbit-test-job', 'queued'), b'+OK\r\n')
            docker('restart', name)
            port = int(docker('port', name, '6379').rsplit(':', 1)[1])
            wait_ready()
            self.assertEqual(command('GET', 'orbit-test-job'), b'queued')
            with socket.create_connection(('127.0.0.1', port), timeout=3) as anonymous:
                anonymous.sendall(b'*1\r\n$4\r\nPING\r\n')
                self.assertTrue(anonymous.recv(1024).startswith(b'-NOAUTH'))
        finally:
            subprocess.run(['docker', 'rm', '-f', name], capture_output=True, check=False)
            subprocess.run(['docker', 'volume', 'rm', volume], capture_output=True, check=False)


if __name__ == '__main__':
    unittest.main()
