#!/usr/bin/env bash

set -euo pipefail

gateway=/home/orbit/orbit/apps/gateway
executor="$gateway/app/Infrastructure/Metrics/MetricsSshExecutor.php"
progress="$gateway/app/Infrastructure/Metrics/MetricsContainerReplacementProgress.php"
runtime="$gateway/app/Infrastructure/Metrics/NativeMetricsContainerRuntime.php"
executor_test="$gateway/tests/Unit/Infrastructure/Metrics/MetricsSshExecutorTest.php"
runtime_test="$gateway/tests/Unit/Infrastructure/Metrics/NativeMetricsContainerRuntimeTest.php"

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
    tests/Unit/Infrastructure/Metrics/MetricsSshExecutorTest.php \
    tests/Unit/Infrastructure/Metrics/NativeMetricsContainerRuntimeTest.php \
    --filter='replacement transition|backup deletion|committed container cleanup|credential verification fails after container commit'

grep -Fq 'public bool $stopped = false;' "$progress"
grep -Fq 'public bool $renamed = false;' "$progress"
grep -Fq 'public bool $replacementStarted = false;' "$progress"
grep -Fq 'public bool $backupDeleted = false;' "$progress"
grep -Fq '$this->removeCommittedBackup($node, $progress);' "$executor"
grep -Fq "'metrics.container_cleanup_failed'" "$executor"
grep -Fq '$containersCommitted = true;' "$runtime"
grep -Fq "it('restores both services when a replacement transition fails'" "$executor_test"
grep -Fq "it('keeps committed replacements when either backup deletion fails'" "$executor_test"
grep -Fq "it('keeps published configuration after committed container cleanup fails'" "$runtime_test"

printf '%s\n' 'metrics-replacement-progress: ok'
