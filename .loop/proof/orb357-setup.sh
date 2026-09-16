#!/usr/bin/env bash
set -euo pipefail
sudo apt-get update -qq
sudo apt-get install -y -qq python3-venv sqlite3 php-mysql
systemctl restart php8.5-fpm 2>/dev/null || true
venv=/home/orbit/.local/state/orbit-cli-ux/ORB-357/venv
python3 -m venv "$venv"
"$venv/bin/python" -m pip install --disable-pip-version-check -q -r /home/orbit/orbit/.agents/skills/verifying-cli-output/scripts/requirements.txt
python3 - <<'PY'
import sqlite3
from pathlib import Path
path = Path('/home/orbit/apps/laravel-typed/e2e-dev/database/database.sqlite')
if path.is_file():
    connection = sqlite3.connect(path)
    connection.execute('CREATE TABLE IF NOT EXISTS ux357_items (name TEXT NOT NULL)')
    connection.commit()
PY
