#!/usr/bin/env bash
set -euo pipefail
sudo apt-get update -qq
sudo apt-get install -y -qq python3-venv
orbit_ux_venv=/home/orbit/.local/state/orbit-cli-ux/ORB-353/venv
python3 -m venv "$orbit_ux_venv"
"$orbit_ux_venv/bin/python" -m pip install --disable-pip-version-check -q -r /home/orbit/orbit/.agents/skills/verifying-cli-output/scripts/requirements.txt
"$orbit_ux_venv/bin/python" /var/lib/orbit-e2e/proof/orb353-action.py setup
