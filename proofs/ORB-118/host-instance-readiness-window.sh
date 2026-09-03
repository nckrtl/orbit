#!/usr/bin/env bash
set -euo pipefail

cd /home/orbit/orbit/apps/e2e
php artisan test --compact tests/Unit/E2E/TopologyConvergerTest.php

printf 'host-instance-readiness-window: six transient probes recovered before one-shot hydration and persistent failures stopped at attempt 30\n'
