#!/usr/bin/env python3
"""Forward clone POST to the real Gateway; inject 503 only for that clone's releases GET."""
import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import ssl
import threading
import urllib.error
import urllib.request

state = {'ids': set(), 'log': []}


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--upstream', required=True)
    parser.add_argument('--upstream-ca', required=True)
    parser.add_argument('--cert', required=True)
    parser.add_argument('--key', required=True)
    parser.add_argument('--port', type=int, default=0)
    parser.add_argument('--state', required=True)
    args = parser.parse_args()
    upstream = args.upstream.rstrip('/')
    ctx = ssl.create_default_context(cafile=args.upstream_ca)
    log_path = Path(args.state).with_name('proxy-log.json')

    def persist():
        Path(args.state).write_text(json.dumps({'port': port, 'ids': sorted(state['ids']), 'log': state['log']}) + '\n')
        log_path.write_text(json.dumps(state['log'], indent=2) + '\n')

    class Handler(BaseHTTPRequestHandler):
        protocol_version = 'HTTP/1.1'

        def do_POST(self):
            self.forward()

        def do_GET(self):
            self.forward()

        def do_PUT(self):
            self.forward()

        def do_DELETE(self):
            self.forward()

        def log_message(self, format, *log_args):
            return

        def forward(self):
            length = int(self.headers.get('Content-Length') or 0)
            body = self.rfile.read(length) if length else b''
            path = self.path.split('?', 1)[0]
            parts = path.strip('/').split('/')
            clone_id = None
            if self.command == 'GET' and len(parts) == 5 and parts[:3] == ['api', 'v1', 'instances'] and parts[4] == 'releases' and parts[3].isdigit():
                clone_id = int(parts[3])
            if clone_id is not None and clone_id in state['ids']:
                request_id = '0198e15c-bf97-7c23-8f1f-61b8fe67a845'
                payload = json.dumps({'error': {'code': 'deployment.releases_unavailable', 'message': 'Releases are unavailable.', 'request_id': request_id}}).encode()
                self.send_response(503)
                self.send_header('Content-Type', 'application/json')
                self.send_header('X-Orbit-Request-Id', request_id)
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)
                state['log'].append({'method': 'GET', 'path': path, 'id': clone_id, 'status': 503, 'provenance': 'fixture-injected', 'request_id': request_id})
                persist()
                return
            request = urllib.request.Request(
                upstream + self.path,
                data=body if body else None,
                method=self.command,
                headers={key: value for key, value in self.headers.items() if key.lower() not in {'host', 'content-length'}},
            )
            try:
                with urllib.request.urlopen(request, context=ctx, timeout=240) as response:
                    payload = response.read()
                    status = response.status
                    headers = dict(response.headers)
            except urllib.error.HTTPError as error:
                payload = error.read()
                status = error.code
                headers = dict(error.headers)
            if self.command == 'POST' and path.endswith('/clone') and 200 <= status < 300:
                try:
                    decoded = json.loads(payload)
                    ident = decoded.get('data', decoded)
                    clone_id = ident.get('id') if isinstance(ident, dict) else None
                    request_id = None
                    for key, value in headers.items():
                        if key.lower() == 'x-orbit-request-id':
                            request_id = value
                            break
                    if not request_id and isinstance(decoded, dict):
                        request_id = (decoded.get('meta') or {}).get('request_id')
                except json.JSONDecodeError:
                    clone_id = None
                    request_id = None
                if isinstance(clone_id, int):
                    state['ids'].add(clone_id)
                    state['log'].append({'method': 'POST', 'path': path, 'id': clone_id, 'status': status, 'provenance': 'forwarded-real', 'request_id': request_id})
                    persist()
            self.send_response(status)
            for key, value in headers.items():
                if key.lower() in {'transfer-encoding', 'content-length', 'connection'}:
                    continue
                self.send_header(key, value)
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            self.wfile.write(payload)

    server = ThreadingHTTPServer(('127.0.0.1', args.port), Handler)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(args.cert, args.key)
    server.socket = tls.wrap_socket(server.socket, server_side=True)
    port = server.server_address[1]
    persist()
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    thread.join()


if __name__ == '__main__':
    main()
