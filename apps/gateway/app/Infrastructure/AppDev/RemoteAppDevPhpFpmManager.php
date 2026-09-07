<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Infrastructure\Nodes\PhpFpmInstalledProjection;
use App\Infrastructure\Nodes\PhpFpmPublicationPlan;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Models\Route;
use App\Rules\SupportedPhpVersion;

/** @mago-expect lint:excessive-parameter-list Fixed paths preserve isolated publication tests; the package service owns host installation. */
final readonly class RemoteAppDevPhpFpmManager implements AppDevPhpFpmManager
{
    public function __construct(
        private AppDevSiteRepository $sites,
        private AppDevPhpFpmConfigRenderer $renderer,
        private AppDevSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private RemotePhpPackageManager $packages,
        private string $phpRoot = '/etc/php',
        private string $lockDirectory = '/run/lock/orbit',
    ) {}

    public function converge(Node $node): void
    {
        $this->convergeSites($node);
    }

    public function convergeRoute(Node $node, Route $route): void
    {
        $this->convergeSites($node, $route);
    }

    private function convergeSites(Node $node, ?Route $pendingRoute = null): void
    {
        $account = $this->accounts->resolve($node);
        $desiredSites = $this->sites
            ->forNode($node, $pendingRoute)
            ->filter(static fn (AppDevSite $site): bool => $site->phpVersion !== null && ! $site->isProxy())
            ->values();
        $desiredVersions = $desiredSites
            ->map(static fn (AppDevSite $site): string => $site->phpVersion ?? '')
            ->unique()
            ->values();
        $unsupportedVersion = $desiredVersions
            ->first(static fn (string $version): bool => ! SupportedPhpVersion::isSupported($version));

        if (is_string($unsupportedVersion)) {
            throw new RuntimeConvergenceException(
                step: 'php-version',
                errorCode: 'app-dev.php_version_unsupported',
                message: "PHP version [{$unsupportedVersion}] is not supported.",
            );
        }

        $installedProjection = $this->installedProjection($node, $account);
        $this->packages->installForAppDev($node, $desiredVersions, $this->ssh);

        $desiredPoolVersions = $desiredSites
            ->mapWithKeys(static fn (AppDevSite $site): array => [$site->poolName() => $site->phpVersion ?? ''])
            ->all();
        $plan = PhpFpmPublicationPlan::from(
            installed: $installedProjection,
            desiredPoolVersions: $desiredPoolVersions,
            poolPattern: '/^\[(orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*)\]$/m',
        );

        $transitionSites = $desiredSites
            ->reject(static fn (AppDevSite $site): bool => in_array(
                needle: $site->poolName(),
                haystack: $plan->movingPoolNames,
                strict: true,
            ))
            ->values();
        $publishedVersions = [];

        try {
            foreach ($plan->publications as $publication) {
                $sites = $publication['retirement'] ? $transitionSites : $desiredSites;
                $version = $publication['version'];
                $configuration = $this->renderer->render(
                    $sites->where('phpVersion', $version)->values(),
                    $account,
                );
                $this->publishVersion($node, $version, $configuration, $account);
                $publishedVersions[] = $version;
            }
        } catch (RuntimeConvergenceException $exception) {
            $recoveryFailure = $this->restorePublishedVersions(
                node: $node,
                publishedVersions: $publishedVersions,
                installedProjection: $installedProjection,
                account: $account,
            );

            throw $recoveryFailure ?? $exception;
        }
    }

    private function installedProjection(Node $node, ManagedUserAccount $account): PhpFpmInstalledProjection
    {
        $result = $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $this->phpRoot,
                    $account->user,
                    $account->group,
                    $account->home,
                ],
                input: <<<'BASH'
                    php_root=$1
                    managed_user=$2
                    managed_group=$3
                    managed_home=$4
                    for path in "$php_root"/*/fpm/pool.d/orbit-scopes.conf; do
                        if [ -e "$path" ]; then
                            version=$(basename "$(dirname "$(dirname "$(dirname "$path")")")")
                            printf '%s\t' "$version"
                            base64 --wrap=0 -- "$path"
                            printf '\n'
                        fi
                    done
                    BASH,
            ),
            step: 'php-fpm-discover',
            errorCode: 'app-dev.php_fpm_discovery_failed',
        );

        return PhpFpmInstalledProjection::fromDiscoveryOutput($result->stdout);
    }

    /**
     * @param  list<string>  $publishedVersions
     */
    private function restorePublishedVersions(
        Node $node,
        array $publishedVersions,
        PhpFpmInstalledProjection $installedProjection,
        ManagedUserAccount $account,
    ): ?RuntimeConvergenceException {
        $restoredVersions = [];
        $recoveryFailure = null;

        foreach (array_reverse($publishedVersions) as $version) {
            if (($restoredVersions[$version] ?? false) === true) {
                continue;
            }

            try {
                $this->publishVersion($node, $version, $installedProjection->previousConfiguration($version), $account);
            } catch (RuntimeConvergenceException $exception) {
                $recoveryFailure ??= $exception;
            }

            $restoredVersions[$version] = true;
        }

        return $recoveryFailure;
    }

    private function publishVersion(
        Node $node,
        string $version,
        string $configuration,
        ManagedUserAccount $account,
    ): void {
        $this->ssh->execute(
            $node,
            new RemoteCommand(
                arguments: [
                    'sudo',
                    'bash',
                    '-seu',
                    '--',
                    $version,
                    $this->phpRoot,
                    $this->lockDirectory,
                    $account->user,
                    $account->group,
                    $account->home,
                ],
                input: $this->publishScript($configuration),
            ),
            step: 'php-fpm-config',
            errorCode: 'app-dev.php_fpm_config_failed',
        );
    }

    private function publishScript(string $configuration): string
    {
        $encoded = base64_encode($configuration);

        return <<<BASH
            version=\$1
            php_root=\$2
            lock_directory=\$3
            managed_user=\$4
            managed_group=\$5
            managed_home=\$6
            umask 0077
            if ! mkdir -- "\$lock_directory" 2>/dev/null; then
                test -d "\$lock_directory"
                test ! -L "\$lock_directory"
            fi
            if [ "\$lock_directory" = /run/lock/orbit ]; then
                test "\$(stat -c %u:%g:%a -- "\$lock_directory")" = 0:0:700
            fi
            pool_directory="\$php_root/\$version/fpm/pool.d"
            main_configuration="\$php_root/\$version/fpm/php-fpm.conf"
            managed_configuration="\$pool_directory/orbit-scopes.conf"
            lock="\$lock_directory/orbit-php-fpm-\$version.lock"
            if [ -e "\$lock" ] || [ -L "\$lock" ]; then
                test ! -L "\$lock"
                test -f "\$lock"
                if [ "\$lock_directory" = /run/lock/orbit ]; then
                    test "\$(stat -c %u:%g -- "\$lock")" = 0:0
                fi
            fi
            exec 9>>"\$lock"
            if [ "\$lock_directory" = /run/lock/orbit ]; then
                chmod 0600 -- "\$lock"
                test "\$(stat -c %a -- "\$lock")" = 600
            fi
            flock -w 30 9
            temporary_directory=\$(mktemp -d)
            candidate="\$temporary_directory/orbit-scopes.conf"
            backup="\$temporary_directory/orbit-scopes.backup"
            trap 'rm -rf -- "\$temporary_directory"' EXIT
            install -d -m 0755 -- "\$temporary_directory/pool.d"

            for pool in "\$pool_directory"/*.conf; do
                if [ ! -e "\$pool" ] || [ "\$pool" = "\$managed_configuration" ]; then
                    continue
                fi

                cp -- "\$pool" "\$temporary_directory/pool.d/"
            done

            printf '%s' '{$encoded}' | base64 --decode > "\$candidate"
            cp -- "\$candidate" "\$temporary_directory/pool.d/orbit-scopes.conf"
            awk -v managed_include="include=\$temporary_directory/pool.d/*.conf" '
                /^include=.*pool[.]d\/[*][.]conf$/ {
                    print managed_include
                    replaced = 1
                    next
                }
                { print }
                END { if (! replaced) exit 42 }
            ' "\$main_configuration" > "\$temporary_directory/php-fpm.conf"
            sudo "php-fpm\$version" -y "\$temporary_directory/php-fpm.conf" -t

            if [ -f "\$managed_configuration" ] && cmp -s -- "\$candidate" "\$managed_configuration"; then
                exit 0
            fi

            had_previous=0
            if [ -f "\$managed_configuration" ]; then
                sudo cp -a -- "\$managed_configuration" "\$backup"
                had_previous=1
            fi

            if [ -s "\$candidate" ]; then
                staged="\$pool_directory/.orbit-scopes.\$\$.candidate"
                sudo install -o root -g root -m 0644 -- "\$candidate" "\$staged"
                sudo mv -fT -- "\$staged" "\$managed_configuration"
            else
                sudo rm -f -- "\$managed_configuration"
            fi

            if ! sudo systemctl enable "php\$version-fpm" || ! sudo systemctl reload-or-restart "php\$version-fpm"; then
                if [ "\$had_previous" = 1 ]; then
                    rollback="\$pool_directory/.orbit-scopes.\$\$.rollback"
                    sudo cp -a -- "\$backup" "\$rollback"
                    sudo mv -fT -- "\$rollback" "\$managed_configuration"
                else
                    sudo rm -f -- "\$managed_configuration"
                fi
                sudo systemctl reload-or-restart "php\$version-fpm" || true
                exit 1
            fi
            BASH;
    }
}
