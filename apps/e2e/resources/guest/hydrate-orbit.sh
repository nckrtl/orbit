#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $# -eq 2 ]] || exit 64
repo=$1; sha=$2
[[ "$repo" == /* && "$sha" =~ ^[0-9a-f]{40}$ ]] || exit 64
[[ "$(git -C "$repo" rev-parse --verify HEAD^{commit})" == "$sha" ]] || exit 65
export COMPOSER_CACHE_DIR=/home/orbit/.cache/composer
mkdir -p "$COMPOSER_CACHE_DIR"
for project in apps/cli apps/gateway packages/php-sdk; do
    composer --working-dir="$repo/$project" install --no-interaction --no-progress --prefer-dist
done
printf '{"sha":"%s","hydrated":["apps/cli","apps/gateway","packages/php-sdk"]}\n' "$sha"
