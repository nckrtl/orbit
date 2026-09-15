#!/usr/bin/env python3
"""Prove a visible frame precedes callback entry in the same retained PTY stream.

Set ORBIT_UX_PAINT_NONCE to a fresh 16-64 digit lowercase hex nonce for the
runtime fixture. Pass every admitted marker ID and its required visible row
patterns in a JSON list: [{"id": "operation", "rows": ["...regex..."]}].
The helper prints a verdict; the runner owns retaining it and enforcing pass.
No comparison between callback wall time and collector monotonic time is made.
"""

import argparse
import hashlib
import json
from pathlib import Path
import re

import pyte


def inspect_raw(raw, columns, rows, nonce, expected, callback_pid):
    if re.fullmatch(r'[a-f0-9]{16,64}', nonce) is None:
        raise ValueError('A fresh lowercase hexadecimal nonce is required')
    if not isinstance(columns, int) or not isinstance(rows, int) or min(columns, rows) < 1:
        raise ValueError('Recorded terminal dimensions must be positive integers')
    if not isinstance(callback_pid, int) or callback_pid < 1:
        raise ValueError('The traced PHP parent PID is required')
    if not isinstance(expected, list) or not expected:
        raise ValueError('At least one callback with visible row assertions is required')

    admitted = {}
    for case in expected:
        if not isinstance(case, dict) or set(case) != {'id', 'rows'}:
            raise ValueError('Each expectation needs only id and rows')
        name = case['id']
        if not isinstance(name, str) or re.fullmatch(r'[a-z][a-z0-9-]{0,63}', name) is None:
            raise ValueError('Invalid callback ID')
        patterns = case['rows']
        if name in admitted or not isinstance(patterns, list) or not patterns:
            raise ValueError('Callback IDs must be unique and have visible row assertions')
        if any(not isinstance(pattern, str) or not pattern for pattern in patterns):
            raise ValueError('Row assertions must be nonempty regex strings')
        admitted[name] = [re.compile(pattern) for pattern in patterns]

    stem = b'\x1b]777;orbit-first-paint;' + nonce.encode('ascii') + b';'
    matcher = re.compile(re.escape(stem) + rb'([a-z][a-z0-9-]{0,63});([1-9][0-9]*)\x07')
    markers = list(matcher.finditer(raw))
    failures = []
    callbacks = []
    if raw.count(stem) != len(markers):
        failures.append('A callback marker is malformed or incomplete')
    for name in admitted:
        count = sum(marker.group(1).decode('ascii') == name for marker in markers)
        if count != 1:
            failures.append(f'Expected exactly one callback marker for {name}; observed {count}')

    for marker in markers:
        name = marker.group(1).decode('ascii')
        pid = int(marker.group(2))
        if name not in admitted:
            failures.append(f'Unexpected callback marker: {name}')
            continue
        if pid != callback_pid:
            failures.append(f'Callback {name} did not execute in the traced PHP parent')

        # Cut by raw byte offset, even if marker and paint share one collector
        # chunk. Replaying later bytes would turn a late paint into false proof.
        prefix = raw[:marker.start()]
        screen = pyte.Screen(columns, rows)
        pyte.Stream(screen).feed(prefix.decode('utf-8', errors='replace'))
        matched = []
        for pattern in admitted[name]:
            found = [(row, line.rstrip()) for row, line in enumerate(screen.display)
                     if pattern.search(line.rstrip())]
            if len(found) != 1:
                failures.append(f'{name}: expected one visible row before callback for {pattern.pattern!r}; observed {len(found)}')
            else:
                row, line = found[0]
                matched.append({'pattern': pattern.pattern, 'row': row, 'line': line})
        callbacks.append({'id': name, 'pid': pid, 'marker_offset': marker.start(),
                          'prefix_sha256': hashlib.sha256(prefix).hexdigest(),
                          'matched_rows': matched})

    return {'passed': not failures, 'failures': failures, 'nonce': nonce,
            'raw_bytes': len(raw), 'raw_sha256': hashlib.sha256(raw).hexdigest(),
            'callbacks': callbacks,
            'scope': 'visible PTY bytes before callback entry; not terminal display latency'}


def inspect_capture(capture, candidate, nonce, expected, callback_pid):
    capture = Path(capture)
    if re.fullmatch(r'[a-f0-9]{40}', candidate) is None:
        raise ValueError('The exact candidate SHA is required')
    metadata = json.loads((capture / 'metadata.json').read_text())
    summary = json.loads((capture / 'summary.json').read_text())
    if metadata['candidate'] != candidate or summary['candidate'] != candidate:
        raise ValueError('Recording candidate differs from the expected candidate')
    result = inspect_raw((capture / 'raw.bin').read_bytes(), metadata['columns'],
                         metadata['rows'], nonce, expected, callback_pid)
    return {'candidate': candidate, **result}


def main():
    parser = argparse.ArgumentParser(description=__doc__)
    parser.add_argument('--capture', type=Path, required=True)
    parser.add_argument('--candidate', required=True)
    parser.add_argument('--nonce', required=True)
    parser.add_argument('--expect', type=Path, required=True)
    parser.add_argument('--callback-pid', type=int, required=True)
    args = parser.parse_args()
    try:
        result = inspect_capture(args.capture, args.candidate, args.nonce,
                                 json.loads(args.expect.read_text()), args.callback_pid)
    except (ValueError, OSError, KeyError, TypeError, re.error) as error:
        result = {'passed': False, 'failures': [str(error)]}
    print(json.dumps(result, ensure_ascii=False, indent=2))
    return 0 if result['passed'] else 1


if __name__ == '__main__':
    raise SystemExit(main())
