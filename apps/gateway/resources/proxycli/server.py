#!/usr/bin/env python3
"""Orbit proxycli collector and CodexBar HTTP surface.

The process is the only CLIProxyAPI quota poller. It writes snapshots, backoff,
and a distributed lock into shared Valkey. HTTP reads never fetch upstream.
"""

from __future__ import annotations

import json
import base64
import re
import struct
import datetime as dt
import email.utils
import math
import uuid
from urllib.parse import unquote
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
ACCOUNT_PREFIX = "orbit:proxycli:account:"
USAGE_URLS = {
    "claude": "https://api.anthropic.com/api/oauth/usage",
    "codex": "https://chatgpt.com/backend-api/wham/usage",
    "grok": "https://cli-chat-proxy.grok.com/v1/billing?format=credits",
    "kimi": "https://api.kimi.com/coding/v1/usages",
    "antigravity": "https://daily-cloudcode-pa.googleapis.com/v1internal:retrieveUserQuotaSummary",
}
POLL_SECONDS = 60
QUOTA_INTERVALS = {"claude": 900}
DEFAULT_QUOTA_INTERVAL = 300


def number(value):
    try:
        value = float(value)
        return value if math.isfinite(value) else None
    except (TypeError, ValueError):
        return None


def timestamp(value):
    if isinstance(value, (int, float)):
        return float(value) if math.isfinite(value) else None
    if isinstance(value, str):
        try:
            return dt.datetime.fromisoformat(value.replace("Z", "+00:00")).timestamp()
        except ValueError:
            pass
    return None


def window(key, title, used, reset=None, capacity=None):
    used = number(used)
    if used is None:
        return None
    return {"id": key, "title": title, "usedPercent": max(0, min(100, used)),
            "resetAt": timestamp(reset), "capacity": capacity}


def duration_title(seconds):
    return {18000: "5-hour limit", 86400: "Daily limit", 604800: "Weekly limit"}.get(
        seconds, f"{seconds // 3600}-hour limit" if seconds else "Quota")


CLAUDE_WINDOWS = {
    "five_hour": "5-hour limit", "seven_day": "7-day limit", "seven_day_oauth_apps": "7-day OAuth apps",
    "seven_day_opus": "7-day Opus", "seven_day_sonnet": "7-day Sonnet", "seven_day_cowork": "7-day Cowork",
    "iguana_necktie": "7-day Fable 5",
}


def parse_windows(provider, body):
    result = []
    plan = str(body.get("plan_type") or "")
    if provider == "codex":
        limits = [("", body.get("rate_limit") or {})]
        for item in body.get("additional_rate_limits") or []:
            limits.append((str(item.get("limit_name") or item.get("metered_feature") or "Additional"),
                           item.get("rate_limit") or {}))
        for scope, limit in limits:
            for slot in ["primary_window", "secondary_window"]:
                w = limit.get(slot)
                if not w:
                    continue
                seconds = int(w.get("limit_window_seconds") or 0)
                result.append(window(f"{scope or 'main'}:{seconds or slot}",
                                     (scope + " " if scope else "") + duration_title(seconds),
                                     w.get("used_percent"), w.get("reset_at")))
    elif provider == "xai":
        c = body.get("config") or body
        period = c.get("currentPeriod") or {}
        monthly = "MONTHLY" in period.get("type", "").upper()
        title = "Monthly credits" if monthly else "Weekly limit"
        result.append(window("monthly" if monthly else "weekly", title, c.get("creditUsagePercent"), period.get("end")))
        # Product percentages are contributions to this pool, not separate allowances.
    elif provider == "kimi":
        def kimi_window(key, detail, title):
            limit, used = number(detail.get("limit")), number(detail.get("used"))
            remaining = number(detail.get("remaining"))
            if used is None and limit is not None and remaining is not None:
                used = limit - remaining
            if limit and limit > 0 and used is not None:
                reset = next((detail.get(k) for k in ("reset_at", "resetAt", "reset_time", "resetTime") if detail.get(k)), None)
                result.append(window(key, title, 100 * used / limit, reset))

        for index, item in enumerate(body.get("limits") or []):
            detail = item.get("detail") or item
            spec = item.get("window") or {}
            duration = number(spec.get("duration", item.get("duration", detail.get("duration"))))
            unit = str(spec.get("timeUnit", item.get("timeUnit", detail.get("timeUnit", "MINUTE")))).upper().replace("TIME_UNIT_", "").rstrip("S")
            title = next((str(detail.get(k) or item.get(k)).strip() for k in ("name", "title", "scope") if detail.get(k) or item.get(k)), None)
            if not title:
                suffix = {"SECOND": "s", "MINUTE": "m", "HOUR": "h", "DAY": "d", "WEEK": "w"}.get(unit, "m")
                amount = int(duration) if duration else 0
                if suffix == "m" and amount and amount % 60 == 0:
                    amount, suffix = amount // 60, "h"
                title = f"{amount}{suffix} limit" if amount else f"Limit #{index + 1}"
            kimi_window(f"limit-{index}", detail, title)
        if body.get("usage"):
            detail = body["usage"]
            kimi_window("summary", detail, detail.get("name") or detail.get("title") or "Weekly limit")
        if not result:
            usages = body.get("usages") or {}
            for key, title in [("limit_5h", "5h limit"), ("limit_month_total", "Monthly limit"),
                               ("limit_month_code", "Monthly coding limit")]:
                w = usages.get(key) or {}
                ratio = number(w.get("used_ratio"))
                if ratio is not None:
                    result.append(window(key, title, ratio * 100, w.get("reset_time")))
    elif provider == "antigravity":
        for group in body.get("groups") or []:
            group_name = group.get("displayName", "Models")
            group_name = {"Gemini Models": "Gemini models", "Claude and GPT Models": "Claude and GPT models"}.get(group_name, group_name)
            for bucket in group.get("buckets") or []:
                remaining = number(bucket.get("remainingFraction"))
                if remaining is not None:
                    label = {"weekly": "Weekly limit", "5h": "5-hour limit", "daily": "Daily limit", "monthly": "Monthly limit"}.get(bucket.get("window"), "Quota")
                    result.append(window(bucket.get("bucketId") or group_name + label,
                                         f"{group_name} · {label}", 100 * (1 - remaining), bucket.get("resetTime")))
    elif provider == "claude":
        scoped = [w for w in body.get("limits") or [] if w.get("kind") == "weekly_scoped"
                  and str(((w.get("scope") or {}).get("model") or {}).get("display_name", "")).lower() in {"fable", "fable 5"}
                  and number(w.get("percent")) is not None]
        fable = next((w for w in scoped if w.get("is_active") is True), scoped[0] if scoped else None)
        for key, title in CLAUDE_WINDOWS.items():
            w = body.get(key) or {}
            if key == "iguana_necktie" and fable:
                result.append(window(key, title, fable.get("percent"), fable.get("resets_at")))
            else:
                result.append(window(key, title, w.get("utilization"), w.get("resets_at")))
    return plan, [w for w in result if w is not None]



def retry_delay(headers, now):
    for key, value in (headers or {}).items():
        if key.lower() != "retry-after":
            continue
        value = value[0] if isinstance(value, list) and value else value
        seconds = number(value)
        if seconds is not None:
            return max(0, seconds)
        try:
            return max(0, email.utils.parsedate_to_datetime(value).timestamp() - now)
        except (ValueError, TypeError, OverflowError):
            return 0
    return 0



def grok_grpc_windows(body: object, now: float | None = None) -> list[dict[str, object]]:
    """Decode Grok's billing fallback, including a validated fresh-period zero.

    Matches CodexBar PR #3325. gRPC-web-text preserves protobuf bytes through
    CLIProxyAPI's JSON-string response; binary gRPC would lose non-UTF-8 bytes.
    """
    now = time.time() if now is None else now
    if not isinstance(body, str):
        raise ValueError("Invalid Grok billing response")
    # gRPC-web can base64-encode each frame separately, including interior padding.
    encoded = "".join(body.split())
    chunks = re.findall(r"[A-Za-z0-9+/]+={0,2}", encoded)
    if "".join(chunks) != encoded:
        raise ValueError("Invalid Grok billing encoding")
    try:
        raw = b"".join(base64.b64decode(chunk, validate=True) for chunk in chunks)
    except ValueError as error:
        raise ValueError("Invalid Grok billing encoding") from error
    payloads, trailers = [], {}
    while raw:
        if len(raw) < 5:
            raise ValueError("Truncated Grok billing frame")
        flag, length = raw[0], int.from_bytes(raw[1:5], "big")
        if len(raw) < 5 + length:
            raise ValueError("Truncated Grok billing frame")
        payload, raw = raw[5:5 + length], raw[5 + length:]
        if flag == 0:
            payloads.append(payload)
        elif flag == 128:
            for line in payload.decode("ascii").splitlines():
                if ":" in line:
                    key, value = line.split(":", 1)
                    trailers[key.lower().strip()] = value.strip()
        else:
            raise ValueError("Unsupported Grok billing frame")
    if len(payloads) != 1 or trailers.get("grpc-status") != "0":
        raise ValueError("Grok billing RPC did not succeed")

    integers, floats = {}, {}
    messages = {(1,), (1, 2), (1, 3), (1, 4), (1, 5), (1, 6), (1, 7), (1, 8), (1, 12),
                (1, 6, 1), (1, 6, 2), (1, 6, 3), (1, 8, 2), (1, 8, 3), (1, 6, 3, 2), (1, 6, 3, 3)}

    def scan(data: bytes, path: tuple[int, ...] = ()) -> None:
        index = 0
        seen = set()

        def varint() -> int:
            nonlocal index
            value = 0
            for shift in range(0, 70, 7):
                if index >= len(data):
                    break
                byte = data[index]
                index += 1
                if shift == 63 and byte > 1:
                    break
                value |= (byte & 127) << shift
                if byte < 128:
                    return value
            raise ValueError("Malformed Grok billing protobuf")

        while index < len(data):
            key = varint()
            field, wire = key >> 3, key & 7
            if not 0 < field <= 536870911:
                raise ValueError("Invalid Grok billing field")
            current = path + (field,)
            if field in seen or (current == (1, 1) and wire != 5):
                raise ValueError("Ambiguous Grok billing field")
            seen.add(field)
            if wire == 0:
                if current in integers:
                    raise ValueError("Ambiguous Grok billing field")
                integers[current] = varint()
                continue
            if wire not in (1, 2, 5):
                raise ValueError("Invalid Grok billing wire type")
            length = varint() if wire == 2 else 8 if wire == 1 else 4
            if index + length > len(data):
                raise ValueError("Truncated Grok billing protobuf")
            value = data[index:index + length]
            index += length
            if wire == 2 and current in messages:
                scan(value, current)
            elif wire == 5:
                if current in floats:
                    raise ValueError("Ambiguous Grok billing percentage")
                floats[current] = struct.unpack("<f", value)[0]

    scan(payloads[0])
    period = integers.get((1, 8, 1))
    start, end = integers.get((1, 8, 2, 1)), integers.get((1, 8, 3, 1))
    used = floats.get((1, 1))
    if used is None:
        # A missing JSON percentage alone is never evidence of zero use.
        if floats or period not in (1, 2) or start is None or end is None or not start <= now < end:
            raise ValueError("Grok billing did not report a verifiable quota")
        used = 0.0
    if not math.isfinite(used) or not 0 <= used <= 100:
        raise ValueError("Invalid Grok billing percentage")
    reset = end or integers.get((1, 5, 1))
    if reset is None or reset <= now:
        raise ValueError("Grok billing period is missing or expired")
    return [{"label": "7d" if period == 2 else "Monthly credits" if period == 1 else "Credits",
             "used_percent": used, "remaining_percent": 100 - used,
             "resets_at": dt.datetime.fromtimestamp(reset, dt.timezone.utc).isoformat()}]


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
        return reply == "OK"

    def release(self, key: str, holder: str) -> None:
        self._command("EVAL", "if redis.call('GET', KEYS[1]) == ARGV[1] then return redis.call('DEL', KEYS[1]) else return 0 end", "1", key, holder)


class CollectorHttpError(Exception):
    def __init__(self, status: int, retry_after: int | None = None) -> None:
        super().__init__(f"HTTP {status}")
        self.status = status
        self.retry_after = retry_after


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
        self.holder = str(uuid.uuid4())
        self.previous = {}

    def collect(self) -> None:
        if self.cache is None or not self.cache.acquire(LOCK_KEY, self.holder, 120):
            return
        try:
            self.previous = {a["id"]: a for a in self.snapshot().get("accounts", [])}
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
        snapshot = json.loads(raw) if raw else {"accounts": [], "providers": [], "collected_at": None}
        for account in snapshot.get("accounts", []):
            account["windows"] = [w for w in account.get("windows", []) if timestamp(w.get("resets_at")) is None or timestamp(w["resets_at"]) > time.time()]
        snapshot["providers"] = self._pools(snapshot.get("accounts", []))
        return snapshot

    def toggle(self, account: str, disabled: bool) -> dict[str, object]:
        file = next((f for f in self._auth_files() if (f.get("auth_index") or f.get("name")) == account), None)
        if file is None:
            raise CollectorHttpError(404)
        self._request("PATCH", "/auth-files/status", {"name": file["name"], "disabled": disabled})
        snapshot = self.snapshot()
        accounts = []
        for item in snapshot.get("accounts", []):
            if not isinstance(item, dict):
                continue
            if item.get("id") == account:
                item = {**item, "disabled": disabled, "status": "disabled" if disabled else "enabled"}
            accounts.append(item)
        collected_at = snapshot.get("collected_at")
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
        now = time.time()
        cached = self.cache.get(ACCOUNT_PREFIX + account_id) if self.cache else None
        previous = json.loads(cached) if cached else self.previous.get(account_id, {})
        account = {
            **previous, "id": account_id, "provider": provider,
            "label": str(file.get("email") or file.get("label") or file.get("name") or "account"),
            "disabled": file.get("disabled") is True,
            "status": str(file.get("status") or "enabled"),
            "windows": previous.get("windows", []), "error": previous.get("error"),
            "success": file.get("success", 0), "failed": file.get("failed", 0),
        }
        if account["disabled"]:
            account["status"] = "disabled"
            return account
        if previous.get("next_check_at", 0) > now or self._backed_off(account_id):
            account["status"] = previous.get("status", account["status"])
            return account
        interval = QUOTA_INTERVALS.get(provider, DEFAULT_QUOTA_INTERVAL)
        # Reserve before network access so an interrupted collection cannot retry immediately.
        if self.cache:
            self.cache.set(ACCOUNT_PREFIX + account_id, json.dumps({**account, "windows": [], "status": "error", "error": "Quota check interrupted; retry delayed", "next_check_at": now + 3600}))
        try:
            claims = file.get("id_token") or {}
            request = {
                "auth_index": account_id, "method": "GET", "url": USAGE_URLS[provider],
                "header": self._headers(provider, file.get("account_id") or claims.get("chatgpt_account_id")),
            }
            if provider == "antigravity":
                if not file.get("project_id"):
                    raise ValueError("Antigravity project is missing")
                request.update(method="POST", data=json.dumps({"project": file["project_id"]}))
            payload = self._request("POST", "/api-call", request)
            status = payload.get("status_code", 200)
            if not 200 <= status < 300:
                raise CollectorHttpError(status, retry_delay(payload.get("header") or payload.get("headers"), now))
            windows = self._windows(provider, payload.get("body"))
            if not windows and provider == "grok":
                windows = self._grok_fallback(account_id)
            if not windows:
                raise ValueError("Provider returned no quota windows")
            account.update(windows=windows, error=None, failures=0)
        except Exception as error:
            failures = previous.get("failures", 0) + 1
            interval = max(getattr(error, "retry_after", None) or 0, min(86400, 3600 * 2 ** min(failures - 1, 5)))
            account.update(windows=[], error=str(error) if isinstance(error, (CollectorHttpError, ValueError)) else "Quota request failed", failures=failures,
                           status="backoff" if isinstance(error, CollectorHttpError) and error.status == 429 else "error")
            if account["status"] == "backoff":
                self._backoff(account_id, int(interval))
        account.update(checked_at=now, next_check_at=now + interval)
        if self.cache:
            self.cache.set(ACCOUNT_PREFIX + account_id, json.dumps(account))
        return account

    def _windows(self, provider: str, body: object) -> list[dict[str, object]]:
        if isinstance(body, str):
            body = json.loads(body)
        if not isinstance(body, dict):
            return []
        _, windows = parse_windows("xai" if provider == "grok" else provider, body)
        labels = {"5-hour limit": "5h", "7-day limit": "7d", "Weekly limit": "7d", "Daily limit": "1d", "5h limit": "5h"}
        return sort_windows([{
            "label": labels.get(w["title"], w["title"]),
            "used_percent": w["usedPercent"], "remaining_percent": 100 - w["usedPercent"],
            "resets_at": dt.datetime.fromtimestamp(w["resetAt"], dt.timezone.utc).isoformat() if w["resetAt"] is not None else None,
        } for w in windows])

    def _grok_fallback(self, account_id: str) -> list[dict[str, object]]:
        payload = self._request("POST", "/api-call", {
            "auth_index": account_id, "method": "POST",
            "url": "https://grok.com/grok_api_v2.GrokBuildBilling/GetGrokCreditsConfig",
            "header": {
                "Authorization": "Bearer $TOKEN$", "Content-Type": "application/grpc-web-text",
                "Accept": "application/grpc-web-text", "Origin": "https://grok.com",
                "Referer": "https://grok.com/?_s=usage", "User-Agent": "CodexBar",
                "x-grpc-web": "1", "x-user-agent": "connect-es/2.1.1",
            },
            "data": "AAAAAAA=",
        })
        status = payload.get("status_code", 200)
        if not 200 <= status < 300:
            raise CollectorHttpError(status, retry_delay(payload.get("header") or payload.get("headers"), time.time()))
        return grok_grpc_windows(payload.get("body"))

    def _headers(self, provider: str, account_id: object) -> dict[str, str]:
        headers = {"Authorization": "Bearer $TOKEN$", "Accept": "application/json"}
        if provider == "claude":
            headers["anthropic-beta"] = "oauth-2025-04-20"
            headers["Content-Type"] = "application/json"
        elif provider == "codex" and isinstance(account_id, str) and account_id:
            headers["ChatGPT-Account-Id"] = account_id
        elif provider == "grok":
            headers["X-XAI-Token-Auth"] = "xai-grok-cli"
        elif provider == "antigravity":
            headers["User-Agent"] = "antigravity/cli/1.0.13 (aidev_client; os_type=darwin; arch=arm64)"
        return headers

    def _provider(self, file: dict[str, object]) -> str | None:
        for value in (file.get("provider"), file.get("type"), file.get("account_type"), file.get("label"), file.get("name")):
            text = str(value or "").lower()
            if text == "antigravity":
                return "antigravity"
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
        resets: dict[str, str] = {}
        for account in accounts:
            if account.get("disabled"):
                continue
            for window in account.get("windows", []):
                if not isinstance(window, dict) or "label" not in window:
                    continue
                label = str(window["label"])
                used[label] = used.get(label, 0.0) + float(window.get("used_percent") or 0)
                count[label] = count.get(label, 0) + 1
                reset = window.get("resets_at")
                if isinstance(reset, str):
                    resets[label] = min(resets.get(label, reset), reset)
        windows = [
            {
                "label": label,
                "used_percent": used[label] / count[label],
                "remaining_percent": max(0.0, 100.0 - used[label] / count[label]),
                "resets_at": resets.get(label),
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
            seconds = retry_delay({"Retry-After": retry_after}, time.time())
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


def codexbar_snapshot(snapshot: dict[str, object]) -> dict[str, object]:
    def window(w, total=1, covered=1):
        return {"id": w["label"], "title": w["label"], "usedPercent": w["used_percent"],
                "resetAt": timestamp(w.get("resets_at")), "coveredAccounts": covered, "totalAccounts": total,
                "basis": "Account average · estimated" if total > 1 else "Reported quota"}
    providers = []
    for pool in snapshot.get("providers", []):
        active = sum(not a["disabled"] for a in pool["accounts"])
        accounts = [{"id": a["id"], "label": a["label"], "plan": "", "disabled": a["disabled"],
                     "unavailable": a.get("status") in {"error", "backoff"}, "error": a.get("error"),
                     "checkedAt": a.get("checked_at"), "nextCheckAt": a.get("next_check_at"),
                     "windows": [window(w) for w in a["windows"]]} for a in pool["accounts"]]
        providers.append({"id": "xai" if pool["provider"] == "grok" else pool["provider"],
                          "name": pool["provider"].title(), "accounts": accounts,
                          "activeAccounts": active, "disabledAccounts": len(accounts) - active,
                          "success": sum(a.get("success", 0) or 0 for a in pool["accounts"]),
                          "failed": sum(a.get("failed", 0) or 0 for a in pool["accounts"]),
                          "windows": [window(w, active, sum(any(v["label"] == w["label"] for v in a["windows"]) for a in pool["accounts"] if not a["disabled"])) for w in pool["windows"]]})
    return {"updatedAt": timestamp(snapshot.get("collected_at")) or 0, "providers": providers}


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
        if self.path == "/api/v1/usage":
            self._send(200, codexbar_snapshot(snapshot))
            return
        if self.path.startswith("/api/v1/providers/"):
            data = codexbar_snapshot(snapshot)
            provider = next((p for p in data["providers"] if p["id"] == self.path.rsplit("/", 1)[-1]), None)
            self._send(200 if provider else 404, {"updatedAt": data["updatedAt"], "provider": provider})
            return
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
        account = unquote(self.path.removeprefix("/v1/accounts/"))
        self._send(200, self.collector.toggle(account, body.get("disabled") is True))

    def do_PUT(self) -> None:  # noqa: N802
        parts = self.path.strip("/").split("/")
        if len(parts) != 6 or parts[:3] != ["api", "v1", "providers"] or parts[4] != "accounts":
            self._send(404, {"error": "not_found"})
            return
        if not self._authorized(env("PROXYCLI_CONTROL_TOKEN")):
            self._send(401, {"error": "unauthorized"})
            return
        account = unquote(parts[5])
        provider = "grok" if parts[3] == "xai" else parts[3]
        if not any(a["id"] == account and a["provider"] == provider for a in self.collector.snapshot().get("accounts", [])):
            self._send(404, {"error": "not_found"})
            return
        body = json.loads(self.rfile.read(int(self.headers.get("Content-Length", "0"))).decode())
        if not isinstance(body.get("disabled"), bool):
            self._send(422, {"error": "disabled_must_be_boolean"})
            return
        try:
            self.collector.toggle(account, body["disabled"])
            self._send(200, codexbar_snapshot(self.collector.snapshot()))
        except CollectorHttpError:
            self._send(502, {"error": "account_update_failed"})

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
        except Exception as error:
            print(f"Quota collection failed: {type(error).__name__}: {error}", flush=True)
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
