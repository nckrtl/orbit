import copy
from contextlib import nullcontext
import json
from pathlib import Path
import runpy
import sys
import unittest
from unittest.mock import patch

module = runpy.run_path(sys.argv.pop(1))
ProjectImage, Refusal = module['ProjectImage'], module['Refusal']


def request():
    return {'project': 'orbit-sandbox-proof-test', 'pool': 'proof', 'sandbox_id': '54f71ca2-0e77-48bd-bac8-d60c9b5e3bd3',
            'budget': 9, 'project_slug': 'dlf', 'base_image': 'a' * 64,
            'source_template': {'id': 'e2d3499f-37bc-4e2e-91c0-1d6fd13a11a2', 'repository': 'https://github.com/nckrtl/orbit.git',
                                'base': 'main', 'commit': 'b' * 40}}


class FakeImage(ProjectImage):
    def __init__(self):
        super().__init__(request())
        self.source = {'fingerprint': 'a' * 64, 'type': 'virtual-machine', 'architecture': 'x86_64', 'public': False,
                       'aliases': [], 'properties': {**self.metadata, 'user.orbit.template.role': 'app-dev'}}
        self.guests, self.images_out, self.calls = [], [], []
        self.ready = True

    def query(self, path, method='GET', data=None):
        self.calls.append((path, method))
        if path.startswith('/1.0/images/'):
            return copy.deepcopy(self.source)
        if path == '/1.0/instances?recursion=1':
            return copy.deepcopy(self.guests)
        if path == '/1.0/images?recursion=1':
            return copy.deepcopy(self.images_out)
        if path == '/1.0/instances' and method == 'POST':
            inherited = {'image.' + key: value for key, value in self.source['properties'].items()}
            self.guests.append({**data, 'status': 'Stopped', 'config': {**inherited, **data['config'], 'volatile.base_image': self.build['base_image']},
                                'expanded_devices': data['devices']})
            return None
        if path == '/1.0/instances/' + self.name:
            return copy.deepcopy(next(value for value in self.guests if value['name'] == self.name))
        raise AssertionError(path)

    def run(self, *args, **kwargs):
        self.calls.append(args)
        if args[0] == 'query':
            return json.dumps({'config': {'user.orbit.compute.owner': 'orbit-task-sandbox', 'features.networks': 'false'}})
        if args[0] in ('start', 'stop'):
            next(value for value in self.guests if value['name'] == self.name)['status'] = 'Running' if args[0] == 'start' else 'Stopped'
        if args[:2] == ('config', 'unset'):
            next(value for value in self.guests if value['name'] == self.name)['config'].pop(args[3])
        if args[0] == 'delete':
            self.guests = [value for value in self.guests if value['name'] != self.name]
        if args[0] == 'publish':
            guest = next(value for value in self.guests if value['name'] == self.name)
            inherited = {key[6:]: value for key, value in guest['config'].items() if key.startswith('image.')}
            self.images_out.append({'fingerprint': 'c' * 64, 'public': False, 'aliases': [], 'type': 'virtual-machine',
                                    'architecture': 'x86_64', 'properties': {**inherited, **dict(arg.split('=', 1) for arg in args[4:])}})
        if args[0] == 'exec' and args[-1] != 'true':
            return json.dumps({'ready': self.ready, 'role': 'app-dev'})
        return ''

    def prepare(self):
        original = runpy.run_path
        def helper(path, **kwargs):
            if str(path).endswith('incus-sandbox.py'):
                return {'sandbox_lock': lambda _: nullcontext()}
            return original(path, **kwargs)
        with patch('runpy.run_path', helper):
            return super().prepare()


class ProjectImageTest(unittest.TestCase):
    def test_owns_an_offline_guest_and_publishes_only_a_new_private_project_image(self):
        image = FakeImage()
        self.assertTrue(image.prepare()['prepared'])
        self.assertEqual({'root'}, set(image.guests[0]['expanded_devices']))
        self.assertEqual('20GiB', image.guests[0]['expanded_devices']['root']['size'])
        result = image.publish()
        self.assertTrue(result['published'])
        self.assertEqual('c' * 64, result['image'])
        self.assertFalse(any(key.startswith('user.orbit.template.') for key in image.images_out[0]['properties']))
        self.assertTrue(image.destroy()['destroyed'])
        self.assertEqual(1, len(image.images_out))
        self.assertTrue(image.destroy()['destroyed'])

    def test_refuses_public_aliased_or_unpinned_workload_bases_before_allocation(self):
        for change in ({'public': True}, {'aliases': [{'name': 'moving'}]}, {'architecture': 'aarch64'},
                       {'properties': {'user.orbit.template.role': 'operator'}}):
            image = FakeImage()
            image.source.update(change)
            with self.subTest(change=change), self.assertRaises(Refusal):
                image.prepare()
            self.assertEqual([], image.guests)

    def test_refuses_changed_guest_devices_and_ownership_before_audit_or_delete(self):
        for change in ({'profiles': ['default']}, {'name': 'foreign'}, {'config': {}},
                       {'expanded_devices': {'root': {'type': 'disk', 'path': '/', 'pool': 'foreign', 'size': '20GiB'}}}):
            image = FakeImage()
            image.prepare()
            image.guests[0].update(change)
            calls = len(image.calls)
            with self.subTest(change=change), self.assertRaises(Refusal):
                image.publish()
            with self.assertRaises(Refusal):
                image.destroy()
            self.assertFalse(any(call[0] in ('exec', 'stop', 'publish', 'delete') for call in image.calls[calls:]))

    def test_failed_guest_audit_keeps_owned_compute_without_publishing(self):
        image = FakeImage()
        image.prepare()
        image.ready = False
        with self.assertRaises(Refusal):
            image.publish()
        self.assertEqual([], image.images_out)
        self.assertEqual('Running', image.guests[0]['status'])

    def test_budget_includes_unrelated_guests_and_does_not_remove_them(self):
        image = FakeImage()
        image.guests = [{'name': 'foreign-' + str(index), 'type': 'virtual-machine'} for index in range(9)]
        with self.assertRaises(Refusal):
            image.prepare()
        self.assertEqual(9, len(image.guests))

    def test_parked_guests_preserve_inventory_without_consuming_running_capacity(self):
        image = FakeImage()
        image.guests = [{'name': 'parked-' + str(index), 'type': 'virtual-machine', 'status': 'Stopped'} for index in range(12)]
        image.guests += [{'name': 'running-' + str(index), 'type': 'virtual-machine', 'status': 'Running'} for index in range(8)]
        before = copy.deepcopy(image.guests)
        self.assertTrue(image.prepare()['prepared'])
        self.assertTrue(image.publish()['published'])
        self.assertTrue(image.destroy()['destroyed'])
        self.assertEqual(before, image.guests)

    def test_changed_inherited_template_properties_refuse_publication_without_relabeling(self):
        image = FakeImage()
        image.prepare()
        image.guests[0]['config']['image.user.orbit.template.id'] = 'foreign'
        with self.assertRaises(Refusal):
            image.publish()
        self.assertEqual([], image.images_out)
        self.assertEqual('Running', image.guests[0]['status'])

    def test_duplicate_outputs_refuse_another_publication(self):
        image = FakeImage()
        image.prepare()
        image.publish()
        with self.assertRaises(Refusal):
            image.publish()
        self.assertEqual(1, len(image.images_out))

    def test_request_is_closed_before_resource_work(self):
        for change in ({'project_slug': 'orbit'}, {'budget': True}, {'base_image': 'moving-alias'}, {'network': 'host'}):
            with self.subTest(change=change), self.assertRaises(Refusal):
                ProjectImage({**request(), **change})


unittest.main()
