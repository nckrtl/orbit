<?php

declare(strict_types=1);

namespace App\Infrastructure\Hibernation;

use App\Domain\Hibernation\AppInstanceCheckoutInspector;
use App\Domain\Hibernation\HibernationException;
use App\Domain\Hibernation\LocalRuntimeDependencies;
use App\Domain\Hibernation\RuntimeDependencyState;
use App\Domain\Hibernation\RuntimeHibernation;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\Storage\StoragePath;
use App\Infrastructure\Ssh\KnownHostsStore;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Infrastructure\Ssh\SshConnection;
use App\Infrastructure\Ssh\SshExecutor;
use App\Infrastructure\Ssh\SshKeyProvider;
use App\Models\AppInstance;
use App\Models\Node;

final readonly class RemoteAppInstanceCheckoutInspector implements AppInstanceCheckoutInspector
{
    public function __construct(
        private SshExecutor $ssh,
        private SshKeyProvider $keys,
        private KnownHostsStore $knownHosts,
        private ManagedUserAccountResolver $accounts,
        private int $restoreTimeoutSeconds = RuntimeHibernation::DefaultColdWakeTimeoutSeconds,
    ) {}

    public function inspect(AppInstance $instance): RuntimeDependencyState
    {
        $checkout = $this->checkout($instance);
        $result = $this->ssh->execute(
            $this->connection($instance->node),
            new RemoteCommand(
                ['sudo', 'bash', '-seu', '--', $checkout],
                self::inspectScript(),
            ),
        );

        if (! $result->succeeded()) {
            throw new HibernationException(
                errorCode: 'hibernation.checkout_inspect_failed',
                message: "Checkout inspection failed on AppInstance [{$instance->name}].",
            );
        }

        return $this->parseInspection($result->stdout);
    }

    public function prune(AppInstance $instance, RuntimeDependencyState $state): void
    {
        $checkout = $this->checkout($instance);
        $targets = [];

        if ($state->prunableVendor()) {
            $targets[] = 'vendor';
        }

        if ($state->prunableNodeModules()) {
            $targets[] = 'node_modules';
        }

        if ($targets === []) {
            return;
        }

        $result = $this->ssh->execute(
            $this->connection($instance->node),
            new RemoteCommand(
                ['sudo', 'bash', '-seu', '--', $checkout, ...$targets],
                self::pruneScript(),
            ),
        );

        if ($result->succeeded()) {
            return;
        }

        throw new HibernationException(
            errorCode: 'hibernation.checkout_prune_failed',
            message: "Checkout dependency prune failed on AppInstance [{$instance->name}].",
        );
    }

    public function restore(AppInstance $instance, RuntimeDependencyState $state): void
    {
        $checkout = $this->checkout($instance);
        $account = $this->accounts->resolve($instance->node);

        if ($state->restorableVendor()) {
            $this->runRestore(
                $instance,
                'restore-composer',
                [
                    'sudo',
                    '-u',
                    $account->user,
                    '-H',
                    'env',
                    'COMPOSER_HOME=/opt/orbit/composer',
                    '/usr/bin/composer',
                    'install',
                    '--no-interaction',
                    '--prefer-dist',
                    '--working-dir='.$checkout,
                ],
            );
        }

        if ($state->restorableNodeModules()) {
            $this->runRestore(
                $instance,
                'restore-javascript',
                [
                    'sudo',
                    '-u',
                    $account->user,
                    '-H',
                    'bash',
                    '-seu',
                    '--',
                    $checkout,
                ],
                <<<'BASH'
                    cd -- "$1"
                    exec /usr/local/bin/vp install --frozen-lockfile
                    BASH,
            );
        }
    }

    private function checkout(AppInstance $instance): string
    {
        $path = StoragePath::tryParse($instance->checkout_path);

        if ($path === null) {
            throw new HibernationException(
                errorCode: 'hibernation.checkout_path_invalid',
                message: "AppInstance [{$instance->name}] has an invalid checkout path.",
            );
        }

        return $path->value;
    }

    private function parseInspection(string $stdout): RuntimeDependencyState
    {
        $values = [];

        foreach (preg_split('/\r\n|\r|\n/', $stdout) ?: [] as $line) {
            if (preg_match('/\A(composer_json|composer_lock|vendor_present|vendor_symlink|package_json|node_modules_present|node_modules_symlink|source_mtime)=(0|[1-9][0-9]*)\z/D', $line, $matches) !== 1) {
                continue;
            }

            $values[$matches[1]] = (int) $matches[2];
        }

        $javascriptLocks = [];

        foreach (preg_split('/\r\n|\r|\n/', $stdout) ?: [] as $line) {
            if (preg_match('/\Ajavascript_lock=(package-lock\.json|yarn\.lock|pnpm-lock\.yaml|bun\.lock|bun\.lockb)\z/D', $line, $matches) === 1) {
                $javascriptLocks[] = $matches[1];
            }
        }

        $source = $values['source_mtime'] ?? null;

        return LocalRuntimeDependencies::inspect(
            composerJsonFile: ($values['composer_json'] ?? 0) === 1,
            composerLockFile: ($values['composer_lock'] ?? 0) === 1,
            vendorPresent: ($values['vendor_present'] ?? 0) === 1,
            vendorSymlink: ($values['vendor_symlink'] ?? 0) === 1,
            packageJsonFile: ($values['package_json'] ?? 0) === 1,
            javascriptLockFiles: $javascriptLocks,
            nodeModulesPresent: ($values['node_modules_present'] ?? 0) === 1,
            nodeModulesSymlink: ($values['node_modules_symlink'] ?? 0) === 1,
            sourceTreeLastActivityUnix: $source === 0 ? null : $source,
        );
    }

    /** @param non-empty-list<string> $arguments */
    private function runRestore(AppInstance $instance, string $step, array $arguments, ?string $input = null): void
    {
        $result = $this->ssh->execute(
            $this->connection($instance->node, $this->restoreTimeoutSeconds),
            new RemoteCommand($arguments, $input, timeout: (float) $this->restoreTimeoutSeconds),
        );

        if ($result->succeeded()) {
            return;
        }

        throw new HibernationException(
            errorCode: 'hibernation.checkout_restore_failed',
            message: "Checkout dependency restore step [{$step}] failed on AppInstance [{$instance->name}].",
        );
    }

    private function connection(Node $node, ?int $timeoutSeconds = null): SshConnection
    {
        if (! is_string($node->wireguard_ip) || $node->wireguard_ip === '') {
            throw new HibernationException(
                errorCode: 'hibernation.wireguard_ip_missing',
                message: "Node [{$node->name}] has no WireGuard address.",
            );
        }

        return new SshConnection(
            host: $node->wireguard_ip,
            user: $node->user,
            port: 22,
            identityFile: $this->keys->privateKeyPath(),
            knownHostsFile: $this->knownHosts->path(),
            commandTimeout: $timeoutSeconds ?? 900.0,
        );
    }

    private static function inspectScript(): string
    {
        return <<<'BASH'
            checkout=$1
            regular_file() {
                [ -f "$1" ] && [ ! -L "$1" ]
            }

            printf 'composer_json=%s\n' "$(regular_file "$checkout/composer.json" && printf 1 || printf 0)"
            printf 'composer_lock=%s\n' "$(regular_file "$checkout/composer.lock" && printf 1 || printf 0)"
            printf 'vendor_present=%s\n' "$([ -d "$checkout/vendor" ] && [ ! -L "$checkout/vendor" ] && printf 1 || printf 0)"
            printf 'vendor_symlink=%s\n' "$([ -L "$checkout/vendor" ] && printf 1 || printf 0)"
            printf 'package_json=%s\n' "$(regular_file "$checkout/package.json" && printf 1 || printf 0)"
            printf 'node_modules_present=%s\n' "$([ -d "$checkout/node_modules" ] && [ ! -L "$checkout/node_modules" ] && printf 1 || printf 0)"
            printf 'node_modules_symlink=%s\n' "$([ -L "$checkout/node_modules" ] && printf 1 || printf 0)"

            for lock in package-lock.json yarn.lock pnpm-lock.yaml bun.lock bun.lockb; do
                if regular_file "$checkout/$lock"; then
                    printf 'javascript_lock=%s\n' "$lock"
                fi
            done

            source_mtime=$(find "$checkout" \
                \( -name vendor -o -name node_modules -o -name .git \) -prune -o \
                -type f ! -type l -printf '%T@\n' 2>/dev/null | sort -n | tail -1)
            source_mtime=${source_mtime%.*}
            printf 'source_mtime=%s\n' "${source_mtime:-0}"
            BASH;
    }

    private static function pruneScript(): string
    {
        return <<<'BASH'
            checkout=$1
            shift
            for target in "$@"; do
                path="$checkout/$target"
                if [ -L "$path" ] || [ ! -d "$path" ]; then
                    printf 'Refusing to prune %s\n' "$path" >&2
                    exit 1
                fi
                rm -rf -- "$path"
            done
            BASH;
    }
}
