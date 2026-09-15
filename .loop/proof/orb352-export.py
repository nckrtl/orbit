#!/usr/bin/env python3
"""Export one bounded read of a retained issue recording archive."""
import base64
import hashlib
import json
from pathlib import Path
import sys

path = Path(sys.argv[1]).resolve(strict=True)
assert path.is_relative_to('/home/orbit/.local/state/orbit-cli-ux/ORB-352')
assert path.is_file() and path.name.endswith('.tar.xz')
expected = sys.argv[2]
offset = int(sys.argv[3])
data = path.read_bytes()
assert hashlib.sha256(data).hexdigest() == expected
assert 0 <= offset < len(data)
chunk = data[offset:offset + 2048]
print(json.dumps({'offset': offset, 'total': len(data), 'bytes': len(chunk),
                  'sha256': expected, 'base64': base64.b64encode(chunk).decode()}))
