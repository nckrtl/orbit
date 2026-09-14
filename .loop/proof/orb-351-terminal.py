#!/usr/bin/env python3
"""Disposable terminal stimulus; deliberately performs no product operation."""
import os
import sys
import time

columns, rows = os.get_terminal_size()
print(f'Terminal {columns}x{rows}; fixture only', flush=True)
answer = input('Continue with disposable fixture? [y/N] ')
if answer != 'y':
    print('Cancelled', flush=True)
    raise SystemExit(1)
print('\x1b[2mUnicode: café 界 ┌─┐\x1b[22m', flush=True)
print('Work: queued', end='', flush=True)
time.sleep(.3)
for glyph in ['⠋', '⠙', '⠹', '⠸', '⠼']:
    print(f'\r\x1b[2K\x1b[36mWork: running {glyph}\x1b[0m', end='', flush=True)
    time.sleep(.3)
print('\r\x1b[2K\x1b[32mWork: complete\x1b[0m', flush=True)
if '--large' in sys.argv:
    for index in range(100):
        print(f'{index:03d} ' + 'recorded bytes ' * 8)
print('FINAL-MARKER', flush=True)
