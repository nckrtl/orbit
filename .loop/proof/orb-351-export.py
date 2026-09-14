#!/usr/bin/env python3
"""Read one bounded chunk of a retained proof archive through a recorded action."""
import argparse
import base64
import hashlib
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('archive', type=Path)
parser.add_argument('sha256')
parser.add_argument('offset', type=int)
args = parser.parse_args()
assert args.offset >= 0
assert args.archive.name.endswith('-recordings.tar.gz')
data = args.archive.read_bytes()
assert hashlib.sha256(data).hexdigest() == args.sha256, 'archive changed'
chunk = data[args.offset:args.offset + 2048]
print(json.dumps({'offset': args.offset, 'bytes': len(chunk), 'total': len(data), 'base64': base64.b64encode(chunk).decode()}))
