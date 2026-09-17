#!/usr/bin/env bash
set -euo pipefail
state_dir=/var/lib/orbit-e2e/proof
mapfile -t lines < <(sudo cat "$state_dir/orb361-drift-state")
conf="${lines[0]}"
line_no="${lines[1]}"
original="${lines[2]}"
sudo awk -v n="$line_no" -v repl="$original" 'NR==n{print repl; next} {print}' "$conf" | sudo tee "$conf.orb361tmp" > /dev/null
sudo mv "$conf.orb361tmp" "$conf"
sudo rm -f "$state_dir/orb361-drift-state"
