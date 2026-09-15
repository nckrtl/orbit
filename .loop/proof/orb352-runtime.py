#!/usr/bin/env python3
"""Record real renderer liveness and lifecycle; expected failures are asserted."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import runpy
import secrets
import shutil
import signal
import subprocess
import sys
import termios
import time

SOURCE = Path('/home/orbit/orbit')
SKILL = SOURCE / '.agents/skills/verifying-cli-output/scripts'
FIXTURE = SOURCE / 'apps/cli/tests/Fixtures/Console/runtime-fixture.php'
SELF = Path(__file__).resolve()


def require(ok, message):
    if not ok:
        raise AssertionError(message)


def save(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')


def read_events(path):
    return [json.loads(line) for line in path.read_text().splitlines()] if path.exists() else []


def tty():
    state = termios.tcgetattr(0)
    state[6] = [v.hex() if isinstance(v, bytes) else v for v in state[6]]
    return state


def child(case, trace, shell_trace, fault):
    # The wrapper survives group signals so it can inspect native PHP cleanup
    # before the outer recorder restores its own terminal.
    for signum in (signal.SIGINT, signal.SIGTERM, signal.SIGHUP):
        signal.signal(signum, lambda *_: None)
    before = tty()
    runtime = {'stty_before': before, 'stdin_tty': os.isatty(0),
               'stdout_tty': os.isatty(1), 'stderr_tty': os.isatty(2),
               'launcher': str(Path(shutil.which('php')).resolve())}
    environment = dict(os.environ, ORBIT_UX_TRACE=trace, ORBIT_UX_FAULT=fault)
    argv = ['php', str(FIXTURE), case]
    if case == 'spinner-output-failure':
        # The output stream is deliberately a failing device, so select the
        # renderer primitive explicitly; this is not a public mode claim.
        argv.append('--renderer-fixture')
    result = subprocess.run(argv, cwd=SOURCE, env=environment).returncode
    runtime.update(stty_after=tty(), php_status=result, argv=argv)
    save(Path(shell_trace), runtime)
    return result if result >= 0 else 128 - result


def manifest(directory):
    return [{'path': str(p.relative_to(directory)), 'bytes': p.stat().st_size,
             'sha256': hashlib.sha256(p.read_bytes()).hexdigest()}
            for p in sorted(directory.rglob('*')) if p.is_file() and p.name != 'manifest.json']


def alive(pid):
    try:
        os.kill(pid, 0)
        return True
    except ProcessLookupError:
        return False


def animation(name, active, terminal, minimum=2):
    return {'name': name, 'pattern': active, 'terminal_pattern': terminal,
            'minimum_changes': minimum, 'min_interval': 0.18, 'max_interval': 0.65}


def run_case(root, candidate, spec, verifier, sentinel):
    label = spec['name']
    case_dir = root / label
    case_dir.mkdir()
    capture_dir = case_dir / 'capture'
    trace = case_dir / 'trace.jsonl'
    shell = case_dir / 'shell.json'
    command = [sys.executable, str(SELF), '--child', spec['fixture'],
               str(trace), str(shell), spec.get('fault', '')]
    capture = [sys.executable, str(SKILL / 'capture.py'), '--output-dir', str(capture_dir),
               '--candidate', candidate, '--label', label, '--columns', '80', '--rows', '40',
               '--timeout', str(spec.get('timeout', 20)), '--idle-timeout', '8', '--', *command]
    environment = dict(os.environ, TERM='xterm-256color', LC_ALL='C.UTF-8')
    environment.pop('ORBIT_UX_PAINT_NONCE', None)
    paint_nonce = secrets.token_hex(16) if spec.get('animations') else None
    if paint_nonce is not None:
        environment['ORBIT_UX_PAINT_NONCE'] = paint_nonce
    for key in ['NO_COLOR', 'CLICOLOR', 'FORCE_COLOR', 'COLUMNS', 'LINES']:
        environment.pop(key, None)
    collector = subprocess.Popen(capture, cwd=SOURCE, env=environment)
    delivered = None
    if 'signal' in spec:
        deadline = time.monotonic() + 12
        while collector.poll() is None and time.monotonic() < deadline:
            events = read_events(trace)
            callback = next((e for e in events if e['event'] == 'callback'), None)
            if callback:
                time.sleep(0.7)
                os.kill(callback['pid'], spec['signal'])
                delivered = {'pid': callback['pid'], 'signal': spec['signal']}
                break
            time.sleep(0.025)
    try:
        code = collector.wait(timeout=30)
    except subprocess.TimeoutExpired:
        collector.terminate()
        collector.wait(timeout=8)
        raise
    report = {'name': label, 'passed': False}
    try:
        summary = json.loads((capture_dir / 'summary.json').read_text())
        frames = read_events(capture_dir / 'frames.jsonl')
        raw = (capture_dir / 'raw.bin').read_bytes()
        events = read_events(trace)
        native = json.loads(shell.read_text())
        expected = spec.get('status', 0)
        require(summary['child_exit_code'] == expected, 'Unexpected child status')
        require(not summary['collector_errors'], 'Recorder has collector errors')
        require(summary['drained'], 'Recorder did not drain child output')
        require(native['stty_before'] == native['stty_after'], 'PHP did not restore native terminal settings')
        require(all(native[k] for k in ('stdin_tty', 'stdout_tty', 'stderr_tty')), 'Fixture was not run in a real PTY')
        require(not frames[-1]['cursor']['hidden'], 'Final terminal cursor is hidden')
        require(b'Private fixture detail' not in raw, 'Callback detail leaked')
        callbacks = [e for e in events if e['event'] == 'callback']
        require(len(callbacks) == spec.get('callbacks', 1), 'Callback admission/count differs')
        parent = next(e['pid'] for e in events if e['event'] == 'start')
        require(all(e['pid'] == parent for e in callbacks), 'Business callback left the parent process')
        if spec.get('animations'):
            paint_expectation = first_paint_rows(spec['fixture'])
            paint_helper = runpy.run_path(str(SELF.with_name('orb352-first-paint.py')))
            paint_result = paint_helper['inspect_capture'](capture_dir, candidate, paint_nonce, paint_expectation, parent)
            save(case_dir / 'first-paint-expectation.json', {'nonce': paint_nonce, 'callbacks': paint_expectation})
            save(case_dir / 'first-paint.json', paint_result)
            require(paint_result['passed'], str(paint_result['failures']))
        renderers = [e['pid'] for e in events if e['event'] == 'renderer']
        require(all(not alive(pid) for pid in renderers), 'Owned renderer is still alive')
        require(sentinel.poll() is None, 'Unrelated sentinel was affected')
        finish = next((e for e in events if e['event'] == 'finish'), None)
        if spec['fixture'] not in ('exit', 'spinner-exit'):
            require(finish and finish['async_restored'] and finish['handler_restored'], 'Signal state was not restored')
        if spec['fixture'] in ('invalid-settled-exception', 'failure-teardown-signal'):
            require(any(e['event'] == 'failure' and e['code'] == 57 for e in events), 'Cleanup replaced the original exception')
        if 'signal' in spec:
            require(delivered is not None, 'Signal was not delivered to admitted callback')
        if 'timeout' in spec:
            require(code == 124 and summary['capture_exit_code'] == 124, 'Timeout status was hidden')
            require(summary['termination'] is not None, 'Timeout was not recorded')
        else:
            expectation = {'candidate': candidate, 'label': label, 'exit_code': expected,
                           'absent': ['Private fixture detail', 'PHP Fatal error', 'PHP Warning']}
            if spec.get('final'):
                expectation['final_contains'] = [spec['final']]
            if spec.get('animations'):
                expectation['animation_rows'] = spec['animations']
                expectation['max_first_output_seconds'] = 2
            if spec.get('states'):
                expectation['state_rows'] = spec['states']
            save(case_dir / 'expectation.json', expectation)
            result = verifier.verify(capture_dir, expectation)
            save(case_dir / 'verify.json', result)
            require(result['passed'], str(result['failures']))
            require(code == expected, 'Recorder exit differs from asserted child exit')
        if expected != 0:
            require(b'Resource updated.' not in raw and b'Wait finished.' not in raw, 'Failure claimed completion')
        for frame in frames:
            require(sum('┌  Update resource' in line for line in frame['lines']) <= 1, 'Parent progress tree duplicated')
        if spec.get('animations'):
            for frame in frames:
                for row, line in enumerate(frame['lines']):
                    if 'Resolving resource' not in line and 'Updating resource' not in line:
                        continue
                    cells = [c for c in frame['cells'] if c['row'] == row]
                    label_cells = [c for c in cells if c['column'] >= 5 and c['data'].strip()]
                    require(all(not c.get('dim', False) for c in label_cells), 'Active label was dim')
        report.update(passed=True, status=expected, collector_status=code,
                      renderer_pids=renderers, callback_pid=parent,
                      callback_count=len(callbacks), signal=delivered,
                      native_terminal_restored=True, cursor_visible=True,
                      raw_bytes=len(raw), frames=len(frames),
                      first_output=summary['first_output_seconds'],
                      max_idle_gap=summary['max_idle_gap_seconds'])
    except Exception as error:
        report['failure'] = str(error)
    save(case_dir / 'case.json', {'candidate': candidate, 'spec': spec,
         'argv': command, 'recorder_argv': capture, 'report': report})
    save(case_dir / 'manifest.json', manifest(case_dir))
    print(json.dumps(report), flush=True)
    return report


def first_paint_rows(fixture):
    if fixture in ('short-spinner', 'nested-spinners'):
        expected = [{'id': 'operation', 'rows': [r'^[○◉] Waiting for response$']}]
        if fixture == 'nested-spinners':
            expected.append({'id': 'nested-spinner', 'rows': [r'^[○◉] Waiting for response$', r'^[○◉] Waiting for nested response$']})
        return expected
    expected = [
        {'id': 'operation', 'rows': [r'^┌  Update resource$', r'^├  [○◉] Resolving resource$', r'^├  ○ Update resource$', r'^└  Working\.\.\.$']},
        {'id': 'update', 'rows': [r'^├  ● Resolved resource', r'^├  [○◉] Updating resource$', r'^└  Working\.\.\.$']},
    ]
    if fixture == 'nested':
        expected.append({'id': 'inner-spinner', 'rows': [r'^├  [○◉] Updating resource$', r'^[○◉] Waiting for inner response$']})
    if fixture == 'nested-progress':
        expected.append({'id': 'inner-progress', 'rows': [r'^├  [○◉] Updating resource$', r'^┌  Inner operation$', r'^├  [○◉] Reading inner resource$']})
    return expected


def cases(action):
    if action == 'liveness':
        states = [{'name': 'resolve', 'pattern': r'^[├│]  [○◉●] (?P<state>Resolve resource|Resolving resource|Resolved resource)',
                   'states': ['Resolve resource', 'Resolving resource', 'Resolved resource'],
                   'transitions': [['Resolve resource', 'Resolving resource'], ['Resolving resource', 'Resolved resource']],
                   'required': ['Resolve resource', 'Resolving resource', 'Resolved resource']}]
        yield {'name': 'blocking-and-stream-gaps', 'fixture': 'progress', 'final': 'Resource updated.',
               'states': states, 'animations': [
                   animation('resolve', r'^[├│]  (?P<glyph>[○◉]) Resolving resource', r'^[├│]  ● Resolved resource'),
                   animation('update', r'^[├│]  (?P<glyph>[○◉]) Updating resource', r'^[├│]  ● Updated resource')]}
        yield {'name': 'subsecond-spinner', 'fixture': 'short-spinner', 'final': 'Wait finished.',
               'animations': [animation('short', r'^(?P<glyph>[○◉]) Waiting for response', r'^Wait finished', 2)]}
        yield {'name': 'nested-spinner', 'fixture': 'nested', 'final': 'Resource updated.',
               'animations': [
                   animation('outer', r'^[├│]  (?P<glyph>[○◉]) Updating resource', r'^[├│]  ● Updated resource'),
                   animation('inner', r'^(?P<glyph>[○◉]) Waiting for inner response', r'^Wait finished', 3)]}
        yield {'name': 'nested-spinners', 'fixture': 'nested-spinners', 'final': 'Wait finished.',
               'animations': [
                   animation('outer-spinner', r'^(?P<glyph>[○◉]) Waiting for response', r'^Wait finished', 4),
                   animation('inner-spinner', r'^(?P<glyph>[○◉]) Waiting for nested response', r'^Wait finished', 3)]}
        yield {'name': 'nested-progress', 'fixture': 'nested-progress', 'final': 'Resource updated.',
               'animations': [animation('nested-tree', r'^[├│]  (?P<glyph>[○◉]) Reading inner resource', r'^[├│]  ● Read inner resource', 3)]}
        return
    for fixture, status, final in [('progress', 0, 'Resource updated.'), ('failure', 7, 'Operation failed.'),
                                  ('short-spinner', 0, 'Wait finished.'), ('spinner-failure', 7, 'Wait failed.'),
                                  ('exit', 23, None), ('spinner-exit', 23, 'Wait interrupted.')]:
        yield {'name': 'lifecycle-' + fixture, 'fixture': fixture, 'status': status, 'final': final}
    for fixture in ['signal', 'spinner-signal']:
        for signum in [signal.SIGINT, signal.SIGTERM]:
            yield {'name': f'{fixture}-{signum}', 'fixture': fixture, 'signal': signum,
                   'status': 128 + signum, 'final': 'Wait interrupted.' if fixture.startswith('spinner') else 'Operation interrupted.'}
        yield {'name': fixture + '-timeout', 'fixture': fixture, 'timeout': 2, 'status': 143}
    for fault, callbacks in [('startup', 0), ('ipc', 0), ('renderer-exit', 1)]:
        yield {'name': 'renderer-' + fault, 'fixture': 'spinner', 'fault': fault, 'status': 7, 'callbacks': callbacks}
    yield {'name': 'renderer-output-failure', 'fixture': 'spinner-output-failure', 'status': 7, 'callbacks': 0}
    for fixture in ['invalid-settled', 'invalid-settled-exception', 'failure-teardown-signal', 'nested-invalid-start']:
        yield {'name': fixture, 'fixture': fixture, 'status': 7}
    yield {'name': 'invalid-start', 'fixture': 'invalid-start', 'status': 7, 'callbacks': 0}


def main():
    if sys.argv[1] == '--child':
        return child(*sys.argv[2:])
    action, output, candidate = sys.argv[1:]
    require(action in ('liveness', 'lifecycle'), 'Unknown runtime action')
    require(re.fullmatch(r'[0-9a-f]{40}', candidate), 'Candidate SHA is required')
    root = Path(output) / action
    root.mkdir(parents=True, exist_ok=False)
    runpy.run_path(str(Path(__file__).with_name('orb352-identity.py')))['verify_source'](SOURCE, candidate, root)
    spec = importlib.util.spec_from_file_location('orb352_verify', SKILL / 'verify.py')
    verifier = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(verifier)
    sentinel = subprocess.Popen(['sleep', '600'], start_new_session=True)
    results = []
    try:
        for case in cases(action):
            results.append(run_case(root, candidate, case, verifier, sentinel))
    finally:
        sentinel.terminate()
        sentinel.wait(timeout=5)
    save(root / 'result.json', {'candidate': candidate, 'action': action,
         'passed': all(r['passed'] for r in results), 'cases': results,
         'scope': 'shared runtime primitives; public command-family adoption remains separate'})
    save(root / 'manifest.json', manifest(root))
    return 0 if all(r['passed'] for r in results) else 1


if __name__ == '__main__':
    sys.exit(main())
