"""Input delivery must preserve bytes without blocking collection deadlines."""

import hashlib
import json
import os
from pathlib import Path
import pty
import select
import subprocess
import sys
import tempfile
import time
import unittest


CAPTURE = Path(__file__).resolve().parents[1] / 'scripts/capture.py'
WRAPPER = r'''
import errno, importlib.util, json, os, sys
from pathlib import Path
source, audit_path, partial = sys.argv[1:4]
del sys.argv[1:4]
spec = importlib.util.spec_from_file_location('recorder', source)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
original_write = os.write
attempt = 0
def write(fd, data):
    global attempt
    assert not os.get_blocking(fd), 'PTY input must be nonblocking'
    attempt += 1
    if partial == 'yes' and attempt % 3 == 1:
        raise BlockingIOError(errno.EAGAIN, 'injected temporary backpressure')
    result = original_write(fd, data[:7] if partial == 'yes' else data)
    with Path(audit_path).open('a') as audit:
        audit.write(json.dumps({'requested': len(data), 'written': result}) + '\n')
    return result
module.os.write = write
raise SystemExit(module.capture(module.arguments()))
'''


class CaptureInputTest(unittest.TestCase):
    def run_input(self, mode, source, payload, *, partial=False, timeout=5, idle_timeout=5):
        with tempfile.TemporaryDirectory(prefix='orbit-recorder-input-') as temporary:
            root = Path(temporary)
            wrapper = root / 'wrapper.py'
            wrapper.write_text(WRAPPER)
            audit = root / 'writes.jsonl'
            output = root / 'capture'
            master, slave = pty.openpty()
            os.set_blocking(master, False)
            command = [sys.executable, str(wrapper), str(CAPTURE), str(audit),
                       'yes' if partial else 'no', '--candidate', 'input-fixture',
                       '--label', mode, '--output-dir', str(output),
                       '--timeout', str(timeout), '--idle-timeout', str(idle_timeout)]
            if mode == 'plan':
                plan = root / 'input.json'
                plan.write_text(json.dumps([{'wait_for': 'READY', 'send': payload.decode()}]))
                command += ['--no-live', '--input-plan', str(plan)]
            command += ['--', sys.executable, '-c', source]
            collector = subprocess.Popen(command, stdin=slave, stdout=slave,
                                         stderr=subprocess.PIPE, start_new_session=True)
            seen = bytearray()
            submitted = 0
            started = time.monotonic()
            try:
                while collector.poll() is None:
                    self.assertLess(time.monotonic() - started, 8, 'input forwarding hung')
                    writers = [master] if mode == 'terminal' and b'READY' in seen and submitted < len(payload) else []
                    readable, writable, _ = select.select([master], writers, [], .01)
                    if master in readable:
                        try:
                            seen.extend(os.read(master, 65536))
                        except BlockingIOError:
                            pass
                    if master in writable:
                        try:
                            submitted += os.write(master, payload[submitted:submitted + 65536])
                        except BlockingIOError:
                            pass
                stderr = collector.communicate(timeout=1)[1]
                self.assertTrue((output / 'summary.json').exists(), stderr.decode())
                summary = json.loads((output / 'summary.json').read_text())
                self.assertEqual(summary['capture_exit_code'], collector.returncode)
                events = [json.loads(line) for line in (output / 'input-events.jsonl').read_text().splitlines()]
                writes = [json.loads(line) for line in audit.read_text().splitlines()] if audit.exists() else []
                # Independently observed syscall returns must agree with saved
                # events, even when a call accepts only part of a buffer.
                self.assertEqual(sum(row['written'] for row in writes), sum(row['bytes'] for row in events))
                self.assertEqual(summary['input_bytes_sent'], sum(row['bytes'] for row in events))
                self.assertTrue(all(row['source'] == mode for row in events))
                self.assertNotIn(payload.decode()[:32], (output / 'input-events.jsonl').read_text())
                transcript = (output / 'transcript.txt').read_text()
                return summary, events, writes, transcript
            finally:
                if collector.poll() is None:
                    collector.terminate()
                    try:
                        collector.wait(timeout=3)
                    except subprocess.TimeoutExpired:
                        collector.kill()
                        collector.wait(timeout=1)
                collector.stderr.close()
                os.close(master)
                os.close(slave)

    def test_scripted_and_live_partial_writes_preserve_every_byte(self):
        payload = ('transfer-┌-✓-' * 160).encode()
        source = (
            "import hashlib,os,time,tty\n"
            "tty.setraw(0); print('READY',flush=True); time.sleep(.08)\n"
            "data=bytearray()\n"
            f"while len(data)<{len(payload)}:\n"
            f" data.extend(os.read(0,min(31,{len(payload)}-len(data)))); time.sleep(.001)\n"
            "print(hashlib.sha256(data).hexdigest(),flush=True)\n")
        for mode in ('plan', 'terminal'):
            with self.subTest(mode=mode):
                summary, events, writes, transcript = self.run_input(mode, source, payload, partial=True)
                self.assertEqual(summary['child_exit_code'], 0)
                self.assertEqual(summary['capture_exit_code'], 0)
                self.assertEqual(summary['input_bytes_sent'], len(payload))
                self.assertEqual(summary['input_bytes_pending'], 0)
                self.assertIn(hashlib.sha256(payload).hexdigest(), transcript)
                self.assertTrue(any(row['written'] < row['requested'] for row in writes))
                self.assertTrue(any(not row['complete'] for row in events))
                if mode == 'plan':
                    self.assertEqual(summary['input_actions_sent'], 1)
                    self.assertEqual(sum(row['complete'] for row in events), 1)

    def test_scripted_and_live_backpressure_cannot_bypass_deadlines(self):
        payload = b'unread-paste-' * 100000
        source = "import time,tty; tty.setraw(0); print('READY',flush=True); time.sleep(3); print('Finished')"
        for mode in ('plan', 'terminal'):
            for deadline in ('wall', 'idle'):
                with self.subTest(mode=mode, deadline=deadline):
                    summary, events, _, _ = self.run_input(
                        mode, source, payload,
                        timeout=.5 if deadline == 'wall' else 5,
                        idle_timeout=.5 if deadline == 'idle' else 5)
                    self.assertEqual(summary['child_exit_code'], 143)
                    self.assertEqual(summary['capture_exit_code'], 124)
                    self.assertEqual(summary['termination'], 'timeout' if deadline == 'wall' else 'idle_timeout')
                    self.assertLess(summary['duration_seconds'], 2)
                    self.assertGreater(summary['input_bytes_pending'], 0)
                    self.assertLess(summary['input_bytes_sent'], len(payload))
                    if mode == 'plan':
                        self.assertEqual(summary['input_actions_sent'], 0)
                        self.assertFalse(any(row['complete'] for row in events))

    def test_child_success_with_undelivered_input_is_capture_failure(self):
        payload = b'unread-paste-' * 100000
        source = "import time,tty; tty.setraw(0); print('READY',flush=True); time.sleep(.3); print('Finished')"
        for mode in ('plan', 'terminal'):
            with self.subTest(mode=mode):
                summary, _, _, transcript = self.run_input(mode, source, payload)
                self.assertEqual(summary['child_exit_code'], 0)
                self.assertEqual(summary['capture_exit_code'], 125)
                self.assertIsNone(summary['termination'])
                self.assertIn('Finished', transcript)
                self.assertGreater(summary['input_bytes_pending'], 0)
                if mode == 'plan':
                    self.assertEqual(summary['input_actions_sent'], 0)


if __name__ == '__main__':
    unittest.main()
