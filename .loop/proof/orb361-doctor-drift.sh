#!/usr/bin/env bash
set -euo pipefail
state_dir=/var/lib/orbit-e2e/proof
sudo mkdir -p "$state_dir"
conf=$(sudo find /etc/php -path '*/fpm/pool.d/orbit-scopes.conf' 2>/dev/null | head -n1)
test -n "$conf"
line=$(sudo awk '/^\[orbit-instance-1\]/{f=1; next} /^\[/{f=0} f && /^pm\.max_children[[:space:]]*=/{print NR; exit}' "$conf")
test -n "$line"
original=$(sudo sed -n "${line}p" "$conf")
printf '%s\n%s\n%s\n' "$conf" "$line" "$original" | sudo tee "$state_dir/orb361-drift-state" > /dev/null
sudo awk -v n="$line" 'NR==n{print "pm.max_children = 1"; next} {print}' "$conf" | sudo tee "$conf.orb361tmp" > /dev/null
sudo mv "$conf.orb361tmp" "$conf"
