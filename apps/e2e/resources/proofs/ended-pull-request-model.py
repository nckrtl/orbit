#!/usr/bin/env python3
"""Deterministic model endpoint for a REAL Pi session; never a driver double.

The first model request waits at a file barrier. This lets the proof merge the
substituted PR while Pi really has a turn in flight. Later requests finish at once.
No model credentials or external model service are needed on a fresh lease.
"""
import json
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path

root = Path(sys.argv[1])


class Handler(BaseHTTPRequestHandler):
    def do_POST(self):
        if self.path != "/v1/chat/completions":
            self.send_error(404)
            return
        data = json.loads(self.rfile.read(int(self.headers["Content-Length"])))
        if data.get("model") != "proof-model" or not data.get("stream"):
            self.send_error(400)
            return
        if not (root / "waiting").exists():
            (root / "waiting").touch()
            deadline = time.monotonic() + 600
            while not (root / "release").exists():
                if time.monotonic() > deadline:
                    self.send_error(504)
                    return
                time.sleep(0.1)
        self.send_response(200)
        self.send_header("Content-Type", "text/event-stream")
        self.end_headers()
        for delta, finish in [({"role": "assistant", "content": "Fixture turn finished."}, None), ({}, "stop")]:
            chunk = {"id": "proof", "object": "chat.completion.chunk", "created": int(time.time()),
                     "model": "proof-model", "choices": [{"index": 0, "delta": delta, "finish_reason": finish}]}
            self.wfile.write(("data: " + json.dumps(chunk) + "\n\n").encode())
        self.wfile.write(b"data: [DONE]\n\n")
        self.wfile.flush()


ThreadingHTTPServer(("127.0.0.1", 18080), Handler).serve_forever()
