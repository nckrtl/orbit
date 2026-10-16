"""Run the migration with a disposable filesystem and simulated Docker/apt boundaries.

Inspect/create fields follow moby/moby docker-v29.1.3/api/swagger.yaml (API 1.52).
"""
import copy
import io
import json
import os
from pathlib import Path
import subprocess
import tempfile
import types
import unittest
from unittest.mock import patch

RESOURCE = Path(__file__).resolve().parents[2] / 'resources/machine-software'
module = types.ModuleType('migration')
exec(compile((RESOURCE.parent / 'remote/lib/recipe.py').read_text().split("if __name__ == '__main__':")[0]
             + '\n' + (RESOURCE.parent / 'remote/docker/migrate.py').read_text(), str(RESOURCE.parent / 'remote/docker/migrate.py'), 'exec'), module.__dict__)


class DockerMigrationTest(unittest.TestCase):
    def setUp(self):
        directory = tempfile.TemporaryDirectory()
        self.addCleanup(directory.cleanup)
        self.root = Path(directory.name)
        self.apt = self.root / 'apt'
        (self.apt / 'sources.list.d').mkdir(parents=True)
        self.source = self.apt / 'sources.list.d/docker.sources'
        self.source.write_text('Types: deb\nURIs: https://download.docker.com/linux/ubuntu\nSuites: resolute\nComponents: stable\nSigned-By: /etc/apt/keyrings/docker.asc\n')
        self.source_before = self.source.read_bytes()
        self.daemon = self.root / 'daemon.json'
        self.daemon.write_text('{"runtimes":{"nvidia":{"path":"nvidia-container-runtime","runtimeArgs":[]}}}')
        self.daemon_before = self.daemon.read_bytes()
        self.data = self.root / 'volumes/mysql/_data'
        self.data.mkdir(parents=True)
        (self.data / 'database').write_text('irreplaceable')
        self.journal = self.root / 'journal'
        self.journal.mkdir(mode=0o700)
        self.dropin = self.root / 'systemd/docker.service.d/orbit-docker-migration.conf'
        self.policy = self.root / 'policy-rc.d'
        self.payload = {'recipe': 'sha256:fixture', 'snapshot': '20261014T000000Z', 'architecture': 'amd64',
                        'docker_version': '29.1.3-0ubuntu4.1', 'containerd_version': '2.2.2-0ubuntu1.1', 'fallback': True, 'process_ids': ['7'], 'process_states': {'7': 'running'},
                        'process_specs': {'7': {'name': 'orbit-process-7-mysql', 'hash': 'spec-hash',
                                                'volumes': [{'source': 'mysql', 'target': '/data'}]}}}
        self.original = {
            'one': self.container('one', 'mailpit'),
            'two': self.container('two', 'orbit-process-7-mysql', orbit=True),
            'three': self.container('three', 'stopped', running=False),
        }
        self.containers = copy.deepcopy(self.original)
        self.volumes = {'mysql': {'Name': 'mysql', 'Driver': 'local', 'Mountpoint': str(self.data), 'Options': None, 'Labels': {}}}
        self.networks = [{'Id': 'bridge-id', 'Name': 'bridge', 'Driver': 'bridge', 'Scope': 'local'},
                         {'Id': 'a' * 64, 'Name': 'app-network', 'Driver': 'bridge', 'Scope': 'local',
                          'Internal': False, 'Attachable': False, 'EnableIPv4': True, 'EnableIPv6': False,
                          'IPAM': {'Driver': 'default', 'Config': [{'Subnet': '172.30.0.0/24', 'Gateway': '172.30.0.1'}]},
                          'Options': {}, 'Labels': {}}]
        self.pinned_image = 'registry.example/app@sha256:' + 'a' * 64
        self.images = [{'Id': 'sha256:img', 'RepoDigests': [self.pinned_image], 'Architecture': 'amd64', 'Os': 'linux'}]
        self.installed = dict.fromkeys(module.CE, 'ce-version')
        self.commands = []
        self.requests = []
        self.service = True
        self.incompatible = False
        self.changes = []
        self.fail_create = None
        self.simulation = 'Remv docker-ce [ce-version]\nRemv containerd.io [ce-version]\n'
        original_publish = module.publish
        replacements = {'APT_ETC': self.apt, 'JOURNAL': self.journal, 'DAEMON': self.daemon,
                        'DROPIN': self.dropin, 'POLICY': self.policy, 'api': self.api, 'run': self.command,
                        'packages': lambda: dict(self.installed), 'snapshot_options': lambda payload, work: ['-o', 'fixture-snapshot=1'],
                        'METADATA_ROOTS': [self.root / 'docker', self.root / 'containerd'],
                        'publish': lambda path, content, mode=0o644: original_publish(path, content, mode, os.getuid(), os.getgid())}
        for name, value in replacements.items():
            fixture = patch.object(module, name, value)
            fixture.start()
            self.addCleanup(fixture.stop)

    def container(self, identifier, name, orbit=False, running=True):
        labels = {'orbit.managed': 'true', 'orbit.container.kind': 'process', 'orbit.process.id': '7', 'orbit.process.spec': 'spec-hash'} if orbit else {'app': 'fixture'}
        return {'Id': identifier, 'Name': '/' + name, 'Image': 'sha256:img',
                'Config': {'Image': 'registry.example/app:stable', 'Env': ['PASSWORD=secret'], 'Labels': labels,
                           'Entrypoint': ['/app'], 'Cmd': ['serve'], 'WorkingDir': '/work'},
                'HostConfig': {'RestartPolicy': {'Name': 'unless-stopped', 'MaximumRetryCount': 0},
                               'Binds': ['mysql:/data:rw'], 'Runtime': 'runc' if orbit else 'nvidia', 'NetworkMode': 'default' if orbit else 'app-network',
                               'PortBindings': {'80/tcp': [{'HostIp': '127.0.0.1', 'HostPort': '8080'}]}},
                'Mounts': [{'Type': 'volume', 'Name': 'mysql', 'Source': str(self.data), 'Destination': '/data', 'RW': True}],
                'NetworkSettings': {'Networks': {'bridge' if orbit else 'app-network':
                                                {'IPAMConfig': None, 'Aliases': None if orbit else ['app'], 'GwPriority': 0 if orbit else 7}}},
                'State': {'Running': running, 'Status': 'running' if running else 'exited'}}

    def api(self, method, path, body=None, *, missing=False):
        self.requests.append((method, path, copy.deepcopy(body)))
        if not self.service:
            raise OSError('service unavailable')
        if path == '/info':
            return {'DockerRootDir': '/var/lib/docker'}
        if path.endswith('/update'):
            reference = path.split('/')[2]
            c = next(c for c in self.containers.values() if reference in (c['Id'], c['Name'].lstrip('/')))
            c['HostConfig'].update(body)
            return {}
        if path == '/containers/json?all=1':
            return [{'Id': c['Id']} for c in self.containers.values()]
        if path.startswith('/containers/') and path.endswith('/changes'):
            return self.changes
        if path.startswith('/containers/') and path.endswith('/json'):
            name = path.split('/')[2].replace('%2F', '/')
            for c in self.containers.values():
                if name in (c['Id'], c['Name'], c['Name'].lstrip('/')):
                    return copy.deepcopy(c)
            if missing:
                return None
            raise module.RecipeFailure('docker-api')
        if path == '/volumes':
            return {'Volumes': list(self.volumes.values())}
        if path.startswith('/volumes/') and method == 'GET':
            return copy.deepcopy(self.volumes.get(path.split('/')[2]))
        if path == '/volumes/create':
            self.volumes[body['Name']] = {'Name': body['Name'], 'Options': body['DriverOpts']}
            return self.volumes[body['Name']]
        if path == '/networks':
            return self.networks
        if path == '/networks/create':
            network = dict(body, Id='new-' + body['Name'], Scope='local')
            self.networks.append(network)
            return {'Id': network['Id']}
        if path.startswith('/networks/'):
            name = path.rsplit('/', 1)[1]
            return next((n for n in self.networks if name in (n['Id'], n['Name'])), None)
        if path == '/images/json?all=1':
            return self.images
        if path.startswith('/images/'):
            return None if self.incompatible and 'docker.io' in self.installed and not getattr(self, 'fresh', False) else copy.deepcopy(self.images[0])
        if path.startswith('/containers/create?name='):
            name = path.split('name=')[1]
            if self.fail_create == name:
                self.fail_create = None
                raise module.RecipeFailure('docker-api')
            identifier = 'new-' + name
            c = self.container(identifier, name, running=False)
            c['Config'] = {k: v for k, v in body.items() if k not in ('HostConfig', 'NetworkingConfig')}
            c['HostConfig'] = body['HostConfig']
            self.containers[identifier] = c
            return {'Id': identifier}
        raise AssertionError((method, path, body))

    def command(self, argv, **kwargs):
        self.commands.append(argv)
        stdout = ''
        if argv[:2] == ['apt-get', 'install']:
            if '--simulate' in argv:
                stdout = self.simulation
            else:
                self.installed = {'docker.io': self.payload['docker_version'], 'containerd': '2.2.2-0ubuntu1.1',
                                  'docker-buildx': 'fixture', 'docker-compose-v2': 'fixture'}
        elif argv[:2] == ['systemctl', 'show']:
            stdout = 'loaded'
        elif argv[:2] == ['docker', 'commit']:
            stdout = 'sha256:' + 'b' * 64
        elif argv[:2] == ['docker', 'save']:
            Path(argv[3]).write_bytes(b'image archive')
        elif argv[:2] == ['systemctl', 'stop']:
            self.service = False
        elif argv[:2] == ['systemctl', 'start']:
            self.service = True
            if self.dropin.exists() and not getattr(self, 'fresh', False):
                self.fresh = True
                self.containers = {}
                self.volumes = {}
                self.networks = self.networks[:1]
        elif argv[:2] == ['docker', 'stop']:
            c = self.containers[argv[-1]]
            c['State'] = {'Running': False, 'Status': 'exited'}
        elif argv[:2] == ['docker', 'start']:
            for c in self.containers.values():
                if argv[-1] in (c['Name'].lstrip('/'), c['Id']):
                    c['State'] = {'Running': True, 'Status': 'running'}
        return subprocess.CompletedProcess(argv, 0, stdout, '')

    def test_fallback_reuses_explicit_bridge_name_and_resumes_without_duplicate_network(self):
        self.networks[1]['Options']['com.docker.network.bridge.name'] = 'app-br'
        self.incompatible = True
        module.migrate(self.payload, 'prepare')
        state = json.loads((self.journal / 'inventory.json').read_text())
        self.assertEqual(self.networks[1]['Options']['com.docker.network.bridge.name'], 'app-br')
        module.fresh_engine(state)
        self.assertEqual(len([r for r in self.requests if r[:2] == ('POST', '/networks/create')]), 1)

    def test_fallback_refuses_existing_network_with_wrong_bridge_or_configuration(self):
        self.incompatible = True
        module.migrate(self.payload, 'prepare')
        state = json.loads((self.journal / 'inventory.json').read_text())
        expected = copy.deepcopy(self.networks[1])
        for change in ({'Options': {'com.docker.network.bridge.name': 'wrong-br'}}, {'Internal': True},
                       {'IPAM': {'Driver': 'default', 'Config': [{'Subnet': '172.31.0.0/24'}]}}):
            self.networks[1] = dict(expected, **change)
            with self.subTest(change=change), self.assertRaisesRegex(module.RecipeFailure, 'network-conflict'):
                module.fresh_engine(state)
        self.assertEqual(len([r for r in self.requests if r[:2] == ('POST', '/networks/create')]), 1)

    def test_dry_run_inventory_plan_has_no_writes_or_stops_and_no_secrets(self):
        before = {str(p): p.read_bytes() for p in self.root.rglob('*') if p.is_file()}
        report = module.migrate(self.payload, 'plan')
        self.assertEqual(report['containers'], ['mailpit', 'orbit-process-7-mysql', 'stopped'])
        self.assertIn('docker.io=29.1.3-0ubuntu4.1', report['packages'])
        self.assertIn('docker-ce-', report['packages'])
        self.assertEqual(report['source_backups'], [str(self.apt / 'docker.sources.orbit-docker-backup')])
        self.assertIn('reconcile Orbit Processes from stored specs', report['steps'])
        self.assertNotIn('secret', json.dumps(report))
        self.assertTrue(all(argv[:3] == ['docker', 'manifest', 'inspect'] for argv in self.commands))
        self.assertEqual(before, {str(p): p.read_bytes() for p in self.root.rglob('*') if p.is_file()})

    def test_refuses_unrecreatable_container_before_stopping_or_recording(self):
        for key, value in [('AutoRemove', True), ('NetworkMode', 'container:other'), ('VolumesFrom', ['other'])]:
            with self.subTest(key=key):
                self.containers['one']['HostConfig'][key] = value
                with self.assertRaises(module.RecipeFailure):
                    module.migrate(self.payload, 'prepare')
                del self.containers['one']['HostConfig'][key]
        self.assertEqual(self.commands, [])
        self.assertFalse((self.journal / 'inventory.json').exists())

    def test_success_keeps_daemon_and_volume_data_and_retires_sources_with_backup(self):
        report = module.migrate(self.payload, 'prepare')
        self.assertFalse(report['fallback'])
        self.assertEqual(report['process_ids'], ['7'])
        self.assertEqual(self.daemon.read_bytes(), self.daemon_before)
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')
        self.assertFalse(self.source.exists())
        self.assertEqual((self.apt / 'docker.sources.orbit-docker-backup').read_bytes(), self.source_before)
        saved = json.loads((self.journal / 'inventory.json').read_text())
        expected = copy.deepcopy(self.original)
        expected['two']['Config'].pop('Env')
        self.assertEqual(saved['containers'], list(expected.values()))
        self.assertEqual((self.journal / 'inventory.json').stat().st_mode & 0o777, 0o600)
        self.assertEqual(self.installed['containerd'], '2.2.2-0ubuntu1.1')
        self.assertFalse(self.policy.exists())
        self.assertFalse(self.dropin.exists())
        self.assertFalse(any(path == '/containers/two/changes' for _, path, _ in self.requests))
        self.assertFalse(any(method == 'POST' and not path.endswith('/update') for method, path, body in self.requests))
        self.assertFalse(module.migrate(self.payload, 'report')['healthy'])  # Gateway must restart its Process.
        self.command(['docker', 'start', 'orbit-process-7-mysql'])
        self.assertTrue(module.migrate(self.payload, 'report')['healthy'])

    def test_fallback_recreates_non_orbit_with_every_recorded_setting_and_reuses_volume_paths(self):
        self.incompatible = True
        report = module.migrate(self.payload, 'prepare')
        self.assertTrue(report['fallback'])
        self.assertIn('--data-root=' + str(self.journal / 'engine'), self.dropin.read_text())
        self.assertNotIn('--containerd', self.dropin.read_text())
        self.assertEqual(self.volumes['mysql']['Options']['device'], str(self.data))
        creates = [body for method, path, body in self.requests if path.startswith('/containers/create')]
        self.assertEqual(len(creates), 2)  # Orbit container is recreated by stored specs, not inspect.
        body = creates[0]
        self.assertEqual(body['Env'], ['PASSWORD=secret'])
        self.assertEqual(body['Labels'], {'app': 'fixture', 'orbit.docker.migration': 'one'})
        expected_host = dict(self.original['one']['HostConfig'], RestartPolicy={'Name': 'no'})
        self.assertEqual(body['HostConfig'], expected_host)
        self.assertEqual(body['NetworkingConfig']['EndpointsConfig']['app-network']['Aliases'], ['app'])
        self.assertEqual(body['NetworkingConfig']['EndpointsConfig']['app-network']['GwPriority'], 7)
        self.assertEqual(self.networks[1]['IPAM']['Config'][0]['Subnet'], '172.30.0.0/24')
        self.assertEqual(self.networks[1]['Options']['com.docker.network.bridge.name'], 'br-' + 'a' * 12)
        self.assertTrue(all(argv[-1] == self.pinned_image for argv in self.commands if argv[:2] == ['docker', 'pull']))
        self.assertIn(['docker', 'tag', self.pinned_image, 'registry.example/app:stable'], self.commands)
        self.assertEqual(self.daemon.read_bytes(), self.daemon_before)
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')
        self.assertEqual(report['containers'][2]['state'], 'exited')
        self.assertNotIn('secret', json.dumps(report))

    def test_refuses_newer_api_settings_and_unrecorded_process_volumes(self):
        self.containers['one']['HostConfig']['FutureStorageMode'] = 'not-supported'
        with self.assertRaisesRegex(module.RecipeFailure, 'newer-engine-configuration'):
            module.migrate(self.payload, 'prepare')
        self.assertEqual(self.commands, [])
        del self.containers['one']['HostConfig']['FutureStorageMode']
        self.payload['process_specs']['7']['volumes'] = []
        with self.assertRaisesRegex(module.RecipeFailure, 'orbit-unrecorded-mount'):
            module.migrate(self.payload, 'prepare')
        self.assertFalse((self.journal / 'inventory.json').exists())
        self.assertFalse(any(argv[:2] == ['docker', 'stop'] for argv in self.commands))

    def test_anonymous_mount_api_reuses_original_volume_name(self):
        self.incompatible = True
        self.containers['one']['HostConfig']['Binds'] = []
        self.containers['one']['HostConfig']['Mounts'] = [{'Type': 'volume', 'Target': '/data', 'Source': ''}]
        module.migrate(self.payload, 'prepare')
        body = next(body for method, path, body in self.requests if path == '/containers/create?name=mailpit')
        self.assertEqual(body['HostConfig']['Mounts'][0]['Source'], 'mysql')
        self.assertEqual(self.volumes['mysql']['Options']['device'], str(self.data))
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')

    def test_old_engine_start_failure_uses_fresh_engine(self):
        command = self.command
        def fail_original(argv, **kwargs):
            if argv[:2] == ['systemctl', 'start'] and not self.dropin.exists():
                raise module.RecipeFailure('engine-start')
            return command(argv, **kwargs)
        with patch.object(module, 'run', fail_original):
            report = module.migrate(self.payload, 'prepare')
        self.assertTrue(report['fallback'])
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')

    def test_changed_stored_spec_refuses_resume_without_any_new_commands(self):
        module.migrate(self.payload, 'prepare')
        before = len(self.commands)
        self.payload['process_specs']['7']['hash'] = 'changed'
        with self.assertRaisesRegex(module.RecipeFailure, 'orbit-spec-changed'):
            module.migrate(self.payload, 'prepare')
        self.assertEqual(len(self.commands), before)

    def test_shutdown_commit_failure_restarts_original_running_containers(self):
        command = self.command
        def shutdown_changes(argv, **kwargs):
            result = command(argv, **kwargs)
            if argv[:2] == ['docker', 'stop']:
                self.changes = [{'Kind': 1, 'Path': '/unmounted-database'}]
            if argv[:2] == ['docker', 'commit']:
                raise module.RecipeFailure('commit-image')
            return result
        with patch.object(module, 'run', shutdown_changes), self.assertRaisesRegex(module.RecipeFailure, 'commit-image'):
            module.migrate(self.payload, 'prepare')
        self.assertIn('docker-ce', self.installed)
        self.assertTrue(self.source.exists())
        self.assertFalse(any(argv[:2] == ['apt-get', 'install'] for argv in self.commands))
        self.assertTrue(self.containers['one']['State']['Running'])
        self.assertTrue(self.containers['two']['State']['Running'])
        self.assertFalse(self.containers['three']['State']['Running'])

    def test_report_includes_unreadable_containers_and_fails_health(self):
        module.migrate(self.payload, 'prepare')
        self.service = False
        report = module.migrate(self.payload, 'report')
        self.assertFalse(report['healthy'])
        self.assertEqual([c['state'] for c in report['containers']], ['unreadable'] * 3)

    def test_resumes_partial_fallback_without_second_swap_or_replacing_original_inventory(self):
        self.incompatible = True
        self.fail_create = 'stopped'
        with self.assertRaisesRegex(module.RecipeFailure, 'docker-api'):
            module.migrate(self.payload, 'prepare')
        saved = json.loads((self.journal / 'inventory.json').read_text())
        self.assertTrue(saved['fallback'])
        self.assertFalse(saved['prepared'])
        report = module.migrate(self.payload, 'prepare')
        self.assertTrue(report['fallback'])
        installs = [a for a in self.commands if a[:2] == ['apt-get', 'install'] and '--yes' in a]
        self.assertEqual(len(installs), 1)
        pulls = [a for a in self.commands if a[:2] == ['docker', 'pull']]
        self.assertEqual(len(pulls), 3)  # First container is not pulled or recreated twice.
        self.assertEqual(json.loads((self.journal / 'inventory.json').read_text())['containers'], saved['containers'])
        self.assertEqual((self.data / 'database').read_text(), 'irreplaceable')

    def test_resumes_after_package_failure_with_daemon_stopped(self):
        self.simulation += 'Remv unrelated [1.0]\n'
        with self.assertRaisesRegex(module.RecipeFailure, 'unrelated-package-removal'):
            module.migrate(self.payload, 'prepare')
        self.assertFalse(self.service)
        self.simulation = 'Remv docker-ce [ce-version]\n'
        report = module.migrate(self.payload, 'prepare')
        self.assertFalse(report['fallback'])
        self.assertEqual(self.installed['docker.io'], self.payload['docker_version'])
        self.assertEqual((self.apt / 'docker.sources.orbit-docker-backup').read_bytes(), self.source_before)

    def test_refuses_recipe_or_daemon_drift_on_resume(self):
        module.migrate(self.payload, 'prepare')
        with self.assertRaisesRegex(module.RecipeFailure, 'recipe-mismatch'):
            module.migrate(dict(self.payload, recipe='other'), 'prepare')
        self.daemon.write_text('{}')
        with self.assertRaisesRegex(module.RecipeFailure, 'daemon-changed'):
            module.migrate(self.payload, 'prepare')

    def test_list_sources_retire_only_docker_lines_and_backup_collision_refuses(self):
        self.source.unlink()
        source = self.apt / 'sources.list.d/docker.list'
        before = b'deb [arch=amd64 signed-by=/key] https://download.docker.com/linux/ubuntu resolute stable\ndeb https://example.org/ubuntu resolute main\n'
        source.write_bytes(before)
        changes = module.docker_sources()
        self.assertEqual(changes, [(source, before, b'deb https://example.org/ubuntu resolute main\n')])
        (self.apt / 'docker.list.orbit-docker-backup').write_text('different original')
        with self.assertRaisesRegex(module.RecipeFailure, 'apt-source'):
            module.migrate(self.payload, 'prepare')
        self.assertTrue(all(argv[:3] == ['docker', 'manifest', 'inspect'] for argv in self.commands))

    def test_beast_like_inventory_passes_preflight_with_cuda_tmpfs_local_images_and_orbit_layers(self):
        for identifier, name in [('one', 'diction-whisper'), ('four', 'ofide'), ('five', 'ohdear'), ('six', 'dngdmt')]:
            self.containers[identifier] = self.container(identifier, name)
        self.containers['one']['HostConfig']['DeviceRequests'] = [{'Driver': 'nvidia', 'Count': -1,
                                                                  'Capabilities': [['gpu']], 'Options': {}}]
        self.containers['one']['Config']['Image'] = 'sha256:img'
        self.containers['one']['HostConfig']['Tmpfs'] = {'/tmp': 'rw,size=1g'}
        self.containers['one']['Mounts'].append({'Type': 'tmpfs', 'Destination': '/tmp', 'RW': True})
        self.images[0]['RepoDigests'] = []
        self.changes = [{'Kind': 1, 'Path': '/workspace/local-only'}]
        plan = module.migrate(self.payload, 'plan')
        self.assertIn('diction-whisper', plan['containers'])
        self.assertIn('sha256:img', plan['local_images'])
        self.assertNotIn('orbit-process-7-mysql', plan['changed_layers'])
        self.assertFalse(any(path == '/containers/two/changes' for _, path, _ in self.requests))
        self.assertFalse((self.journal / 'inventory.json').exists())
        self.assertFalse(any(command[:2] == ['docker', 'save'] for command in self.commands))

    def test_local_images_and_changed_layers_are_archived_and_loaded_in_fallback(self):
        self.images[0]['RepoDigests'] = []
        self.changes = [{'Kind': 1, 'Path': '/local-data'}]
        self.incompatible = True
        module.migrate(self.payload, 'prepare')
        state = json.loads((self.journal / 'inventory.json').read_text())
        self.assertEqual(len(state['archives']), 2)
        self.assertEqual(set(state['committed']), {'one:running', 'three:running', 'one:shutdown', 'three:shutdown'})
        self.assertNotIn('Env', state['containers'][1]['Config'])
        self.assertFalse(any(path == '/containers/two/changes' for _, path, _ in self.requests))
        self.assertEqual(len([a for a in self.commands if a[:2] == ['docker', 'load']]), 2)
        bodies = [body for _, path, body in self.requests if path.startswith('/containers/create')]
        self.assertEqual(bodies[0]['Image'], 'sha256:' + 'b' * 64)
        self.assertTrue(all(Path(archive).stat().st_mode & 0o077 == 0 for archive in state['archives'].values()))

    def test_local_tag_process_recovery_restores_all_inventory_tags(self):
        self.local_tag_process_recovery(interrupt=False)

    def test_local_tag_process_recovery_resumes_after_partial_tag_restoration(self):
        self.local_tag_process_recovery(interrupt=True)

    def local_tag_process_recovery(self, *, interrupt):
        local_tag = 'local/mysql:production'
        alias = 'local/mysql:backup'
        self.images[0].update(RepoDigests=[], RepoTags=[local_tag, alias])
        self.containers['two']['Config']['Image'] = local_tag
        self.incompatible = True
        loaded, tags = set(), {}
        original_command, original_api = self.command, self.api
        fail_tag = interrupt

        def command(argv, **kwargs):
            nonlocal fail_tag
            if argv[:2] == ['docker', 'load']:
                # Docker save by ID contains image bytes, not repository tags.
                loaded.add('sha256:img')
            if argv[:2] == ['docker', 'tag']:
                self.assertIn(argv[2], loaded)
                if argv[3] == alias and fail_tag:
                    fail_tag = False
                    raise module.RecipeFailure('command')
                tags[argv[3]] = argv[2]
            return original_command(argv, **kwargs)

        def api(method, path, body=None, **kwargs):
            if path.startswith('/containers/create') and body['Image'] == local_tag:
                if tags.get(local_tag) != 'sha256:img':
                    raise module.RecipeFailure('docker-api')
            return original_api(method, path, body, **kwargs)

        with patch.object(module, 'run', command), patch.object(module, 'api', api):
            if interrupt:
                with self.assertRaises(module.RecipeFailure):
                    module.migrate(self.payload, 'prepare')
                state = json.loads((self.journal / 'inventory.json').read_text())
                self.assertEqual(state['stage'], 'restore-image-tags')
                self.assertFalse(state['prepared'])
                self.assertEqual(tags, {local_tag: 'sha256:img'})
            report = module.migrate(self.payload, 'prepare')
            self.assertTrue(report['fallback'])
            self.assertEqual(tags, {local_tag: 'sha256:img', alias: 'sha256:img'})
            # The Gateway recreates the Process using its stored image tag,
            # not the inventory image ID used for non-Orbit recovery.
            body = copy.deepcopy(self.original['two']['Config'])
            body['Image'] = local_tag
            body['HostConfig'] = self.original['two']['HostConfig']
            api('POST', '/containers/create?name=orbit-process-7-mysql', body)
            self.assertEqual(self.containers['new-orbit-process-7-mysql']['Config']['Image'], local_tag)
        installs = [a for a in self.commands if a[:2] == ['apt-get', 'install'] and '--yes' in a]
        self.assertEqual(len(installs), 1)
        loads = [a for a in self.commands if a[:2] == ['docker', 'load']]
        self.assertEqual(len(loads), 2 if interrupt else 1)

    def test_apt_failure_after_removal_is_recorded_and_repairs_before_retry(self):
        original = self.command
        def fail_after_removal(argv, **kwargs):
            if argv[:2] == ['apt-get', 'install'] and '--yes' in argv:
                self.installed = {}
                self.assertEqual(module.signal.getsignal(module.signal.SIGTERM), module.signal.SIG_IGN)
                self.assertIsNone(kwargs['timeout'])
                self.assertFalse(any(a.startswith(('Dir=', 'Dir::Log=', 'Dir::State=')) for a in argv))
                raise module.RecipeFailure()
            return original(argv, **kwargs)
        with patch.object(module, 'run', fail_after_removal), self.assertRaises(module.RecipeFailure):
            module.migrate(self.payload, 'prepare')
        state = json.loads((self.journal / 'inventory.json').read_text())
        self.assertTrue(state['swapping'])
        self.assertEqual(state['stage'], 'swapping')
        self.assertFalse(self.service)
        before = len(self.commands)
        module.migrate(self.payload, 'prepare')
        resumed = self.commands[before:]
        self.assertIn(['dpkg', '--configure', '-a'], resumed)
        repair = next(i for i, a in enumerate(resumed) if a[:3] == ['apt-get', '-f', 'install'] and '--yes' in a)
        install = next(i for i, a in enumerate(resumed) if a[:2] == ['apt-get', 'install'] and '--yes' in a)
        self.assertLess(repair, install)
        self.assertNotIn('Dir::State', ' '.join(resumed[install]))
        self.assertIn('fixture-snapshot=1', resumed[install])
        self.assertFalse(self.policy.exists())
        self.assertFalse(json.loads((self.journal / 'inventory.json').read_text())['swapping'])

    def test_metadata_backups_preserve_databases_not_volume_payloads(self):
        root = self.root / 'docker'
        files = ['volumes/metadata.db', 'network/files/local-kv.db', 'containers/one/config.v2.json',
                 'containers/one/hostconfig.json', 'image/overlay2/imagedb/content/sha256/abc',
                 'containerd/daemon/io.containerd.metadata.v1.bolt/meta.db', 'volumes/mysql/_data/database']
        for relative in files:
            path = root / relative
            path.parent.mkdir(parents=True, exist_ok=True)
            path.write_bytes(b'original metadata')
        module.migrate(self.payload, 'prepare')
        for relative in files[:-1]:
            self.assertEqual((self.journal / 'metadata/docker' / relative).read_bytes(), b'original metadata')
        self.assertFalse((self.journal / 'metadata/docker' / files[-1]).exists())

    def test_verification_mounts_non_orbit_layers_and_probes_before_restoring_restart_policies(self):
        module.migrate(self.payload, 'prepare')
        updates = [(i, body) for i, (method, path, body) in enumerate(self.requests) if path.endswith('/update')]
        restored = min(i for i, body in updates if body['RestartPolicy']['Name'] == 'unless-stopped')
        for identifier in ('one', 'three'):
            mounts = [i for i, (_, path, _) in enumerate(self.requests) if path == '/containers/' + identifier + '/changes']
            self.assertTrue(mounts)
            self.assertLess(max(mounts), restored)
        self.assertIn(['docker', 'start', 'one'], self.commands)
        self.assertGreaterEqual(self.commands.count(['docker', 'stop', '--time', '60', 'one']), 2)

    def test_fallback_is_explicit_and_resume_can_authorize_it(self):
        self.incompatible = True
        self.payload['fallback'] = False
        with self.assertRaisesRegex(module.RecipeFailure, 'fallback-required'):
            module.migrate(self.payload, 'prepare')
        self.assertFalse(self.dropin.exists())
        self.payload['fallback'] = True
        self.assertTrue(module.migrate(self.payload, 'prepare')['fallback'])

    def test_unrelated_downgrade_refuses_and_only_loaded_units_are_stopped(self):
        self.simulation = 'Inst unrelated [9] (8 Ubuntu:26.04/resolute [amd64])\n'
        with self.assertRaisesRegex(module.RecipeFailure, 'unrelated-package-downgrade'):
            module.migrate(self.payload, 'prepare')
        self.commands.clear()
        command = self.command
        def no_containerd(argv, **kwargs):
            if argv[:2] == ['systemctl', 'show'] and argv[-1] == 'containerd.service':
                return subprocess.CompletedProcess(argv, 0, 'not-found', '')
            return command(argv, **kwargs)
        with patch.object(module, 'run', no_containerd):
            module.stop_loaded(['docker.service', 'containerd.service'])
        self.assertIn(['systemctl', 'stop', 'docker.service'], self.commands)

    def test_resume_normalizes_effective_daemon_defaults(self):
        info = {'DefaultRuntime': 'runc', 'LoggingDriver': 'json-file'}
        expected = {'Runtime': '', 'LogConfig': {'Type': '', 'Config': None}, 'RestartPolicy': {'Name': 'no'}}
        actual = {'Runtime': 'runc', 'LogConfig': {'Type': 'json-file', 'Config': {}},
                  'RestartPolicy': {'Name': 'no', 'MaximumRetryCount': 0}}
        self.assertEqual(module.effective_host(expected, info), module.effective_host(actual, info))
        actual['Runtime'] = 'nvidia'
        self.assertNotEqual(module.effective_host(expected, info), module.effective_host(actual, info))

    def test_prepare_uses_transient_unit_with_private_stdin_and_cleans_temporary_secrets(self):
        source = b'# assembled program\n'
        def systemd(argv, **kwargs):
            self.assertEqual(argv[0], 'systemd-run')
            self.assertIn('--property=RuntimeMaxSec=2400', argv)
            program = Path(argv[-1])
            self.assertEqual(program.read_bytes(), source)
            payload = json.loads((program.parent / 'input.json').read_text())
            self.assertTrue(payload['worker'])
            self.assertEqual(payload['mode'], 'prepare')
            self.assertEqual(program.stat().st_mode & 0o077, 0)
            self.assertNotIn('process_specs', ' '.join(argv))
            (program.parent / 'result.json').write_text('{"outcome":"prepared","data":{"containers":[]}}')
            return subprocess.CompletedProcess(argv, 0, '', '')
        with patch.dict(module.__dict__, {'__orbit_source__': source}), patch.object(module, 'run', systemd):
            self.assertEqual(module.transient_prepare(dict(self.payload, mode='prepare')), ('prepared', {'containers': []}))
        self.assertEqual(list(self.journal.iterdir()), [])

    def test_locked_worker_cannot_modify_another_transactions_journal(self):
        module.record('inventory.json', module.inventory(self.payload))
        before = (self.journal / 'inventory.json').read_bytes()
        with (self.journal / 'lock').open('a') as lock:
            module.fcntl.flock(lock, module.fcntl.LOCK_EX | module.fcntl.LOCK_NB)
            with patch.object(module, 'journal_permissions'), patch.object(module, 'platform_check'), \
                 patch.object(module, 'migrate') as migrate:
                outcome, report = module.migration_main(dict(self.payload, mode='prepare', worker=True))
            self.assertEqual(outcome, 'failed')
            self.assertEqual(report['failure'], 'docker-migration:locked')
            migrate.assert_not_called()
        self.assertEqual((self.journal / 'inventory.json').read_bytes(), before)

    def test_failure_reports_every_final_state_and_restarts_on_pre_swap_refusal(self):
        self.simulation += 'Remv unrelated [1]\n'
        with patch.object(module, 'journal_permissions'), patch.object(module, 'platform_check'):
            outcome, report = module.migration_main(dict(self.payload, mode='prepare', worker=True))
        self.assertEqual(outcome, 'failed')
        self.assertEqual(report['failure'], 'docker-migration:unrelated-package-removal')
        self.assertEqual([c['state'] for c in report['containers']], ['running', 'running', 'exited'])
        self.assertIn('docker-ce', self.installed)
        self.assertFalse(json.loads((self.journal / 'inventory.json').read_text())['stopped'])

    def test_daemon_start_is_bounded_and_retried(self):
        self.service = False
        def unavailable(*args, **kwargs):
            raise OSError('unavailable')
        with patch.object(module, 'api', unavailable), patch.object(module.time, 'sleep') as sleep:
            with self.assertRaisesRegex(module.RecipeFailure, 'daemon-start'):
                module.start_daemon()
        self.assertEqual(sleep.call_count, 30)
        self.assertEqual(len([c for c in self.commands if c[:2] == ['systemctl', 'start']]), 3)

    def test_convergence_detects_ce_and_refuses_before_plan_or_apply(self):
        for mode in ('inspect', 'apply'):
            with patch.object(module, 'platform_check'), patch.object(module, 'plan') as plan, \
                 patch.object(module, 'apply') as apply, patch.object(module.sys, 'argv', ['program', mode, json.dumps(self.payload)]), \
                 patch('sys.stdout', new_callable=io.StringIO) as output:
                self.assertEqual(module.main(), 1)
                self.assertEqual(output.getvalue().strip(), 'recipe-stage:docker-provider:docker-ce')
                plan.assert_not_called()
                apply.assert_not_called()


if __name__ == '__main__':
    unittest.main()
