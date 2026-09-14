"""Exercise cleanup through a real parent terminal and private process trees."""

import json
import os
from pathlib import Path
import pty
import select
import signal
import subprocess
import sys
import tempfile
import termios
import time
import unittest

import pyte


CAPTURE = Path(__file__).resolve().parents[1] / "scripts/capture.py"

# Each fixture publishes only its own PIDs. Descendants ignore graceful signals
# and close terminal FDs so success cannot depend on their holding the PTY open.
CHILD = r'''
import json, os, signal, sys, time
from pathlib import Path
root = Path(sys.argv[1])
case = sys.argv[2]
def sleep_forever():
    signal.signal(signal.SIGHUP, signal.SIG_IGN)
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    for fd in (0, 1, 2):
        os.close(fd)
    while True:
        time.sleep(.1)
child = os.fork()
if child == 0:
    os.setpgid(0, 0)
    signal.signal(signal.SIGHUP, signal.SIG_IGN)
    signal.signal(signal.SIGTERM, signal.SIG_IGN)
    grandchild = os.fork()
    if grandchild == 0:
        signal.signal(signal.SIGHUP, signal.SIG_IGN)
        signal.signal(signal.SIGTERM, signal.SIG_IGN)
        (root / 'grandchild-ready').write_text(str(os.getpid()))
        sleep_forever()
    (root / 'descendant-ready').write_text(str(os.getpid()))
    sleep_forever()
while not (root / 'grandchild-ready').exists() or not (root / 'descendant-ready').exists():
    time.sleep(.01)
(root / 'owned.json').write_text(json.dumps([
    os.getpid(), child, int((root / 'grandchild-ready').read_text())]))
os.write(1, b'\x1b[?25lREADY\r\n')
while not (root / 'release').exists():
    time.sleep(.01)
if case == 'live_input':
    answer = input('Answer? ')
    print('accepted' if answer == 'yes' else 'refused', flush=True)
    raise SystemExit(0 if answer == 'yes' else 9)
if case in ('success', 'child_failure'):
    raise SystemExit(0 if case == 'success' else 7)
os.write(1, b'TRIGGER\r\n')
while True:
    time.sleep(.1)
'''

# Fault injection is private to this disposable collector interpreter. No
# production fault flags or global files are needed to exercise I/O failures.
WRAPPER = r'''
import importlib.util, json, os, signal, sys, time
from pathlib import Path
source, root_text, fault = sys.argv[1:4]
del sys.argv[1:4]
root = Path(root_text)
spec = importlib.util.spec_from_file_location('recorder', source)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
original_signals = {sig: signal.getsignal(sig) for sig in
                    (signal.SIGINT, signal.SIGTERM, signal.SIGHUP)}
waited = []
original_waitpid = module.os.waitpid
def waitpid(pid, flags):
    result = original_waitpid(pid, flags)
    if result[0]:
        waited.append(result[0])
    return result
module.os.waitpid = waitpid
original_record = module.record
def record(stream, value):
    if fault == 'record' and 'TRIGGER' in value.get('text', ''):
        raise OSError('injected recording failure')
    return original_record(stream, value)
module.record = record
original_write = Path.write_text
def write_text(path, *args, **kwargs):
    if fault == 'early_metadata' and path.name == 'metadata.json':
        raise OSError('injected early metadata failure')
    if fault == 'metadata' and path.name == 'metadata.json':
        deadline = time.monotonic() + 5
        while not (root / 'owned.json').exists() and time.monotonic() < deadline:
            time.sleep(.01)
        raise OSError('injected metadata failure')
    if fault == 'summary' and path.name == 'summary.json':
        raise OSError('injected summary failure')
    return original_write(path, *args, **kwargs)
Path.write_text = write_text
original_tcsetattr = module.termios.tcsetattr
def tcsetattr(fd, when, attrs):
    if fault == 'terminal' and when == module.termios.TCSANOW:
        raise OSError('injected terminal restoration failure')
    return original_tcsetattr(fd, when, attrs)
module.termios.tcsetattr = tcsetattr
if fault == 'early_metadata':
    def delayed_fork():
        # Hold the child before setsid, the startup window where killpg cannot
        # reach it yet. No descriptor or child belongs to another fixture.
        pid = os.fork()
        if pid == 0:
            time.sleep(20)
            os._exit(99)
        (root / 'early-child').write_text(str(pid))
        return pid, os.open('/dev/null', os.O_RDONLY)
    module.pty.fork = delayed_fork
if fault == 'stderr':
    class FailedStderr:
        def write(self, data):
            raise OSError('injected stderr failure')
        def flush(self):
            pass
    module.sys.stderr = FailedStderr()
result = module.capture(module.arguments())
(root / 'audit.json').write_text(json.dumps({
    'waited': waited,
    'signals_restored': all(signal.getsignal(sig) == previous
                            for sig, previous in original_signals.items()),
    'subreaper_restored': module.set_subreaper() in (None, 0),
}))
raise SystemExit(result)
'''


class CaptureLifecycleTest(unittest.TestCase):
    def process_exists(self, pid):
        try:
            os.kill(pid, 0)
            return True
        except ProcessLookupError:
            return False

    def run_lifecycle(self, case, *, fault='', interrupt=signal.SIGINT):
        with tempfile.TemporaryDirectory(prefix='orbit-recorder-lifecycle-') as temporary:
            root = Path(temporary)
            child_file = root / 'child.py'
            child_file.write_text(CHILD)
            wrapper_file = root / 'wrapper.py'
            wrapper_file.write_text(WRAPPER)
            master, slave = pty.openpty()
            before = termios.tcgetattr(slave)
            # Use a non-default attribute to prove exact restoration, not just
            # an approximate "sane" reset.
            before[3] &= ~termios.ECHOE
            termios.tcsetattr(slave, termios.TCSANOW, before)
            sentinel = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(30)'],
                                        start_new_session=True)
            command = [sys.executable, str(wrapper_file), str(CAPTURE), str(root), fault,
                       '--candidate', 'lifecycle-fixture', '--label', case,
                       '--output-dir', str(root / 'capture'), '--timeout',
                       '1.2' if case == 'timeout' else '8', '--idle-timeout',
                       '1.2' if case == 'idle_timeout' else '8', '--',
                       sys.executable, str(child_file), str(root), case]
            collector = None
            owned = []
            output = bytearray()
            screen = pyte.Screen(100, 40)
            stream = pyte.ByteStream(screen)
            try:
                collector = subprocess.Popen(command, stdin=slave, stdout=slave,
                                             stderr=subprocess.PIPE, start_new_session=True)
                deadline = time.monotonic() + 6
                while b'READY' not in output and collector.poll() is None:
                    self.assertLess(time.monotonic(), deadline, 'fixture did not become ready')
                    if select.select([master], [], [], .03)[0]:
                        data = os.read(master, 65536)
                        output.extend(data)
                        stream.feed(data)
                owned = json.loads((root / 'owned.json').read_text())
                self.assertEqual(len(owned), 3)
                self.assertTrue(all(self.process_exists(pid) for pid in owned)
                                or fault == 'metadata')
                if fault != 'metadata':
                    self.assertTrue(screen.cursor.hidden, 'fixture must first hide the cursor')
                    self.assertNotEqual(termios.tcgetattr(slave), before, 'collector must enter raw mode')
                    if case == 'interrupt':
                        collector.send_signal(interrupt)
                    else:
                        (root / 'release').touch()
                        if case == 'live_input':
                            os.write(master, b'yes\n')
                while collector.poll() is None:
                    self.assertLess(time.monotonic(), deadline + 7, 'collector cleanup hung')
                    if select.select([master], [], [], .03)[0]:
                        data = os.read(master, 65536)
                        output.extend(data)
                        stream.feed(data)
                while select.select([master], [], [], .03)[0]:
                    data = os.read(master, 65536)
                    output.extend(data)
                    stream.feed(data)
                stderr = collector.communicate(timeout=1)[1]
                after = termios.tcgetattr(slave)
                if fault == 'terminal':
                    self.assertNotEqual(after, before)
                else:
                    self.assertEqual(after, before, 'parent terminal settings must be restored exactly')
                self.assertFalse(screen.cursor.hidden, 'collector must show the parent cursor')
                self.assertIn(b'\x1b[?25h\x1b[0m', output)
                self.assertIsNone(sentinel.poll(), 'unrelated process must survive cleanup')
                reap_deadline = time.monotonic() + 3
                while any(self.process_exists(pid) for pid in owned) and time.monotonic() < reap_deadline:
                    time.sleep(.02)
                self.assertFalse([pid for pid in owned if self.process_exists(pid)],
                                 'no owned live or zombie process may remain')
                summary_path = root / 'capture/summary.json'
                summary = (json.loads(summary_path.read_text()) if summary_path.exists()
                           else json.loads(stderr.decode().splitlines()[-1]))
                self.assertEqual(summary['capture_exit_code'], collector.returncode)
                if case == 'live_input':
                    self.assertIn(b'accepted', output)
                    events = (root / 'capture/input-events.jsonl').read_text()
                    self.assertNotIn('yes', events)
                    self.assertTrue(any(json.loads(line)['source'] == 'terminal'
                                        for line in events.splitlines()))
                audit = json.loads((root / 'audit.json').read_text())
                self.assertTrue(audit['signals_restored'])
                self.assertTrue(audit['subreaper_restored'])
                self.assertIn(owned[0], audit['waited'], 'direct child must be reaped by collector')
                if sys.platform.startswith('linux'):
                    self.assertTrue(set(owned).issubset(audit['waited']),
                                    'Linux collector must reap orphan descendants')
                return summary
            finally:
                # Only processes allocated by this test are ever signalled.
                # Keep the PTY open until the collector gets its cleanup chance.
                if collector is not None and collector.poll() is None:
                    collector.terminate()
                    try:
                        collector.wait(timeout=4)
                    except subprocess.TimeoutExpired:
                        collector.kill()
                        collector.wait(timeout=2)
                if (root / 'owned.json').exists():
                    owned = json.loads((root / 'owned.json').read_text())
                for pid in owned:
                    try:
                        if os.getsid(pid) == owned[0]:
                            os.kill(pid, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                sentinel.terminate()
                sentinel.wait(timeout=2)
                termios.tcsetattr(slave, termios.TCSANOW, before)
                os.close(master)
                os.close(slave)
                if collector is not None and collector.stderr is not None:
                    collector.stderr.close()

    def test_success_restores_terminal_and_cleans_descendants(self):
        summary = self.run_lifecycle('success')
        self.assertEqual(summary['child_exit_code'], 0)
        self.assertEqual(summary['capture_exit_code'], 0)
        self.assertIsNone(summary['termination'])

    def test_child_failure_restores_terminal_and_preserves_status(self):
        summary = self.run_lifecycle('child_failure')
        self.assertEqual(summary['child_exit_code'], 7)
        self.assertEqual(summary['capture_exit_code'], 7)
        self.assertIsNone(summary['termination'])

    def test_interruption_restores_terminal_and_separates_status(self):
        for sig in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
            with self.subTest(signal=sig):
                summary = self.run_lifecycle('interrupt', interrupt=sig)
                self.assertEqual(summary['child_exit_code'], 143)
                self.assertEqual(summary['capture_exit_code'], 128 + sig)
                self.assertEqual(summary['termination'], 'signal')

    def test_timeouts_restore_terminal_and_separate_status(self):
        for case in ('timeout', 'idle_timeout'):
            with self.subTest(case=case):
                summary = self.run_lifecycle(case)
                self.assertEqual(summary['child_exit_code'], 143)
                self.assertEqual(summary['capture_exit_code'], 124)
                self.assertEqual(summary['termination'], case)

    def test_collector_failure_restores_terminal_and_separates_status(self):
        summary = self.run_lifecycle('collector_failure', fault='record')
        self.assertEqual(summary['child_exit_code'], 137)
        self.assertEqual(summary['capture_exit_code'], 125)
        self.assertEqual(summary['termination'], 'collector_error')
        self.assertIn('injected recording failure', summary['collector_errors'][0])

    def test_metadata_failure_after_fork_still_cleans_descendants(self):
        summary = self.run_lifecycle('collector_failure', fault='metadata')
        self.assertEqual(summary['child_exit_code'], 137)
        self.assertEqual(summary['capture_exit_code'], 125)
        self.assertIn('injected metadata failure', summary['collector_errors'][0])

    def test_terminal_restoration_failure_does_not_skip_other_cleanup(self):
        summary = self.run_lifecycle('success', fault='terminal')
        self.assertEqual(summary['child_exit_code'], 0)
        self.assertEqual(summary['capture_exit_code'], 125)
        self.assertIn('injected terminal restoration failure', summary['collector_errors'][0])

    def test_live_keyboard_input_reaches_child_without_storing_contents(self):
        summary = self.run_lifecycle('live_input')
        self.assertEqual(summary['child_exit_code'], 0)
        self.assertEqual(summary['capture_exit_code'], 0)

    def test_metadata_failure_before_child_session_creation_reaps_direct_child(self):
        with tempfile.TemporaryDirectory(prefix='orbit-recorder-startup-') as temporary:
            root = Path(temporary)
            wrapper = root / 'wrapper.py'
            wrapper.write_text(WRAPPER)
            collector = subprocess.Popen([
                sys.executable, str(wrapper), str(CAPTURE), str(root), 'early_metadata',
                '--candidate', 'startup-fixture', '--label', 'startup', '--no-live',
                '--output-dir', str(root / 'capture'), '--', sys.executable, '-c', 'pass'],
                stdout=subprocess.PIPE, stderr=subprocess.PIPE, start_new_session=True)
            try:
                collector.communicate(timeout=5)
                self.assertEqual(collector.returncode, 125)
                child = int((root / 'early-child').read_text())
                self.assertFalse(self.process_exists(child))
                summary = json.loads((root / 'capture/summary.json').read_text())
                self.assertEqual(summary['child_exit_code'], 137)
                self.assertEqual(summary['capture_exit_code'], 125)
                audit = json.loads((root / 'audit.json').read_text())
                self.assertIn(child, audit['waited'])
                self.assertTrue(audit['signals_restored'])
            finally:
                if collector.poll() is None:
                    collector.kill()
                    collector.wait(timeout=2)
                if (root / 'early-child').exists():
                    child = int((root / 'early-child').read_text())
                    try:
                        if os.getsid(child) == collector.pid:
                            os.kill(child, signal.SIGKILL)
                    except ProcessLookupError:
                        pass
                collector.stdout.close()
                collector.stderr.close()

    def test_summary_stderr_failure_invalidates_on_disk_success(self):
        summary = self.run_lifecycle('success', fault='stderr')
        self.assertEqual(summary['child_exit_code'], 0)
        self.assertEqual(summary['capture_exit_code'], 125)
        self.assertIn('injected stderr failure', summary['collector_errors'][0])

    def test_summary_failure_reports_separate_status_on_stderr(self):
        summary = self.run_lifecycle('success', fault='summary')
        self.assertEqual(summary['child_exit_code'], 0)
        self.assertEqual(summary['capture_exit_code'], 125)
        self.assertIn('injected summary failure', summary['collector_errors'][0])


if __name__ == '__main__':
    unittest.main()
