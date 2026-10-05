"""Disposable loopback transport fixture; never contacts an S3 service."""
import http.server
import json
import os
import socketserver
import sys
import time

mode = sys.argv[1]
state_path = sys.argv[2] if len(sys.argv) > 2 else None
objects = {}
requests = []


class TlsStall(socketserver.BaseRequestHandler):
    def handle(self):
        time.sleep(20)


class ProbeHandler(http.server.BaseHTTPRequestHandler):
    def record(self, status):
        requests.append({"method": self.command, "key": self.path, "status": status,
                         "acl": self.headers.get("x-amz-acl"),
                         "authorized": self.headers.get("Authorization", "").startswith("AWS4-HMAC-SHA256 ")})
        if state_path:
            with os.fdopen(os.open(state_path, os.O_WRONLY | os.O_CREAT | os.O_TRUNC, 0o600), "w") as state:
                json.dump({"requests": requests, "object_count": len(objects)}, state)

    def do_PUT(self):
        objects[self.path] = self.rfile.read(int(self.headers.get("Content-Length", "0")))
        self.record(200)
        self.send_response(200)
        self.send_header("Content-Length", "0")
        self.end_headers()

    def do_GET(self):
        body = objects.get(self.path, b"a" * 32)
        status = 200
        if mode.startswith("get-"):
            status = int(mode.removeprefix("get-").split("-")[0])
            code = {403: "AccessDenied", 404: "NoSuchKey", 500: "InternalError"}[status]
            if mode.endswith("-bucket"):
                code = "NoSuchBucket"
            body = ("<Error><Code>" + code + "</Code><Message>fixture-access fixture-secret</Message></Error>").encode()
            if mode.endswith("-oversized"):
                body += b"a" * (1024 * 1024)
        if mode == "oversized":
            body = b"a" * (1024 * 1024)
        elif mode == "trailing":
            body += b"b"
        self.record(status)
        self.send_response(status)
        if status != 200:
            self.send_header("Content-Type", "application/xml")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        try:
            if mode in ("body-stall", "body-timeout"):
                self.wfile.write(body[:8])
                self.wfile.flush()
                time.sleep(35 if mode == "body-timeout" else 20)
            else:
                for offset in range(0, len(body), 8 if mode == "short" else 4096):
                    self.wfile.write(body[offset:offset + (8 if mode == "short" else 4096)])
                    self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError):
            pass

    def do_DELETE(self):
        status = 204
        body = b""
        if mode == "oversized":
            status = 500
            body = b"a" * (1024 * 1024)
        elif mode.startswith("delete-"):
            status = int(mode.removeprefix("delete-"))
            code = {403: "AccessDenied", 404: "NoSuchKey", 500: "InternalError", 302: "Redirect"}[status]
            body = ("<Error><Code>" + code + "</Code><Message>fixture-access fixture-secret</Message></Error>").encode()
        if status == 204:
            objects.pop(self.path, None)
        self.record(status)
        self.send_response(status)
        if status == 302:
            self.send_header("Location", "/redirect-target")
        self.send_header("Content-Type", "application/xml")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        try:
            self.wfile.write(body)
            self.wfile.flush()
        except (BrokenPipeError, ConnectionResetError):
            pass

    def log_message(self, *args):
        pass


server = (socketserver.ThreadingTCPServer if mode == "tls-stall" else http.server.ThreadingHTTPServer)(
    ("127.0.0.1", 0), TlsStall if mode == "tls-stall" else ProbeHandler
)
server.daemon_threads = True
print(server.server_address[1], flush=True)
server.serve_forever()
