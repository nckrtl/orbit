#!/usr/bin/env bash
set -euo pipefail
unit=$(systemctl list-units --all --no-legend | awk '/orb361-drift-check/{print $1; exit}')
test -n "$unit"
sudo systemctl start "$unit"
