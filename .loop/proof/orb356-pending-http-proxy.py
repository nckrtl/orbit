#!/usr/bin/env python3
"""Forward Gateway traffic; delay GET /api/v1/instances after recording arrival."""
import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import ssl
import threading
import time
import urllib.error
import urllib.request

def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--upstream', required=True)
    parser.add_argument('--upstream-ca', required=True)
    parser.add_argument('--cert', required=True)
    parser.add_argument('--key', required=True)
    parser.add_argument('--marker', required=True)
    parser.add_argument('--delay', type=float, default=8.0)
    parser.add_argument('--state', required=True)
    parser.add_argument('--port', type=int, default=0)
    args = parser.parse_args()
    upstream = args.upstream.rstrip('/')
    ctx = ssl.create_default_context(cafile=args.upstream_ca)
    log = []

    def persist(port):
        Path(args.state).write_text(json.dumps({'port': port, 'log': log}) + '\n')

    class Handler(BaseHTTPRequestHandler):
        protocol_version = 'HTTP/1.1'

        def do_GET(self):
            self.forward()

        def do_POST(self):
            self.forward()

        def do_PUT(self):
            self.forward()

        def do_DELETE(self):
            self.forward()

        def log_message(self, format, *a):
            return

        def forward(self):
            length = int(self.headers.get('Content-Length') or 0)
            body = self.rfile.read(length) if length else b''
            path = self.path.split('?', 1)[0]
            if self.command == 'GET' and path.rstrip('/') == '/api/v1/instances':
                arrived = time.time()
                Path(args.marker).write_text(json.dumps({'arrived': arrived, 'path': path}) + '\n')
                log.append({'event': 'arrived', 'path': path, 'at': arrived, 'provenance': 'proxy-hold-before-upstream'})
                persist(port)
                time.sleep(args.delay)
                log.append({'event': 'delay-elapsed', 'at': time.time(), 'delay': args.delay})
                persist(port)
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
            except (BrokenPipeError, ConnectionResetError, urllib.error.URLError) as error:
                log.append({'event': 'client-gone', 'error': type(error).__name__, 'at': time.time()})
                persist(port)
                return
            self.send_response(status)
            for key, value in headers.items():
                if key.lower() in {'transfer-encoding', 'content-length', 'connection'}:
                    continue
                self.send_header(key, value)
            self.send_header('Content-Length', str(len(payload)))
            self.end_headers()
            try:
                self.wfile.write(payload)
            except BrokenPipeError:
                log.append({'event': 'write-after-interrupt', 'at': time.time()})
                persist(port)

    server = ThreadingHTTPServer(('127.0.0.1', args.port), Handler)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(args.cert, args.key)
    server.socket = tls.wrap_socket(server.socket, server_side=True)
    port = server.server_address[1]
    persist(port)
    threading.Thread(target=server.serve_forever, daemon=True).start()
    threading.Event().wait()


if __name__ == '__main__':
    main()
