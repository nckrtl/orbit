#!/usr/bin/env bash
set -euo pipefail

orbit=/home/orbit/orbit/apps/cli/orbit
gateway=/home/orbit/orbit/apps/gateway
production_user=orb210prod
production_home=/home/orb210prod
production_id_file=/home/orbit/.orbit/orb210-prod-id
ssh_key=/home/orbit/.orbit/ssh/id_ed25519
known_hosts=/home/orbit/.orbit/ssh/known_hosts
remote=(ssh -i "$ssh_key" -o BatchMode=yes -o StrictHostKeyChecking=yes -o UserKnownHostsFile="$known_hosts" orbit@10.44.0.3)

fail() {
    printf 'environment-cli-prepare: %s\n' "$1" >&2
    exit 1
}

[[ -x "$orbit" ]] || fail 'CLI entrypoint is unavailable'
[[ -f "$ssh_key" ]] || fail 'Gateway SSH identity is unavailable'
"${remote[@]}" "sudo -n useradd --create-home --home-dir '$production_home' --shell /bin/bash '$production_user'"

production_id=$(cd "$gateway" && php8.5 artisan tinker --execute='
$development = App\Models\AppInstance::query()->findOrFail(1);
$development->update(["source_is_laravel" => true]);
$app = App\Models\App::query()->findOrFail(1);
$node = App\Models\Node::query()->findOrFail(3);
$instance = App\Models\AppInstance::query()->create([
    "app_id" => $app->id,
    "node_id" => $node->id,
    "name" => "orb210-prod",
    "environment" => "production",
    "checkout_path" => "/home/orb210prod",
    "production_user" => "orb210prod",
    "production_home" => "/home/orb210prod",
    "source_is_laravel" => true,
    "provisioning_step" => "active",
]);
$route = App\Models\Route::query()->create([
    "app_id" => $app->id,
    "node_id" => $node->id,
    "hostname" => "orb210-prod.orbit",
    "provenance" => "explicit",
    "publication" => "private",
    "status" => "pending",
]);
$route->targets()->create(["app_instance_id" => $instance->id, "position" => 0]);
$route->update(["status" => "active"]);
$instance->update(["status" => "active"]);
echo $instance->id;
')
[[ "$production_id" =~ ^[1-9][0-9]*$ ]] || fail 'production AppInstance fixture was not recorded'
printf '%s\n' "$production_id" > "$production_id_file"

printf '%s\n' 'APP_KEY=base64:orb210-production-key' 'APP_URL=http://localhost' 'ORB_ENV_PROOF=before' \
    | "${remote[@]}" "sudo -n -u '$production_user' -- sh -c 'umask 077; cat > \"\$HOME/.env\"'"
"${remote[@]}" "test ! -e '$production_home/database'" || fail 'production fixture unexpectedly has a database'

"$orbit" app:list --json >/dev/null
printf 'environment-cli-prepare ok\n'
