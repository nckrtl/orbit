#!/usr/bin/env python3
"""Orbit proxycli collector and CodexBar HTTP surface.

The process is the only CLIProxyAPI quota poller. It writes snapshots, backoff,
and a distributed lock into shared Valkey. HTTP reads never fetch upstream.
"""

from __future__ import annotations

import json
import os
import socket
import threading
import time
import urllib.error
import urllib.request
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

LOCK_KEY = "orbit:proxycli:lock"
RAW_KEY = "orbit:proxycli:raw"
SNAPSHOT_KEY = "orbit:proxycli:snapshot"
BACKOFF_PREFIX = "orbit:proxycli:backoff:"
DEFAULT_BACKOFF_SECONDS = 60
USAGE_URLS = {
    "claude": "https://api.anthropic.com/api/oauth/usage",
    "codex": "https://chatgpt.com/backend-api/wham/usage",
    "grok": "https://cli-chat-proxy.grok.com/v1/billing?format=credits",
    "kimi": "https://api.kimi.com/coding/v1/usages",
}
POLL_SECONDS = 300


def env(name: str, default: str = "") -> str:
    return os.environ.get(name, default)


class Valkey:
    def __init__(self, host: str, port: int, username: str, password: str) -> None:
        self.host = host
        self.port = port
        self.username = username
        self.password = password

    def _command(self, *parts: str) -> object:
        payload = f"*{len(parts)}\r\n".encode()
        for part in parts:
            encoded = part.encode()
            payload += f"${len(encoded)}\r\n".encode() + encoded + b"\r\n"
        sock = socket.create_connection((self.host, self.port), timeout=3)
        reader = sock.makefile("rb")
        try:
            if self.password:
                if self.username:
                    sock.sendall(self._encode("AUTH", self.username, self.password))
                else:
                    sock.sendall(self._encode("AUTH", self.password))
                self._read_reply(reader)
            sock.sendall(payload)
            return self._read_reply(reader)
        finally:
            reader.close()
            sock.close()

    def _encode(self, *parts: str) -> bytes:
        payload = f"*{len(parts)}\r\n".encode()
        for part in parts:
            encoded = part.encode()
            payload += f"${len(encoded)}\r\n".encode() + encoded + b"\r\n"
        return payload

    def _decode(self, source: socket.socket) -> object:
        reader = source if hasattr(source, "readline") else source.makefile("rb")
        return self._read_reply(reader)

    def _read_reply(self, reader: object) -> object:
        line = reader.readline()
        if not line:
            return None
        kind, payload = line[:1], line[1:-2]
        if kind == b"+":
            return payload.decode()
        if kind == b":":
            return int(payload)
        if kind == b"$":
            length = int(payload)
            if length < 0:
                return None
            data = reader.read(length + 2)
            if len(data) < length + 2:
                raise RuntimeError("valkey bulk truncated")
            return data[:length].decode()
        if kind == b"-":
            raise RuntimeError("valkey error")
        return None

    def get(self, key: str) -> str | None:
        value = self._command("GET", key)
        return value if isinstance(value, str) else None

    def set(self, key: str, value: str, seconds: int | None = None) -> None:
        if seconds is None:
            self._command("SET", key, value)
            return
        self._command("SET", key, value, "EX", str(seconds))

    def acquire(self, key: str, holder: str, seconds: int) -> bool:
        reply = self._command("SET", key, holder, "NX", "EX", str(seconds))
        return reply == "OK" or self.get(key) == holder

    def release(self, key: str, holder: str) -> None:
        if self.get(key) == holder:
            self._command("DEL", key)


class CollectorHttpError(Exception):
    def __init__(self, status: int, retry_after: int | None = None) -> None:
        super().__init__(f"HTTP {status}")
        self.status = status
        self.retry_after = retry_after


def duration_label(seconds: int) -> str:
    if seconds % 86400 == 0:
        return f"{seconds // 86400}d"
    if seconds % 3600 == 0:
        return f"{seconds // 3600}h"
    if seconds % 60 == 0:
        return f"{seconds // 60}m"
    return f"{seconds}s"


def window_rank(label: str) -> int:
    if label.endswith(("d", "w")):
        return 0
    if label.endswith(("h", "m", "s")):
        return 1
    return 2


def sort_windows(windows: list[dict[str, object]]) -> list[dict[str, object]]:
    return sorted(windows, key=lambda item: (window_rank(str(item["label"])), str(item["label"])))


class Collector:
    def __init__(self, cache: Valkey | None) -> None:
        self.cache = cache
        self.holder = "proxycli"

    def collect(self) -> None:
        if self.cache is None or not self.cache.acquire(LOCK_KEY, self.holder, 120):
            return
        try:
            files = self._auth_files()
            accounts = [account for item in files if (account := self._account(item))]
            collected_at = time.strftime("%Y-%m-%dT%H:%M:%S%z")
            snapshot = {"accounts": accounts, "providers": self._pools(accounts), "collected_at": collected_at}
            self.cache.set(RAW_KEY, json.dumps({"accounts": accounts, "collected_at": collected_at}))
            self.cache.set(SNAPSHOT_KEY, json.dumps(snapshot))
        finally:
            self.cache.release(LOCK_KEY, self.holder)

    def snapshot(self) -> dict[str, object]:
        if self.cache is None:
            return {"accounts": [], "providers": [], "collected_at": None}
        raw = self.cache.get(SNAPSHOT_KEY)
        return json.loads(raw) if raw else {"accounts": [], "providers": [], "collected_at": None}

    def toggle(self, account: str, disabled: bool) -> dict[str, object]:
        self._request("PATCH", "/auth-files/status", {"name": account, "disabled": disabled})
        snapshot = self.snapshot()
        accounts = []
        for item in snapshot.get("accounts", []):
            if not isinstance(item, dict):
                continue
            if item.get("id") == account:
                item = {**item, "disabled": disabled, "status": "disabled" if disabled else "enabled"}
            accounts.append(item)
        collected_at = time.strftime("%Y-%m-%dT%H:%M:%S%z")
        compiled = {"accounts": accounts, "providers": self._pools(accounts), "collected_at": collected_at}
        if self.cache is not None:
            self.cache.set(RAW_KEY, json.dumps({"accounts": accounts, "collected_at": collected_at}))
            self.cache.set(SNAPSHOT_KEY, json.dumps(compiled))
        match = next((item for item in accounts if item.get("id") == account), {"id": account, "disabled": disabled})
        return match

    def _auth_files(self) -> list[dict[str, object]]:
        payload = self._request("GET", "/auth-files")
        files = payload.get("files", payload)
        return [item for item in files if isinstance(item, dict)] if isinstance(files, list) else []

    def _account(self, file: dict[str, object]) -> dict[str, object] | None:
        account_id = file.get("auth_index") or file.get("name")
        provider = self._provider(file)
        if not isinstance(account_id, str) or provider is None:
            return None
        disabled = file.get("disabled") is True
        label = str(file.get("email") or file.get("label") or file.get("name") or "account")
        if disabled:
            return {"id": account_id, "provider": provider, "label": label, "disabled": True, "status": "disabled", "windows": [], "error": None}
        if self._backed_off(account_id):
            return {"id": account_id, "provider": provider, "label": label, "disabled": False, "status": "backoff", "windows": [], "error": None}
        try:
            payload = self._request(
                "POST",
                "/api-call",
                {
                    "auth_index": account_id,
                    "method": "GET",
                    "url": USAGE_URLS[provider],
                    "header": self._headers(provider, file.get("account_id")),
                },
            )
            windows = self._windows(provider, payload.get("body"))
        except CollectorHttpError as error:
            if error.status == 429:
                self._backoff(account_id, error.retry_after or DEFAULT_BACKOFF_SECONDS)
                return {"id": account_id, "provider": provider, "label": label, "disabled": False, "status": "backoff", "windows": [], "error": None}
            return {
                "id": account_id,
                "provider": provider,
                "label": label,
                "disabled": False,
                "status": "error",
                "windows": [],
                "error": str(error),
            }
        except Exception as error:  # noqa: BLE001
            return {
                "id": account_id,
                "provider": provider,
                "label": label,
                "disabled": False,
                "status": "error",
                "windows": [],
                "error": str(error),
            }
        return {
            "id": account_id,
            "provider": provider,
            "label": label,
            "disabled": False,
            "status": str(file.get("status") or "enabled"),
            "windows": windows,
            "error": None,
        }

    def _windows(self, provider: str, body: object) -> list[dict[str, object]]:
        if not isinstance(body, dict):
            return []
        if provider == "claude":
            windows = []
            for key, label in (("seven_day", "7d"), ("five_hour", "5h")):
                item = body.get(key)
                if isinstance(item, dict) and isinstance(item.get("utilization"), (int, float)):
                    windows.append({"label": label, "used_percent": float(item["utilization"]), "remaining_percent": max(0.0, 100.0 - float(item["utilization"])), "resets_at": item.get("resets_at")})
            return sort_windows(windows)
        rate = body.get("rate_limit") if provider == "codex" else None
        if isinstance(rate, dict):
            windows = []
            for slot in ("primary_window", "secondary_window"):
                item = rate.get(slot)
                if not isinstance(item, dict) or not isinstance(item.get("used_percent"), (int, float)):
                    continue
                seconds = item.get("limit_window_seconds")
                label = duration_label(int(seconds)) if isinstance(seconds, (int, float)) else "limit"
                used = float(item["used_percent"])
                windows.append({"label": label, "used_percent": used, "remaining_percent": max(0.0, 100.0 - used), "resets_at": item.get("reset_at")})
            return sort_windows(windows)
        return []

    def _headers(self, provider: str, account_id: object) -> dict[str, str]:
        headers = {"Authorization": "Bearer $TOKEN$", "Accept": "application/json"}
        if provider == "claude":
            headers["anthropic-beta"] = "oauth-2025-04-20"
            headers["Content-Type"] = "application/json"
        elif provider == "codex" and isinstance(account_id, str) and account_id:
            headers["ChatGPT-Account-Id"] = account_id
        elif provider == "grok":
            headers["X-XAI-Token-Auth"] = "xai-grok-cli"
        return headers

    def _provider(self, file: dict[str, object]) -> str | None:
        for value in (file.get("provider"), file.get("account_type"), file.get("label"), file.get("name")):
            text = str(value or "").lower()
            if text in {"claude", "anthropic"} or "claude" in text:
                return "claude"
            if text in {"codex", "openai"} or "codex" in text or "gpt" in text:
                return "codex"
            if text in {"grok", "xai"} or "grok" in text:
                return "grok"
            if text in {"kimi", "moonshot"} or "kimi" in text:
                return "kimi"
        return None

    def _pools(self, accounts: list[dict[str, object]]) -> list[dict[str, object]]:
        grouped: dict[str, list[dict[str, object]]] = {}
        for account in accounts:
            grouped.setdefault(str(account["provider"]), []).append(account)
        return [
            {"provider": provider, "windows": self._pool_windows(members), "accounts": members}
            for provider, members in sorted(grouped.items())
        ]

    def _pool_windows(self, accounts: list[dict[str, object]]) -> list[dict[str, object]]:
        used: dict[str, float] = {}
        count: dict[str, int] = {}
        for account in accounts:
            if account.get("disabled"):
                continue
            for window in account.get("windows", []):
                if not isinstance(window, dict) or "label" not in window:
                    continue
                label = str(window["label"])
                used[label] = used.get(label, 0.0) + float(window.get("used_percent") or 0)
                count[label] = count.get(label, 0) + 1
        windows = [
            {
                "label": label,
                "used_percent": used[label] / count[label],
                "remaining_percent": max(0.0, 100.0 - used[label] / count[label]),
                "resets_at": None,
            }
            for label in used
        ]
        return sort_windows(windows)

    def _request(self, method: str, path: str, body: dict[str, object] | None = None) -> dict[str, object]:
        origin = env("PROXYCLI_CLIPROXY_URL").rstrip("/")
        origin = origin.removesuffix("/v0/management")
        request = urllib.request.Request(
            f"{origin}/v0/management{path}",
            data=None if body is None else json.dumps(body).encode(),
            method=method,
            headers={
                "Authorization": f"Bearer {env('PROXYCLI_MANAGEMENT_KEY')}",
                "Accept": "application/json",
                "Content-Type": "application/json",
            },
        )
        try:
            with urllib.request.urlopen(request, timeout=10) as response:
                payload = json.loads(response.read().decode() or "{}")
        except urllib.error.HTTPError as error:
            retry_after = error.headers.get("Retry-After") if error.headers else None
            seconds = int(retry_after) if isinstance(retry_after, str) and retry_after.isdigit() else None
            raise CollectorHttpError(error.code, seconds) from error
        return payload if isinstance(payload, dict) else {}

    def _backed_off(self, account_id: str) -> bool:
        if self.cache is None:
            return False
        until = self.cache.get(f"{BACKOFF_PREFIX}{account_id}")
        return until is not None and until.isdigit() and int(until) > int(time.time())

    def _backoff(self, account_id: str, seconds: int) -> None:
        if self.cache is None:
            return
        delay = max(1, seconds)
        self.cache.set(f"{BACKOFF_PREFIX}{account_id}", str(int(time.time()) + delay), delay)


def quota_stats(snapshot: dict[str, object]) -> dict[str, object]:
    groups = []
    providers: dict[str, dict[str, float]] = {}
    for pool in snapshot.get("providers", []):
        if not isinstance(pool, dict):
            continue
        lowest = None
        for window in pool.get("windows", []):
            if not isinstance(window, dict):
                continue
            groups.append(
                {
                    "name": window.get("label"),
                    "used_percent": window.get("used_percent"),
                    "remaining_percent": window.get("remaining_percent"),
                    "reset_at": window.get("resets_at"),
                }
            )
            remaining = window.get("remaining_percent")
            if isinstance(remaining, (int, float)):
                lowest = remaining if lowest is None else min(lowest, remaining)
        if lowest is not None:
            providers[str(pool.get("provider"))] = {"remaining_percent": float(lowest)}
    return {"quota_groups": groups, "total_requests": 0, "total_tokens": 0, "providers": providers}


class Handler(BaseHTTPRequestHandler):
    collector: Collector

    def log_message(self, format: str, *args: object) -> None:
        return

    def do_GET(self) -> None:  # noqa: N802
        if self.path == "/health":
            self._send(200, {"ok": True})
            return
        if not self._authorized(env("PROXYCLI_READ_TOKEN")):
            self._send(401, {"error": "unauthorized"})
            return
        snapshot = self.collector.snapshot()
        if self.path in {"/v1/quota-stats", "/quota-stats"}:
            self._send(200, quota_stats(snapshot))
            return
        if self.path == "/v1/providers":
            self._send(200, snapshot.get("providers", []))
            return
        self._send(404, {"error": "not_found"})

    def do_PATCH(self) -> None:  # noqa: N802
        if not self.path.startswith("/v1/accounts/"):
            self._send(404, {"error": "not_found"})
            return
        if not self._authorized(env("PROXYCLI_CONTROL_TOKEN")):
            self._send(401, {"error": "unauthorized"})
            return
        length = int(self.headers.get("Content-Length", "0"))
        body = json.loads(self.rfile.read(length).decode() or "{}")
        account = self.path.removeprefix("/v1/accounts/")
        self._send(200, self.collector.toggle(account, body.get("disabled") is True))

    def _authorized(self, token: str) -> bool:
        header = self.headers.get("Authorization", "")
        return token != "" and header == f"Bearer {token}"

    def _send(self, status: int, payload: object) -> None:
        body = json.dumps(payload).encode()
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


def loop(collector: Collector) -> None:
    while True:
        try:
            collector.collect()
        except Exception:
            pass
        time.sleep(POLL_SECONDS)


def main() -> None:
    host = env("PROXYCLI_CACHE_HOST")
    cache = Valkey(host, int(env("PROXYCLI_CACHE_PORT", "6379")), env("PROXYCLI_CACHE_USERNAME"), env("PROXYCLI_CACHE_PASSWORD")) if host else None
    collector = Collector(cache)
    Handler.collector = collector
    threading.Thread(target=loop, args=(collector,), daemon=True).start()
    ThreadingHTTPServer(("127.0.0.1", int(env("PROXYCLI_PORT", "8787"))), Handler).serve_forever()


if __name__ == "__main__":
    main()
