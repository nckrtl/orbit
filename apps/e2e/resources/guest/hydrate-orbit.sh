#!/usr/bin/env bash
set -euo pipefail
umask 077
[[ $# -eq 2 ]] || exit 64
repo=$1; sha=$2
[[ "$repo" == /* && "$sha" =~ ^[0-9a-f]{40}$ ]] || exit 64
[[ "$(git -C "$repo" rev-parse --verify HEAD^{commit})" == "$sha" ]] || exit 65
export COMPOSER_CACHE_DIR=/home/orbit/.cache/composer
mkdir -p "$COMPOSER_CACHE_DIR" /home/orbit/.orbit
chmod 0700 /home/orbit/.orbit
if [[ "$repo" == /home/orbit/orbit ]]; then
    export ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway
    export ORBIT_HOME=/home/orbit/.orbit
    key_file=$ORBIT_HOME/gateway.app-key
    if [[ ! -s "$key_file" ]]; then
        key_candidate=$(mktemp "$ORBIT_HOME/gateway.app-key.XXXXXX")
        printf 'base64:' > "$key_candidate"
        openssl rand -base64 32 | tr -d '\n' >> "$key_candidate"
        printf '\n' >> "$key_candidate"
        chmod 0600 "$key_candidate"
        mv -f "$key_candidate" "$key_file"
    fi
    app_key=$(cat "$key_file")
    [[ "$app_key" =~ ^base64:[A-Za-z0-9+/]{43}=$ ]]
    cp "$repo/apps/gateway/.env.example" "$repo/apps/gateway/.env"
    sed -i \
        -e "s|^APP_KEY=.*$|APP_KEY=$app_key|" \
        -e 's|^APP_URL=.*$|APP_URL=https://gateway.orbit|' \
        -e 's|^ORBIT_HOME=.*$|ORBIT_HOME=/home/orbit/.orbit|' \
        -e 's|^ORBIT_GATEWAY_CHECKOUT=.*$|ORBIT_GATEWAY_CHECKOUT=/home/orbit/orbit/apps/gateway|' \
        "$repo/apps/gateway/.env"
    printf 'DB_DATABASE=/home/orbit/.orbit/gateway.sqlite\n' >> "$repo/apps/gateway/.env"
    chmod 0600 "$repo/apps/gateway/.env"
fi
hydrate_composer_dependencies() {
    local project_path=$1
    local lock_hash marker marker_tmp

    lock_hash=$(sha256sum "$project_path/composer.lock" | awk '{print $1}')
    marker="$project_path/vendor/.orbit-e2e-composer-lock"
    if [[ -s "$project_path/vendor/autoload.php" && -f "$marker" && "$(<"$marker")" == "$lock_hash" ]]; then
        return
    fi

    composer --working-dir="$project_path" install --no-interaction --no-progress --prefer-dist
    marker_tmp=$(mktemp "$project_path/vendor/.orbit-e2e-composer-lock.XXXXXX")
    printf '%s' "$lock_hash" > "$marker_tmp"
    mv -f "$marker_tmp" "$marker"
}

for project in apps/cli apps/e2e apps/gateway packages/php-sdk; do
    hydrate_composer_dependencies "$repo/$project"
done
printf '%s\n' "$sha" > "$repo/.git/orbit-hydrated.sha"
chmod 0600 "$repo/.git/orbit-hydrated.sha"
printf '{"sha":"%s","hydrated":["apps/cli","apps/e2e","apps/gateway","packages/php-sdk"]}\n' "$sha"
