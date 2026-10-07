import copy
import json
from pathlib import Path
import runpy
import sys
import tempfile
import unittest
import uuid
from unittest.mock import patch
from types import SimpleNamespace

module = runpy.run_path(sys.argv.pop(1))
Publisher, Refusal = module['Publisher'], module['Refusal']
audit = runpy.run_path(str(Path(module['__file__']).with_name('guest-template-audit.py')))['audit']


class FakePublisher(Publisher):
    def __init__(self):
        super().__init__({'project': 'disposable', 'pool': 'proof', 'sandbox_id': str(uuid.uuid4()),
                          'source_template': {'id': str(uuid.uuid4()), 'repository': 'https://github.com/acme/orbit.git', 'base': 'main', 'commit': 'a' * 40}})
        self.calls = []
        self.fail_publish = False
        self.fail_audit = False
        self.fail_health = False
        self.image_rows = []
        self.volume_rows = []
        self.snapshots = []
        config = {'user.orbit.compute.owner': 'orbit-task-sandbox', 'user.orbit.compute.id': self.request['sandbox_id'],
                  'user.orbit.template.candidate': self.template['id']}
        self.instances = [{'name': self.name + '-' + role, 'type': 'virtual-machine', 'status': 'Running', 'profiles': [],
                           'config': dict(config), 'expanded_devices': {
                               'root': {'type': 'disk', 'pool': self.pool, 'path': '/'},
                               'worktree': {'type': 'disk', 'pool': self.pool, 'path': '/home/orbit/orbit', 'source': self.name + '-worktree'},
                               'eth0': {'type': 'nic', 'network': self.name}}} for role in ('operator', 'gateway')]
        self.source = {'name': self.name + '-worktree', 'config': dict(config), 'content_type': 'filesystem',
                       'used_by': ['/1.0/instances/' + row['name'] + '?project=disposable' for row in self.instances]}

    def query(self, path, method='GET', data=None):
        if method == 'POST':
            self.calls.append(('copy', copy.deepcopy(data)))
            self.volume_rows.append({'name': data['name'], 'config': data['config'], 'used_by': []})
            return None
        if path == '/1.0/images?recursion=1':
            return copy.deepcopy(self.image_rows)
        if path.endswith('/volumes/custom?recursion=1'):
            return copy.deepcopy(self.volume_rows)
        if path == '/1.0/instances?recursion=1':
            return copy.deepcopy(self.instances)
        if path.startswith('/1.0/instances/'):
            return copy.deepcopy(next(row for row in self.instances if row['name'] == path.rsplit('/', 1)[1]))
        if path.endswith('/snapshots?recursion=1'):
            return copy.deepcopy(self.snapshots)
        if path.endswith('/snapshots/ready'):
            return copy.deepcopy(self.snapshots[0])
        if path == self.volume_path(self.name + '-worktree'):
            return copy.deepcopy(self.source)
        if path == self.volume_path(self.target):
            return copy.deepcopy(self.volume_rows[0])
        raise AssertionError(path)

    def run(self, *args, data=None, timeout=1200):
        self.calls.append(args)
        if args[0] == 'stop':
            next(row for row in self.instances if row['name'] == args[1])['status'] = 'Stopped'
        elif args[:3] == ('storage', 'volume', 'snapshot'):
            self.snapshots.append({'name': 'ready', 'config': dict(self.metadata)})
        elif args[0] == 'publish':
            role = args[1].rsplit('-', 1)[1]
            # Model a server-side success followed by a lost client response.
            self.image_rows.append({'fingerprint': ('1' if role == 'operator' else '2') * 64, 'public': False,
                                    'type': 'virtual-machine', 'aliases': [], 'properties': {**self.metadata, 'user.orbit.template.role': role}})
            if self.fail_publish:
                raise Refusal('Lost response')
        elif args[:2] == ('image', 'delete'):
            self.image_rows = [row for row in self.image_rows if row['fingerprint'] != args[2]]
        elif args[:3] == ('storage', 'volume', 'delete'):
            self.volume_rows = []
            self.snapshots = []
        else:
            raise AssertionError(args)
        return ''

    def guest(self, role, script, data=None, user='root'):
        self.calls.append(('guest', role, user))
        if self.fail_health and 'sandbox_template_native_health_failed' in script:
            return {'ready': False}
        if user == 'root':
            if self.fail_audit:
                raise Refusal('Guest has credentials')
            return {'ready': True, 'scanned_files': 20}
        return {'head': self.template['commit'], 'source_template': self.template, 'ready': True, 'gateway_version': self.template['commit']}


class Publication(unittest.TestCase):
    def test_plan_is_read_only_and_publication_returns_pinned_private_pair(self):
        publisher = FakePublisher()
        plan = publisher.preflight()
        self.assertEqual(plan['candidate'], publisher.name)
        self.assertEqual(publisher.calls, [])
        result = publisher.publish()
        self.assertTrue(result['published'])
        self.assertEqual(result['images'], {'operator': '1' * 64, 'gateway': '2' * 64})
        self.assertEqual([row['status'] for row in publisher.instances], ['Stopped', 'Stopped'])
        self.assertEqual(publisher.volume_rows[0]['config'], {**publisher.metadata, 'size': '20GiB'})
        self.assertEqual(publisher.snapshots[0]['config'], publisher.metadata)
        self.assertFalse(any('--alias' in args or '--reuse' in args for args in publisher.calls))
        self.assertEqual(next(args[1]['source'] for args in publisher.calls if args[0] == 'copy'),
                         {'type': 'copy', 'name': publisher.name + '-worktree', 'pool': 'proof', 'project': 'disposable'})
        before = list(publisher.calls)
        with self.assertRaises(Refusal):
            publisher.publish()
        self.assertEqual(before, publisher.calls)

    def test_refuses_foreign_devices_ownership_attachments_before_guest_or_host_mutation(self):
        def cases(p):
            return [lambda: p.instances[0]['config'].pop('user.orbit.template.candidate'),
                    lambda: p.instances[0]['config'].update({'user.orbit.compute.id': str(uuid.uuid4())}),
                    lambda: p.instances[0]['expanded_devices'].update({'proxy': {'type': 'proxy'}}),
                    lambda: p.instances[0].update({'profiles': ['default']}),
                    lambda: p.instances[0].update({'status': 'Stopped'}),
                    lambda: p.source['used_by'].append('/1.0/instances/foreign?project=disposable'),
                    lambda: p.source['used_by'].__setitem__(0, p.source['used_by'][0].replace('disposable', 'foreign')),
                    lambda: p.source['config'].update({'user.orbit.compute.template': 'old'}),
                    lambda: p.volume_rows.append({'name': p.target, 'config': {}})]
        for index in range(9):
            with self.subTest(case=index):
                publisher = FakePublisher()
                cases(publisher)[index]()
                with self.assertRaises(Refusal):
                    publisher.publish()
                self.assertEqual(publisher.calls, [])

    def test_audit_failure_preserves_running_pair_without_publication(self):
        publisher = FakePublisher()
        publisher.fail_audit = True
        with self.assertRaises(Refusal):
            publisher.publish()
        self.assertEqual([row['status'] for row in publisher.instances], ['Running', 'Running'])
        self.assertEqual(publisher.image_rows, [])
        self.assertEqual(publisher.volume_rows, [])
        self.assertEqual(publisher.calls, [('guest', 'operator', 'root')])

    def test_unready_native_pair_is_not_stopped_or_published(self):
        publisher = FakePublisher()
        publisher.fail_health = True
        with self.assertRaises(Refusal):
            publisher.publish()
        self.assertEqual([], publisher.image_rows)
        self.assertTrue(all(row['status'] == 'Running' for row in publisher.instances))
        self.assertFalse(any(call[0] == 'stop' for call in publisher.calls))

    def test_lost_publish_response_cleans_owned_outputs_and_preserves_candidate(self):
        publisher = FakePublisher()
        foreign = {'fingerprint': 'f' * 64, 'properties': {'user.orbit.template.id': str(uuid.uuid4())}}
        publisher.image_rows.append(foreign)
        publisher.fail_publish = True
        with self.assertRaises(Refusal):
            publisher.publish()
        self.assertEqual(publisher.image_rows, [foreign])
        self.assertEqual(publisher.volume_rows, [])
        self.assertEqual([row['status'] for row in publisher.instances], ['Stopped', 'Stopped'])

    def test_cleanup_refuses_drift_or_foreign_attachments(self):
        for drift in ('owner', 'used_by'):
            with self.subTest(drift=drift):
                publisher = FakePublisher()
                publisher.publish()
                if drift == 'owner':
                    publisher.volume_rows[0]['config']['user.orbit.template.owner'] = 'foreign'
                else:
                    publisher.volume_rows[0]['used_by'] = ['/1.0/instances/foreign']
                with self.assertRaises(Refusal):
                    publisher.cleanup()
                self.assertEqual(len(publisher.volume_rows), 1)

    def test_query_passes_project_in_url_and_keeps_incus_output_private(self):
        publisher = Publisher(FakePublisher().request)
        with patch('subprocess.run', return_value=SimpleNamespace(returncode=0, stdout='[]')) as run:
            self.assertEqual(publisher.query('/1.0/images?recursion=1'), [])
            self.assertEqual(run.call_args.args[0], ['incus', '--force-local', 'query', '/1.0/images?recursion=1&project=disposable'])
        with patch('subprocess.run', return_value=SimpleNamespace(returncode=1, stdout='secret')):
            with self.assertRaises(Refusal) as caught:
                publisher.query('/1.0/images')
            self.assertNotIn('secret', str(caught.exception))

    def test_input_rejects_extra_fields_default_scope_and_injection(self):
        good = FakePublisher().request
        for changes in ({'project': 'default'}, {'pool': '../outside'}, {'extra': True},
                        {'source_template': {**good['source_template'], 'base': 'main\n[include]'}}):
            with self.assertRaises(ValueError):
                Publisher({**good, **changes})


class GuestAudit(unittest.TestCase):
    def test_detects_token_across_read_boundary_and_credential_environment(self):
        with tempfile.TemporaryDirectory() as scratch:
            root = Path(scratch)
            files, proc = root / 'files', root / 'proc'
            files.mkdir(); proc.mkdir()
            file = files / 'unknown'
            file.write_bytes(b'x' * (1024 * 1024 - 10) + b'ghp_' + b'a' * 36)
            with self.assertRaises(ValueError):
                audit([files], proc, False)
            file.write_text('clean')
            (proc / '123').mkdir()
            env = proc / '123/environ'
            env.write_bytes(b'GH_TOKEN=secret-with-unknown-format\0')
            with self.assertRaises(ValueError):
                audit([files], proc, False)
            env.write_bytes(b'HOME=/home/orbit\0GH_TOKEN=\0')
            self.assertTrue(audit([files], proc, False)['ready'])

    def test_refuses_task_state_and_auth_files_without_leaking_contents(self):
        for relative in ('.git/orbit-sandbox-source.json', '.config/gh/hosts.yml', '.pi/auth.json', '.git-credentials'):
            with self.subTest(path=relative), tempfile.TemporaryDirectory() as scratch:
                root = Path(scratch)
                path = root / relative
                path.parent.mkdir(parents=True, exist_ok=True)
                path.write_text('do-not-print-secret')
                with self.assertRaises(ValueError) as caught:
                    audit([root], root / 'no-processes', False)
                self.assertNotIn('do-not-print-secret', str(caught.exception))


unittest.main()
