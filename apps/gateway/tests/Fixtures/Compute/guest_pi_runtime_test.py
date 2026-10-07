import importlib.util
import json
import os
from pathlib import Path
import sys
import tempfile
import unittest

spec = importlib.util.spec_from_file_location('runtime', sys.argv.pop(1))
runtime = importlib.util.module_from_spec(spec)
spec.loader.exec_module(runtime)


class RuntimeBoundary(unittest.TestCase):
    def test_empty_pi_auth_file_is_reusable_but_credentials_are_refused(self):
        with tempfile.TemporaryDirectory() as directory:
            path = Path(directory) / 'auth.json'
            runtime.validate_auth(path, os.geteuid())
            path.write_text('{}')
            path.chmod(0o600)
            runtime.validate_auth(path, os.geteuid())
            path.write_text(json.dumps({'provider': {'type': 'oauth', 'access': 'proof-secret'}}))
            with self.assertRaises(ValueError):
                runtime.validate_auth(path, os.geteuid())
            path.write_text('{}')
            path.chmod(0o644)
            with self.assertRaises(ValueError):
                runtime.validate_auth(path, os.geteuid())
            path.unlink()
            path.symlink_to(Path(directory) / 'missing')
            with self.assertRaises(ValueError):
                runtime.validate_auth(path, os.geteuid())

    def test_loopback_forwarding_only_targets_a_group_bridge(self):
        request = {'sandbox_id': 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'checkout': '/home/orbit/orbit',
                   'pi_token': 'a' * 64, 'model_key': 'b' * 64,
                   'models': [{'id': 'probe', 'name': 'Proof', 'reasoning': False, 'input': ['text'], 'contextWindow': 8192, 'maxTokens': 1024}]}
        request['model_relay_address'] = '10.233.201.1'
        runtime.validate(request)
        for address in ['127.0.0.1', '10.44.0.3', '10.233.201.2', '169.254.169.254', 'example.test']:
            request['model_relay_address'] = address
            with self.subTest(address=address), self.assertRaises(ValueError):
                runtime.validate(request)

    def test_cloud_relay_requires_an_explicit_private_endpoint_and_valid_port(self):
        request = {'sandbox_id': 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'checkout': '/home/orbit/orbit',
                   'pi_token': 'a' * 64, 'model_key': 'b' * 64,
                   'models': [{'id': 'probe', 'name': 'Proof', 'reasoning': False, 'input': ['text'], 'contextWindow': 8192, 'maxTokens': 1024}],
                   'model_relay_kind': 'upcloud', 'model_relay_address': '10.44.0.3', 'model_relay_port': 8320}
        runtime.validate(request)
        for address in ['127.0.0.1', '169.254.169.254', '10.233.201.1', 'example.test', None]:
            with self.subTest(address=address), self.assertRaises(ValueError):
                runtime.validate({**request, 'model_relay_address': address})
        for port in [0, 65536, True, '8317']:
            with self.subTest(port=port), self.assertRaises(ValueError):
                runtime.validate({**request, 'model_relay_port': port})

    def test_model_descriptors_cannot_supply_another_endpoint_or_key(self):
        request = {'sandbox_id': 'ca656ccf-240d-476c-90f1-cf70f9dd7a12', 'checkout': '/home/orbit/orbit',
                   'pi_token': 'a' * 64, 'model_key': 'b' * 64,
                   'models': [{'id': 'probe', 'name': 'Proof', 'reasoning': False, 'input': ['text'], 'contextWindow': 8192, 'maxTokens': 1024}]}
        runtime.validate(request)
        request['models'][0]['baseUrl'] = 'http://other.test/v1'
        with self.assertRaises(ValueError):
            runtime.validate(request)
        del request['models'][0]['baseUrl']
        request['models'][0]['id'] = 'claude-proof'
        with self.assertRaises(ValueError):
            runtime.validate(request)


unittest.main()
