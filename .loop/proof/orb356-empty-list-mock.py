#!/usr/bin/env python3
"""Isolated empty instance list. Does not touch the real registry."""
import argparse
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
import json
from pathlib import Path
import ssl
import threading

REQUEST_ID = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeeeee'


def main():
    parser = argparse.ArgumentParser()
    parser.add_argument('--cert', required=True)
    parser.add_argument('--key', required=True)
    parser.add_argument('--state', required=True)
    parser.add_argument('--port', type=int, default=0)
    args = parser.parse_args()

    class Handler(BaseHTTPRequestHandler):
        protocol_version = 'HTTP/1.1'

        def do_GET(self):
            path = self.path.split('?', 1)[0].rstrip('/')
            if path == '/api/v1/instances':
                payload = json.dumps({'data': [], 'meta': {'request_id': REQUEST_ID}}).encode()
                self.send_response(200)
                self.send_header('Content-Type', 'application/json')
                self.send_header('X-Orbit-Request-Id', REQUEST_ID)
                self.send_header('Content-Length', str(len(payload)))
                self.end_headers()
                self.wfile.write(payload)
                return
            self.send_response(404)
            self.end_headers()

        def log_message(self, format, *a):
            return

    server = ThreadingHTTPServer(('127.0.0.1', args.port), Handler)
    tls = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
    tls.load_cert_chain(args.cert, args.key)
    server.socket = tls.wrap_socket(server.socket, server_side=True)
    port = server.server_address[1]
    Path(args.state).write_text(json.dumps({'port': port, 'request_id': REQUEST_ID}) + '\n')
    threading.Thread(target=server.serve_forever, daemon=True).start()
    threading.Event().wait()


if __name__ == '__main__':
    main()
