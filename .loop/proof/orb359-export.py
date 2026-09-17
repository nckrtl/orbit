#!/usr/bin/env python3
"""Hash proof recordings into a local export directory. Not a published archive."""
import argparse
import hashlib
import json
from pathlib import Path

parser = argparse.ArgumentParser()
parser.add_argument('--state', type=Path, required=True)
parser.add_argument('--output', type=Path, required=True)
args = parser.parse_args()
args.output.mkdir(parents=True, exist_ok=True)
files = []
for path in sorted(args.state.rglob('*')):
    if not path.is_file():
        continue
    relative = path.relative_to(args.state).as_posix()
    digest = hashlib.sha256(path.read_bytes()).hexdigest()
    files.append({'path': relative, 'sha256': digest, 'bytes': path.stat().st_size})
manifest = {
    'kind': 'orb359-proof-state-export',
    'not_published_proof': True,
    'state': str(args.state),
    'files': files,
}
(args.output / 'manifest.json').write_text(json.dumps(manifest, indent=2) + '\n')
print(json.dumps({'exported': len(files), 'output': str(args.output / 'manifest.json')}), flush=True)
