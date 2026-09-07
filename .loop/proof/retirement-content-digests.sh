#!/usr/bin/env bash

set -euo pipefail

cd /home/orbit/orbit/apps/e2e

php artisan test --compact \
    tests/Unit/E2E/LegacyRetirementTest.php \
    tests/Feature/Commands/LegacyCommandsTest.php

printf '%s\n' 'retirement-content-digests: ok'
