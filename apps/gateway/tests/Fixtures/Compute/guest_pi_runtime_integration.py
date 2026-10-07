"""Run only in an operator-owned disposable Linux VM with systemd and a real Pi ELF."""
import hashlib
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
import os
from pathlib import Path
import secrets
import shutil
import subprocess
import sys
import tempfile
import threading
import unittest
import uuid

BINARY, ARTIFACT_HELPER, RUNTIME_HELPER = map(Path, sys.argv[1:4])
del sys.argv[1:4]


class GuestRuntimeIntegration(unittest.TestCase):
    def test_real_pi_and_model_relay_are_authenticated_and_reusable(self):
        self.assertEqual(os.geteuid(), 0)
        subprocess.run(['useradd', '--create-home', '--shell', '/bin/bash', 'orbit'], check=True)
        subprocess.run(['install', '-d', '-o', 'orbit', '-g', 'orbit', '/home/orbit/orbit'], check=True)
        subprocess.run(['ip', 'address', 'add', '10.44.0.3/32', 'dev', 'lo'], check=True)
        identity = str(uuid.uuid4())
        checksum = hashlib.sha256()
        with BINARY.open('rb') as source:
            for chunk in iter(lambda: source.read(1024 * 1024), b''):
                checksum.update(chunk)
        header = {'sandbox_id': identity, 'sha256': checksum.hexdigest(), 'size': BINARY.stat().st_size}
        with tempfile.TemporaryFile() as spool:
            spool.write(json.dumps(header).encode() + b'\n')
            with BINARY.open('rb') as source:
                shutil.copyfileobj(source, spool)
            spool.seek(0)
            first = subprocess.run(['python3', '-I', str(ARTIFACT_HELPER)], stdin=spool, capture_output=True, check=True)
            self.assertEqual(json.loads(first.stdout)['sha256'], header['sha256'])
            inode = Path('/usr/local/bin/orbit-pi-server').stat().st_ino
            spool.seek(0)
            subprocess.run(['python3', '-I', str(ARTIFACT_HELPER)], stdin=spool, capture_output=True, check=True)
            self.assertEqual(Path('/usr/local/bin/orbit-pi-server').stat().st_ino, inode)
        key = secrets.token_hex(32)
        class Model(BaseHTTPRequestHandler):
            def do_GET(self):
                valid = self.path == '/v1/models' and self.headers.get('Authorization') == 'Bearer ' + key
                self.send_response(200 if valid else 401)
                self.end_headers()
                self.wfile.write(b'{"object":"list","data":[]}')
            def log_message(self, *args):
                pass
        server = ThreadingHTTPServer(('10.44.0.3', 8320), Model)
        threading.Thread(target=server.serve_forever, daemon=True).start()
        self.addCleanup(server.server_close)
        self.addCleanup(server.shutdown)
        self.addCleanup(lambda: subprocess.run(['systemctl', 'stop', 'orbit-sandbox-pi.service', 'orbit-sandbox-model.socket', 'orbit-sandbox-model.service'], capture_output=True))
        request = {'sandbox_id': identity, 'checkout': '/home/orbit/orbit', 'pi_token': secrets.token_hex(32), 'model_key': key,
                   'models': [{'id': 'probe', 'name': 'Proof', 'reasoning': False, 'input': ['text'], 'contextWindow': 8192, 'maxTokens': 1024}],
                   'model_relay_kind': 'upcloud', 'model_relay_address': '10.44.0.3', 'model_relay_port': 8320}
        def prepare(value):
            return subprocess.run(['python3', '-I', str(RUNTIME_HELPER)], input=json.dumps(value).encode(), capture_output=True)
        first = prepare(request)
        self.assertEqual(first.returncode, 0, first.stdout)
        self.assertEqual(json.loads(first.stdout), {'sandbox_id': identity, 'ready': True})
        pid = subprocess.check_output(['systemctl', 'show', 'orbit-sandbox-pi.service', '--property=MainPID', '--value'])
        second = prepare(request)
        self.assertEqual(second.returncode, 0, second.stdout)
        self.assertEqual(subprocess.check_output(['systemctl', 'show', 'orbit-sandbox-pi.service', '--property=MainPID', '--value']), pid)
        request['model_relay_port'] = 8317
        drift = prepare(request)
        self.assertNotEqual(drift.returncode, 0)
        self.assertEqual(json.loads(drift.stdout), {'error': 'sandbox_pi_setup_failed'})
        self.assertNotIn(key.encode(), drift.stdout + drift.stderr)
        print('Real Pi readiness, model authentication, retry, and relay drift checks passed.')


unittest.main()
