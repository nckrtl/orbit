#!/usr/bin/env python3
"""Usage: VENV/bin/python orb352-states.py EXISTING_SESSION_ROOT EXACT_SHA.

Record twelve public ProgressDisplay fixture cases into NEW SESSION_ROOT/states.
The expected frames encode the contributor standard, with fixture-local labels.
"""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import runpy
import shutil
import subprocess
import sys

from wcwidth import wcswidth


SOURCE = Path('/home/orbit/orbit')
SKILL = SOURCE / '.agents/skills/verifying-cli-output/scripts'
FIXTURE = SOURCE / 'apps/cli/tests/Fixtures/Console/progress-states-fixture.php'
LABELS = {
    'check': {'waiting': 'Validate local record', 'running': 'Validating local record', 'success': 'Validated local record', 'failure': 'Validating local record'},
    'review': {'waiting': 'Review local note', 'running': 'Reviewing local note', 'warning': 'Reviewed local note'},
    'optional': {'skipped': 'Read optional input'},
}
MESSAGES = {
    'success': 'Local record is valid.',
    'warning': 'The optional note is incomplete; the local record remains valid and unchanged.',
    'failure': 'The local record is invalid; no follow-up work was started and nothing was changed.',
    'skipped': 'No optional input was provided; this step did not read or change a local record.',
}
COLORS = {'waiting': 'default', 'running': 'cyan', 'success': 'green', 'failure': 'red', 'warning': 'ff8700', 'skipped': 'ff8700'}


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def save(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def manifest(directory):
    return [{'path': str(path.relative_to(directory)), 'bytes': path.stat().st_size, 'sha256': sha(path)}
            for path in sorted(directory.rglob('*')) if path.is_file() and path != directory / 'manifest.json']


def row(text, segments):
    """Expected row with explicit style spans, measured in terminal cells."""
    require(wcswidth(text) == len(text), 'Fixture expectations must use one-cell characters')
    return {'text': text, 'styles': segments}


def panel(states, glyphs, columns, outcome=None):
    rows = [row('', []), row('┌  Local checks', [(0, 3, 'default', True), (3, 15, 'default', False)]), row('│', [(0, 1, 'default', True)])]
    for name in ['check', 'review', 'optional']:
        state = states[name]
        text = '├  ' + glyphs[name] + ' ' + LABELS[name][state]
        rows.append(row(text, [(0, 3, 'default', True), (3, 4, COLORS[state], state == 'waiting'),
                              (4, 5, 'default', False), (5, len(text), 'default', state == 'waiting')]))
        message = MESSAGES.get(state, '')
        for start in range(0, len(message), columns - 5):
            text = '│    ' + message[start:start + columns - 5]
            rows.append(row(text, [(0, 5, 'default', True), (5, len(text), 'red' if state == 'failure' else 'default', state in ['warning', 'skipped'])]))
        rows.append(row('│', [(0, 1, 'default', True)]))
    footer = outcome or 'Working...'
    color = 'red' if outcome and 'failure' in states.values() else 'default'
    rows.append(row('└  ' + footer, [(0, 3, 'default', True), (3, 3 + len(footer), color, outcome is None)]))
    rows.append(row('', []))
    require(all(wcswidth(item['text']) <= columns for item in rows), 'Expected panel exceeds terminal')
    return rows


def inspect_frame(frame, columns):
    texts = [line.rstrip() for line in frame['lines']]
    titles = [index for index, text in enumerate(texts) if text == '┌  Local checks']
    if not titles:
        return None
    require(len(titles) == 1, 'Progress title duplicated in a reconstructed frame')
    title = titles[0]
    footers = [index for index in range(title + 1, len(texts)) if texts[index].startswith('└  ')]
    if not footers:
        return None
    require(len(footers) == 1, 'Progress footer duplicated')
    footer = footers[0]
    step_rows = [text for text in texts[title:footer] if text.startswith('├  ')]
    if len(step_rows) != 3:
        return None
    states, glyphs = {}, {}
    for name, text in zip(['check', 'review', 'optional'], step_rows):
        match = re.fullmatch(r'├  ([○◉●]) (.+)', text)
        require(match is not None, 'Step connector/glyph spacing differs')
        glyph, label = match.groups()
        candidates = [state for state, expected in LABELS[name].items() if expected == label
                      and ((state == 'waiting' and glyph == '○') or (state == 'running' and glyph in ['○', '◉'])
                           or (state not in ['waiting', 'running'] and glyph == '●'))]
        require(len(candidates) == 1, 'Unknown or ambiguous step state: ' + text)
        states[name], glyphs[name] = candidates[0], glyph
    outcome = texts[footer][3:]
    require(outcome in ['Working...', 'Local checks completed.', 'Local checks failed.'], 'Unknown footer outcome')
    expected = panel(states, glyphs, columns, None if outcome == 'Working...' else outcome)
    start = title - 1
    require(start >= 0 and start + len(expected) <= len(texts), 'Full panel does not fit the actual viewport')
    require(frame['lines'][start:start + len(expected)] == [item['text'].ljust(columns) for item in expected],
            'Complete progress frame content/order/spacing/wrapping differs')
    require(all(not text for text in texts[:start] + texts[start + len(expected):]), 'Progress left duplicate/stale rows outside its panel')
    cells = {(cell['row'], cell['column']): cell for cell in frame['cells']}
    for index, expected_row in enumerate(expected):
        actual_row = start + index
        require(wcswidth(frame['lines'][actual_row]) == columns, 'Reconstructed frame width differs')
        for left, right, color, dim in expected_row['styles']:
            for column in range(left, right):
                cell = cells.get((actual_row, column), {'fg': 'default', 'bg': 'default', 'dim': False, 'bold': False})
                require(cell['fg'] == color and cell['bg'] == 'default' and cell['dim'] == dim and not cell['bold'],
                        f'Progress style differs at row {actual_row}, column {column}: expected {color}/dim={dim}; got {cell}')
    return {'states': states, 'glyphs': glyphs, 'footer': outcome, 'panel_rows': len(expected), 'elapsed': frame['elapsed']}


def assert_auto(frames, scenario, columns):
    observed = []
    for index, frame in enumerate(frames):
        result = inspect_frame(frame, columns)
        if result is not None:
            observed.append({'frame': index, **result})
    require(observed, 'No complete styled progress frame was captured')
    final_states = {'check': 'success' if scenario == 'success' else 'failure',
                    'review': 'warning' if scenario == 'success' else 'waiting', 'optional': 'skipped'}
    outcome = 'Local checks completed.' if scenario == 'success' else 'Local checks failed.'
    final = inspect_frame(frames[-1], columns)
    require(final is not None and final['states'] == final_states and final['footer'] == outcome, 'Final styled result is incomplete or incorrect')
    required = {'check': ['waiting', 'running', final_states['check']],
                'review': ['waiting', 'running', 'warning'] if scenario == 'success' else ['waiting'],
                'optional': ['skipped']}
    for name, expected in required.items():
        sequence = []
        for frame in observed:
            state = frame['states'][name]
            if not sequence or state != sequence[-1]:
                sequence.append(state)
        require(sequence == expected, f'State sequence differs for {name}: {sequence} != {expected}')
        if 'running' in expected:
            glyphs = {frame['glyphs'][name] for frame in observed if frame['states'][name] == 'running'}
            require(glyphs == {'○', '◉'}, 'Active glyph alternation missing for ' + name)
    require(any(frame['footer'] == 'Working...' for frame in observed), 'Pending footer was never observed')
    require(not frames[-1]['cursor']['hidden'], 'Final cursor remains hidden')
    return {'complete_frames_verified': len(observed), 'observations': observed, 'final_states': final_states,
            'treatments': 'waiting dim; running cyan glyph; success green; failure red; warning/skipped orange with dim explanation; final footer full strength'}


def assert_plain(raw, scenario, columns):
    require(b'\x1b' not in raw, 'Plain progress emitted ANSI or repaint bytes')
    states = {'check': 'success' if scenario == 'success' else 'failure',
              'review': 'warning' if scenario == 'success' else 'waiting', 'optional': 'skipped'}
    glyphs = {name: '○' if state == 'waiting' else '●' for name, state in states.items()}
    outcome = 'Local checks completed.' if scenario == 'success' else 'Local checks failed.'
    waiting = 'Validating local record...\n' + ('Reviewing local note...\n' if scenario == 'success' else '')
    expected = waiting + '\n'.join(item['text'] for item in panel(states, glyphs, columns, outcome)) + '\n'
    actual = raw.decode('utf-8', errors='strict').replace('\r\n', '\n')
    require(actual == expected, 'Plain progress differs from one waiting line per callback plus complete settled tree')
    require(all(wcswidth(line) <= columns for line in actual.splitlines()), 'Plain progress relies on terminal auto-wrap')
    return {'complete_plain_output_verified': True, 'waiting_lines': 2 if scenario == 'success' else 1,
            'final_states': states, 'footer': outcome}


def main():
    require(len(sys.argv) == 3, __doc__)
    root, candidate = Path(sys.argv[1]).resolve() / 'states', sys.argv[2]
    require(re.fullmatch('[0-9a-f]{40}', candidate) is not None, 'Exact candidate SHA required')
    root.mkdir(parents=True, exist_ok=False)
    identity = runpy.run_path(str(Path(__file__).with_name('orb352-identity.py')))['verify_source'](SOURCE, candidate, root)
    php = shutil.which('php')
    require(php is not None, 'PHP launcher missing')
    spec = importlib.util.spec_from_file_location('orb352_verify_states', SKILL / 'verify.py')
    verifier = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(verifier)
    save(root / 'identity.json', {'candidate': candidate, 'source_identity': identity, 'php': php,
         'python': sys.executable, 'fixture_sha256': sha(FIXTURE), 'runner_sha256': sha(Path(__file__)),
         'capture_sha256': sha(SKILL / 'capture.py'), 'verify_sha256': sha(SKILL / 'verify.py')})
    environment = {**os.environ, 'TERM': 'xterm-256color', 'LC_ALL': 'C.UTF-8'}
    for key in ['NO_COLOR', 'CLICOLOR', 'FORCE_COLOR', 'COLUMNS', 'LINES', 'ORBIT_UX_FAULT', 'ORBIT_UX_TRACE']:
        environment.pop(key, None)
    reports = []
    for columns in [60, 80, 120]:
        for scenario in ['success', 'failure']:
            for mode in ['auto', 'plain']:
                label = f'states-{scenario}-{columns}x24-{mode}'
                directory = root / label
                directory.mkdir()
                trace = directory / 'trace.json'
                command = [php, str(FIXTURE), scenario, mode, str(trace)]
                invocation = [sys.executable, str(SKILL / 'capture.py'), '--output-dir', str(directory / 'capture'),
                    '--candidate', candidate, '--label', label, '--columns', str(columns), '--rows', '24',
                    '--timeout', '15', '--idle-timeout', '5', '--', *command]
                save(directory / 'case.json', {'candidate': candidate, 'argv': command, 'recorder_argv': invocation,
                     'columns': columns, 'rows': 24, 'format': mode, 'width_source': 'detected actual PTY',
                     'scenario': scenario, 'expected_status': 0 if scenario == 'success' else 7})
                capture = subprocess.run(invocation, cwd=SOURCE, env=environment)
                try:
                    status = 0 if scenario == 'success' else 7
                    require(capture.returncode == status, 'Capture status differs from expected local result')
                    expectation = {'candidate': candidate, 'label': label, 'exit_code': status,
                        'final_contains': ['Local checks completed.' if scenario == 'success' else 'Local checks failed.'],
                        'absent': ['PHP Fatal error', 'PHP Warning']}
                    save(directory / 'expectation.json', expectation)
                    verified = verifier.verify(directory / 'capture', expectation)
                    save(directory / 'verify.json', verified)
                    require(verified['passed'], str(verified['failures']))
                    metadata = json.loads((directory / 'capture/metadata.json').read_text())
                    require(metadata['columns'] == columns and metadata['rows'] == 24 and metadata['child_has_pty'], 'Capture dimensions or PTY differ')
                    facts = json.loads(trace.read_text())
                    require(facts['scenario'] == scenario and facts['status'] == status, 'Trace scenario/status differs')
                    require(facts['mode'] == {'machine': False, 'mayPrompt': True, 'decorated': mode == 'auto', 'mayRepaint': mode == 'auto', 'columns': columns}, 'Detected terminal mode differs')
                    require(facts['callbacks'] == {'check': 1, 'review': 1 if scenario == 'success' else 0}, 'Local callback counts or failure follow-up differ')
                    frames = [json.loads(line) for line in (directory / 'capture/frames.jsonl').read_text().splitlines()]
                    raw = (directory / 'capture/raw.bin').read_bytes()
                    assertions = assert_auto(frames, scenario, columns) if mode == 'auto' else assert_plain(raw, scenario, columns)
                    report = {'label': label, 'passed': True, 'assertions': assertions, 'callbacks': facts['callbacks']}
                except Exception as error:
                    report = {'label': label, 'passed': False, 'failure': str(error)}
                save(directory / 'assertions.json', report)
                save(directory / 'manifest.json', manifest(directory))
                reports.append(report)
                print(json.dumps({'case': label, 'passed': report['passed'], 'manifest': str(directory / 'manifest.json'),
                     'manifest_bytes': (directory / 'manifest.json').stat().st_size, 'manifest_sha256': sha(directory / 'manifest.json')}), flush=True)
    save(root / 'result.json', {'candidate': candidate, 'passed': all(report['passed'] for report in reports),
         'cases': reports, 'scope': 'ProgressDisplay state styles and frames; no public command-family adoption verdict.'})
    save(root / 'manifest.json', manifest(root))
    print(json.dumps({'result': str(root / 'result.json'), 'manifest': str(root / 'manifest.json'),
         'manifest_bytes': (root / 'manifest.json').stat().st_size, 'manifest_sha256': sha(root / 'manifest.json'),
         'cases': len(reports), 'passed': sum(report['passed'] for report in reports)}), flush=True)
    return 0 if all(report['passed'] for report in reports) else 1


if __name__ == '__main__':
    sys.exit(main())
