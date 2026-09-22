"""Exercise the real command in a PTY; return raw bytes, status, and termios restoration."""
import base64
import errno
import fcntl
import json
import os
import pty
import select
import struct
import subprocess
import sys
import termios
import time

configuration = json.loads(open(sys.argv[3]).read())
master, slave = pty.openpty()
fcntl.ioctl(slave, termios.TIOCSWINSZ, struct.pack('HHHH', 50, configuration.get('columns', 200), 0, 0))
before = termios.tcgetattr(slave)
process = subprocess.Popen(sys.argv[1:4], stdin=slave, stdout=slave, stderr=slave,
                           env={**os.environ, 'TERM': 'xterm-256color', 'PAO_DISABLE': '1'})
output = bytearray()
keys = [base64.b64decode(key) for key in configuration['keys']]
ready = False
gateway_switched = False
last_key = 0
started = time.monotonic()
while process.poll() is None:
    if time.monotonic() - started > 10:
        process.kill()
        process.wait()
        raise RuntimeError('Command fixture timed out: ' + output.decode(errors='replace'))
    if select.select([master], [], [], 0.02)[0]:
        try:
            output.extend(os.read(master, 65536))
        except OSError as error:
            if error.errno != errno.EIO:
                raise
    ready = ready or configuration['prompt'].encode() in output
    if ready and not gateway_switched and 'switch_gateway' in configuration:
        subprocess.run([sys.argv[1], os.path.join(os.path.dirname(sys.argv[2]), '../../../orbit'),
                        'gateway:use', configuration['switch_gateway'], '--json'],
                       env={**os.environ, 'ORBIT_HOME': configuration['home'], 'PAO_DISABLE': '1'},
                       capture_output=True, check=True, timeout=5)
        gateway_switched = True
    if ready and keys and time.monotonic() - last_key > 0.15:
        os.write(master, keys.pop(0))
        last_key = time.monotonic()
while select.select([master], [], [], 0.02)[0]:
    try:
        chunk = os.read(master, 65536)
    except OSError as error:
        if error.errno != errno.EIO:
            raise
        break
    if not chunk:
        break
    output.extend(chunk)
after = termios.tcgetattr(slave)
# Darwin sets PENDIN when stty restores canonical input. It is a transient
# kernel input-reprocessing marker, not a change to the saved terminal mode.
if sys.platform == 'darwin':
    before[3] &= ~termios.PENDIN
    after[3] &= ~termios.PENDIN
restored = after == before
os.close(master)
os.close(slave)
print(json.dumps({'status': process.returncode, 'raw': base64.b64encode(output).decode(),
                  'restored': restored, 'termios_diff': repr([(i, a, b) for i, (a, b) in enumerate(zip(before, after)) if a != b]), 'prompt_seen': ready, 'keys_remaining': len(keys),
                  'gateway_switched': gateway_switched}))
