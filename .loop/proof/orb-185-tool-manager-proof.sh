#!/bin/sh

set -eu

mode="${1:-}"
database=/home/orbit/.orbit/gateway.sqlite

managed_node_id=$(
    php -r '
        $database = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
        $id = $database->query(
            "SELECT nodes.id FROM nodes INNER JOIN node_roles ON node_roles.node_id = nodes.id WHERE node_roles.role = \"app-dev\" LIMIT 1",
        )->fetchColumn();
        if (! is_int($id) && ! ctype_digit((string) $id)) {
            exit(1);
        }
        echo $id;
    '
)

if [ "$mode" = materialize ]; then
    php -r '
        $database = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
        $statement = $database->prepare("DELETE FROM tool_managers WHERE node_id = ? AND name = ?");
        $statement->execute([$argv[1], "composer"]);
    ' "$managed_node_id"

    before=$(orbit tool:manager:list --node="$managed_node_id" --json)
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        foreach ($payload["managers"] as $manager) {
            if ($manager["name"] === "composer") {
                if ($manager["id"] !== null || $manager["status"] !== "uninstalled") {
                    exit(1);
                }
                exit(0);
            }
        }
        exit(1);
    ' "$before"

    installed=$(orbit tool:install phpstan/phpstan --manager=composer --node="$managed_node_id" --json)
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        if (
            $payload["manager"] !== "composer"
            || $payload["package"] !== "phpstan/phpstan"
            || $payload["status"] !== "installed"
        ) {
            exit(1);
        }
    ' "$installed"

    after=$(orbit tool:manager:list --node="$managed_node_id" --json)
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        foreach ($payload["managers"] as $manager) {
            if ($manager["name"] === "composer") {
                if (! is_int($manager["id"]) || $manager["status"] !== "active") {
                    exit(1);
                }
                exit(0);
            }
        }
        exit(1);
    ' "$after"
    exit 0
fi

if [ "$mode" = unmanaged ]; then
    unmanaged_node_id=$(
        php -r '
            $database = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
            $statement = $database->prepare(
                "INSERT INTO nodes (name, status, platform, public_ssh_host, wireguard_ip, created_at, updated_at) VALUES (?, ?, ?, ?, ?, ?, ?)",
            );
            $now = gmdate("Y-m-d H:i:s");
            $statement->execute([
                "unmanaged-tool-client",
                "active",
                "linux",
                "192.0.2.250",
                "10.44.0.250",
                $now,
                $now,
            ]);
            echo $database->lastInsertId();
        '
    )

    cleanup_unmanaged_node() {
        php -r '
            $database = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
            $statement = $database->prepare("DELETE FROM nodes WHERE id = ?");
            $statement->execute([$argv[1]]);
        ' "$unmanaged_node_id"
    }
    trap cleanup_unmanaged_node EXIT

    set +e
    rejected=$(orbit tool:install jq --manager=apt --node="$unmanaged_node_id" --json 2>&1)
    status=$?
    set -e
    [ "$status" -ne 0 ]
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        if (($payload["error"]["code"] ?? null) !== "tool.node_unmanaged") {
            exit(1);
        }
    ' "$rejected"
    cleanup_unmanaged_node
    trap - EXIT
    exit 0
fi

exit 64
