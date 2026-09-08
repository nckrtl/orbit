<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

/**
 * @mago-expect lint:cyclomatic-complexity The adapter keeps each fail-closed VP parsing branch explicit.
 * @mago-expect lint:too-many-methods The closed manager contract requires every lifecycle method on one adapter.
 */
final readonly class VpToolManager implements ToolManager
{
    private const string VP_BINARY = '/usr/local/bin/vp';

    private const int MAX_PACKAGE_LENGTH = 214;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A(?:[a-z0-9][a-z0-9._~-]*|@[a-z0-9][a-z0-9._~-]*\/[a-z0-9][a-z0-9._~-]*)\z/D';

    public function __construct(
        private RemoteToolCommandRunner $commands,
        private SemverVersionNormalizer $versions,
    ) {}

    public function name(): ToolManagerName
    {
        return ToolManagerName::Vp;
    }

    public function supportsNode(Node $node): bool
    {
        return $node->platform === 'linux';
    }

    public function validatePackage(string $package): bool
    {
        $length = strlen($package);

        return $length >= 1 && $length <= self::MAX_PACKAGE_LENGTH && preg_match(self::PACKAGE_PATTERN, $package) === 1;
    }

    public function materialize(Node $node): void
    {
        $this->guardNode($node);

        $program = <<<'BASH'
            managed_user=$1
            passwd_entry=$(getent passwd -- "$managed_user")
            test "$(printf '%s\n' "$passwd_entry" | wc -l)" -eq 1
            managed_home=$(printf '%s\n' "$passwd_entry" | cut -d: -f6)
            managed_group=$(id -gn -- "$managed_user")

            if { [ -e /opt/orbit ] || [ -L /opt/orbit ]; } \
                && { [ -L /opt/orbit ] || [ ! -d /opt/orbit ] || [ "$(stat -c '%U:%G' /opt/orbit)" != 'root:root' ]; }; then
                printf 'Orbit Vite Plus directory conflict: %s\n' /opt/orbit >&2
                exit 1
            fi
            install -d -m 0755 /opt/orbit

            vp_home=
            vp_environment=
            launcher_environment=
            for candidate in /opt/orbit/vite-plus "$managed_home/.vite-plus" "$managed_home/.local/share/vite-plus"; do
                if [ -e "$candidate" ] || [ -L "$candidate" ]; then
                    vp_home="$candidate"
                    break
                fi
            done
            if [ -z "$vp_home" ]; then
                vp_home="$managed_home/.local/share/vite-plus"
            fi
            if [ "$vp_home" = /opt/orbit/vite-plus ]; then
                vp_environment='VP_HOME=/opt/orbit/vite-plus'
                launcher_environment='export VP_HOME=/opt/orbit/vite-plus'
            fi
            if { [ -e "$vp_home" ] || [ -L "$vp_home" ]; } \
                && { [ -L "$vp_home" ] || [ ! -d "$vp_home" ]; }; then
                printf 'Orbit Vite Plus directory conflict: %s\n' "$vp_home" >&2
                exit 1
            fi
            vp_binary="$vp_home/bin/vp"
            if [ ! -x "$vp_binary" ]; then
                sudo -u "$managed_user" -H env -u VP_HOME bash -o pipefail -c 'curl -fsSL https://vite.plus | bash'
                test -x "$vp_binary"
                sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" env setup
                sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" env on
                sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" env install lts
                sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" env default lts
                sudo -u "$managed_user" -H env ${vp_environment:-} "$vp_binary" install -g --node lts pnpm
            fi
            test -x "$vp_binary"
            test -x "$vp_home/bin/pnpm"

            launcher_candidates=$(mktemp -d "/usr/local/bin/.orbit-vp-runtime.XXXXXX")
            published_paths=
            rollback_vp_runtime() {
                runtime_status=$?
                if [ "$runtime_status" -ne 0 ]; then
                    for published_path in $published_paths; do
                        rm -f -- "$published_path"
                    done
                fi
                rm -rf -- "$launcher_candidates"
                return "$runtime_status"
            }
            trap rollback_vp_runtime EXIT

            for binary in vp node pnpm npm npx; do
                target="$vp_home/bin/$binary"
                candidate="$launcher_candidates/$binary"
                test -x "$target"
                launcher_header='#!/bin/sh'
                if [ -n "${launcher_environment:-}" ]; then
                    launcher_header="$launcher_header\\n$launcher_environment"
                fi
                printf '%b\n' "$launcher_header" "exec \"$target\" \"\$@\"" > "$candidate"
                chmod 0755 "$candidate"
                chown root:root "$candidate"
            done

            for binary in vp node pnpm npm npx; do
                launcher="/usr/local/bin/$binary"
                candidate="$launcher_candidates/$binary"
                if { [ -e "$launcher" ] || [ -L "$launcher" ]; } \
                    && { [ -L "$launcher" ] || [ ! -f "$launcher" ] \
                        || [ "$(stat -c '%U:%G' "$launcher")" != 'root:root' ] \
                        || [ "$(stat -c '%a' "$launcher")" != '755' ] \
                        || ! cmp -s "$launcher" "$candidate"; }; then
                    printf 'Orbit Vite Plus launcher conflict: %s\n' "$launcher" >&2
                    exit 1
                fi
            done

            for binary in vp node pnpm npm npx; do
                launcher="/usr/local/bin/$binary"
                candidate="$launcher_candidates/$binary"
                if ! { [ -e "$launcher" ] || [ -L "$launcher" ]; }; then
                    mv "$candidate" "$launcher"
                    published_paths="$published_paths $launcher"
                fi
            done

            sudo -u "$managed_user" -H /usr/local/bin/vp --version
            sudo -u "$managed_user" -H /usr/local/bin/node --version
            sudo -u "$managed_user" -H /usr/local/bin/pnpm --version
            sudo -u "$managed_user" -H /usr/local/bin/npm --version
            sudo -u "$managed_user" -H /usr/local/bin/npx --version

            rm -rf -- "$launcher_candidates"
            launcher_candidates=
            published_paths=
            trap - EXIT
            BASH;

        $result = $this->commands->execute($node, ['sudo', 'bash', '-seu', '--', $node->user], $program);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'materialize',
            message: 'The VP manager could not be materialized.',
        );
    }

    public function managerVersion(Node $node): string
    {
        $this->guardNode($node);

        $result = $this->commands->execute($node, [self::VP_BINARY, '--version']);

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-version',
            message: 'The VP manager version probe failed.',
        );

        $lines = preg_split('/\R/', $result->stdout, limit: 2);
        $version = is_array($lines) ? $lines[0] ?? '' : '';

        if (! $this->isSafeString($version)) {
            throw new ToolManagerException(
                step: 'manager-version',
                message: 'The VP manager version probe returned malformed output.',
                result: $result,
            );
        }

        return $version;
    }

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): ?string
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $this->vpArguments('info', $package, 'version', '--json'));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'candidate-version',
            message: 'The VP candidate version probe failed.',
        );

        return $this->decodeJsonString($result, 'candidate-version');
    }

    public function installedVersion(Node $node, string $package): ?string
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $this->vpArguments('list', '-g', $package, '--json'));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'installed-version',
            message: 'The VP installed version probe failed.',
        );

        /** @var mixed $decoded */
        $decoded = json_decode($result->stdout);

        if (! is_array($decoded)) {
            throw new ToolManagerException(
                step: 'installed-version',
                message: 'The VP installed version probe returned malformed output.',
                result: $result,
            );
        }

        if ($decoded === []) {
            return null;
        }

        $matchedVersion = null;

        /** @var mixed $entry */
        foreach ($decoded as $entry) {
            if (! is_object($entry)) {
                throw new ToolManagerException(
                    step: 'installed-version',
                    message: 'The VP installed version probe returned malformed output.',
                    result: $result,
                );
            }

            /** @var mixed $name */
            $name = $entry->name ?? null;
            /** @var mixed $version */
            $version = $entry->version ?? null;

            if (
                ! is_string($name)
                || ! is_string($version)
                || ! $this->isSafeString($name)
                || ! $this->isSafeString($version)
            ) {
                throw new ToolManagerException(
                    step: 'installed-version',
                    message: 'The VP installed version probe returned malformed output.',
                    result: $result,
                );
            }

            if ($name !== $package) {
                continue;
            }

            if ($matchedVersion !== null) {
                throw new ToolManagerException(
                    step: 'installed-version',
                    message: 'The VP installed version probe returned ambiguous output.',
                    result: $result,
                );
            }

            $matchedVersion = $version;
        }

        return $matchedVersion;
    }

    public function normalizeVersion(string $rawVersion): ?string
    {
        return $this->versions->normalize($rawVersion);
    }

    public function install(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'install',
            arguments: $this->vpArguments('install', '-g', $package, '--node', 'lts'),
        );
    }

    public function update(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'update',
            arguments: $this->vpArguments('update', '-g', $package, '--reinstall-node-mismatch'),
        );
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $this->vpArguments('remove', '-g', '--dry-run', $package));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'removal-plan',
            message: 'The VP removal plan failed.',
        );

        return new ToolRemovalPlan([$package]);
    }

    public function remove(Node $node, string $package): void
    {
        $this->mutate(
            node: $node,
            package: $package,
            step: 'remove',
            arguments: $this->vpArguments('remove', '-g', $package),
        );
    }

    private function guardNode(Node $node): void
    {
        if ($this->supportsNode($node)) {
            return;
        }

        throw new ToolManagerException(
            step: 'node',
            message: 'The VP tool manager does not support this node.',
        );
    }

    private function guardPackage(string $package): void
    {
        if ($this->validatePackage($package)) {
            return;
        }

        throw new ToolManagerException(
            step: 'package',
            message: 'The VP package coordinate is invalid.',
        );
    }

    private function guardSuccessfulResult(CommandResult $result, string $step, string $message): void
    {
        if ($result->succeeded()) {
            return;
        }

        throw new ToolManagerException(
            step: $step,
            message: $message,
            result: $result,
        );
    }

    private function decodeJsonString(CommandResult $result, string $step): string
    {
        /** @var mixed $decoded */
        $decoded = json_decode($result->stdout, associative: true);

        if (! is_string($decoded) || ! $this->isSafeString($decoded)) {
            throw new ToolManagerException(
                step: $step,
                message: 'The VP candidate version probe returned malformed output.',
                result: $result,
            );
        }

        return $decoded;
    }

    private function isSafeString(string $value): bool
    {
        return (
            $value !== ''
            && strlen($value) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1
        );
    }

    /** @param non-empty-list<string> $arguments */
    private function mutate(Node $node, string $package, string $step, array $arguments): void
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $arguments);

        $this->guardSuccessfulResult(
            result: $result,
            step: $step,
            message: "The VP {$step} operation failed.",
        );
    }

    /** @return non-empty-list<string> */
    private function vpArguments(string ...$arguments): array
    {
        return [
            self::VP_BINARY,
            ...$arguments,
        ];
    }
}
