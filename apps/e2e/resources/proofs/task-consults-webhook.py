#!/usr/bin/env python3
"""Disposable local webhook receiver for the TASK-753 / subtask-760 Incus proof."""

import hashlib
import hmac
import json
from http.server import BaseHTTPRequestHandler, HTTPServer
from pathlib import Path

ROOT = Path("/home/orbit/task-760-proof")
SECRET = b"task-760-disposable-webhook-secret"
OWNER = json.loads((ROOT / "owner.json").read_text())
if OWNER != {
    "issue": "TASK-753",
    "subtask": 760,
    "lease": "0fe465d650e58420fa3ee73124991aea",
}:
    raise SystemExit("Refusing a receiver without the exact fixture owner")
if (ROOT / "webhooks.jsonl").exists():
    raise SystemExit("Refusing to overwrite webhook evidence")


class Receiver(BaseHTTPRequestHandler):
    def do_POST(self):
        size = int(self.headers.get("Content-Length", "0"))
        if self.client_address[0] != "127.0.0.1" or not 0 < size < 8192:
            self.send_error(400)
            return
        raw = self.rfile.read(size)
        timestamp = self.headers.get("X-Orbit-Timestamp", "")
        signature = self.headers.get("X-Orbit-Signature", "")
        expected = "sha256=" + hmac.new(
            SECRET, timestamp.encode() + b"." + raw, hashlib.sha256
        ).hexdigest()
        valid = timestamp.isdecimal() and hmac.compare_digest(signature, expected)
        body = json.loads(raw)
        record = {"timestamp": timestamp, "signature_valid": valid, "body": body}
        with (ROOT / "webhooks.jsonl").open("a") as output:
            output.write(json.dumps(record) + "\n")
        print(json.dumps(record), flush=True)
        self.send_response(200 if valid else 403)
        self.send_header("Content-Length", "0")
        self.end_headers()


print("TASK-760 receiver listening on 127.0.0.1:18761", flush=True)
HTTPServer(("127.0.0.1", 18761), Receiver).serve_forever()
