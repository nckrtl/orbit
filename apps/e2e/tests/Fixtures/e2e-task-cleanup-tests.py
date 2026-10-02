"""Exercise bin/e2e-task-cleanup against disposable Git repositories."""
import hashlib
import os
import re
import shutil
import stat
import subprocess
import sys
import tempfile
import unittest
from pathlib import Path

REPOSITORY = Path(sys.argv.pop(1)).resolve()
HELPER = REPOSITORY / 'bin/e2e-task-cleanup'
ORIGIN = 'ssh://git@example.test/acme/orbit.git'
OTHER_ORIGIN = 'ssh://git@example.test/acme/other.git'


def origin_key(url):
    match = re.fullmatch(
        r'(?:[a-z][a-z0-9+.-]*://)?(?:[^@/]+@)?([^:/]+)[:/](.+?)(?:\.git)?/*',
        url,
        re.IGNORECASE,
    )
    material = f'{match.group(1).lower()}/{match.group(2)}' if match else url
    return hashlib.sha256(material.encode()).hexdigest()


def git(cwd, *args, check=True):
    result = subprocess.run(['git', '-C', str(cwd), *args], capture_output=True, text=True)
    if check and result.returncode:
        raise AssertionError(f'git {args} exited {result.returncode}: {result.stderr}')
    return result


class CleanupWorld:
    def __init__(self, root, task='task-727', origin=ORIGIN):
        self.root = Path(root)
        self.helper = HELPER
        self.task = task
        self.origin = origin
        self.state = self.root / 'state'
        self.home = self.root / 'home'
        self.primary = self.root / 'primary'
        self.worktrees = self.root / 'worktrees'
        self.checkouts = self.root / 'checkouts'
        self.checkout = self.checkouts / task
        self.bridge = self.worktrees / f'{task}-e2e'
        self.other_task = 'task-728'
        self.other_bridge = self.worktrees / f'{self.other_task}-e2e'
        self.caller_worktree = self.root / 'caller-worktree'
        self.fake_bin = self.root / 'fake-bin'
        self.invoked = self.home / 'invoked'
        self.home.mkdir()
        self.state.mkdir()
        self.worktrees.mkdir()
        self.checkouts.mkdir()
        self._git_env = {
            'GIT_CONFIG_GLOBAL': os.devnull,
            'GIT_CONFIG_NOSYSTEM': '1',
            'GIT_CONFIG_SYSTEM': os.devnull,
            'GIT_AUTHOR_NAME': 'Orbit',
            'GIT_AUTHOR_EMAIL': 'orbit@example.test',
            'GIT_COMMITTER_NAME': 'Orbit',
            'GIT_COMMITTER_EMAIL': 'orbit@example.test',
        }
        self._init_repo(self.primary, 'main', origin)
        (self.primary / '.e2e' / 'topology-snapshot').mkdir(parents=True)
        (self.primary / '.e2e' / 'topology-snapshot' / 'promoted.json').write_text('{}\n')
        (self.primary / '.e2e' / 'topologies').mkdir()
        (self.primary / '.e2e' / 'topologies' / 'lease.json').write_text('{"lease":"keep"}\n')
        git(self.primary, 'config', 'orbit.worktreeRoot', str(self.worktrees))
        self._init_repo(self.checkout, task, origin)
        (self.checkout / 'C').write_text('clone\n')
        git(self.checkout, 'add', 'C')
        git(self.checkout, 'commit', '-q', '-m', 'clone')
        self.head = git(self.checkout, 'rev-parse', 'HEAD').stdout.strip()
        git(self.checkout, 'branch', 'side')
        git(self.checkout, 'worktree', 'add', '-q', str(self.caller_worktree), 'side')

    def _init_repo(self, path, branch, origin):
        path.mkdir(parents=True, exist_ok=True)
        git(self.root, 'init', '-q', '-b', branch, str(path))
        git(path, 'config', 'user.email', 'orbit@example.test')
        git(path, 'config', 'user.name', 'Orbit')
        marker = path / 'README'
        marker.write_text(f'{path.name}\n')
        git(path, 'add', 'README')
        git(path, 'commit', '-q', '-m', 'init')
        git(path, 'remote', 'add', 'origin', origin)

    def register(self, primary=None, origin=None):
        target = primary or self.primary
        key = origin_key(origin or self.origin)
        link = self.state / 'orbit' / 'e2e-primary-checkouts' / key
        link.parent.mkdir(parents=True, exist_ok=True)
        if link.exists() or link.is_symlink():
            link.unlink()
        link.symlink_to(target)
        return link

    def add_bridge(self, path, branch, staging=None):
        git(self.primary, 'branch', branch)
        git(self.primary, 'worktree', 'add', '-q', str(path), branch)
        (path / 'dirt.txt').write_text('dirt\n')
        (path / '.e2e').mkdir()
        (path / '.e2e' / 'attempt.json').write_text('{}\n')
        if staging:
            git(self.primary, 'update-ref', staging, 'HEAD')

    def add_matching_bridge(self):
        self.add_bridge(self.bridge, f'{self.task}-e2e', f'refs/orbit/e2e-bridge/{self.task}')
        self.add_bridge(self.other_bridge, f'{self.other_task}-e2e', f'refs/orbit/e2e-bridge/{self.other_task}')
        git(self.primary, 'branch', f'{self.task}0-e2e')
        git(self.primary, 'update-ref', f'refs/orbit/e2e-bridge/{self.task}0', 'HEAD')

    def install_traps(self):
        self.fake_bin.mkdir()
        for name in ('incus', 'e2e-topology'):
            program = self.fake_bin / name
            program.write_text('#!/bin/sh\necho "$0" >> "$HOME/invoked"\nexit 99\n')
            program.chmod(program.stat().st_mode | stat.S_IEXEC)

    def cleanup(self, cwd=None):
        env = os.environ.copy()
        env.update(self._git_env)
        env['HOME'] = str(self.home)
        env['XDG_STATE_HOME'] = str(self.state)
        for name in (
            'GIT_DIR', 'GIT_WORK_TREE', 'GIT_INDEX_FILE', 'GIT_OBJECT_DIRECTORY',
            'GIT_COMMON_DIR', 'GIT_NAMESPACE', 'GIT_PREFIX',
        ):
            env.pop(name, None)
        if self.fake_bin.is_dir():
            env['PATH'] = str(self.fake_bin) + os.pathsep + env.get('PATH', '')
        return subprocess.run(
            [str(self.helper)],
            cwd=cwd or self.checkout,
            env=env,
            capture_output=True,
            text=True,
        )

    def has_ref(self, ref):
        return git(self.primary, 'show-ref', '--verify', '--quiet', ref, check=False).returncode == 0

    def lists(self, path):
        listing = git(self.primary, 'worktree', 'list', '--porcelain').stdout
        return f'worktree {path}\n' in listing


class TaskCleanupTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='orbit-task-cleanup-')
        self.addCleanup(self._cleanup_temporary)
        self.world = CleanupWorld(self.temporary.name)
        self.world.register()
        self.world.install_traps()

    def _cleanup_temporary(self):
        root = Path(self.temporary.name)
        subprocess.run(
            ['sudo', '-n', 'chown', '-R', f'{os.getuid()}:{os.getgid()}', str(root)],
            check=False,
            capture_output=True,
        )
        self.temporary.cleanup()

    def assert_unchanged_caller_and_topology(self):
        world = self.world
        self.assertTrue(world.checkout.is_dir())
        self.assertEqual(git(world.checkout, 'rev-parse', 'HEAD').stdout.strip(), world.head)
        self.assertEqual(git(world.checkout, 'symbolic-ref', '--short', 'HEAD').stdout.strip(), world.task)
        self.assertTrue(world.caller_worktree.is_dir())
        self.assertTrue((world.caller_worktree / 'C').is_file())
        self.assertEqual((world.primary / '.e2e' / 'topology-snapshot' / 'promoted.json').read_text(), '{}\n')
        self.assertEqual((world.primary / '.e2e' / 'topologies' / 'lease.json').read_text(), '{"lease":"keep"}\n')
        self.assertFalse(world.invoked.exists(), world.invoked.read_text() if world.invoked.exists() else '')

    def assert_other_task_untouched(self):
        world = self.world
        self.assertTrue(world.other_bridge.is_dir())
        self.assertTrue(world.lists(world.other_bridge))
        self.assertTrue(world.has_ref(f'refs/heads/{world.other_task}-e2e'))
        self.assertTrue(world.has_ref(f'refs/orbit/e2e-bridge/{world.other_task}'))
        self.assertTrue(world.has_ref(f'refs/heads/{world.task}0-e2e'))
        self.assertTrue(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}0'))

    def test_helper_is_executable_and_has_no_gateway_dependency(self):
        self.assertTrue(HELPER.is_file())
        self.assertTrue(os.access(HELPER, os.X_OK))
        text = HELPER.read_text()
        self.assertNotIn('artisan', text)
        self.assertNotIn('apps/gateway', text)
        self.assertNotIn('vendor/', text)

    def test_matching_bridge_removes_only_that_task_and_keeps_the_caller(self):
        world = self.world
        world.add_matching_bridge()
        (world.bridge / 'README').write_text('changed\n')

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(world.bridge.exists())
        self.assertFalse(world.lists(world.bridge))
        self.assertFalse(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertFalse(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_other_task_untouched()
        self.assert_unchanged_caller_and_topology()

    def test_shared_registration_accepts_the_checkout_owner_not_the_caller(self):
        world = self.world
        world.add_matching_bridge()
        shared = world.root / 'shared-registry'
        shared.mkdir()
        link = world.register()
        (shared / link.name).symlink_to(world.primary)
        link.unlink()
        # Substitute only the root-owned registry path and caller UID. The helper
        # still checks the actual primary and checkout owners and real Git state.
        world.helper = world.root / 'cleanup'
        world.helper.write_text(HELPER.read_text().replace(
            '/var/lib/orbit/e2e-primary-checkouts', str(shared)))
        world.helper.chmod(0o755)
        fake_id = world.fake_bin / 'id'
        fake_id.write_text(f'#!/bin/sh\necho {os.getuid() + 1}\n')
        fake_id.chmod(0o755)

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(world.bridge.exists())
        self.assertFalse(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertFalse(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_other_task_untouched()
        self.assert_unchanged_caller_and_topology()

    def test_home_registration_is_used_when_xdg_registration_is_invalid(self):
        world = self.world
        world.add_matching_bridge()
        link = world.register()
        home_link = world.home / '.local/state/orbit/e2e-primary-checkouts' / link.name
        home_link.parent.mkdir(parents=True)
        home_link.symlink_to(world.primary)
        link.unlink()
        link.symlink_to(world.root / 'missing')

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(world.bridge.exists())
        self.assert_other_task_untouched()
        self.assert_unchanged_caller_and_topology()

    def test_retry_after_matching_cleanup_is_idempotent(self):
        world = self.world
        world.add_matching_bridge()
        first = world.cleanup()
        self.assertEqual(first.returncode, 0, first.stderr)

        second = world.cleanup()

        self.assertEqual(second.returncode, 0, second.stderr)
        self.assertFalse(world.bridge.exists())
        self.assertFalse(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertFalse(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_other_task_untouched()
        self.assert_unchanged_caller_and_topology()

    def test_absent_bridge_and_registration_are_success(self):
        world = self.world
        link = world.state / 'orbit' / 'e2e-primary-checkouts' / origin_key(world.origin)
        link.unlink()
        world.add_matching_bridge()

        missing = world.cleanup()
        self.assertEqual(missing.returncode, 0, missing.stderr)
        self.assertTrue(world.bridge.is_dir())
        again = world.cleanup()
        self.assertEqual(again.returncode, 0, again.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertTrue(world.has_ref(f'refs/heads/{world.task}-e2e'))

        link.symlink_to(world.primary)
        link.unlink()
        dangling = world.state / 'orbit' / 'e2e-primary-checkouts' / 'dangling'
        dangling.parent.mkdir(parents=True, exist_ok=True)
        # The checkout's key is absent; a dangling link for another name must not matter.
        dangling.symlink_to(world.root / 'missing-primary')
        regular = world.state / 'orbit' / 'e2e-primary-checkouts' / origin_key(world.origin)
        regular.write_text(str(world.primary))
        planted = world.cleanup()
        self.assertEqual(planted.returncode, 0, planted.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertTrue(regular.is_file())

    def test_registered_primary_without_a_bridge_is_idempotent(self):
        world = self.world
        first = world.cleanup()
        second = world.cleanup()
        self.assertEqual(first.returncode, 0, first.stderr)
        self.assertEqual(second.returncode, 0, second.stderr)
        self.assert_unchanged_caller_and_topology()

    def test_ordinary_checkout_changes_nothing(self):
        world = self.world
        world.add_matching_bridge()
        ordinary = world.root / 'feature'
        world._init_repo(ordinary, 'main', world.origin)
        nested = ordinary / 'task-727'
        nested.mkdir()

        from_root = world.cleanup(ordinary)
        from_nested = world.cleanup(nested)

        self.assertEqual(from_root.returncode, 0, from_root.stderr)
        self.assertEqual(from_nested.returncode, 0, from_nested.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertTrue(world.lists(world.bridge))
        self.assertTrue(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertTrue(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assertTrue(ordinary.is_dir())

    def test_wrong_origin_leaves_the_bridge(self):
        world = self.world
        world.add_matching_bridge()
        git(world.checkout, 'remote', 'set-url', 'origin', OTHER_ORIGIN)

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertTrue(world.lists(world.bridge))
        self.assertTrue(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertTrue(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))

    def test_primary_without_an_origin_is_explicitly_rejected(self):
        world = self.world
        world.add_matching_bridge()
        git(world.primary, 'remote', 'remove', 'origin')

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertTrue(world.lists(world.bridge))
        self.assertTrue(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertTrue(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_other_task_untouched()
        self.assert_unchanged_caller_and_topology()

    def test_primary_registered_under_the_wrong_origin_leaves_the_bridge(self):
        world = self.world
        world.add_matching_bridge()
        git(world.primary, 'remote', 'set-url', 'origin', OTHER_ORIGIN)

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertTrue(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_unchanged_caller_and_topology()

    def test_wrong_owner_leaves_the_bridge(self):
        probe = subprocess.run(['sudo', '-n', 'true'], capture_output=True)
        if probe.returncode != 0:
            self.skipTest('sudo is not available to change the primary owner')
        world = self.world
        world.add_matching_bridge()
        chown = subprocess.run(['sudo', '-n', 'chown', 'nobody', str(world.primary)], capture_output=True, text=True)
        self.assertEqual(chown.returncode, 0, chown.stderr)

        result = world.cleanup()

        subprocess.run(['sudo', '-n', 'chown', str(os.getuid()), str(world.primary)], check=True)
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertTrue(world.lists(world.bridge))
        self.assertTrue(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertTrue(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_unchanged_caller_and_topology()

    def test_missing_snapshot_or_linked_primary_leaves_the_bridge(self):
        world = self.world
        world.add_matching_bridge()
        promoted = world.primary / '.e2e' / 'topology-snapshot' / 'promoted.json'
        promoted.unlink()
        missing = world.cleanup()
        self.assertEqual(missing.returncode, 0, missing.stderr)
        self.assertTrue(world.bridge.is_dir())
        promoted.write_text('{}\n')

        linked = world.root / 'linked'
        git(world.primary, 'worktree', 'add', '-q', '-b', 'linked-primary', str(linked))
        (linked / '.e2e' / 'topology-snapshot').mkdir(parents=True)
        (linked / '.e2e' / 'topology-snapshot' / 'promoted.json').write_text('{}\n')
        git(linked, 'config', 'orbit.worktreeRoot', str(world.worktrees))
        world.register(primary=linked)
        result = world.cleanup()
        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertTrue(world.lists(world.bridge))
        self.assertTrue(world.has_ref(f'refs/heads/{world.task}-e2e'))

    def test_wrong_branch_at_the_bridge_path_stays(self):
        world = self.world
        git(world.primary, 'branch', 'orb-user')
        git(world.primary, 'worktree', 'add', '-q', str(world.bridge), 'orb-user')
        (world.bridge / 'keep.txt').write_text('keep\n')
        git(world.primary, 'branch', f'{world.task}-e2e')
        git(world.primary, 'update-ref', f'refs/orbit/e2e-bridge/{world.task}', 'HEAD')

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertEqual((world.bridge / 'keep.txt').read_text(), 'keep\n')
        self.assertTrue(world.lists(world.bridge))
        self.assertEqual(git(world.bridge, 'symbolic-ref', '--short', 'HEAD').stdout.strip(), 'orb-user')
        self.assertFalse(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertFalse(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_unchanged_caller_and_topology()

    def test_wrong_path_and_branch_in_another_worktree_stay(self):
        world = self.world
        held = world.worktrees / 'held-elsewhere'
        world.add_bridge(held, f'{world.task}-e2e', f'refs/orbit/e2e-bridge/{world.task}')
        world.bridge.mkdir()
        (world.bridge / 'keep.txt').write_text('not a worktree\n')

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertEqual((world.bridge / 'keep.txt').read_text(), 'not a worktree\n')
        self.assertFalse(world.lists(world.bridge))
        self.assertTrue(held.is_dir())
        self.assertTrue(world.lists(held))
        self.assertEqual(git(held, 'symbolic-ref', '--short', 'HEAD').stdout.strip(), f'{world.task}-e2e')
        self.assertTrue(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertFalse(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_unchanged_caller_and_topology()

    def test_checkout_branch_is_not_required(self):
        world = self.world
        world.add_matching_bridge()
        git(world.checkout, 'checkout', '-q', '-b', 'wip')

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(world.bridge.exists())
        self.assertFalse(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertEqual(git(world.checkout, 'symbolic-ref', '--short', 'HEAD').stdout.strip(), 'wip')
        self.assertTrue(world.checkout.is_dir())
        self.assertTrue(world.caller_worktree.is_dir())

    def test_primary_probe_failure_retains_shared_registration_and_task_resources(self):
        world = self.world
        world.add_matching_bridge()
        shared = world.root / 'shared-registry'
        shared.mkdir()
        link = world.register()
        (shared / link.name).symlink_to(world.primary)
        link.unlink()
        world.helper = world.root / 'cleanup'
        world.helper.write_text(HELPER.read_text().replace(
            '/var/lib/orbit/e2e-primary-checkouts', str(shared)))
        world.helper.chmod(0o755)
        real_git = shutil.which('git')
        shim = world.fake_bin / 'git'
        mutations = world.root / 'unexpected-mutation'

        for probe in (
            ['rev-parse', '--path-format=absolute', '--git-dir'],
            ['rev-parse', '--path-format=absolute', '--git-common-dir'],
            ['remote'],
            ['remote', 'get-url', 'origin'],
            ['config', '--path', '--get', 'orbit.worktreeRoot'],
        ):
            with self.subTest(probe=probe):
                shim.write_text(
                    f'#!{sys.executable}\n'
                    'import os, sys\n'
                    'from pathlib import Path\n'
                    'args = sys.argv[1:]\n'
                    f'if args == ["-C", {str(world.primary)!r}, *{probe!r}]:\n'
                    '    print("primary metadata probe failed", file=sys.stderr)\n'
                    '    sys.exit(73)\n'
                    'command = args[2:] if args[:1] == ["-C"] else args\n'
                    'read_only = (command[:1] in (["rev-parse"], ["show-ref"])\n'
                    '    or command == ["remote"]\n'
                    '    or command[:2] in (["remote", "get-url"], ["worktree", "list"])\n'
                    '    or command[:1] == ["config"] and "--get" in command)\n'
                    'if not read_only:\n'
                    f'    Path({str(mutations)!r}).write_text(repr(args))\n'
                    '    sys.exit(99)\n'
                    f'os.execv({real_git!r}, [{real_git!r}, *args])\n'
                )
                shim.chmod(0o755)

                result = world.cleanup()

                self.assertEqual(result.returncode, 73, result.stderr)
                self.assertIn('primary metadata probe failed', result.stderr)
                self.assertFalse(mutations.exists())
                self.assertTrue(world.bridge.is_dir())
                self.assertEqual((world.bridge / 'dirt.txt').read_text(), 'dirt\n')
                self.assertTrue(world.lists(world.bridge))
                self.assertTrue(world.has_ref(f'refs/heads/{world.task}-e2e'))
                self.assertTrue(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
                self.assertTrue((shared / link.name).is_symlink())
                self.assert_other_task_untouched()
                self.assert_unchanged_caller_and_topology()

        shim.unlink()
        retried = world.cleanup()
        self.assertEqual(retried.returncode, 0, retried.stderr)
        self.assertFalse(world.bridge.exists())
        self.assertFalse(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertFalse(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_other_task_untouched()
        self.assert_unchanged_caller_and_topology()

    def test_missing_worktree_root_uses_the_default_without_hiding_probe_failures(self):
        world = self.world
        world.add_matching_bridge()
        git(world.primary, 'config', '--unset', 'orbit.worktreeRoot')
        # Keep the default root inside this disposable fixture.
        world.helper = world.root / 'cleanup'
        world.helper.write_text(HELPER.read_text().replace(
            'root=/fast/worktrees/orbit', f'root={world.worktrees}'))
        world.helper.chmod(0o755)

        result = world.cleanup()

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertFalse(world.bridge.exists())
        self.assertFalse(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertFalse(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_other_task_untouched()
        self.assert_unchanged_caller_and_topology()

    def test_cleanup_command_failure_exits_nonzero_and_retry_succeeds(self):
        world = self.world
        world.add_matching_bridge()
        git(world.primary, 'worktree', 'lock', str(world.bridge))

        failed = world.cleanup()

        self.assertNotEqual(failed.returncode, 0, failed.stderr)
        self.assertTrue(world.bridge.is_dir())
        self.assertTrue((world.bridge / 'dirt.txt').is_file())
        self.assertTrue(world.lists(world.bridge))
        self.assertTrue(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertTrue(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_other_task_untouched()
        self.assert_unchanged_caller_and_topology()

        git(world.primary, 'worktree', 'unlock', str(world.bridge))
        retried = world.cleanup()
        self.assertEqual(retried.returncode, 0, retried.stderr)
        self.assertFalse(world.bridge.exists())
        self.assertFalse(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertFalse(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_other_task_untouched()
        self.assert_unchanged_caller_and_topology()

    def test_stale_branch_ref_and_missing_directory_are_pruned(self):
        world = self.world
        world.add_matching_bridge()
        shutil.rmtree(world.bridge)
        stale = world.cleanup()
        self.assertEqual(stale.returncode, 0, stale.stderr)
        self.assertFalse(world.lists(world.bridge))
        self.assertFalse(world.has_ref(f'refs/heads/{world.task}-e2e'))
        self.assertFalse(world.has_ref(f'refs/orbit/e2e-bridge/{world.task}'))
        self.assert_other_task_untouched()

        again = world.cleanup()
        self.assertEqual(again.returncode, 0, again.stderr)

    def test_helper_does_not_remove_a_caller_checked_out_at_the_bridge_path(self):
        world = self.world
        caller = world.checkouts / 'task-729'
        git(world.primary, 'worktree', 'add', '-q', '-b', 'task-729-e2e', str(caller))
        git(world.primary, 'config', 'orbit.worktreeRoot', str(world.checkouts))
        bridge_link = world.checkouts / 'task-729-e2e'
        bridge_link.symlink_to(caller)
        (caller / 'keep.txt').write_text('caller\n')
        git(world.primary, 'update-ref', 'refs/orbit/e2e-bridge/task-729', 'HEAD')
        # Registration follows the primary that owns this linked checkout.
        result = world.cleanup(caller)

        self.assertEqual(result.returncode, 0, result.stderr)
        self.assertTrue(caller.is_dir())
        self.assertEqual((caller / 'keep.txt').read_text(), 'caller\n')
        self.assertTrue(bridge_link.is_symlink())
        self.assertEqual(bridge_link.resolve(), caller.resolve())
        self.assertTrue(world.lists(caller))
        self.assertEqual(git(caller, 'symbolic-ref', '--short', 'HEAD').stdout.strip(), 'task-729-e2e')
        self.assertTrue(world.has_ref('refs/heads/task-729-e2e'))


if __name__ == '__main__':
    unittest.main(verbosity=1)
