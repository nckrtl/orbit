"""Exercise the deployed Python collector, including CLIProxyAPI's wrapped replies."""
import importlib.util
import json
import base64
import struct
from pathlib import Path
import time
import unittest

spec = importlib.util.spec_from_file_location('collector', Path(__file__).resolve().parents[4] / 'resources/proxycli/server.py')
m = importlib.util.module_from_spec(spec)
spec.loader.exec_module(m)

class Cache:
    def __init__(self): self.values = {}
    def get(self, key): return self.values.get(key)
    def set(self, key, value, seconds=None): self.values[key] = value
    def acquire(self, *args): return True
    def release(self, *args): pass

class CollectorTests(unittest.TestCase):
    def setUp(self):
        self.c = m.Collector(Cache())
        self.file = {'auth_index': '0123456789abcdef', 'name': 'codex.json', 'provider': 'codex'}
    def test_wrapped_string_and_reset(self):
        self.c._request = lambda *a: {'status_code': 200, 'body': json.dumps({'rate_limit': {'primary_window': {'used_percent': 21, 'limit_window_seconds': 18000, 'reset_at': 2000000000}}})}
        a = self.c._account(self.file)
        self.assertEqual(a['windows'][0]['remaining_percent'], 79)
        self.assertEqual(a['windows'][0]['label'], '5h')
        self.assertIsInstance(a['windows'][0]['resets_at'], str)
    def test_wrapped_rate_limit_and_retry_after(self):
        self.c._request = lambda *a: {'status_code': 429, 'header': {'Retry-After': ['7200']}, 'body': '{}'}
        a = self.c._account(self.file)
        self.assertEqual(a['status'], 'backoff')
        self.assertGreaterEqual(a['next_check_at'] - a['checked_at'], 7200)
        self.c.previous[a['id']] = a
        self.c._request = lambda *a: self.fail('must not fetch during backoff')
        self.assertEqual(self.c._account(self.file)['next_check_at'], a['next_check_at'])
    def test_claude_interval_survives_restart(self):
        self.c._request = lambda *a: {'status_code': 200, 'body': {'five_hour': {'utilization': 10}}}
        a = self.c._account({**self.file, 'provider': 'claude'})
        self.assertEqual(a['next_check_at'] - a['checked_at'], 900)
        self.c.cache.set(m.SNAPSHOT_KEY, json.dumps({'accounts': [a]}))
        other = m.Collector(self.c.cache)
        other._auth_files = lambda: [{**self.file, 'provider': 'claude'}]
        other._request = lambda *a: self.fail('cached quota must be reused')
        other.collect()
        self.assertEqual(other.snapshot()['accounts'][0]['checked_at'], a['checked_at'])
    def test_all_provider_parsers(self):
        cases = [('grok', {'config': {'creditUsagePercent': 42, 'currentPeriod': {'type': 'USAGE_PERIOD_TYPE_WEEKLY'}}}),
                 ('kimi', {'limits': [{'window': {'duration': 5, 'timeUnit': 'TIME_UNIT_HOUR'}, 'detail': {'used': '3', 'limit': '10'}}]}),
                 ('antigravity', {'groups': [{'displayName': 'Gemini Models', 'buckets': [{'remainingFraction': .7, 'window': 'weekly'}]}]})]
        for provider, body in cases:
            self.assertTrue(self.c._windows(provider, json.dumps(body)), provider)
    def test_errors_not_successful_empty_quota(self):
        self.c._request = lambda *a: {'status_code': 401, 'body': 'private error body'}
        a = self.c._account(self.file)
        self.assertEqual(a['error'], 'HTTP 401')
        self.assertEqual(a['windows'], [])
    def test_toggle_uses_auth_filename(self):
        calls = []
        self.c._auth_files = lambda: [self.file]
        self.c._request = lambda *args: calls.append(args)
        self.c.toggle(self.file['auth_index'], True)
        self.assertEqual(calls[0][2]['name'], 'codex.json')
    def test_codexbar_reads_cached_snapshot(self):
        a = {**self.file, 'id': self.file['auth_index'], 'label': 'account', 'disabled': False, 'windows': self.c._windows('codex', {'rate_limit': {'primary_window': {'used_percent': 21, 'limit_window_seconds': 18000}}})}
        snapshot = {'collected_at': '2026-09-21T10:00:00+00:00', 'providers': self.c._pools([a])}
        self.c._request = lambda *a: self.fail('projection must not call upstream')
        data = m.codexbar_snapshot(snapshot)
        self.assertEqual(data['providers'][0]['windows'][0]['usedPercent'], 21)
        self.assertGreater(data['updatedAt'], 0)

def varint(value):
    encoded = bytearray()
    while value >= 128:
        encoded.append((value & 127) | 128)
        value >>= 7
    return bytes(encoded + bytes([value]))

def field(number, value):
    return varint(number * 8 + 2) + varint(len(value)) + value

def grpc_fixture(percent=None, start=1999999900, end=2000000100, status="0", extra=b"", periods=1):
    period = b"\x08\x02" + field(2, b"\x08" + varint(start)) + field(3, b"\x08" + varint(end))
    config = (b"\x0d" + struct.pack("<f", percent) if percent is not None else b"") + field(8, period) + extra
    data = field(1, config)
    frame = b"\x00" + len(data).to_bytes(4, "big") + data
    trailer = ("grpc-status:" + status + "\r\n").encode()
    tail = b"\x80" + len(trailer).to_bytes(4, "big") + trailer
    return base64.b64encode(frame).decode() * periods + base64.b64encode(tail).decode()

class GrokFallbackTests(unittest.TestCase):
    def test_reads_published_percent_and_segmented_base64(self):
        result = m.grok_grpc_windows(grpc_fixture(percent=42.5), now=2000000000)
        self.assertEqual(result[0]['remaining_percent'], 57.5)
        self.assertEqual(result[0]['label'], '7d')
    def test_accepts_zero_only_for_complete_active_period(self):
        self.assertEqual(m.grok_grpc_windows(grpc_fixture(), now=2000000000)[0]['used_percent'], 0)
        for fixture in [grpc_fixture(start=2000000010), grpc_fixture(end=1999999999),
                        grpc_fixture(status="7"), grpc_fixture(percent=float('nan')),
                        grpc_fixture(percent=101), grpc_fixture(periods=2),
                        grpc_fixture(extra=b"\x0a\x01\x00"), grpc_fixture(extra=b"\x08\x00")]:
            with self.assertRaises(ValueError): m.grok_grpc_windows(fixture, now=2000000000)
    def test_rejects_empty_and_truncated(self):
        for fixture in ['', '!!!', 'AAAAAAE=', grpc_fixture()[:-3]]:
            with self.assertRaises(ValueError): m.grok_grpc_windows(fixture, now=2000000000)
    def test_period_only_json_uses_billing_fallback_through_proxy(self):
        c = m.Collector(Cache())
        calls = []
        def request(*args):
            calls.append(args)
            if len(calls) == 1: return {'status_code': 200, 'body': {'config': {'currentPeriod': {'type': 'USAGE_PERIOD_TYPE_WEEKLY'}}}}
            return {'status_code': 200, 'body': grpc_fixture(start=int(time.time())-10, end=int(time.time())+100)}
        c._request = request
        a = c._account({'auth_index': 'grok-account', 'provider': 'xai'})
        self.assertEqual(a['windows'][0]['remaining_percent'], 100)
        self.assertEqual(len(calls), 2)
        self.assertEqual(calls[1][2]['header']['Content-Type'], 'application/grpc-web-text')
        self.assertEqual(a['next_check_at'] - a['checked_at'], 300)

if __name__ == '__main__': unittest.main()
