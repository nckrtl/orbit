#!/usr/bin/env bash

set -euo pipefail

gateway=/home/orbit/orbit/apps/gateway

cd "$gateway"
env \
    APP_ENV=testing \
    APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA= \
    APP_CONFIG_CACHE=/tmp/orbit-proof-no-config-cache.php \
    CACHE_STORE=array \
    DB_CONNECTION=sqlite \
    DB_DATABASE=:memory: \
    DB_URL= \
    ORBIT_HOME=/tmp/orbit-gateway-proof \
    QUEUE_CONNECTION=sync \
    SESSION_DRIVER=array \
    vendor/bin/pest --no-tia --compact \
    tests/Feature/Api/AppInstancesTest.php \
    tests/Feature/Domain/ProvisionDevelopmentAppInstanceTest.php \
    --filter='lease|provisioning failure|reservation conflict|without persisting'

printf '%s\n' 'appinstance-failure-lease: ok'
