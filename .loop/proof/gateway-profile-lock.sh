#!/usr/bin/env bash

set -euo pipefail

cd /home/orbit/orbit/apps/cli

vendor/bin/pest --no-tia --compact \
    tests/Unit/GatewayConfigRepositoryTest.php \
    tests/Unit/GatewayConfigRepositorySecurityTest.php \
    tests/Unit/GatewayConfigRepositoryOwnershipTest.php

printf '%s\n' 'gateway-profile-lock: ok'
