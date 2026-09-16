#!/usr/bin/env bash
set -euo pipefail
sudo apt-get update -qq
sudo apt-get install -y -qq python3-venv
venv=/home/orbit/.local/state/orbit-cli-ux/ORB-357/venv
python3 -m venv "$venv"
"$venv/bin/python" -m pip install --disable-pip-version-check -q -r /home/orbit/orbit/.agents/skills/verifying-cli-output/scripts/requirements.txt
