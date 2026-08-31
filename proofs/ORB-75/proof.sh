#!/usr/bin/env bash
set -euo pipefail
source /var/lib/orbit-e2e/proof/lib.sh

criterion=${1:-}

case "$criterion" in
  01)
    with_tld=$(orbit cluster:new orb75-dev --tld=' ORB75 ' --json)
    without_tld=$(orbit cluster:new orb75-prod --json)
    [[ "$(echo "$with_tld" | json_get tld)" == orb75 ]] || fail "Cluster TLD was not normalized: $with_tld"
    [[ "$(echo "$without_tld" | json_get tld)" == null ]] || fail "Cluster without TLD returned a value: $without_tld"

    api=$(gateway_json GET /api/v1/clusters)
    [[ "$(echo "$api" | json_find data name orb75-dev tld)" == orb75 ]] || fail "API omitted normalized TLD: $api"
    [[ "$(echo "$api" | json_find data name orb75-prod tld)" == null ]] || fail "API omitted null TLD: $api"
    php /var/lib/orbit-e2e/proof/sdk-check.php clusters
    echo "criterion 1: Cluster creation and normalized retrieval passed"
    ;;
  02)
    before=$(cluster_snapshot)
    expect_error validation.failed orbit cluster:new orb75-dev --json
    expect_error validation.failed orbit cluster:new orb75-other --tld=ORB75 --json
    expect_api_error validation.failed POST /api/v1/clusters '{"name":"orb75-malformed","tld":"bad.tld"}'
    dev=$(cluster_id orb75-dev)
    expect_error cluster.router_required orbit cluster:update "$dev" --state=active --json
    after=$(cluster_snapshot)
    [[ "$after" == "$before" ]] || fail "rejected Cluster mutations changed state: before=$before after=$after"
    echo "criterion 2: duplicate, malformed, and state rejections preserved Clusters"
    ;;
  03)
    dev=$(cluster_id orb75-dev)
    prod=$(cluster_id orb75-prod)
    app_dev=$(node_id app-dev)
    [[ "$(node_field app-dev cluster_id)" == null ]] || fail "fresh app-dev was unexpectedly assigned"
    [[ "$(node_field app-prod cluster_id)" == null ]] || fail "fresh app-prod was unexpectedly assigned"

    orbit cluster:node:attach "$dev" "$app_dev" --json >/dev/null
    cli=$(orbit cluster:show "$dev" --json)
    [[ "$(echo "$cli" | json_get nodes.0.id)" == "$app_dev" ]] || fail "CLI omitted Cluster membership: $cli"
    api=$(gateway_json GET "/api/v1/nodes/$app_dev")
    [[ "$(echo "$api" | json_get data.cluster_id)" == "$dev" ]] || fail "API omitted Node membership: $api"
    php /var/lib/orbit-e2e/proof/sdk-check.php membership "$dev" "$app_dev"

    expect_error cluster.membership_conflict orbit cluster:node:attach "$prod" "$app_dev" --json
    [[ "$(node_field app-dev cluster_id)" == "$dev" ]] || fail "second membership disturbed the first"
    echo "criterion 3: API, SDK, and CLI membership is singular"
    ;;
  04)
    dev=$(cluster_id orb75-dev)
    app_dev=$(node_id app-dev)
    app_prod=$(node_id app-prod)
    orbit cluster:node:attach "$dev" "$app_prod" --json >/dev/null
    cluster=$(orbit cluster:router:set "$dev" "$app_dev" --json)
    [[ "$(echo "$cluster" | json_get router.id)" == "$app_dev" ]] || fail "app-dev did not become Router: $cluster"

    node=$(orbit node:show "$app_dev" --json)
    roles=$(echo "$node" | json_get roles)
    [[ "$roles" == *'"app-dev"'* && "$roles" == *'"router"'* ]] || fail "Router did not co-locate with app-dev: $node"
    expect_error validation.failed orbit node:role:add app-prod router --json
    [[ "$(orbit cluster:show "$dev" --json | json_get router.id)" == "$app_dev" ]] || fail "rejected second Router disturbed the first"
    [[ "$(sql_router_count "$dev")" == 1 ]] || fail "Cluster did not retain exactly one Router"
    echo "criterion 4: Router co-location and dedicated singleton boundary passed"
    ;;
  05)
    dev=$(cluster_id orb75-dev)
    app_prod=$(node_id app-prod)
    orbit cluster:update "$dev" --state=active --json >/dev/null
    replaced=$(orbit cluster:router:set "$dev" "$app_prod" --json)
    [[ "$(echo "$replaced" | json_get router.id)" == "$app_prod" ]] || fail "Router replacement failed: $replaced"
    [[ "$(sql_router_count "$dev")" == 1 ]] || fail "Router replacement exposed an invalid count"
    expect_error cluster.active_router_required orbit cluster:router:clear "$dev" --force --json
    [[ "$(orbit cluster:show "$dev" --json | json_get router.id)" == "$app_prod" ]] || fail "active clear lost the Router"

    orbit cluster:update "$dev" --state=inactive --json >/dev/null
    cleared=$(orbit cluster:router:clear "$dev" --force --json)
    [[ "$(echo "$cleared" | json_get router)" == null ]] || fail "inactive Router clear failed: $cleared"
    [[ "$(sql_router_count "$dev")" == 0 ]] || fail "Router row remained after supported clear"
    echo "criterion 5: atomic replacement and deactivate-before-clear passed"
    ;;
  06)
    dev=$(cluster_id orb75-dev)
    [[ "$(sql_mirror_mismatches)" == 0 ]] || fail "migration did not mirror existing WireGuard values"
    app_prod=$(orbit node:show "$(node_id app-prod)" --json)
    [[ "$(echo "$app_prod" | json_get lan_ip)" == null ]] || fail "Node without LAN IP did not return null: $app_prod"

    node=$(provision_app_dev \
      --cluster="$dev" \
      --wireguard-ip=10.44.0.2 \
      --wireguard-address=10.44.0.2 \
      --lan-ip=10.200.0.2 \
      --json)
    [[ "$(echo "$node" | json_get wireguard_ip)" == 10.44.0.2 ]] || fail "canonical WireGuard IP did not round-trip: $node"
    [[ "$(echo "$node" | json_get lan_ip)" == 10.200.0.2 ]] || fail "LAN IP did not round-trip: $node"
    [[ "$(echo "$node" | json_get wireguard_endpoint_override)" == null ]] || fail "bare IP changed the separate endpoint: $node"
    [[ "$(echo "$node" | json_has wireguard_address)" == no ]] || fail "canonical response exposed wireguard_address: $node"
    [[ "$(sql_node_field app-dev wireguard_ip)" == 10.44.0.2 ]] || fail "canonical WireGuard storage changed"
    [[ "$(sql_node_field app-dev wireguard_address)" == 10.44.0.2 ]] || fail "compatibility mirror was not synchronized"
    [[ "$(sql_mirror_mismatches)" == 0 ]] || fail "WireGuard mirrors diverged"
    echo "criterion 6: canonical addresses, optional LAN, endpoint separation, and mirror passed"
    ;;
  07)
    dev=$(cluster_id orb75-dev)
    prod=$(cluster_id orb75-prod)
    app_prod=$(node_id app-prod)
    expect_error cluster.lan_ip_conflict provision_app_prod \
      --cluster="$dev" \
      --wireguard-ip=10.44.0.3 \
      --lan-ip=10.200.0.2 \
      --json
    [[ "$(sql_node_field app-prod cluster_id)" == "$dev" ]] || fail "LAN collision moved app-prod"
    [[ "$(sql_node_field app-prod lan_ip)" == null ]] || fail "LAN collision stored app-prod address"

    orbit cluster:node:detach "$dev" "$app_prod" --force --json >/dev/null
    orbit cluster:node:attach "$prod" "$app_prod" --json >/dev/null
    node=$(provision_app_prod \
      --cluster="$prod" \
      --wireguard-ip=10.44.0.3 \
      --lan-ip=10.200.0.2 \
      --json)
    [[ "$(echo "$node" | json_get cluster_id)" == "$prod" ]] || fail "app-prod did not retain the second Cluster: $node"
    [[ "$(echo "$node" | json_get lan_ip)" == 10.200.0.2 ]] || fail "cross-Cluster LAN reuse failed: $node"
    [[ "$(sql_node_field app-dev lan_ip)" == "$(sql_node_field app-prod lan_ip)" ]] || fail "different Clusters did not retain equal LAN IPs"
    echo "criterion 7: LAN IP uniqueness is Cluster-local"
    ;;
  08)
    before_paths=$(checkout_snapshot)
    before_legacy=$(sql_legacy_settings app-dev)

    first=$(orbit node:settings app-dev --setting=apps.path:/srv/orbit/orb75-apps --json)
    [[ "$(echo "$first" | json_get settings.apps.path)" == /srv/orbit/orb75-apps ]] || fail "apps.path set did not remain raw: $first"
    second=$(orbit node:settings app-dev --setting=apps.path:/srv/orbit/orb75-apps-next --json)
    [[ "$(echo "$second" | json_get settings.apps.path)" == /srv/orbit/orb75-apps-next ]] || fail "apps.path update failed: $second"
    unset=$(orbit node:settings app-dev --setting=apps.path: --json)
    [[ "$(echo "$unset" | json_get settings)" == null ]] || fail "apps.path unset did not return raw null: $unset"

    [[ "$(sql_legacy_settings app-dev)" == "$before_legacy" ]] || fail "apps.path mutation rewrote legacy settings"
    [[ "$(checkout_snapshot)" == "$before_paths" ]] || fail "apps.path mutation moved an existing checkout"
    php /var/lib/orbit-e2e/proof/storage-root-check.php
    echo "criterion 8: apps root raw values, fallback/default, and immutable paths passed"
    ;;
  09)
    expect_nonzero orbit cluster:new --json --no-interaction
    expect_local_error cluster.tld_invalid orbit cluster:new orb75-invalid --tld=bad.tld --json
    expect_local_error cluster.id_invalid orbit cluster:show 0 --json
    expect_local_error cluster.update_required orbit cluster:update 1 --json
    expect_local_error cluster.state_invalid orbit cluster:update 1 --state=pending --json
    expect_local_error cluster.id_invalid orbit cluster:node:attach 0 1 --json
    expect_local_error cluster.id_invalid orbit cluster:node:detach 0 1 --force --json
    expect_local_error cluster.id_invalid orbit cluster:router:set 0 1 --json
    expect_local_error cluster.id_invalid orbit cluster:router:clear 0 --force --json
    expect_local_error cluster.id_invalid orbit cluster:remove 0 --force --json
    expect_local_error cluster.confirmation_required orbit cluster:remove 1 --json --no-interaction

    created=$(orbit cluster:new orb75-cli --tld=ORB75CLI --json)
    cli_cluster=$(echo "$created" | json_get id)
    gateway=$(node_id gateway)
    orbit cluster:list >/dev/null
    orbit cluster:show "$cli_cluster" >/dev/null
    expect_error validation.failed orbit cluster:new orb75-cli --json
    expect_error validation.failed orbit cluster:new orb75-cli-tld --tld=orb75cli --json
    orbit cluster:node:attach "$cli_cluster" "$gateway" --json >/dev/null
    orbit cluster:router:set "$cli_cluster" "$gateway" --json >/dev/null
    updated=$(orbit cluster:update "$cli_cluster" --name=orb75-cli-renamed --tld= --state=active --json)
    [[ "$(echo "$updated" | json_get name)" == orb75-cli-renamed ]] || fail "CLI name update failed: $updated"
    [[ "$(echo "$updated" | json_get tld)" == null ]] || fail "explicit empty TLD did not unset: $updated"
    orbit cluster:update "$cli_cluster" --state=inactive --json >/dev/null
    orbit cluster:router:clear "$cli_cluster" --force --json >/dev/null
    orbit cluster:node:detach "$cli_cluster" "$gateway" --force --json >/dev/null
    expect_local_error cluster.confirmation_required orbit cluster:remove "$cli_cluster" --json --no-interaction
    orbit cluster:remove "$cli_cluster" --force --json >/dev/null

    dev=$(cluster_id orb75-dev)
    provision_app_dev \
      --cluster="$dev" \
      --wireguard-address=10.44.0.2 \
      --lan-ip=10.200.0.2 \
      --setting=apps.path: \
      --json >/dev/null
    provision_app_dev \
      --cluster="$dev" \
      --wireguard-ip=10.44.0.2 \
      --wireguard-address=10.44.0.2 \
      --lan-ip=10.200.0.2 \
      --json >/dev/null
    expect_local_error cluster.id_invalid orbit node:provision app-dev --cluster=0 --json
    expect_local_error node.wireguard_ip_invalid orbit node:provision app-dev --wireguard-ip=not-an-ip --json
    expect_local_error node.lan_ip_invalid orbit node:provision app-dev --lan-ip=fd00::2 --json
    expect_local_error node.wireguard_ip_conflict orbit node:provision app-dev \
      --wireguard-ip=10.44.0.2 --wireguard-address=10.44.0.9 --json
    expect_local_error node.setting_duplicate orbit node:provision app-dev \
      --setting=apps.path:/srv/a --setting=apps.path:/srv/b --json
    echo "criterion 9: every Cluster command and new Node option passed valid and invalid CLI checks"
    ;;
  10)
    dev=$(cluster_id orb75-dev)
    app_dev=$(node_id app-dev)
    orbit cluster:router:set "$dev" "$app_dev" --json >/dev/null
    orbit cluster:update "$dev" --state=active --json >/dev/null
    expect_error cluster.not_empty orbit cluster:remove "$dev" --force --json
    expect_error cluster.router_detach_forbidden orbit cluster:node:detach "$dev" "$app_dev" --force --json
    [[ "$(node_field app-dev cluster_id)" == "$dev" ]] || fail "unsafe detach removed membership"
    [[ "$(orbit cluster:show "$dev" --json | json_get router.id)" == "$app_dev" ]] || fail "unsafe operations removed Router"
    [[ "$(sql_router_count "$dev")" == 1 ]] || fail "unsafe operations changed Router rows"
    echo "criterion 10: non-empty removal and Router detach preserved state"
    ;;
  11)
    app_dev=$(node_id app-dev)
    report=$(orbit doctor --node="$app_dev" --family=role --json)
    [[ "$(echo "$report" | json_get healthy)" == true ]] || fail "Router role Doctor report was not healthy: $report"
    php /home/orbit/orbit/apps/gateway/vendor/bin/pest \
      /home/orbit/orbit/apps/gateway/tests/Unit/Architecture/DoctorModelCoverageTest.php \
      --colors=never >/dev/null
    echo "criterion 11: Doctor partition and active Router projection are healthy"
    ;;
  12)
    [[ "$(sql_mirror_mismatches)" == 0 ]] || fail "final compatibility mirrors diverged"
    nodes=$(orbit node:list --json)
    [[ "$nodes" != *'"wireguard_address"'* ]] || fail "Node collection exposed legacy field"
    dev=$(cluster_id orb75-dev)
    app_dev=$(node_id app-dev)
    [[ "$(orbit cluster:show "$dev" --json | json_get router.id)" == "$app_dev" ]] || fail "final active Router changed"
    orbit gateway:status --json >/dev/null
    [[ "$(sql_node_field app-dev wireguard_address)" == 10.44.0.2 ]] || fail "legacy harness address mirror is missing"
    [[ "$(sql_node_field app-prod wireguard_address)" == 10.44.0.3 ]] || fail "app-prod address mirror is missing"
    echo "criterion 12: final real-node state retains the unchanged harness bridge"
    ;;
  *)
    fail "unknown ORB-75 proof criterion [$criterion]"
    ;;
esac
