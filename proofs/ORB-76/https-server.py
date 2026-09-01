#!/usr/bin/env python3

import http.server
import os
import ssl
import sys


root, certificate, key, port = sys.argv[1:]
os.chdir(root)
server = http.server.ThreadingHTTPServer(("0.0.0.0", int(port)), http.server.SimpleHTTPRequestHandler)
context = ssl.SSLContext(ssl.PROTOCOL_TLS_SERVER)
context.load_cert_chain(certificate, key)
server.socket = context.wrap_socket(server.socket, server_side=True)
server.serve_forever()
