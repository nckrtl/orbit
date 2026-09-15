#!/usr/bin/env python3
"""Record and assert the ORB-352 static layouts on the exact guest candidate.

Usage: VENV/bin/python orb352-layout.py NEW_OUTPUT_ROOT EXACT_CANDIDATE
Run inside disposable app-dev, after the harness verifies its candidate identity.
The supplemental styled scrollback is decoded from the complete raw recording;
it is not a replacement for the original timestamped, fixed-size pyte frames.
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
import unicodedata

import pyte
from wcwidth import wcswidth


SOURCE = Path('/home/orbit/orbit')
SCRIPTS = SOURCE / '.agents/skills/verifying-cli-output/scripts'
FIXTURE = SOURCE / 'apps/cli/tests/Fixtures/Console/layout-fixture.php'
IDENTITY = 'fixture-record-with-a-long-unbroken-identity-0123456789'
FIELDS = [
    ('Name', IDENTITY), ('Unicode', '東京 café 👩‍💻 👍🏽 1️⃣'),
    ('Optional', '—'), ('Enabled', 'no'), ('Count', '0'),
    ('Tags', 'first, second'), ('Literal markup', '<info>literal</info>'),
]
HEADERS = ['RESOURCE IDENTITY', 'UNICODE LOCATION', 'OPTIONAL VALUE',
           'ENABLED STATE', 'REQUEST COUNT']
RECORDS = [[IDENTITY, '東京 café 👩‍💻', '—', 'no', '0'],
           ['<info>literal</info>', '👍🏽 1️⃣', 'present', 'yes', '12']]
FAILURE = ['The fixture request could not be completed.',
           'name: Use a unique fixture name.',
           'name: Keep the entire long identity visible: ' + IDENTITY + '.',
           'Code: fixture.validation_failed', 'Request ID: fixture-request-0123456789']
CSI = re.compile(r'\x1b\[([0-9;?]*)([A-Za-z])')


def require(condition, message):
    if not condition:
        raise AssertionError(message)


def save(path, value):
    path.write_text(json.dumps(value, ensure_ascii=False, indent=2) + '\n')


def normalized(value):
    return unicodedata.normalize('NFC', value)


def sha(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()


def decode_static(raw, columns):
    """Reject repaint/control operations; retain every styled emitted character.

    This narrow decoder accepts only SGR and balanced cursor visibility in an
    append-only layout. Width uses wcwidth's complete-string grapheme handling.
    No ANSI bytes, UTF-8 byte lengths or Python string lengths stand in for cells.
    """
    text = raw.decode('utf-8', errors='strict').replace('\r\n', '\n')
    lines = [[]]
    style = {'fg': 'default', 'dim': False, 'bold': False}
    hidden = False
    position = 0
    while position < len(text):
        char = text[position]
        if char == '\x1b':
            match = CSI.match(text, position)
            require(match is not None, 'Unrecognized terminal escape')
            args, operation = match.groups()
            if operation in ('h', 'l') and args == '?25':
                hidden = operation == 'l'
            else:
                require(operation == 'm', 'Static layout used cursor movement or erase')
                for code in map(int, (args or '0').split(';')):
                    if code == 0:
                        style = {'fg': 'default', 'dim': False, 'bold': False}
                    elif code == 1:
                        style['bold'] = True
                    elif code == 2:
                        style['dim'] = True
                    elif code == 22:
                        style['dim'] = style['bold'] = False
                    elif code == 31:
                        style['fg'] = 'red'
                    elif code == 39:
                        style['fg'] = 'default'
                    else:
                        raise AssertionError('Unexpected static layout SGR: ' + str(code))
            position = match.end()
            continue
        if char == '\n':
            lines.append([])
        else:
            require(ord(char) >= 32 and ord(char) != 127, 'Unexpected control in layout')
            lines[-1].append({'data': char, **style})
        position += 1
    require(not hidden, 'Layout left cursor hidden')
    require(style == {'fg': 'default', 'dim': False, 'bold': False}, 'Layout left active styling')
    result = []
    for characters in lines:
        value = ''.join(cell['data'] for cell in characters)
        width = wcswidth(value)
        require(0 <= width <= columns, f'Output relies on auto-wrap: {width}>{columns}: {value!r}')
        result.append({'text': value, 'display_width': width, 'characters': characters})
    return result


def normal(cell):
    return cell['fg'] == 'default' and not cell['dim'] and not cell['bold']


def fields_match(parts, expected):
    """Match all wrapped fragments, allowing only each cell's right padding."""
    parts = [normalized(part) for part in parts]
    expected = normalized(expected)
    positions = {0}
    for part in parts:
        next_positions = set()
        trailing = len(part) - len(part.rstrip(' '))
        for padding in range(trailing + 1):
            fragment = part[:len(part) - padding] if padding else part
            for position in positions:
                if expected.startswith(fragment, position):
                    next_positions.add(position + len(fragment))
        positions = next_positions
    require(len(expected) in positions, f'Wrapped field differs: {parts!r} != {expected!r}')


def check_table(lines, decorated, columns):
    content = [line for line in lines if line['text']]
    values = [line['text'] for line in content]
    if columns < 23:
        require(not any('│' in value or '─' in value for value in values), 'Fallback retained table borders')
        expected = []
        for index, record in enumerate(RECORDS, 1):
            expected.append('Record ' + str(index))
            expected.extend(header + ': ' + value for header, value in zip(HEADERS, record))
        require(normalized(''.join(values)) == normalized(''.join(expected)), 'Fallback lost or reordered a field')
        require(all(normal(cell) for line in content for cell in line['characters']), 'Fallback is not plain')
        return {'kind': 'labeled-records', 'records': RECORDS, 'headers': HEADERS}
    require(values[0].startswith(' ┌') and values[-1].startswith(' └'), 'Missing table bounds')
    separator = next((i for i, value in enumerate(values) if value.startswith(' ├')), None)
    require(separator is not None, 'Missing header boundary')
    widths = [wcswidth(part) - 2 for part in values[0][2:-1].split('┬')]
    require(len(widths) == 5 and min(widths) >= 1, 'Table lost a column')
    total = wcswidth(values[0])
    require(total == columns, 'Fixture table did not use the detected terminal width')
    matrix = []
    for index, line in enumerate(content):
        value = line['text']
        require(wcswidth(value) == total, 'Misaligned table boundary')
        border = index in (0, separator, len(content) - 1)
        header = 0 < index < separator
        for offset, cell in enumerate(line['characters']):
            expected_dim = decorated and ((border and offset > 0) or cell['data'] == '│' or (header and offset > 0))
            require(cell['dim'] == expected_dim and cell['fg'] == 'default' and not cell['bold'], 'Table border/header/value style mismatch')
        if not border:
            pieces = value.split('│')
            require(len(pieces) == 7 and pieces[0] == ' ' and pieces[-1] == '', 'Broken column separators')
            row = []
            for part, width in zip(pieces[1:-1], widths):
                require(part.startswith(' ') and part.endswith(' ') and wcswidth(part) == width + 2, 'Cell padding or width differs')
                row.append(part[1:-1])
            matrix.append((index, row))
    headers = [row for index, row in matrix if index < separator]
    rows = [row for index, row in matrix if index > separator]
    for column, header in enumerate(HEADERS):
        fields_match([row[column] for row in headers], header)
    split = next((index for index, row in enumerate(rows) if row[0].startswith('<')), None)
    require(split is not None and split > 0, 'Second complete record missing')
    for rendered, expected in zip((rows[:split], rows[split:]), RECORDS):
        for column, value in enumerate(expected):
            fields_match([row[column] for row in rendered], value)
    return {'kind': 'table', 'column_widths': widths, 'headers': HEADERS, 'records': RECORDS,
            'styles': 'dim borders and uppercase headers; full-strength values' if decorated else 'plain'}


def check_detail(lines, decorated):
    values = [line['text'] for line in lines]
    require(values[0] == '' and values[-2:] == ['', ''], 'Detail needs one blank line before and after')
    require(values[1] == '┌  Resource: layout-fixture', 'Detail title differs')
    starts = [index for index, value in enumerate(values) if value.startswith(('├', '└'))]
    require(len(starts) == len(FIELDS), 'Detail lost a field')
    for order, ((label, expected), start) in enumerate(zip(FIELDS, starts)):
        final = order == len(FIELDS) - 1
        stop = starts[order + 1] - 1 if not final else len(values) - 2
        require(values[start - 1] == '│', 'Detail needs a blank continuation separator')
        prefix = ('└' if final else '├') + '  ' + label.ljust(14) + '   '
        require(values[start].startswith(prefix), 'Detail label alignment differs: ' + label)
        parts = [values[start][len(prefix):]]
        for value in values[start + 1:stop]:
            require(value.startswith((' ' if final else '│') + ' ' * (len(prefix) - 1)), 'Detail continuation alignment differs')
            parts.append(value[len(prefix):])
        require(normalized(''.join(parts)) == normalized(expected), 'Detail wrapped value differs: ' + label)
    for line in lines:
        for offset, cell in enumerate(line['characters']):
            connector = offset == 0 and cell['data'] in '┌│├└'
            require(cell['dim'] == (decorated and connector) and cell['fg'] == 'default' and not cell['bold'], 'Detail must dim only connectors')
    return {'fields': FIELDS, 'styles': 'only connectors dim' if decorated else 'plain'}


def check_plain_block(lines, scenario, decorated):
    content = [line for line in lines if line['text']]
    values = [line['text'] for line in content]
    if scenario == 'properties':
        require(values[:2] == ['Fixture properties', '  Primary record'], 'Property hierarchy differs')
        require(all(value.startswith('    ') for value in values[2:]), 'Property fields must be indented four spaces')
        require(normalized(''.join(value[4:] for value in values[2:])) == normalized(''.join(label + ': ' + value for label, value in FIELDS)), 'Property values lost or reordered')
    elif scenario == 'failure':
        require(''.join(values) == ''.join(FAILURE), 'Failure content, order or wrapping differs')
        require(all(not value.startswith(' ') for value in values), 'Failure fields unexpectedly indented')
    else:
        require(values == ['No matching records found.'], 'Empty result message differs')
    message_remaining = len(FAILURE[0]) if scenario == 'failure' else 0
    for line in content:
        for cell in line['characters']:
            red = decorated and message_remaining > 0
            require(cell['fg'] == ('red' if red else 'default') and not cell['dim'] and not cell['bold'], 'Property/failure/empty styling differs')
            message_remaining -= 1
    return {'kind': scenario, 'values': FIELDS if scenario == 'properties' else FAILURE if scenario == 'failure' else values}


def manifest(directory):
    return [{'path': str(path.relative_to(directory)), 'bytes': path.stat().st_size, 'sha256': sha(path)}
            for path in sorted(directory.rglob('*')) if path.is_file() and path != directory / 'manifest.json']


def check_captured_frame(frame, lines, raw):
    """Check exact viewport positions, with source-mapped emoji spill limits.

    Derive scroll positions from the full, independently asserted raw rows.
    Mark every emulator row touched by a source row containing joined emoji,
    including overflow fragments that no longer contain an emoji character.
    Never match rows by unordered text membership.
    """
    # Use recorded terminal columns, not Python string lengths of wide glyphs.
    columns = frame['proof_columns']
    height = sum(len(line['text']) // columns + 3 for line in lines) + len(frame['lines'])
    screen = pyte.Screen(columns, height)
    stream = pyte.Stream(screen)
    styled_lines = raw.decode('utf-8', errors='strict').replace('\r\n', '\n').split('\n')
    require(len(styled_lines) == len(lines), 'Raw/source line inventory differs')
    origins = {}
    affected = set()
    for index, line in enumerate(lines):
        first_row = screen.cursor.y
        # Preserve SGR boundaries during replay. Pyte 0.8.2 can stop drawing
        # the rest of a text segment at a joined emoji. An ANSI boundary starts
        # the following segment, so stripping SGR changes its visible rows.
        # This verifies the exact recorded emulator viewport, while the
        # independent raw field/width/style checks establish the real content.
        stream.feed(styled_lines[index])
        last_row = screen.cursor.y
        unsupported = any(char in line['text'] for char in ['👩', '💻', '👍', '🏽', '\u200d', '\u20e3', '\ufe0f'])
        for row in range(first_row, last_row + 1):
            origins[row] = index
            if unsupported:
                affected.add(row)
        if index < len(lines) - 1:
            stream.feed('\r\n')
    rows = len(frame['lines'])
    offset = max(0, screen.cursor.y - rows + 1)
    expected_view = screen.display[offset:offset + rows]
    require(len(expected_view) == rows, 'Expected viewport row count differs')
    require([normalized(value) for value in frame['lines']] == [normalized(value) for value in expected_view],
            'Reconstructed viewport differs in row content, order, duplication, padding or scroll position')
    require(frame['cursor']['x'] == screen.cursor.x and frame['cursor']['y'] == screen.cursor.y - offset,
            'Final cursor position differs from full static output')
    cells = {(cell['row'], cell['column']): cell for cell in frame['cells']}
    checked = 0
    emoji_rows = []
    for row, visible in enumerate(frame['lines']):
        visible = normalized(visible.rstrip())
        source_index = origins.get(row + offset)
        if row + offset in affected:
            emoji_rows.append({'viewport_row': row, 'source_row': source_index})
            continue
        if source_index is None:
            require(visible == '', 'Unexpected occupied viewport row')
            continue
        line = lines[source_index]
        require(visible == normalized(line['text'].rstrip()), 'Supported viewport row differs from exact source row')
        prefix = ''
        for character in line['characters']:
            column = wcswidth(prefix)
            prefix += character['data']
            if character['data'].isspace() or unicodedata.combining(character['data']):
                continue
            cell = cells.get((row, column))
            require(cell is not None, 'Visible reconstructed cell missing')
            require(all(cell[key] == character[key] for key in ['fg', 'dim', 'bold']), 'Reconstructed cell style differs')
        checked += 1
    require(checked > 0, 'No complete reconstructed rows were verified')
    return {'exact_ordered_viewport_rows_verified': rows, 'complete_source_rows_verified': checked,
            'source_scroll_offset': offset, 'emoji_rows_and_spills_retained_for_solo': emoji_rows}


def main():
    require(len(sys.argv) == 3, __doc__)
    output = Path(sys.argv[1]).resolve()
    candidate = sys.argv[2]
    require(re.fullmatch('[0-9a-f]{40}', candidate) is not None, 'Exact candidate SHA required')
    output.mkdir(parents=True, exist_ok=False)
    source_identity = runpy.run_path(str(Path(__file__).with_name('orb352-identity.py')))['verify_source'](SOURCE, candidate, output)
    php = shutil.which('php')
    require(php is not None, 'PHP launcher missing')
    spec = importlib.util.spec_from_file_location('orbit_capture_verify', SCRIPTS / 'verify.py')
    verifier = importlib.util.module_from_spec(spec)
    spec.loader.exec_module(verifier)
    save(output / 'identity.json', {'candidate': candidate, 'source': str(SOURCE), 'source_identity': source_identity, 'php_launcher': php,
         'php_realpath': str(Path(php).resolve()), 'python': sys.executable,
         'fixture_sha256': sha(FIXTURE), 'capture_sha256': sha(SCRIPTS / 'capture.py'),
         'verify_sha256': sha(SCRIPTS / 'verify.py'), 'runner_sha256': sha(Path(__file__))})
    cases = [(scenario, columns, rows, mode) for columns, rows in [(60, 24), (80, 24), (120, 40)]
             for scenario in ['detail', 'table', 'properties', 'failure', 'empty'] for mode in ['auto', 'plain']]
    cases += [('table', columns, 24, mode) for columns in [23, 22] for mode in ['auto', 'plain']]
    reports = []
    environment = {**os.environ, 'TERM': 'xterm-256color'}
    for key in ['NO_COLOR', 'CLICOLOR', 'FORCE_COLOR', 'COLUMNS', 'LINES']:
        environment.pop(key, None)
    for scenario, columns, rows, mode in cases:
        label = f'layout-{scenario}-{columns}x{rows}-{mode}'
        directory = output / label
        command = [php, str(FIXTURE), scenario, mode]
        capture = [sys.executable, str(SCRIPTS / 'capture.py'), '--output-dir', str(directory),
                   '--candidate', candidate, '--label', label, '--columns', str(columns), '--rows', str(rows),
                   '--timeout', '20', '--idle-timeout', '5', '--', *command]
        result = subprocess.run(capture, cwd=SOURCE, env=environment)
        save(directory / 'case.json', {'candidate': candidate, 'label': label, 'argv': command,
             'recorder_argv': capture, 'columns': columns, 'rows': rows, 'format': mode,
             'width_source': 'detected real child PTY; no fixture width override',
             'environment': {key: environment.get(key) for key in ['TERM', 'NO_COLOR', 'COLUMNS', 'LINES']}})
        try:
            require(result.returncode == 0, 'Recorder did not succeed')
            expected_visible = {'detail': 'Literal markup', 'properties': 'Literal markup',
                                'failure': 'Request ID:', 'empty': 'No matching records found.',
                                'table': '│' if columns >= 23 else 'Record 2'}[scenario]
            expectation = {'candidate': candidate, 'label': label, 'exit_code': 0,
                           'contains': [expected_visible], 'absent': ['PHP Fatal error', 'PHP Warning']}
            save(directory / 'expectation.json', expectation)
            verified = verifier.verify(directory, expectation)
            save(directory / 'verify.json', verified)
            require(verified['passed'], str(verified['failures']))
            raw = (directory / 'raw.bin').read_bytes()
            if mode == 'plain':
                require(b'\x1b' not in raw, 'Plain layout contains escape bytes')
            lines = decode_static(raw, columns)
            save(directory / 'styled-scrollback.json', {'source': 'raw.bin', 'columns': columns,
                 'actual_viewport_rows': rows, 'lines': lines,
                 'scope': 'append-only output; no cursor movement permitted; all lines retained'})
            if scenario == 'table':
                checked = check_table(lines, mode == 'auto', columns)
            elif scenario == 'detail':
                checked = check_detail(lines, mode == 'auto')
            else:
                checked = check_plain_block(lines, scenario, mode == 'auto')
            frames = [json.loads(line) for line in (directory / 'frames.jsonl').read_text().splitlines()]
            require(all(len(frame['lines']) == rows for frame in frames), 'Captured frame dimensions differ')
            require(not frames[-1]['cursor']['hidden'], 'Final captured frame cursor hidden')
            frame_check = check_captured_frame({**frames[-1], 'proof_columns': columns}, lines, raw)
            report = {'label': label, 'passed': True, 'assertions': checked,
                      'full_scrollback_lines': len(lines), 'captured_frames': len(frames),
                      'settled_frame_assertions': frame_check,
                      'limitation': 'pyte 0.8.2 is not a modern emoji grapheme emulator; raw Unicode sequences and wcwidth widths are asserted in supplemental styled scrollback. Solo visual inspection remains required.'}
        except Exception as error:
            report = {'label': label, 'passed': False, 'failure': str(error)}
        save(directory / 'assertions.json', report)
        save(directory / 'manifest.json', manifest(directory))
        reports.append(report)
        print(json.dumps({'case': label, 'passed': report['passed'],
                          'manifest': str(directory / 'manifest.json'),
                          'manifest_bytes': (directory / 'manifest.json').stat().st_size,
                          'manifest_sha256': sha(directory / 'manifest.json')}, ensure_ascii=False), flush=True)
    save(output / 'result.json', {'candidate': candidate, 'passed': all(report['passed'] for report in reports),
         'cases': reports, 'scope': 'shared static layout primitives; no family command compliance verdict'})
    save(output / 'manifest.json', manifest(output))
    print(json.dumps({'result': str(output / 'result.json'), 'manifest': str(output / 'manifest.json'),
                     'manifest_bytes': (output / 'manifest.json').stat().st_size,
                     'manifest_sha256': sha(output / 'manifest.json'),
                     'cases': len(reports), 'passed': sum(report['passed'] for report in reports)}), flush=True)
    return 0 if all(report['passed'] for report in reports) else 1


if __name__ == '__main__':
    sys.exit(main())
