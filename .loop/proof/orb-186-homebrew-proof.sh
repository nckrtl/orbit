#!/bin/sh

set -eu

mode="${1:-}"
database=/home/orbit/.orbit/gateway.sqlite
brew=/home/linuxbrew/.linuxbrew/bin/brew
brew_revision=2b3683acbeac84c27669195235785694b72e253e
brew_origin=https://github.com/Homebrew/brew
node_address=10.44.0.2

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

remote() {
    ssh \
        -i /home/orbit/.orbit/ssh/id_ed25519 \
        -o BatchMode=yes \
        -o StrictHostKeyChecking=yes \
        -o UserKnownHostsFile=/home/orbit/.orbit/ssh/known_hosts \
        -- "orbit@$node_address" "$@"
}

remote_brew() {
    remote env \
        HOMEBREW_NO_AUTO_UPDATE=1 \
        HOMEBREW_NO_ANALYTICS=1 \
        HOMEBREW_NO_ENV_HINTS=1 \
        PATH=/home/linuxbrew/.linuxbrew/bin:/usr/bin:/bin \
        "$brew" "$@"
}

assert_manager() {
    payload=$1
    status=$2
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        foreach ($payload["managers"] as $manager) {
            if ($manager["name"] !== "brew") {
                continue;
            }
            if ($manager["status"] !== $argv[2]) {
                exit(1);
            }
            if ($argv[2] === "uninstalled" && $manager["id"] !== null) {
                exit(1);
            }
            if ($argv[2] === "active" && (! is_int($manager["id"]) || $manager["installed_version"] !== "Homebrew 6.0.6")) {
                exit(1);
            }
            exit(0);
        }
        exit(1);
    ' "$payload" "$status"
}

if [ "$mode" = lifecycle ]; then
    before=$(orbit tool:manager:list --node="$managed_node_id" --json)
    assert_manager "$before" uninstalled

    set +e
    rejected=$(orbit tool:install other/tap/herdr --manager=brew --node="$managed_node_id" --json 2>&1)
    rejected_status=$?
    set -e
    [ "$rejected_status" -ne 0 ]
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        if (($payload["error"]["code"] ?? null) !== "tool.package_invalid") {
            exit(1);
        }
    ' "$rejected"
    after_rejection=$(orbit tool:manager:list --node="$managed_node_id" --json)
    assert_manager "$after_rejection" uninstalled

    installed=$(orbit tool:install herdr --manager=brew --constraint='^0.9' --node="$managed_node_id" --json)
    tool_id=$(
        php -r '
            $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
            if (
                ! is_int($payload["id"] ?? null)
                || ($payload["manager"] ?? null) !== "brew"
                || ($payload["package"] ?? null) !== "herdr"
                || ($payload["status"] ?? null) !== "installed"
                || ($payload["installed_version"] ?? null) !== "0.9.0"
                || ($payload["outcome"] ?? null) !== "applied"
            ) {
                exit(1);
            }
            echo $payload["id"];
        ' "$installed"
    )

    active=$(orbit tool:manager:list --node="$managed_node_id" --json)
    assert_manager "$active" active
    [ "$(remote git -C /home/linuxbrew/.linuxbrew/Homebrew remote get-url origin)" = "$brew_origin" ]
    [ "$(remote git -C /home/linuxbrew/.linuxbrew/Homebrew rev-parse HEAD)" = "$brew_revision" ]
    [ -z "$(remote git -C /home/linuxbrew/.linuxbrew/Homebrew status --porcelain=v1 --untracked-files=all)" ]
    [ "$(remote_brew list --versions --formula homebrew/core/herdr)" = 'herdr 0.9.0' ]
    [ "$(remote /home/linuxbrew/.linuxbrew/bin/herdr --version)" = 'herdr 0.9.0' ]
    if remote pgrep -x herdr >/dev/null 2>&1; then
        exit 1
    fi

    updated=$(orbit tool:update "$tool_id" --json)
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        if (
            ($payload["manager"] ?? null) !== "brew"
            || ($payload["installed_version"] ?? null) !== "0.9.0"
            || ($payload["outcome"] ?? null) !== "unchanged"
        ) {
            exit(1);
        }
    ' "$updated"
    [ "$(remote /home/linuxbrew/.linuxbrew/bin/herdr --version)" = 'herdr 0.9.0' ]

    removed=$(orbit tool:remove "$tool_id" --json)
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        if (
            ($payload["manager"] ?? null) !== "brew"
            || ($payload["package"] ?? null) !== "herdr"
            || ($payload["outcome"] ?? null) !== "applied"
        ) {
            exit(1);
        }
    ' "$removed"
    tools=$(orbit tool:list --node="$managed_node_id" --json)
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        foreach ($payload["tools"] as $tool) {
            if ($tool["manager"] === "brew" && $tool["package"] === "herdr") {
                exit(1);
            }
        }
    ' "$tools"
    retained=$(orbit tool:manager:list --node="$managed_node_id" --json)
    assert_manager "$retained" active
    [ -x "$brew" ] || remote test -x "$brew"
    if remote_brew list --versions --formula homebrew/core/herdr >/dev/null 2>&1; then
        exit 1
    fi
    exit 0
fi

if [ "$mode" = recognition ]; then
    remote_brew install --formula --force-bottle homebrew/core/herdr >/dev/null
    cleanup_formula() {
        remote_brew uninstall --formula homebrew/core/herdr >/dev/null 2>&1 || true
    }
    trap cleanup_formula EXIT

    php -r '
        $database = new PDO("sqlite:/home/orbit/.orbit/gateway.sqlite");
        $statement = $database->prepare("DELETE FROM tool_managers WHERE node_id = ? AND name = ?");
        $statement->execute([$argv[1], "brew"]);
    ' "$managed_node_id"

    before=$(orbit tool:manager:list --node="$managed_node_id" --json)
    assert_manager "$before" uninstalled
    set +e
    rejected=$(orbit tool:install herdr --manager=brew --node="$managed_node_id" --json 2>&1)
    rejected_status=$?
    set -e
    [ "$rejected_status" -ne 0 ]
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        if (($payload["error"]["code"] ?? null) !== "tool.already_installed") {
            exit(1);
        }
    ' "$rejected"

    active=$(orbit tool:manager:list --node="$managed_node_id" --json)
    assert_manager "$active" active
    tools=$(orbit tool:list --node="$managed_node_id" --json)
    php -r '
        $payload = json_decode($argv[1], true, flags: JSON_THROW_ON_ERROR);
        foreach ($payload["tools"] as $tool) {
            if ($tool["manager"] === "brew" && $tool["package"] === "herdr") {
                exit(1);
            }
        }
    ' "$tools"
    [ "$(remote git -C /home/linuxbrew/.linuxbrew/Homebrew rev-parse HEAD)" = "$brew_revision" ]
    [ "$(remote /home/linuxbrew/.linuxbrew/bin/herdr --version)" = 'herdr 0.9.0' ]
    if remote pgrep -x herdr >/dev/null 2>&1; then
        exit 1
    fi

    cleanup_formula
    trap - EXIT
    retained=$(orbit tool:manager:list --node="$managed_node_id" --json)
    assert_manager "$retained" active
    exit 0
fi

exit 64
