"""Recipe floors and diagnostics. All APT, dpkg, service and network operations are faked."""
import contextlib
import gzip
import importlib.util
import io
import json
import os
from pathlib import Path
import signal
import subprocess
import sys
import tempfile
import unittest
from unittest.mock import patch

RESOURCE = Path(__file__).resolve().parents[2] / 'resources/machine-software'
spec = importlib.util.spec_from_file_location('recipe_versions', RESOURCE.parent / 'remote/lib/recipe.py')
recipe = importlib.util.module_from_spec(spec)
exec(compile((RESOURCE.parent / 'remote/lib/artifacts.py').read_text(), 'lib/artifacts.py', 'exec'), recipe.__dict__)
spec.loader.exec_module(recipe)
image_spec = importlib.util.spec_from_file_location('recipe_image_versions', RESOURCE.parent / 'remote/lib/recipe.py')
image = importlib.util.module_from_spec(image_spec)
exec(compile((RESOURCE.parent / 'remote/lib/artifacts.py').read_text(), 'lib/artifacts.py', 'exec'), image.__dict__)
image_spec.loader.exec_module(image)
exec(compile((RESOURCE / 'build-image.py').read_text(), 'build-image.py', 'exec'), image.__dict__)

PIN = '8.18.0-1ubuntu2.7'
NEWER = '8.18.0-1ubuntu2.8'
INDEX = 'https://security.ubuntu.com/ubuntu resolute-security/main amd64 Packages'
SUPERSEDED_BY = '8.18.0-1ubuntu2.9'
LEGACY_SOURCE = b'''Types: deb
URIs: https://snapshot.ubuntu.com/ubuntu/20261013T000000Z/
Suites: resolute resolute-updates resolute-security
Components: main universe restricted multiverse
Signed-By: /usr/share/keyrings/ubuntu-archive-keyring.gpg
Check-Valid-Until: no
'''


class RecipeVersionsTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.apt = Path(directory.name)
        (self.apt / 'sources.list.d').mkdir()
        for module in (recipe, image):
            source_patch = patch.object(module, 'APT_ETC', self.apt)
            source_patch.start()
            self.addCleanup(source_patch.stop)
            log_patch = patch.object(module, 'DPKG_LOG', self.apt / 'dpkg.log')
            log_patch.start()
            self.addCleanup(log_patch.stop)
        self.tools = {'curl': {'source': 'ubuntu', 'package': 'curl', 'version': PIN}}
        self.release = 'v=26.04,o=Ubuntu,a=resolute-security,n=resolute,l=Ubuntu,c=main,b=amd64'
        self.version_policy = f'''curl:
  Installed: {NEWER}
  Candidate: {NEWER}
  Version table:
 *** {NEWER} 500
        500 {INDEX}
        100 /var/lib/dpkg/status
     {PIN} 500
        500 https://snapshot.ubuntu.com/ubuntu/20261014T000000Z resolute/main amd64 Packages
'''
        self.commands = []
        self.snapshot_version_policy = ''

    def command(self, argv, **kwargs):
        self.commands.append(argv)
        isolated = any(arg.startswith('Dir::State::lists=') for arg in argv)
        if argv[0] == 'apt-cache' and '-o' in argv:
            argv = argv[:argv.index('-c')] if '-c' in argv else argv[:argv.index('-o')]
        output = ''
        code = 0
        if argv[:2] == ['dpkg', '--compare-versions']:
            self.assertFalse(kwargs['required'])
            order = ['8.18.0-1ubuntu2.6', PIN, NEWER, SUPERSEDED_BY]
            left, right = order.index(argv[2]), order.index(argv[4])
            code = 0 if (left > right if argv[3] == 'gt' else left < right) else 1
        elif argv == ['dpkg', '--print-architecture']:
            output = 'amd64\n'
        elif argv == ['apt-cache', 'policy']:
            output = f'Package files:\n 500 {INDEX}\n     release {self.release}\n     origin security.ubuntu.com\n'
        elif argv == ['apt-cache', 'policy', 'curl:amd64']:
            output = self.snapshot_version_policy if isolated else self.version_policy
        return subprocess.CompletedProcess(argv, code, output, '')

    def assess(self, installed):
        with patch.object(recipe, 'run', side_effect=self.command):
            return recipe.assess(self.tools, {'curl': installed})['curl']

    def test_newer_same_release_security_build_satisfies_floor_with_both_versions(self):
        self.assertEqual(self.assess(NEWER), {'source': 'ubuntu', 'version': PIN, 'installed': NEWER,
                                           'changed': False, 'status': 'newer_than_recipe'})

    def test_release_and_updates_pockets_are_also_accepted(self):
        for pocket in ('resolute', 'resolute-updates', 'resolute-security'):
            with self.subTest(pocket=pocket):
                self.release = f'o=Ubuntu,a={pocket},n=resolute'
                self.assertEqual(self.assess(NEWER)['status'], 'newer_than_recipe')

    def test_exact_older_and_missing_versions(self):
        for installed, status, changed in [(PIN, 'current', False), ('8.18.0-1ubuntu2.6', 'older_than_recipe', True),
                                            (None, 'missing', True)]:
            with self.subTest(installed=installed):
                result = self.assess(installed)
                self.assertEqual((result['status'], result['changed']), (status, changed))
        self.assertFalse(any(c[0] == 'apt-cache' for c in self.commands))

    def test_other_release_third_party_and_unknown_origins_are_refused_without_downgrade(self):
        for release in ('o=Ubuntu,a=other-security,n=other', 'o=ThirdParty,a=resolute-security,n=resolute',
                        'o=Ubuntu,a=resolute-proposed,n=resolute', ''):
            with self.subTest(release=release):
                self.release = release
                tool = self.assess(NEWER)
                self.assertEqual((tool['status'], tool['changed']), ('unsupported_newer', False))
                with self.assertRaisesRegex(recipe.RecipeFailure, 'origin:curl'):
                    recipe.supported({'curl': tool})

    def test_another_versions_trusted_index_does_not_prove_the_installed_origin(self):
        self.version_policy = self.version_policy.replace(f'500 {INDEX}', '100 /var/lib/dpkg/status')
        self.version_policy += f'     {PIN} 500\n        500 {INDEX}\n'
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_a_dpkg_status_only_version_is_not_accepted(self):
        self.version_policy = f'curl:\n *** {NEWER} 100\n        100 /var/lib/dpkg/status\n'
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_a_snapshot_only_newer_version_can_be_confirmed_without_losing_normal_origins(self):
        def command(argv, **kwargs):
            if argv[:2] == ['apt-cache', 'policy'] and '-o' not in argv:
                return subprocess.CompletedProcess(argv, 0, '', '')
            return self.command([arg for arg in argv if arg not in ('-o', 'snapshot-options')], **kwargs)
        with patch.object(recipe, 'run', side_effect=command):
            report = recipe.assess(self.tools, {'curl': NEWER}, ['-o', 'snapshot-options'])
        self.assertEqual(report['curl']['status'], 'newer_than_recipe')

    def test_epoch_versions_are_passed_intact_to_dpkg(self):
        tools = {'openssh-client': {'source': 'ubuntu', 'package': 'openssh-client', 'version': '1:10.2p1-2ubuntu3.7'}}
        with patch.object(recipe, 'run', return_value=subprocess.CompletedProcess([], 0, '', '')) as run, \
             patch.object(recipe, 'ubuntu_origin', return_value=True):
            result = recipe.assess(tools, {'openssh-client': '1:10.2p1-2ubuntu3.8'})
        self.assertEqual(run.call_args.args[0], ['dpkg', '--compare-versions', '1:10.2p1-2ubuntu3.8', 'gt', '1:10.2p1-2ubuntu3.7'])
        self.assertFalse(result['openssh-client']['changed'])

    def test_sury_php_artifacts_stay_exact_even_when_the_installed_build_is_newer(self):
        tools = {'php8.5-cli': {'source': 'artifact', 'version': '8.5.11-1'}}
        with patch.object(recipe, 'run') as run:
            result = recipe.assess(tools, {'php8.5-cli': '8.5.12-1'})
        self.assertTrue(result['php8.5-cli']['changed'])
        self.assertEqual(result['php8.5-cli']['status'], 'different')
        run.assert_not_called()

    def test_dpkg_inventory_requires_the_native_or_all_architecture_and_installed_status(self):
        output = (f'curl\tamd64\t{NEWER}\tinstall ok installed\n'
                  'curl\tarm64\t99\tinstall ok installed\n'
                  'ca-certificates\tall\t2026\tinstall ok installed\n'
                  'git\tamd64\t3\tdeinstall ok config-files\n')
        def command(argv, **kwargs):
            return subprocess.CompletedProcess(argv, 0, 'amd64' if argv[0] == 'dpkg' else output, '')
        with patch.object(recipe, 'run', side_effect=command):
            self.assertEqual(recipe.packages(), {'curl': NEWER, 'ca-certificates': '2026'})

    def test_node_apply_keeps_newer_packages_reports_drift_and_never_installs_the_old_pin(self):
        with tempfile.TemporaryDirectory() as directory:
            policy = Path(directory) / 'policy'
            def publish(path, content, *args):
                Path(path).write_bytes(content)
            with patch.object(recipe, 'POLICY', policy), \
                 patch.object(recipe, 'packages', return_value={'curl': NEWER}), \
                 patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
                 patch.object(recipe, 'active_services', return_value=set()), \
                 patch.object(recipe, 'publish', side_effect=publish), \
                 patch.object(recipe.pwd, 'getpwnam', return_value=None), \
                 patch.object(recipe, 'run', side_effect=self.command):
                result = recipe.apply({'tools': self.tools, 'architecture': 'amd64', 'snapshot': '20261014T000000Z'})
        self.assertEqual(result['changed_tools'], [])
        self.assertEqual(result['changed_packages'], [])
        self.assertEqual(result['newer_than_recipe'], {'curl': {'installed': NEWER, 'version': PIN}})
        self.assertFalse(any(c[:2] == ['apt-get', 'install'] for c in self.commands))

    def test_node_refuses_foreign_newer_package_before_sources_or_installs_change(self):
        self.release = 'o=Ubuntu,a=other-security,n=other'
        with patch.object(recipe, 'packages', return_value={'curl': NEWER}), \
             patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
             patch.object(recipe, 'publish') as publish, \
             patch.object(recipe.pwd, 'getpwnam', return_value=None), \
             patch.object(recipe, 'run', side_effect=self.command):
            with self.assertRaisesRegex(recipe.RecipeFailure, 'origin:curl'):
                recipe.apply({'tools': self.tools, 'architecture': 'amd64', 'snapshot': '20261014T000000Z'})
        publish.assert_not_called()
        self.assertEqual([c[:2] for c in self.commands if c[0] == 'apt-get'], [['apt-get', 'update']])

    def status_only_policy(self):
        self.version_policy = f'''curl:
  Installed: {NEWER}
  Candidate: {SUPERSEDED_BY}
  Version table:
     {SUPERSEDED_BY} 500 (phased 20%)
        500 {INDEX}
 *** {NEWER} 100
        100 /var/lib/dpkg/status
'''

    def install_log(self, filename='dpkg.log', timestamp='2026-10-14 12:30:00', package='curl:amd64', version=NEWER):
        content = f'{timestamp} status installed {package} {version}\n'
        path = self.apt / filename
        if filename.endswith('.gz'):
            with gzip.open(path, 'wt') as stream:
                stream.write(content)
        else:
            path.write_text(content)

    def test_newer_trusted_candidate_does_not_prove_a_status_only_installed_build(self):
        self.status_only_policy()
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')
        self.assertFalse(any(c[0] == 'apt-get' for c in self.commands))

    def test_superseded_security_build_requires_exact_signed_snapshot_proof_at_install_time(self):
        self.status_only_policy()
        self.install_log()
        self.snapshot_version_policy = f'curl:\n *** {NEWER} 500\n        500 {INDEX}\n'
        with patch.dict(os.environ, TZ='UTC'):
            tool = self.assess(NEWER)
        self.assertEqual((tool['status'], tool['changed']), ('newer_than_recipe', False))
        updates = [c for c in self.commands if c[:2] == ['apt-get', 'update']]
        self.assertEqual(len(updates), 1)
        self.assertTrue(any(arg.startswith('Dir::State::lists=') for arg in updates[0]))
        self.assertIn('Dir::Etc::sourceparts=-', updates[0])

    def test_install_date_uses_action_before_later_trigger_status_across_rotated_logs(self):
        self.install_log('dpkg.log.2.gz', '2026-10-12 12:30:00')
        self.install_log('dpkg.log.1', '2026-10-13 12:30:00', package='curl:all')
        self.install_log('dpkg.log', '2026-10-14 12:30:00', package='curl:arm64')
        with gzip.open(self.apt / 'dpkg.log.2.gz', 'at') as stream:
            stream.write(f'2026-10-12 12:29:00 upgrade curl:amd64 {PIN} {NEWER}\n')
        with (self.apt / 'dpkg.log').open('a') as stream:
            stream.write(f'2026-10-15 12:30:00 upgrade curl:amd64 {NEWER} {SUPERSEDED_BY}\n')
            stream.write(f'2026-10-16 12:30:00 status installed curl:amd64 {SUPERSEDED_BY}\n')
        with patch.object(recipe, 'run', side_effect=self.command), patch.dict(os.environ, TZ='UTC'):
            self.assertEqual(recipe.install_snapshot('curl', NEWER), '20261012T122900Z')

    def test_install_action_is_used_and_status_fallback_uses_earliest_native_or_all_record(self):
        self.install_log('dpkg.log.2.gz', '2026-10-12 12:30:00', package='curl:all')
        self.install_log('dpkg.log.1', '2026-10-13 12:30:00')
        self.install_log('dpkg.log', '2026-10-11 12:30:00', package='curl:arm64')
        with patch.object(recipe, 'run', side_effect=self.command), patch.dict(os.environ, TZ='UTC'):
            self.assertEqual(recipe.install_snapshot('curl', NEWER), '20261012T123000Z')
            with (self.apt / 'dpkg.log.1').open('a') as stream:
                stream.write(f'2026-10-12 12:29:00 install curl:all <none> {NEWER}\n')
            self.assertEqual(recipe.install_snapshot('curl', NEWER), '20261012T122900Z')

    def test_status_only_build_is_refused_without_proof_even_when_the_snapshot_has_a_newer_candidate(self):
        self.status_only_policy()
        self.install_log()
        untrusted_version = f'curl:\n *** {NEWER} 500\n        500 https://vendor.example/ubuntu resolute/main amd64 Packages\n'
        for policy in ('', self.version_policy, untrusted_version):
            with self.subTest(policy=policy):
                self.snapshot_version_policy = policy
                tool = self.assess(NEWER)
                self.assertEqual(tool['status'], 'unsupported_newer')
                self.assertEqual(tool['refusal'], 'origin:curl:snapshot-unproved')
                with self.assertRaisesRegex(recipe.RecipeFailure, '^origin:curl:snapshot-unproved$'):
                    recipe.supported({'curl': tool})

    def test_missing_unreadable_or_malformed_install_history_refuses_before_snapshot_access(self):
        self.status_only_policy()
        for content in ('', f'bad-date 12:00:00 status installed curl:amd64 {NEWER}\n'):
            with self.subTest(content=content):
                (self.apt / 'dpkg.log').write_text(content)
                self.assertEqual(self.assess(NEWER)['refusal'], 'origin:curl:dpkg-log-missing')
        (self.apt / 'dpkg.log').unlink()
        (self.apt / 'dpkg.log').mkdir()
        self.assertEqual(self.assess(NEWER)['refusal'], 'origin:curl:dpkg-log-missing')
        self.assertFalse(any(c[0] == 'apt-get' for c in self.commands))

    def test_snapshot_refresh_failure_refuses_instead_of_accepting_the_newer_candidate(self):
        self.status_only_policy()
        self.install_log()
        def command(argv, **kwargs):
            if argv[:2] == ['apt-get', 'update']:
                raise recipe.RecipeFailure(result=subprocess.CompletedProcess(argv, 100, '', 'signature or network failure'))
            return self.command(argv, **kwargs)
        with patch.object(recipe, 'run', side_effect=command):
            self.assertEqual(recipe.assess(self.tools, {'curl': NEWER})['curl']['refusal'], 'origin:curl:snapshot-unavailable')

    def test_snapshot_indexes_are_shared_by_packages_and_post_install_assessment_and_removed_after_apply(self):
        self.status_only_policy()
        self.install_log()
        self.snapshot_version_policy = f'curl:\n *** {NEWER} 500\n        500 {INDEX}\n'
        tools = {**self.tools, 'second-curl': dict(self.tools['curl'])}
        observed = dict.fromkeys(tools, NEWER)
        indexes = []
        def command(argv, **kwargs):
            if argv[:2] == ['apt-get', 'update'] and '-c' in argv:
                indexes.append(Path(next(arg.split('=', 1)[1] for arg in argv if arg.startswith('Dir::State::lists='))))
                self.assertEqual(kwargs['timeout'], 60)
            if argv[:3] == ['apt-cache', 'policy', 'curl:amd64'] and '-c' in argv:
                for path in indexes:
                    self.assertTrue(path.is_dir())
            return self.command(argv, **kwargs)
        with patch.object(recipe, 'POLICY', self.apt / 'policy'), \
             patch.object(recipe, 'packages', return_value={'curl': NEWER}), \
             patch.object(recipe, 'inspect', return_value=observed), \
             patch.object(recipe, 'active_services', return_value=set()), \
             patch.object(recipe, 'publish', side_effect=lambda path, content, *args: Path(path).write_bytes(content)), \
             patch.object(recipe.pwd, 'getpwnam', return_value=None), \
             patch.object(recipe, 'run', side_effect=command):
            result = recipe.apply({'tools': tools, 'architecture': 'amd64', 'snapshot': '20261014T000000Z'})
        self.assertEqual(set(result['newer_than_recipe']), set(tools))
        self.assertEqual(len(indexes), 1)
        self.assertFalse(indexes[0].exists())
        self.assertEqual(sum(c[:3] == ['apt-cache', 'policy', 'curl:amd64'] and '-c' in c for c in self.commands), 4)

    def test_failed_snapshot_lookups_are_cached_and_the_cap_refuses_without_more_network_calls(self):
        self.status_only_policy()
        self.install_log()
        updates = []
        def command(argv, **kwargs):
            if argv[:2] == ['apt-get', 'update']:
                updates.append(argv)
                raise recipe.RecipeFailure()
            return self.command(argv, **kwargs)
        with patch.object(recipe, 'run', side_effect=command), recipe.SnapshotLookups() as snapshots:
            for day in (14, 15, 16, 17, 14):
                self.install_log(timestamp=f'2026-10-{day} 12:30:00')
                for _ in range(2):
                    tool = recipe.assess(self.tools, {'curl': NEWER}, snapshots=snapshots)['curl']
                    reason = 'snapshot-lookup-limit' if day == 17 else 'snapshot-unavailable'
                    self.assertEqual(tool['refusal'], 'origin:curl:' + reason)
            self.assertEqual(len(updates), 3)
        with patch.object(recipe, 'run', side_effect=command):
            recipe.assess(self.tools, {'curl': NEWER})
        self.assertEqual(len(updates), 4)

    def test_successful_lookups_also_count_toward_cap_and_cached_results_stay_usable(self):
        self.status_only_policy()
        self.snapshot_version_policy = f'curl:\n *** {NEWER} 500\n        500 {INDEX}\n'
        with patch.object(recipe, 'run', side_effect=self.command), recipe.SnapshotLookups() as snapshots:
            for day in (14, 15, 16, 17, 14):
                self.install_log(timestamp=f'2026-10-{day} 12:30:00')
                tool = recipe.assess(self.tools, {'curl': NEWER}, snapshots=snapshots)['curl']
                if day == 17:
                    self.assertEqual(tool['refusal'], 'origin:curl:snapshot-lookup-limit')
                else:
                    self.assertEqual(tool['status'], 'newer_than_recipe')
        self.assertEqual(sum(c[:2] == ['apt-get', 'update'] for c in self.commands), 3)

    def test_arm64_build_is_proved_using_the_public_snapshot_archive_arm64_index(self):
        self.status_only_policy()
        self.install_log(package='curl:arm64')
        self.snapshot_version_policy = f'curl:\n *** {NEWER} 500\n        500 {INDEX}\n'
        def command(argv, **kwargs):
            if argv == ['dpkg', '--print-architecture']:
                return subprocess.CompletedProcess(argv, 0, 'arm64\n', '')
            if argv[:2] == ['apt-get', 'update']:
                source = Path(next(arg.split('=', 1)[1] for arg in argv if arg.startswith('Dir::Etc::sourcelist=')))
                self.assertIn('https://snapshot.ubuntu.com/ubuntu/', source.read_text())
            native_argv = [arg.replace('curl:arm64', 'curl:amd64') for arg in argv]
            result = self.command(native_argv, **kwargs)
            result.stdout = result.stdout.replace('amd64', 'arm64')
            return result
        with patch.object(recipe, 'run', side_effect=command):
            self.assertEqual(recipe.assess(self.tools, {'curl': NEWER})['curl']['status'], 'newer_than_recipe')

    def test_sigterm_during_snapshot_update_reaches_entrypoint_as_interrupted(self):
        self.status_only_policy()
        self.install_log()
        def command(argv, **kwargs):
            if argv[:2] == ['apt-get', 'update']:
                os.kill(os.getpid(), signal.SIGTERM)
            return self.command(argv, **kwargs)
        previous_handler = signal.getsignal(signal.SIGTERM)
        self.addCleanup(signal.signal, signal.SIGTERM, previous_handler)
        previous_int_handler = signal.getsignal(signal.SIGINT)
        self.addCleanup(signal.signal, signal.SIGINT, previous_int_handler)
        with patch.object(recipe, 'run', side_effect=command), \
             patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
             patch.object(recipe, 'platform_check'), \
             patch.object(sys, 'argv', ['program', 'inspect', json.dumps({'tools': self.tools, 'architecture': 'amd64'})]), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(recipe.main(), 1)
        self.assertEqual(output.getvalue(), 'recipe-stage:interrupted\n')

    def test_command_cleanup_preserves_interruption_during_snapshot_subprocess(self):
        with patch.object(recipe.subprocess, 'Popen') as popen, patch.object(recipe.os, 'killpg') as kill:
            process = popen.return_value.__enter__.return_value
            process.communicate.side_effect = [recipe.RecipeInterrupted('interrupted'), ('', '')]
            with self.assertRaisesRegex(recipe.RecipeInterrupted, '^interrupted$'):
                recipe.run(['apt-get', 'update'], timeout=60)
        kill.assert_called_once_with(process.pid, signal.SIGTERM)

    def test_any_untrusted_installed_listing_refuses_even_with_a_trusted_listing_or_newer_candidate(self):
        for trusted_listing in (True, False):
            for foreign_first in (True, False):
                with self.subTest(trusted_listing=trusted_listing, foreign_first=foreign_first):
                    self.status_only_policy()
                    listings = ['        500 https://ppa.example/ubuntu resolute/main amd64 Packages\n']
                    if trusted_listing:
                        listings.append(f'        500 {INDEX}\n')
                    if foreign_first:
                        listings.reverse()
                    self.version_policy += ''.join(listings)
                    with patch.object(recipe, 'snapshot_origin') as snapshot:
                        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')
                    snapshot.assert_not_called()

    def test_normal_and_cached_snapshot_policies_cannot_override_each_others_untrusted_listing(self):
        trusted = f'curl:\n *** {NEWER} 500\n        500 {INDEX}\n'
        untrusted = f'curl:\n *** {NEWER} 500\n        500 https://ppa.example/ubuntu resolute/main amd64 Packages\n'
        for normal, snapshot in ((trusted, untrusted), (untrusted, trusted)):
            with self.subTest(normal=normal, snapshot=snapshot):
                self.version_policy = normal
                def command(argv, snapshot=snapshot, **kwargs):
                    if argv[:3] == ['apt-cache', 'policy', 'curl:amd64'] and '-o' in argv:
                        return subprocess.CompletedProcess(argv, 0, snapshot, '')
                    return self.command(argv, **kwargs)
                with patch.object(recipe, 'run', side_effect=command):
                    self.assertEqual(recipe.assess(self.tools, {'curl': NEWER}, ['-o', 'snapshot-options'])['curl']['status'], 'unsupported_newer')

    def test_installed_version_in_an_untrusted_file_index_is_refused(self):
        self.version_policy += f' *** {NEWER} 100\n        100 file:/vendor ./ Packages\n'
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            if argv == ['apt-cache', 'policy']:
                result.stdout += ' 100 file:/vendor ./ Packages\n     release c=\n'
            return result
        with patch.object(recipe, 'run', side_effect=command):
            self.assertEqual(recipe.assess(self.tools, {'curl': NEWER})['curl']['status'], 'unsupported_newer')

    def test_historical_snapshot_source_is_the_logged_time_and_does_not_load_system_apt_hooks(self):
        self.status_only_policy()
        self.install_log()
        self.snapshot_version_policy = f'curl:\n *** {NEWER} 500\n        500 {INDEX}\n'
        def command(argv, **kwargs):
            if argv[:2] == ['apt-get', 'update']:
                source = Path(next(arg.split('=', 1)[1] for arg in argv if arg.startswith('Dir::Etc::sourcelist=')))
                self.assertIn('https://snapshot.ubuntu.com/ubuntu/20261014T123000Z/', source.read_text())
                self.assertIn('Signed-By: ' + recipe.UBUNTU_KEYRING, source.read_text())
                config = Path(kwargs['env']['APT_CONFIG'])
                self.assertEqual(config.read_text(), 'Dir::Etc::parts "-";\nDir::Etc::main "-";\n')
                self.assertEqual(config.parent, source.parent)
            return self.command(argv, **kwargs)
        with patch.object(recipe, 'run', side_effect=command), patch.dict(os.environ, TZ='UTC'):
            self.assertEqual(recipe.assess(self.tools, {'curl': NEWER})['curl']['status'], 'newer_than_recipe')

    def test_superseded_build_without_an_install_record_is_refused(self):
        self.version_policy = f'''curl:
  Installed: {NEWER}
  Candidate: {SUPERSEDED_BY}
  Version table:
     {SUPERSEDED_BY} 500 (phased 20%)
        500 {INDEX}
 *** {NEWER} 100
        100 /var/lib/dpkg/status
'''
        tool = self.assess(NEWER)
        self.assertEqual((tool['status'], tool['changed']), ('unsupported_newer', False))
        self.assertFalse(any(c[0] == 'apt-get' for c in self.commands))

    def test_phased_installed_version_and_later_version_blocks_are_parsed_independently(self):
        self.version_policy = self.version_policy.replace(f'*** {NEWER} 500', f'*** {NEWER} 500 (phased 30%)')
        self.assertEqual(self.assess(NEWER)['status'], 'newer_than_recipe')
        self.version_policy = self.version_policy.replace(f'500 {INDEX}', '100 /var/lib/dpkg/status')
        self.version_policy += f'     {PIN} 500 (phased 10%)\n        500 {INDEX}\n'
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_a_newer_trusted_version_that_is_not_the_candidate_does_not_satisfy_the_fallback(self):
        self.version_policy = f'''curl:
  Installed: {NEWER}
  Candidate: {NEWER}
  Version table:
     {SUPERSEDED_BY} -1
         -1 {INDEX}
 *** {NEWER} 100
        100 /var/lib/dpkg/status
'''
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_an_unparsed_version_line_does_not_reuse_the_preceding_version(self):
        self.version_policy = f'curl:\n *** {NEWER} 500\n     malformed version header\n        500 {INDEX}\n'
        self.assertEqual(self.assess(NEWER)['status'], 'unsupported_newer')

    def test_third_party_release_cannot_claim_to_be_ubuntu(self):
        for host in ('evil.example', 'security.ubuntu.com.evil.example', 'fakeubuntu.com'):
            with self.subTest(host=host):
                foreign_index = INDEX.replace('security.ubuntu.com', host)
                def command(argv, foreign_index=foreign_index, **kwargs):
                    result = self.command(argv, **kwargs)
                    result.stdout = result.stdout.replace(INDEX, foreign_index)
                    return result
                with patch.object(recipe, 'run', side_effect=command):
                    tool = recipe.assess(self.tools, {'curl': NEWER})['curl']
                self.assertEqual(tool['status'], 'unsupported_newer')
                self.assertEqual(tool['refusal'], 'origin:curl')

    def test_mirror_requires_matching_ubuntu_keyring_source_uri_suite_and_component(self):
        foreign_index = INDEX.replace('security.ubuntu.com', 'mirror.example')
        source = self.apt / 'sources.list.d/mirror.sources'
        def command(argv, **kwargs):
            result = self.command(argv, **kwargs)
            result.stdout = result.stdout.replace(INDEX, foreign_index)
            return result
        for uri, suite, keyring, expected in [
            ('https://mirror.example/ubuntu', 'resolute-security', recipe.UBUNTU_KEYRING, True),
            ('https://other.example/ubuntu', 'resolute-security', recipe.UBUNTU_KEYRING, False),
            ('https://mirror.example/ubuntu', 'resolute-updates', recipe.UBUNTU_KEYRING, False),
            ('https://mirror.example/ubuntu', 'resolute-security', '/etc/apt/keyrings/vendor.gpg', False),
        ]:
            with self.subTest(uri=uri, suite=suite, keyring=keyring):
                source.write_text(f'Types: deb\nURIs: {uri}\nSuites: {suite}\nComponents: main\nSigned-By: {keyring}\n')
                with patch.object(recipe, 'run', side_effect=command):
                    self.assertEqual(recipe.ubuntu_origin('curl', NEWER), expected)

    def test_ubuntu_archive_security_ports_snapshot_and_country_archive_hosts_are_trusted(self):
        for host in ('archive.ubuntu.com', 'security.ubuntu.com', 'ports.ubuntu.com', 'snapshot.ubuntu.com', 'nl.archive.ubuntu.com'):
            with self.subTest(host=host):
                self.assertTrue(recipe.ubuntu_index(INDEX.replace('security.ubuntu.com', host), set()))

    def test_legacy_list_sources_require_the_scoped_ubuntu_keyring_and_disabled_sources_do_not_count(self):
        source = self.apt / 'sources.list.d/mirror.list'
        trusted_index = ('https://mirror.example/ubuntu', 'resolute-security/main')
        for keyring, expected in ((recipe.UBUNTU_KEYRING, True), ('/etc/apt/keyrings/vendor.gpg', False)):
            source.write_text(f'deb [arch=amd64 signed-by={keyring}] https://mirror.example/ubuntu resolute-security main\n')
            self.assertEqual(trusted_index in recipe.ubuntu_signed_sources([]), expected)
        source.unlink()
        source = self.apt / 'sources.list.d/mirror.sources'
        source.write_text(f'Types: deb\nURIs: https://mirror.example/ubuntu\nSuites: resolute-security\nComponents: main\nSigned-By: {recipe.UBUNTU_KEYRING}\nEnabled: no\n')
        self.assertNotIn(trusted_index, recipe.ubuntu_signed_sources([]))

    def test_already_converged_node_repairs_only_the_exact_legacy_snapshot_before_refresh_and_assessment(self):
        source = self.apt / 'sources.list.d/ubuntu.sources'
        custom = self.apt / 'sources.list.d/custom.list'
        custom.write_text('deb https://vendor.example resolute main\n')
        for architecture in ('amd64', 'arm64'):
            with self.subTest(architecture=architecture):
                source.write_bytes(LEGACY_SOURCE)
                policy = self.apt / 'policy'
                refreshed = False
                def command(argv, architecture=architecture, **kwargs):
                    nonlocal refreshed
                    if argv[:2] == ['apt-get', 'update'] and ('Dir::Etc::sourcelist=' + str(source)) in argv:
                        self.assertIn('Dir::Etc::sourceparts=-', argv)
                        self.assertIn('APT::Get::List-Cleanup=false', argv)
                        self.assertEqual((self.apt / 'ubuntu.sources.orbit-snapshot-backup').read_bytes(), LEGACY_SOURCE)
                        self.assertNotIn('snapshot.ubuntu.com', source.read_text())
                        self.assertIn('Suites: resolute resolute-updates resolute-backports\n', source.read_text())
                        self.assertIn('Suites: resolute-security\n', source.read_text())
                        host = 'ports.ubuntu.com/ubuntu-ports' if architecture == 'arm64' else 'archive.ubuntu.com/ubuntu'
                        self.assertIn(host, source.read_text())
                        self.assertIn('ports.ubuntu.com/ubuntu-ports' if architecture == 'arm64' else 'security.ubuntu.com/ubuntu', source.read_text())
                        self.assertEqual(source.read_text().count('Signed-By: ' + recipe.UBUNTU_KEYRING), 2)
                        refreshed = True
                    if argv[0] == 'apt-cache':
                        self.assertTrue(refreshed)
                    return self.command(argv, **kwargs)
                with patch.object(recipe, 'POLICY', policy), \
                     patch.object(recipe, 'packages', return_value={'curl': NEWER}), \
                     patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
                     patch.object(recipe, 'active_services', return_value=set()), \
                     patch.object(recipe.os, 'chown'), \
                     patch.object(recipe.pwd, 'getpwnam', return_value=None), \
                     patch.object(recipe, 'run', side_effect=command):
                    # Use real atomic publication in this process-owned fixture directory.
                    original_publish = recipe.publish
                    with patch.object(recipe, 'publish', side_effect=lambda path, content, mode=0o644, original_publish=original_publish: original_publish(path, content, mode, uid=os.getuid(), gid=os.getgid())):
                        result = recipe.apply({'tools': self.tools, 'architecture': architecture, 'snapshot': '20261014T000000Z'})
                self.assertTrue(refreshed)
                repairs = [c for c in self.commands if c[0] == 'logger']
                self.assertTrue(any('Restored generic Ubuntu sources' in c[-1] and 'ubuntu.sources.orbit-snapshot-backup' in c[-1] for c in repairs))
                self.assertEqual(result['changed_tools'], [])
                self.assertEqual(result['newer_than_recipe']['curl']['installed'], NEWER)
                self.assertEqual(custom.read_text(), 'deb https://vendor.example resolute main\n')
                self.assertFalse(any(c[:2] == ['apt-get', 'install'] for c in self.commands))

    def test_failed_backup_stops_before_replacing_sources_refreshing_indexes_or_logging_success(self):
        source = self.apt / 'sources.list.d/ubuntu.sources'
        source.write_bytes(LEGACY_SOURCE)
        with patch.object(recipe, 'publish', side_effect=recipe.RecipeFailure('apt-source')) as publish, \
             patch.object(recipe, 'run', side_effect=self.command):
            with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source'):
                recipe.repair_ubuntu_sources('amd64')
        self.assertEqual(publish.call_count, 1)
        self.assertEqual(publish.call_args.args[0], self.apt / 'ubuntu.sources.orbit-snapshot-backup')
        self.assertEqual(source.read_bytes(), LEGACY_SOURCE)
        self.assertEqual(self.commands, [])

    def test_repair_refuses_to_overwrite_a_different_backup_or_follow_a_backup_symlink(self):
        source = self.apt / 'sources.list.d/ubuntu.sources'
        source.write_bytes(LEGACY_SOURCE)
        backup = self.apt / 'ubuntu.sources.orbit-snapshot-backup'
        backup.write_text('earlier backup')
        with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source'):
            recipe.repair_ubuntu_sources('amd64')
        self.assertEqual(backup.read_text(), 'earlier backup')
        backup.unlink()
        backup.symlink_to(source)
        with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source'):
            recipe.repair_ubuntu_sources('amd64')
        self.assertEqual(source.read_bytes(), LEGACY_SOURCE)

    def test_repair_can_retry_after_a_successful_backup_and_failed_source_publication(self):
        source = self.apt / 'sources.list.d/ubuntu.sources'
        source.write_bytes(LEGACY_SOURCE)
        backup = self.apt / 'ubuntu.sources.orbit-snapshot-backup'
        backup.write_bytes(LEGACY_SOURCE)
        with patch.object(recipe, 'publish', side_effect=lambda path, content: path.write_bytes(content)), \
             patch.object(recipe, 'run', side_effect=self.command):
            self.assertTrue(recipe.repair_ubuntu_sources('amd64'))
        self.assertEqual(backup.read_bytes(), LEGACY_SOURCE)
        self.assertNotIn('snapshot.ubuntu.com', source.read_text())

    def test_dry_run_plans_repair_and_matches_apply_origin_refusals_without_changing_system_sources(self):
        source = self.apt / 'sources.list.d/ubuntu.sources'
        custom = self.apt / 'sources.list.d/custom.list'
        custom.write_text('deb https://broken.example resolute main\n')
        source.write_bytes(LEGACY_SOURCE)
        self.status_only_policy()
        rolling_policy = f'curl:\n *** {NEWER} 500\n        500 {INDEX}\n'
        for trusted in (True, False):
            with self.subTest(trusted=trusted):
                self.commands = []
                refreshed = False
                state = {'applied': False}
                def command(argv, trusted=trusted, state=state, **kwargs):
                    nonlocal refreshed
                    if argv[:2] == ['apt-get', 'update']:
                        self.assertIn('Dir::Etc::sourceparts=-', argv)
                        selected_source = Path(next(arg.split('=', 1)[1] for arg in argv if arg.startswith('Dir::Etc::sourcelist=')))
                        if state['applied'] and selected_source != source:
                            return self.command(argv, **kwargs)
                        self.assertIn('http://archive.ubuntu.com/ubuntu', selected_source.read_text())
                        self.assertIn('Suites: resolute-security', selected_source.read_text())
                        if not state['applied']:
                            self.assertEqual(source.read_bytes(), LEGACY_SOURCE)
                            self.assertNotEqual(selected_source, source)
                        refreshed = True
                    result = self.command(argv, **kwargs)
                    if argv[:3] == ['apt-cache', 'policy', 'curl:amd64'] and (('-c' in argv) or state['applied']):
                        self.assertTrue(refreshed)
                        result.stdout = rolling_policy if trusted else rolling_policy.replace('security.ubuntu.com', 'ppa.example')
                    return result
                with patch.object(recipe, 'platform_check'), \
                     patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
                     patch.object(recipe, 'run', side_effect=command), \
                     patch.object(recipe, 'publish') as publish, \
                     patch.object(sys, 'argv', ['program', 'inspect', json.dumps({'architecture': 'amd64', 'tools': self.tools})]), \
                     contextlib.redirect_stdout(io.StringIO()) as output:
                    self.assertEqual(recipe.main(), 0)
                planned = json.loads(output.getvalue())
                self.assertEqual(planned['_actions'], ['repair_ubuntu_sources'])
                self.assertEqual(planned['curl']['status'], 'newer_than_recipe' if trusted else 'unsupported_newer')
                publish.assert_not_called()
                self.assertEqual(source.read_bytes(), LEGACY_SOURCE)
                self.assertFalse((self.apt / 'ubuntu.sources.orbit-snapshot-backup').exists())
                self.assertFalse(any(c[0] in ('logger', 'systemctl') or c[:2] == ['apt-get', 'install'] for c in self.commands))
                state['applied'] = True
                refreshed = False
                with patch.object(recipe, 'POLICY', self.apt / 'policy'), \
                     patch.object(recipe, 'packages', return_value={'curl': NEWER}), \
                     patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
                     patch.object(recipe, 'active_services', return_value=set()), \
                     patch.object(recipe, 'publish', side_effect=lambda path, content, *args: Path(path).write_bytes(content)), \
                     patch.object(recipe.pwd, 'getpwnam', return_value=None), \
                     patch.object(recipe, 'run', side_effect=command):
                    payload = {'architecture': 'amd64', 'snapshot': '20261014T000000Z', 'tools': self.tools}
                    if trusted:
                        self.assertEqual(recipe.apply(payload)['newer_than_recipe']['curl']['installed'], NEWER)
                    else:
                        with self.assertRaisesRegex(recipe.RecipeFailure, planned['curl']['refusal']):
                            recipe.apply(payload)
                self.assertEqual(custom.read_text(), 'deb https://broken.example resolute main\n')
                source.write_bytes(LEGACY_SOURCE)
                (self.apt / 'ubuntu.sources.orbit-snapshot-backup').unlink()

    def test_similar_but_not_exact_sources_and_symlinks_are_never_overwritten(self):
        source = self.apt / 'sources.list.d/ubuntu.sources'
        for content in (LEGACY_SOURCE + b'# user edit\n', LEGACY_SOURCE.replace(b'Check-Valid-Until: no\n', b''), b'Types: deb\nURIs: http://archive.ubuntu.com/ubuntu\n'):
            with self.subTest(content=content):
                source.write_bytes(content)
                with patch.object(recipe, 'publish') as publish:
                    self.assertFalse(recipe.repair_ubuntu_sources('amd64'))
                publish.assert_not_called()
                self.assertEqual(source.read_bytes(), content)
        source.unlink()
        target = self.apt / 'target'
        target.write_bytes(LEGACY_SOURCE)
        source.symlink_to(target)
        self.assertFalse(recipe.repair_ubuntu_sources('amd64'))
        self.assertEqual(target.read_bytes(), LEGACY_SOURCE)

    def test_dry_run_reports_a_planned_origin_refusal_without_any_mutation(self):
        self.release = 'o=ThirdParty,a=resolute-security,n=resolute'
        with patch.object(recipe, 'platform_check'), \
             patch.object(recipe, 'inspect', return_value={'curl': NEWER}), \
             patch.object(recipe, 'run', side_effect=self.command), \
             patch.object(recipe, 'publish') as publish, \
             patch.object(sys, 'argv', ['program', 'inspect', json.dumps({'architecture': 'amd64', 'tools': self.tools})]), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(recipe.main(), 0)
        tool = json.loads(output.getvalue())['curl']
        self.assertEqual((tool['status'], tool['refusal']), ('unsupported_newer', 'origin:curl'))
        self.assertFalse(tool['changed'])
        publish.assert_not_called()
        self.assertFalse(any(c[0] == 'apt-get' for c in self.commands))

    def test_exact_deb_downgrades_are_allowed_but_newer_ubuntu_pins_are_explicitly_preserved(self):
        tools = {**self.tools, 'caddy': {'source': 'artifact', 'version': '2.0'}}
        def command(argv, **kwargs):
            self.commands.append(argv)
            return subprocess.CompletedProcess(argv, 0, 'Inst caddy [3.0] (2.0 local-deb [amd64])\n', '')
        with patch.object(recipe, 'run', side_effect=command):
            recipe.install_exact_debs(['/work/caddy.deb'], tools, {'curl': NEWER, 'caddy': '3.0'}, [], {})
        self.assertEqual(len(self.commands), 2)
        for argv in self.commands:
            self.assertIn('--allow-downgrades', argv)
            self.assertIn('curl=' + NEWER, argv)
            self.assertNotIn('curl=' + PIN, argv)
        self.assertIn('--simulate', self.commands[0])
        self.assertNotIn('--simulate', self.commands[1])

    def test_exact_deb_install_refuses_any_dependency_downgrade_before_applying(self):
        for dependency in ('curl', 'libother'):
            with self.subTest(dependency=dependency):
                self.commands = []
                def command(argv, dependency=dependency, **kwargs):
                    if argv[0] == 'apt-get':
                        self.commands.append(argv)
                        return subprocess.CompletedProcess(argv, 0, f'Inst {dependency} [{NEWER}] ({PIN} Ubuntu:26.04 [amd64])\n', '')
                    return self.command(argv, **kwargs)
                with patch.object(recipe, 'run', side_effect=command):
                    with self.assertRaises(recipe.RecipeFailure):
                        recipe.install_exact_debs(['/work/caddy.deb'], self.tools, {dependency: NEWER}, [], {})
                installs = [c for c in self.commands if c[0] == 'apt-get']
                self.assertEqual(len(installs), 1)
                self.assertIn('--simulate', installs[0])

    def test_node_and_image_install_exact_artifact_downgrades_without_downgrading_ubuntu(self):
        tools = {**self.tools, 'caddy': {'source': 'artifact', 'version': '2.0',
                 'artifacts': {'amd64': {'url': 'https://example.test/caddy.deb'}}}}
        payload = {'tools': tools, 'architecture': 'amd64', 'snapshot': '20261014T000000Z', 'profile': 'app-prod'}
        for module in (recipe, image):
            with self.subTest(module=module.__name__), tempfile.TemporaryDirectory() as directory:
                commands = []
                observed = {'curl': NEWER, 'caddy': '3.0'}
                def command(argv, commands=commands, observed=observed, **kwargs):
                    commands.append(argv)
                    if argv[:2] == ['dpkg-deb', '-f']:
                        return subprocess.CompletedProcess(argv, 0, 'caddy\n2.0\namd64\n', '')
                    if argv[:2] == ['apt-get', 'install']:
                        self.assertIn('--allow-downgrades', argv)
                        self.assertIn('curl=' + NEWER, argv)
                        self.assertNotIn('curl=' + PIN, argv)
                        if '--simulate' not in argv:
                            observed['caddy'] = '2.0'
                        return subprocess.CompletedProcess(argv, 0, 'Inst caddy [3.0] (2.0 local [amd64])\n', '')
                    return self.command(argv, **kwargs)
                with patch.object(module, 'POLICY', Path(directory) / 'policy'), \
                     patch.object(module, 'packages', side_effect=lambda observed=observed: dict(observed)), \
                     patch.object(module, 'inspect', side_effect=lambda tools, observed=observed: dict(observed)), \
                     patch.object(module, 'active_services', return_value=set()), \
                     patch.object(module, 'publish', side_effect=lambda path, content, *args: Path(path).write_bytes(content)), \
                     patch.object(module.pwd, 'getpwnam', return_value=None), \
                     patch.object(module, 'download', side_effect=lambda pin, architecture, path: path), \
                     patch.object(module, 'run', side_effect=command):
                    if module is recipe:
                        result = module.apply(payload)
                        self.assertEqual(result['changed_packages'], ['caddy'])
                        self.assertEqual(result['changed_tools'], ['caddy'])
                    else:
                        module.image_packages(payload)
                self.assertEqual(observed, {'curl': NEWER, 'caddy': '2.0'})
                self.assertEqual(sum(c[:2] == ['apt-get', 'install'] for c in commands), 2)
                self.assertIn(['apt-get', 'check'], commands)
                self.assertIn(['dpkg', '--audit'], commands)

    def test_audit_output_refuses_incomplete_packages_even_when_dpkg_exits_zero(self):
        for stdout in ('The following packages are only half configured: curl\n', ''):
            with self.subTest(stdout=stdout), patch.object(recipe, 'run', return_value=subprocess.CompletedProcess(['dpkg', '--audit'], 0, stdout, '')):
                if stdout:
                    with self.assertRaisesRegex(recipe.RecipeFailure, 'packages'):
                        recipe.audit_packages({})
                else:
                    recipe.audit_packages({})

    def test_failed_rolling_index_refresh_stops_before_assessment_or_download(self):
        def command(argv, **kwargs):
            self.assertEqual(argv[:2], ['apt-get', 'update'])
            self.assertIn('APT::Update::Error-Mode=any', argv)
            raise recipe.RecipeFailure(result=subprocess.CompletedProcess(argv, 100, '', 'index unavailable'))
        with patch.object(recipe, 'run', side_effect=command), \
             patch.object(recipe, 'assess') as assess, \
             patch.object(recipe, 'download') as download:
            with self.assertRaisesRegex(recipe.RecipeFailure, 'apt-source'):
                recipe.apply({'tools': self.tools, 'architecture': 'amd64'})
        assess.assert_not_called()
        download.assert_not_called()

    def test_image_rechecks_after_full_upgrade_and_uses_the_same_floor_in_verification(self):
        payload = {'tools': self.tools, 'architecture': 'amd64', 'snapshot': '20261014T000000Z', 'profile': 'base', 'identity': 'sha256:current'}
        with tempfile.TemporaryDirectory() as directory:
            policy = Path(directory) / 'policy'
            with patch.object(image, 'POLICY', policy), \
                 patch.object(image, 'packages', return_value={'curl': NEWER}), \
                 patch.object(image, 'run', side_effect=self.command), \
                 patch.object(image, 'publish', side_effect=lambda path, content, *args: Path(path).write_bytes(content)):
                image.image_packages(payload)
            self.assertFalse(any(c[:2] == ['apt-get', 'install'] for c in self.commands))
            self.assertTrue(any(c[:2] == ['apt-get', 'full-upgrade'] for c in self.commands))
            original_read = Path.read_text
            def read_text(path, *args, **kwargs):
                if path == Path('/etc/orbit-machine-recipe.json'):
                    return json.dumps({'identity': 'sha256:current', 'profile': 'base'})
                return original_read(path, *args, **kwargs)
            with patch.object(image.Path, 'read_text', read_text), \
                 patch.object(image.Path, 'exists', return_value=False), \
                 patch.object(image, 'publish', side_effect=lambda path, content, *args: Path(path).write_bytes(content)), \
                 patch.object(image, 'inspect', return_value={'curl': NEWER}), \
                 patch.object(image, 'run', side_effect=self.command):
                image.image_verify(payload)
                self.release = 'o=Ubuntu,a=other-security,n=other'
                with self.assertRaises(image.RecipeFailure):
                    image.image_verify(payload)

    def test_failed_command_keeps_output_and_package_identity_through_the_entrypoint(self):
        argv = [sys.executable, '-c', "import sys; print('curl dependency conflict'); print('API_TOKEN=private-token', file=sys.stderr); sys.exit(100)"]
        with self.assertRaises(recipe.RecipeFailure) as failed:
            recipe.package_command(argv, ['curl=' + PIN])
        self.assertEqual(failed.exception.result.returncode, 100)
        self.assertIn('curl dependency conflict', failed.exception.result.stdout)
        with patch.object(recipe, 'platform_check'), \
             patch.object(recipe, 'packages', return_value={}), \
             patch.object(recipe, 'apply', side_effect=recipe.RecipeFailure('packages', result=failed.exception.result, packages=failed.exception.packages)) as apply, \
             patch.object(sys, 'argv', ['program', 'apply', '{"architecture":"amd64"}']), \
             contextlib.redirect_stdout(io.StringIO()) as output:
            self.assertEqual(recipe.main(), 1)
        apply.assert_called_once_with({'architecture': 'amd64'})
        failure = json.loads(output.getvalue())['failure']
        self.assertEqual(failure['packages'], ['curl=' + PIN])
        self.assertIn('curl dependency conflict', failure['stdout'])
        self.assertEqual(failure['stderr'], 'API_TOKEN=[REDACTED]\n')
        self.assertEqual(failure['command'], argv)

    def test_large_unicode_and_control_output_stays_within_the_ssh_response_limit(self):
        result = recipe.failure_output(('☃\n\t\x1b' * 15000))
        envelope = json.dumps({'failure': {'stdout': result, 'stderr': result}}, ensure_ascii=False)
        self.assertLess(len(envelope.encode()), 40000)
        self.assertNotIn('\x1b', result)

    def test_failure_output_is_redacted_before_it_is_bounded(self):
        value = 'x' * 15000 + '\nhttps://user:password@example.test/pkg\nAuthorization: Bearer private-token\nSECRET="a b c"\nAWS_ACCESS_KEY_ID=hidden-access\nCOOKIE=session-data\n'
        result = recipe.failure_output(value)
        self.assertLessEqual(len(result.encode()), 8000)
        for secret in ('user:password', 'private-token', 'a b c', 'hidden-access', 'session-data'):
            self.assertNotIn(secret, result)
        self.assertIn('[REDACTED]', result)


if __name__ == '__main__':
    unittest.main()
