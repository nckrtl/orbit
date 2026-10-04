"""Exercise bin/e2e-clone-bridge and the bin/e2e-topology bridge with real repositories."""
import importlib.machinery
import importlib.util
import json
import os
import shutil
import signal
import stat
import subprocess
import sys
import tempfile
import time
import unittest
from pathlib import Path
from unittest.mock import patch

REPOSITORY = Path(sys.argv.pop(1)).resolve()
loader = importlib.machinery.SourceFileLoader('e2e_clone_bridge', str(REPOSITORY / 'bin/e2e-clone-bridge'))
spec = importlib.util.spec_from_loader(loader.name, loader)
bridge = importlib.util.module_from_spec(spec)
loader.exec_module(bridge)


def run(cwd, *command, env=None, check=True):
    result = subprocess.run(command, cwd=cwd, capture_output=True, text=True, env=env)
    if check and result.returncode:
        raise AssertionError(f'{command} exited {result.returncode}: {result.stdout}{result.stderr}')
    return result


def git(cwd, *args):
    return run(cwd, 'git', *args).stdout.strip()


class CloneBridgeTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='orbit clone bridge ')
        self.addCleanup(self.temporary.cleanup)
        base = Path(self.temporary.name).resolve()
        self.state = base / 'state'
        self.environment = patch_environment({'XDG_STATE_HOME': str(self.state), 'ORBIT_E2E_BRIDGE': None})
        self.addCleanup(self.environment)

        seed = base / 'seed'
        seed.mkdir()
        git(seed, 'init', '-q', '-b', 'main')
        git(seed, 'config', 'user.name', 'Orbit')
        git(seed, 'config', 'user.email', 'orbit@example.test')
        (seed / '.gitignore').write_text('/.e2e/\n/vendor/\n/apps/*/vendor/\n.env\n')
        (seed / 'bin').mkdir()
        for name in ('e2e-topology', 'e2e-clone-bridge'):
            shutil.copy2(REPOSITORY / 'bin' / name, seed / 'bin' / name)
        (seed / 'kept.php').write_text('kept')
        (seed / 'edited.php').write_text('before')
        (seed / 'removed.php').write_text('removed')
        git(seed, 'add', '.')
        git(seed, 'commit', '-q', '-m', 'initial')
        self.origin = base / 'origin.git'
        git(base, 'clone', '-q', '--bare', str(seed), str(self.origin))

        self.primary = base / 'primary'
        git(base, 'clone', '-q', str(self.origin), str(self.primary))
        self.worktrees = base / 'worktrees'
        git(self.primary, 'config', 'orbit.worktreeRoot', str(self.worktrees))
        promoted = self.primary / '.e2e/topology-snapshot/promoted.json'
        promoted.parent.mkdir(parents=True)
        promoted.write_text('{}')

        self.clone = base / 'task-7'
        git(base, 'clone', '-q', str(self.origin), str(self.clone))
        git(self.clone, 'checkout', '-q', '-b', 'task-7')
        git(self.clone, 'config', 'user.name', 'Orbit')
        git(self.clone, 'config', 'user.email', 'orbit@example.test')
        self.bridge = self.worktrees / 'task-7-e2e'

    def key(self):
        return bridge.origin_key(self.clone)

    def test_finds_a_shared_registration_owned_by_the_checkout_owner(self):
        shared = Path(self.temporary.name) / 'shared-registry'
        shared.mkdir()
        (shared / self.key()).symlink_to(self.primary)
        # Real repositories have the managed user's ownership; only the worker UID
        # and root-owned registry location are substituted, without requiring sudo.
        with patch.object(bridge, 'SHARED_REGISTRY', shared, create=True), \
                patch.object(bridge.os, 'geteuid', return_value=os.getuid() + 1):
            self.assertEqual(bridge.bridge_primary(self.clone), self.primary)

    def test_rejects_a_shared_primary_owned_by_neither_allowed_user(self):
        shared = Path(self.temporary.name) / 'shared-registry'
        shared.mkdir()
        (shared / self.key()).symlink_to(self.primary)
        with patch.object(bridge, 'SHARED_REGISTRY', shared, create=True), \
                patch.object(bridge.os, 'geteuid', return_value=os.getuid() + 1):
            self.assertIsNone(bridge.registered_primary(self.key()))

    def test_reads_home_registration_after_an_invalid_xdg_registration(self):
        home = Path(self.temporary.name) / 'home'
        link = home / '.local/state/orbit/e2e-primary-checkouts' / self.key()
        link.parent.mkdir(parents=True)
        link.symlink_to(self.primary)
        invalid = bridge.registry_path(self.key())
        invalid.parent.mkdir(parents=True)
        invalid.symlink_to(Path(self.temporary.name) / 'missing')
        with patch.object(bridge.Path, 'home', return_value=home):
            self.assertEqual(bridge.bridge_primary(self.clone), self.primary)
        self.assertTrue(bridge.register(self.primary))
        with patch.object(bridge.Path, 'home', return_value=home):
            self.assertEqual(bridge.bridge_primary(self.clone), self.primary)

    def test_registers_only_a_checkout_that_holds_a_promoted_generation(self):
        with self.assertRaisesRegex(bridge.BridgeFailure, 'no promoted topology snapshot'):
            bridge.register(self.clone)
        self.assertTrue(bridge.register(self.primary))
        self.assertEqual(bridge.registered_primary(self.key()), self.primary)
        self.assertFalse(bridge.register(self.primary))

    def test_a_linked_worktree_registers_its_primary(self):
        linked = Path(self.temporary.name) / 'linked'
        git(self.primary, 'worktree', 'add', '-q', '-b', 'task-9', str(linked))
        self.assertTrue(bridge.register(linked))
        self.assertEqual(bridge.registered_primary(self.key()), self.primary)

    def test_first_live_primary_keeps_the_registration_until_forced_or_gone(self):
        other = Path(self.temporary.name) / 'other-primary'
        git(Path(self.temporary.name), 'clone', '-q', str(self.origin), str(other))
        (other / '.e2e/topology-snapshot').mkdir(parents=True)
        (other / '.e2e/topology-snapshot/promoted.json').write_text('{}')
        self.assertTrue(bridge.register(self.primary))
        self.assertFalse(bridge.register(other))
        self.assertEqual(bridge.registered_primary(self.key()), self.primary)
        self.assertTrue(bridge.register(other, force=True))
        self.assertEqual(bridge.registered_primary(self.key()), other.resolve())
        # A primary that no longer holds a promoted generation is no longer live.
        (other / '.e2e/topology-snapshot/promoted.json').unlink()
        self.assertIsNone(bridge.registered_primary(self.key()))
        self.assertTrue(bridge.register(self.primary))

    def test_runs_in_place_without_a_live_registration_or_when_disabled(self):
        self.assertIsNone(bridge.bridge_primary(self.clone))
        bridge.register(self.primary)
        self.assertEqual(bridge.bridge_primary(self.clone), self.primary)
        self.assertIsNone(bridge.bridge_primary(self.primary))
        os.environ['ORBIT_E2E_BRIDGE'] = '0'
        self.assertIsNone(bridge.bridge_primary(self.clone))

    def test_a_linked_worktree_or_another_origin_never_bridges(self):
        bridge.register(self.primary)
        linked = Path(self.temporary.name) / 'linked'
        git(self.primary, 'worktree', 'add', '-q', '-b', 'task-8', str(linked))
        self.assertIsNone(bridge.bridge_primary(linked))
        git(self.clone, 'remote', 'set-url', 'origin', 'git@github.com:someone/else.git')
        self.assertIsNone(bridge.bridge_primary(self.clone))

    def test_mirrors_vendor_without_preserving_owner_or_group(self):
        if not shutil.which('rsync'):
            self.skipTest('rsync is required for the cross-owner mirror regression')
        source = self.clone / 'vendor'
        destination = self.bridge / 'vendor'
        source.mkdir()
        destination.mkdir(parents=True)
        executable = source / 'runner'
        executable.write_text('new dependency')
        executable.chmod(0o755)
        os.utime(executable, (1700000000, 1700000000))
        (source / 'link').symlink_to('runner')
        (source / '.hidden').write_text('hidden dependency')
        (source / 'new-runner').write_text('new executable')
        (source / 'new-runner').chmod(0o755)
        (destination / 'stale').write_text('removed dependency')
        (destination / 'runner').write_text('old dependency')
        (destination / 'runner').chmod(0o775)
        destination.chmod(0o775)
        original_run = subprocess.run

        def cross_owner_run(command, **options):
            # Model a worker that may write the destination but cannot chown/chgrp
            # it. Keep the actual rsync transfer real after disabling those requests.
            if command[0] == 'rsync' and not all(
                    flag in command and command.index(flag) > command.index('-a')
                    for flag in ('--no-owner', '--no-group')):
                return subprocess.CompletedProcess(command, 23, '', 'chgrp: Operation not permitted')
            if command[0] == 'rsync' and '--no-perms' not in command:
                return subprocess.CompletedProcess(command, 23, '', 'failed to set permissions: Operation not permitted')
            if command[0] == 'rsync' and '--omit-dir-times' not in command:
                return subprocess.CompletedProcess(command, 23, '', 'failed to set times on vendor/.: Operation not permitted')
            return original_run(command, **options)

        with patch.object(bridge.subprocess, 'run', side_effect=cross_owner_run):
            bridge.mirror_vendor(self.clone, self.bridge, Path('vendor'))

        self.assertEqual((destination / 'runner').read_text(), 'new dependency')
        self.assertEqual((destination / 'runner').stat().st_mode & 0o777, 0o775)
        self.assertEqual(destination.stat().st_mode & 0o777, 0o775)
        self.assertTrue(os.access(destination / 'new-runner', os.X_OK))
        self.assertEqual((destination / 'runner').stat().st_mtime, 1700000000)
        self.assertEqual(os.readlink(destination / 'link'), 'runner')
        self.assertEqual((destination / '.hidden').read_text(), 'hidden dependency')
        self.assertFalse((destination / 'stale').exists())

    def test_mirrors_the_clone_head_and_uncommitted_work_into_a_linked_bridge(self):
        bridge.register(self.primary)
        (self.clone / 'committed.php').write_text('committed')
        git(self.clone, 'add', 'committed.php')
        git(self.clone, 'commit', '-q', '-m', 'task work')
        (self.clone / 'edited.php').write_text('after')
        (self.clone / 'removed.php').unlink()
        (self.clone / 'scripts').mkdir()
        (self.clone / 'scripts/new.ts').write_text('new')
        (self.clone / 'vendor/autoload').mkdir(parents=True)
        (self.clone / 'vendor/autoload/autoload.php').write_text('autoload')
        (self.clone / 'apps/gateway/vendor').mkdir(parents=True)
        (self.clone / 'apps/gateway/vendor/autoload.php').write_text('gateway autoload')
        (self.clone / '.env').write_text('clone env')

        result = bridge.prepare(self.clone, self.primary)

        self.assertEqual(result, self.bridge)
        self.assertTrue(bridge.is_linked_worktree(self.bridge))
        self.assertEqual(git(self.bridge, 'rev-parse', 'HEAD'), git(self.clone, 'rev-parse', 'HEAD'))
        self.assertEqual(git(self.bridge, 'branch', '--show-current'), 'task-7-e2e')
        self.assertEqual((self.bridge / 'committed.php').read_text(), 'committed')
        self.assertEqual((self.bridge / 'edited.php').read_text(), 'after')
        self.assertFalse((self.bridge / 'removed.php').exists())
        self.assertEqual((self.bridge / 'scripts/new.ts').read_text(), 'new')
        self.assertEqual((self.bridge / 'vendor/autoload/autoload.php').read_text(), 'autoload')
        self.assertEqual((self.bridge / 'apps/gateway/vendor/autoload.php').read_text(), 'gateway autoload')
        # Other ignored files belong to the bridge and its guests, not to the clone.
        self.assertFalse((self.bridge / '.env').exists())

    def test_mirrors_staged_work_and_a_staged_deletion(self):
        bridge.register(self.primary)
        (self.clone / 'edited.php').write_text('staged')
        (self.clone / 'staged-new.php').write_text('staged new')
        git(self.clone, 'add', 'edited.php', 'staged-new.php')
        git(self.clone, 'rm', '-q', 'removed.php')

        bridge.prepare(self.clone, self.primary)

        self.assertEqual((self.bridge / 'edited.php').read_text(), 'staged')
        self.assertEqual((self.bridge / 'staged-new.php').read_text(), 'staged new')
        self.assertFalse((self.bridge / 'removed.php').exists())

    def test_a_later_prepare_follows_the_clone_and_keeps_bridge_owned_ignored_files(self):
        bridge.register(self.primary)
        (self.clone / 'scripts').mkdir()
        (self.clone / 'scripts/new.ts').write_text('new')
        bridge.prepare(self.clone, self.primary)
        (self.bridge / '.env').write_text('harness env')
        (self.bridge / '.e2e').mkdir()
        (self.bridge / '.e2e/attempt.json').write_text('{}')
        (self.bridge / 'kept.php').write_text('edited in the bridge')

        (self.clone / 'scripts/new.ts').unlink()
        (self.clone / 'second.php').write_text('second')
        git(self.clone, 'add', 'second.php')
        git(self.clone, 'commit', '-q', '-m', 'second')
        bridge.prepare(self.clone, self.primary)

        self.assertEqual(git(self.bridge, 'rev-parse', 'HEAD'), git(self.clone, 'rev-parse', 'HEAD'))
        self.assertFalse((self.bridge / 'scripts/new.ts').exists())
        self.assertEqual((self.bridge / 'kept.php').read_text(), 'kept')
        self.assertEqual((self.bridge / '.env').read_text(), 'harness env')
        self.assertEqual((self.bridge / '.e2e/attempt.json').read_text(), '{}')

    def test_refuses_a_detached_clone_or_a_foreign_directory_at_the_bridge_path(self):
        bridge.register(self.primary)
        self.bridge.mkdir(parents=True)
        with self.assertRaisesRegex(bridge.BridgeFailure, 'not a worktree'):
            bridge.prepare(self.clone, self.primary)
        git(self.clone, 'checkout', '-q', '--detach')
        with self.assertRaisesRegex(bridge.BridgeFailure, 'not on a branch'):
            bridge.prepare(self.clone, self.primary)

    def test_rewrites_clone_paths_to_the_bridge(self):
        arguments, has_worktree = bridge.rewrite(
            ['TASK-7', '.', f'--worktree={self.clone}', '--json', 'kept.php'], self.clone, self.bridge, self.clone,
        )
        self.assertEqual(arguments, ['TASK-7', str(self.bridge), f'--worktree={self.bridge}', '--json', 'kept.php'])
        self.assertTrue(has_worktree)

    def test_signalling_the_public_web_pid_reaches_the_bridged_owner_and_waits_for_cleanup(self):
        bridge.register(self.primary)
        fake = Path(self.temporary.name) / 'signal-bin'
        fake.mkdir()
        (fake / 'php').write_text('''#!/usr/bin/env python3
import json
import os
import signal
import sys
from pathlib import Path
state = Path(os.environ['WEB_TEST_STATE'])
unit = state / 'unit'
publication = state / 'publication'
unit.touch()
publication.touch()
def stop(number, frame):
    unit.unlink()
    publication.unlink()
    (state / 'cleaned').write_text(str(number))
    sys.exit(0)
signal.signal(signal.SIGTERM, stop)
signal.signal(signal.SIGHUP, stop)
(state / 'ready.tmp').write_text(json.dumps({'pid': os.getpid(), 'argv': sys.argv[1:]}))
(state / 'ready.tmp').rename(state / 'ready')
while True:
    signal.pause()
''')
        (fake / 'php').chmod(stat.S_IRWXU)
        for number in (signal.SIGTERM, signal.SIGHUP):
            with self.subTest(signal=number):
                state = Path(self.temporary.name) / f'signal-{number}'
                state.mkdir()
                environment = {**os.environ, 'PATH': f'{fake}:{os.environ["PATH"]}',
                               'WEB_TEST_STATE': str(state)}
                # Exercise the public wrapper, real clone registry and real bridge routing.
                # Only the final PHP/service boundary is substituted.
                process = subprocess.Popen(['bin/e2e-topology', 'web', 'TASK-7'], cwd=self.clone,
                                           env=environment, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                                           text=True, start_new_session=True)
                try:
                    deadline = time.monotonic() + 10
                    while not (state / 'ready').exists() and process.poll() is None and time.monotonic() < deadline:
                        time.sleep(0.01)
                    self.assertTrue((state / 'ready').exists(), 'bridged foreground owner did not become ready')
                    ready = json.loads((state / 'ready').read_text())
                    self.assertEqual(ready['argv'], [str(self.bridge / 'apps/e2e/artisan'),
                                                    'topology:web', 'TASK-7', f'--worktree={self.bridge}'])
                    # Signal only the command PID, not its process group or the PHP PID.
                    os.kill(process.pid, number)
                    self.assertEqual(process.wait(timeout=5), 0, 'public command exited without waiting for owner cleanup')
                    output, errors = process.communicate(timeout=5)
                    self.assertEqual(process.returncode, 0, output + errors)
                    self.assertEqual(ready['pid'], process.pid, 'the public command left a waiting parent wrapper')
                    self.assertEqual((state / 'cleaned').read_text(), str(number))
                    self.assertFalse((state / 'unit').exists())
                    self.assertFalse((state / 'publication').exists())
                finally:
                    # Kill only this fixture's newly created process group, including orphaned
                    # bridge descendants when testing the broken wrapper.
                    if process.poll() is None or not (state / 'cleaned').exists():
                        try:
                            os.killpg(process.pid, signal.SIGKILL)
                        except ProcessLookupError:
                            pass
                    process.communicate(timeout=5)

    def test_the_wrapper_runs_the_command_through_the_bridge(self):
        fake = Path(self.temporary.name) / 'fake-bin'
        fake.mkdir()
        (fake / 'php').write_text('#!/bin/sh\nprintf "php %s\\n" "$*"\n')
        (fake / 'php').chmod(stat.S_IRWXU)
        environment = {**os.environ, 'PATH': f'{fake}:{os.environ["PATH"]}'}

        in_place = run(self.clone, 'bin/e2e-topology', 'status', 'TASK-7', env=environment)
        self.assertIn(f'php {self.clone}/apps/e2e/artisan topology:status TASK-7', in_place.stdout)

        linked = Path(self.temporary.name) / 'linked'
        git(self.primary, 'worktree', 'add', '-q', '-b', 'task-1', str(linked))
        run(linked, 'bin/e2e-topology', 'status', 'TASK-1', env=environment)
        self.assertEqual(bridge.registered_primary(self.key()), self.primary)

        acquired = run(self.clone, 'bin/e2e-topology', 'acquire', 'TASK-7', '.', env=environment)
        self.assertIn(f'php {self.bridge}/apps/e2e/artisan topology:acquire TASK-7 {self.bridge}', acquired.stdout)
        self.assertIn('running through bridge worktree', acquired.stderr)

        status = run(self.clone, 'bin/e2e-topology', 'status', 'TASK-7', '--json', env=environment)
        self.assertIn(f'topology:status TASK-7 --json --worktree={self.bridge}', status.stdout)

        disabled = run(self.clone, 'bin/e2e-topology', 'status', 'TASK-7',
                       env={**environment, 'ORBIT_E2E_BRIDGE': '0'})
        self.assertIn(f'php {self.clone}/apps/e2e/artisan', disabled.stdout)


def patch_environment(values):
    previous = {name: os.environ.get(name) for name in values}
    for name, value in values.items():
        if value is None:
            os.environ.pop(name, None)
        else:
            os.environ[name] = value

    def restore():
        for name, value in previous.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value
    return restore


if __name__ == '__main__':
    unittest.main(verbosity=1)
