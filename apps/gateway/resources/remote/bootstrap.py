# Starts a Python remote program: `python3 -I -c <this file> <program name>`. Standard input holds the assembled
# program, one NUL byte, and the program's JSON input. The program reads that input as its own standard input.
import io
import sys

# The programs use os.path.realpath(strict=True), which Python 3.10 added.
if sys.version_info < (3, 10):
    sys.exit(f'{sys.argv[1]}: Orbit remote programs need Python 3.10 or newer, and this Node has {sys.version.split()[0]}.')

source, _, payload = sys.stdin.buffer.read().partition(b'\0')
sys.stdin = io.TextIOWrapper(io.BytesIO(payload), encoding='utf-8')
exec(compile(source, sys.argv[1], 'exec'), {'__name__': '__main__', '__orbit_source__': source})
