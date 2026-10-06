<?php

declare(strict_types=1);

namespace App\Infrastructure\Tools;

use App\Domain\Tools\SemverVersionNormalizer;
use App\Domain\Tools\SupportsToolAdoption;
use App\Domain\Tools\ToolAdoptionFact;
use App\Domain\Tools\ToolInventoryPackage;
use App\Domain\Tools\ToolManager;
use App\Domain\Tools\ToolManagerException;
use App\Domain\Tools\ToolManagerName;
use App\Domain\Tools\ToolOperation;
use App\Domain\Tools\ToolRemovalPlan;
use App\Infrastructure\Processes\CommandResult;
use App\Models\Node;

final readonly class VpToolManager implements SupportsToolAdoption, ToolManager
{
    private const int MAX_PACKAGE_LENGTH = 214;

    private const int MAX_VERSION_LENGTH = 255;

    private const string PACKAGE_PATTERN = '/\A(?:[a-z0-9][a-z0-9._~-]*|@[a-z0-9][a-z0-9._~-]*\/[a-z0-9][a-z0-9._~-]*)\z/D';

    private const int MAC_SCOPE_ABSENT = 42;

    private const int MAC_SCOPE_CONFLICT = 43;

    /**
     * Verifies the enrolled account's existing Vite+ global scope.
     * It does not install Vite+, publish launchers, or change the scope.
     */
    private const string MAC_SCOPE_SCRIPT = <<<'BASH'
        account=$1
        if [ -z "${account:-}" ]; then
            printf 'Orbit Vite Plus account is missing\n' >&2
            exit 1
        fi
        current=$(/usr/bin/id -un)
        if [ "$current" != "$account" ]; then
            printf 'Orbit Vite Plus account mismatch\n' >&2
            exit 1
        fi
        home=$(/usr/bin/dscacheutil -q user -a name "$account" | /usr/bin/awk '/^dir: / { print substr($0, 6); exit }')
        if [ -z "${home:-}" ] || [ ! -d "$home" ]; then
            printf 'Orbit Vite Plus account home is unreadable\n' >&2
            exit 1
        fi
        case "$home" in
            *..*|*[!/A-Za-z0-9._-]*)
                printf 'Orbit Vite Plus account home is unreadable\n' >&2
                exit 1
                ;;
        esac

        valid=
        saw_conflict=0
        consider() {
            scope=$1
            binary="$scope/bin/vp"
            if [ ! -e "$scope" ] && [ ! -L "$scope" ]; then
                return 0
            fi
            if [ -L "$scope" ] || [ ! -d "$scope" ]; then
                saw_conflict=1
                return 0
            fi
            owner=$(/usr/bin/stat -f '%Su' "$scope" 2>/dev/null || true)
            if [ "$owner" != "$account" ]; then
                saw_conflict=1
                return 0
            fi
            if [ ! -e "$binary" ] && [ ! -L "$binary" ]; then
                return 0
            fi
            binary_owner=$(/usr/bin/stat -f '%Su' "$binary" 2>/dev/null || true)
            if [ "$binary_owner" != "$account" ] || [ ! -x "$binary" ]; then
                saw_conflict=1
                return 0
            fi
            if [ -n "$valid" ]; then
                saw_conflict=1
                valid=
                return 0
            fi
            valid=$binary
        }

        consider "$home/.vite-plus"
        consider "$home/.local/share/vite-plus"

        if [ -n "$valid" ] && [ "$saw_conflict" -eq 0 ]; then
            printf '%s\n' "$valid"
            exit 0
        fi
        if [ "$saw_conflict" -eq 1 ]; then
            printf 'Orbit Vite Plus scope conflict\n' >&2
            exit 43
        fi
        printf 'Orbit Vite Plus scope is absent\n' >&2
        exit 42
        BASH;

    /**
     * Verifies the enrolled account's existing Linux Vite+ global scope.
     * The first store with bin/vp wins, in materialize order. A later store is not a conflict.
     * It does not install Vite+, publish launchers, or change the scope.
     */
    private const string LINUX_SCOPE_SCRIPT = <<<'BASH'
        account=$1
        if [ -z "${account:-}" ]; then
            printf 'Orbit Vite Plus account is missing\n' >&2
            exit 1
        fi
        current=$(/usr/bin/id -un)
        if [ "$current" != "$account" ]; then
            printf 'Orbit Vite Plus account mismatch\n' >&2
            exit 1
        fi
        passwd_entry=$(/usr/bin/getent passwd -- "$account" || true)
        if [ -z "$passwd_entry" ]; then
            printf 'Orbit Vite Plus account home is unreadable\n' >&2
            exit 1
        fi
        line_count=$(printf '%s\n' "$passwd_entry" | /usr/bin/wc -l | /usr/bin/tr -d '[:space:]')
        if [ "$line_count" != "1" ]; then
            printf 'Orbit Vite Plus account home is unreadable\n' >&2
            exit 1
        fi
        home=$(printf '%s\n' "$passwd_entry" | /usr/bin/cut -d: -f6)
        group=$(/usr/bin/id -gn -- "$account" || true)
        if [ -z "$home" ] || [ -z "$group" ] || [ ! -d "$home" ]; then
            printf 'Orbit Vite Plus account home is unreadable\n' >&2
            exit 1
        fi
        case "$home" in
            /*) ;;
            *)
                printf 'Orbit Vite Plus account home is unreadable\n' >&2
                exit 1
                ;;
        esac
        case "$home" in
            *..*|*[!/A-Za-z0-9._-]*)
                printf 'Orbit Vite Plus account home is unreadable\n' >&2
                exit 1
                ;;
        esac
        if [ -e /opt/orbit ] || [ -L /opt/orbit ]; then
            if [ -L /opt/orbit ] || [ ! -d /opt/orbit ]; then
                printf 'Orbit Vite Plus scope conflict\n' >&2
                exit 43
            fi
            orbit_owner=$(/usr/bin/stat -c '%U:%G' /opt/orbit 2>/dev/null || true)
            if [ "$orbit_owner" != "root:root" ]; then
                printf 'Orbit Vite Plus scope conflict\n' >&2
                exit 43
            fi
        fi

        consider() {
            scope=$1
            binary="$scope/bin/vp"
            if [ ! -e "$scope" ] && [ ! -L "$scope" ]; then
                return 0
            fi
            if [ -L "$scope" ] || [ ! -d "$scope" ]; then
                printf 'Orbit Vite Plus scope conflict\n' >&2
                exit 43
            fi
            owner=$(/usr/bin/stat -c '%U:%G' "$scope" 2>/dev/null || true)
            if [ "$owner" != "$account:$group" ]; then
                printf 'Orbit Vite Plus scope conflict\n' >&2
                exit 43
            fi
            if [ ! -e "$binary" ] && [ ! -L "$binary" ]; then
                return 0
            fi
            binary_owner=$(/usr/bin/stat -c '%U:%G' "$binary" 2>/dev/null || true)
            if [ "$binary_owner" != "$account:$group" ] || [ ! -x "$binary" ]; then
                printf 'Orbit Vite Plus scope conflict\n' >&2
                exit 43
            fi
            printf '%s\n' "$binary"
            exit 0
        }

        consider /opt/orbit/vite-plus
        consider "$home/.vite-plus"
        consider "$home/.local/share/vite-plus"

        printf 'Orbit Vite Plus scope is absent\n' >&2
        exit 42
        BASH;

    private const int MAX_SCOPE_PROBE_BYTES = 4_096;

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
        return $node->platform === 'linux' || $node->platform === 'macos';
    }

    /**
     * The enrolled account's existing Vite+ binary.
     * The probe does not install Vite+, publish launchers, or change the scope.
     *
     * @throws ToolManagerException
     */
    public function existingBinary(Node $node): string
    {
        $this->guardNode($node);

        return $node->platform === 'macos'
            ? $this->resolveMacBinary($node)
            : $this->resolveLinuxBinary($node);
    }

    public function validatePackage(string $package): bool
    {
        $length = strlen($package);

        return $length >= 1 && $length <= self::MAX_PACKAGE_LENGTH && preg_match(self::PACKAGE_PATTERN, $package) === 1;
    }

    public function materialize(Node $node): void
    {
        $this->guardNode($node);

        if ($node->platform === 'macos') {
            $this->existingBinary($node);

            return;
        }

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
                    if [ -d "$candidate" ] && [ ! -L "$candidate" ] \
                        && [ ! -e "$candidate/bin/vp" ] && [ ! -L "$candidate/bin/vp" ]; then
                        candidate_owner=$(stat -c '%U:%G' "$candidate" 2>/dev/null || true)
                        if [ "$candidate_owner" != "$managed_user:$managed_group" ]; then
                            printf 'Orbit Vite Plus directory conflict: %s\n' "$candidate" >&2
                            exit 1
                        fi
                        continue
                    fi
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

        $result = $this->commands->execute($node, $this->vpArguments($node, '--version'));

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

    public function candidateVersion(Node $node, string $package, ToolOperation $operation): string
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $this->vpArguments($node, 'info', $package, 'version', '--json'));

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

        $result = $this->commands->execute($node, $this->vpArguments($node, 'list', '-g', $package, '--json'));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'installed-version',
            message: 'The VP installed version probe failed.',
        );

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

        foreach ($decoded as $entry) {
            if (! is_object($entry)) {
                throw new ToolManagerException(
                    step: 'installed-version',
                    message: 'The VP installed version probe returned malformed output.',
                    result: $result,
                );
            }

            $name = $entry->name ?? null;
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

    public function inspectForAdoption(Node $node, string $package): ToolAdoptionFact
    {
        $this->guardNode($node);
        $this->guardPackage($package);
        $version = $this->installedVersion($node, $package);

        if ($version === null) {
            return new ToolAdoptionFact(null, null);
        }

        if ($package === 'pnpm') {
            return new ToolAdoptionFact($version, ToolInventoryPackage::BLOCK_PROTECTED);
        }

        return new ToolAdoptionFact($version, null);
    }

    public function install(Node $node, string $package): void
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $this->mutate(
            node: $node,
            package: $package,
            step: 'install',
            arguments: $this->vpArguments($node, 'install', '-g', $package, '--node', 'lts'),
        );
    }

    public function update(Node $node, string $package): void
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $this->mutate(
            node: $node,
            package: $package,
            step: 'update',
            arguments: $this->vpArguments($node, 'update', '-g', $package, '--reinstall-node-mismatch'),
        );
    }

    public function planRemoval(Node $node, string $package): ToolRemovalPlan
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $result = $this->commands->execute($node, $this->vpArguments($node, 'remove', '-g', '--dry-run', $package));

        $this->guardSuccessfulResult(
            result: $result,
            step: 'removal-plan',
            message: 'The VP removal plan failed.',
        );

        return new ToolRemovalPlan([$package]);
    }

    public function remove(Node $node, string $package): void
    {
        $this->guardNode($node);
        $this->guardPackage($package);

        $this->mutate(
            node: $node,
            package: $package,
            step: 'remove',
            arguments: $this->vpArguments($node, 'remove', '-g', $package),
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
        return
            $value !== ''
            && strlen($value) <= self::MAX_VERSION_LENGTH
            && preg_match('/[\x00-\x1F\x7F]/', $value) !== 1;
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
    private function vpArguments(Node $node, string ...$arguments): array
    {
        $binary = $this->existingBinary($node);

        return array_values([
            'env',
            'VP_HOME='.dirname($binary, 2),
            $binary,
            ...$arguments,
        ]);
    }

    private function resolveMacBinary(Node $node): string
    {
        $result = $this->commands->execute(
            $node,
            ['/bin/bash', '-su', '--', $node->user],
            self::MAC_SCOPE_SCRIPT,
            maxOutputBytes: self::MAX_SCOPE_PROBE_BYTES,
        );

        if ($result->exitCode === self::MAC_SCOPE_ABSENT) {
            throw new ToolManagerException(
                step: 'manager-absent',
                message: 'The Vite+ global scope is absent for the enrolled account.',
                result: $result,
            );
        }

        if ($result->exitCode === self::MAC_SCOPE_CONFLICT) {
            throw new ToolManagerException(
                step: 'manager-conflict',
                message: 'The Vite+ global scope conflicts with the enrolled account.',
                result: $result,
            );
        }

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-probe',
            message: 'The Vite+ global scope probe failed.',
        );

        $lines = preg_split('/\R/', rtrim($result->stdout, "\r\n"));
        $binary = is_array($lines) ? ($lines[0] ?? '') : '';

        if (! is_array($lines) || count($lines) !== 1 || ! $this->isSafeMacBinary($binary)) {
            throw new ToolManagerException(
                step: 'manager-probe',
                message: 'The Vite+ global scope probe returned malformed output.',
                result: $result,
            );
        }

        return $binary;
    }

    private function isSafeMacBinary(string $binary): bool
    {
        return preg_match(
            '/\A\/(?:[A-Za-z0-9._-]+\/)+(?:\.vite-plus|\.local\/share\/vite-plus)\/bin\/vp\z/D',
            $binary,
        ) === 1 && ! str_contains($binary, '..');
    }

    private function resolveLinuxBinary(Node $node): string
    {
        $result = $this->commands->execute(
            $node,
            ['/bin/bash', '-seu', '--', $node->user],
            self::LINUX_SCOPE_SCRIPT,
            maxOutputBytes: self::MAX_SCOPE_PROBE_BYTES,
        );

        if ($result->exitCode === self::MAC_SCOPE_ABSENT) {
            throw new ToolManagerException(
                step: 'manager-absent',
                message: 'The Vite+ global scope is absent for the enrolled account.',
                result: $result,
            );
        }

        if ($result->exitCode === self::MAC_SCOPE_CONFLICT) {
            throw new ToolManagerException(
                step: 'manager-conflict',
                message: 'The Vite+ global scope conflicts with the enrolled account.',
                result: $result,
            );
        }

        $this->guardSuccessfulResult(
            result: $result,
            step: 'manager-probe',
            message: 'The Vite+ global scope probe failed.',
        );

        $lines = preg_split('/\R/', rtrim($result->stdout, "\r\n"));
        $binary = is_array($lines) ? ($lines[0] ?? '') : '';

        if (! is_array($lines) || count($lines) !== 1 || ! $this->isSafeLinuxBinary($binary)) {
            throw new ToolManagerException(
                step: 'manager-probe',
                message: 'The Vite+ global scope probe returned malformed output.',
                result: $result,
            );
        }

        return $binary;
    }

    private function isSafeLinuxBinary(string $binary): bool
    {
        return preg_match(
            '/\A(?:\/opt\/orbit\/vite-plus\/bin\/vp|\/(?:[A-Za-z0-9._-]+\/)+(?:\.vite-plus|\.local\/share\/vite-plus)\/bin\/vp)\z/D',
            $binary,
        ) === 1 && ! str_contains($binary, '..');
    }
}
