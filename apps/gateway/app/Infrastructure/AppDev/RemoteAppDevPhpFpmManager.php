<?php

declare(strict_types=1);

namespace App\Infrastructure\AppDev;

use App\Domain\AppDev\AppDevPhpFpmManager;
use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Nodes\ManagedUserAccount;
use App\Domain\Nodes\ManagedUserAccountResolver;
use App\Domain\Nodes\RoleName;
use App\Infrastructure\Nodes\PhpFpmInstalledProjection;
use App\Infrastructure\Nodes\PhpFpmPublicationPlan;
use App\Infrastructure\Nodes\RemotePhpPackageManager;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Node;
use App\Rules\SupportedPhpVersion;
use Illuminate\Support\Collection;

final readonly class RemoteAppDevPhpFpmManager implements AppDevPhpFpmManager
{
    public function __construct(
        private DevelopmentSiteRepository $sites,
        private DevelopmentPhpFpmConfigRenderer $renderer,
        private DevelopmentSshExecutor $ssh,
        private ManagedUserAccountResolver $accounts,
        private RemotePhpPackageManager $packages,
        private string $phpRoot = '/etc/php',
        private string $lockDirectory = '/run/lock/orbit',
    ) {}

    /**
     * Publishes one `orbit-scopes.conf` for each PHP version from stored state. A site whose working
     * directory is missing on the Node gets no pool: PHP-FPM refuses to start while any pool names a
     * missing `chdir`, so one such pool would stop every site of that version. Doctor reports it as
     * `role.php_pool_directory_missing`. Package installation never starts PHP-FPM; each publication
     * starts or reloads it after it installs the validated pools, so a stale pool that keeps PHP-FPM
     * from starting cannot block the publication that removes it.
     */
    public function converge(Node $node): void
    {
        $this->convergeSites($node);
    }

    /**
     * The Orbit-rendered pools on the Node that name a missing working directory: installed pools, which
     * stop PHP-FPM from starting, and desired pools, which converge skips.
     *
     * @return list<array{pool: string, version: string, directory: string, installed: bool}>
     */
    public function poolsWithMissingDirectories(Node $node, ?float $commandTimeout = null): array
    {
        $desiredSites = $this->desiredSites($node);
        $installed = $this->installedProjection(
            $node,
            $this->accounts->resolve($node),
            $this->workingDirectories($desiredSites),
            $commandTimeout,
        );
        $pools = array_map(
            static fn (array $pool): array => [...$pool, 'installed' => true],
            $installed->poolsWithMissingDirectories(),
        );
        $installedPools = array_column($pools, 'pool');

        foreach ($desiredSites as $site) {
            if (
                ! in_array($site->phpWorkingDirectory(), $installed->missingDirectories, strict: true)
                || in_array($site->poolName(), $installedPools, strict: true)
            ) {
                continue;
            }

            $pools[] = [
                'pool' => $site->poolName(),
                'version' => $site->phpVersion ?? '',
                'directory' => $site->phpWorkingDirectory(),
                'installed' => false,
            ];
        }

        return $pools;
    }

    private function convergeSites(Node $node): void
    {
        $account = $this->accounts->resolve($node);
        $desiredSites = $this->desiredSites($node);
        $unsupportedVersion = $desiredSites
            ->map(static fn (DevelopmentSite $site): string => $site->phpVersion ?? '')
            ->unique()
            ->first(static fn (string $version): bool => ! SupportedPhpVersion::isSupported($version));

        if (is_string($unsupportedVersion)) {
            throw new RuntimeConvergenceException(
                step: 'php-version',
                errorCode: 'app-dev.php_version_unsupported',
                message: "PHP version [{$unsupportedVersion}] is not supported.",
            );
        }

        $installedProjection = $this->installedProjection($node, $account, $this->workingDirectories($desiredSites));
        $desiredSites = $desiredSites
            ->reject(static fn (DevelopmentSite $site): bool => in_array(
                needle: $site->phpWorkingDirectory(),
                haystack: $installedProjection->missingDirectories,
                strict: true,
            ))
            ->values();
        $desiredVersions = $desiredSites
            ->map(static fn (DevelopmentSite $site): string => $site->phpVersion ?? '')
            ->unique()
            ->values();
        $role = $desiredSites->contains(static fn (DevelopmentSite $site): bool => $site->environment === 'production')
            ? RoleName::AppProd
            : RoleName::AppDev;
        $this->packages->installForInstance($node->loadMissing('roles'), $desiredVersions, $this->ssh, $role);

        $desiredPoolVersions = $desiredSites
            ->mapWithKeys(static fn (DevelopmentSite $site): array => [$site->poolName() => $site->phpVersion ?? ''])
            ->all();
        $plan = PhpFpmPublicationPlan::from(
            installed: $installedProjection,
            desiredPoolVersions: $desiredPoolVersions,
            poolPattern: '/^\[(orbit-(?:instance|workspace|app-instance)-[1-9][0-9]*)\]$/m',
        );

        $transitionSites = $desiredSites
            ->reject(static fn (DevelopmentSite $site): bool => in_array(
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

    /** @return Collection<int, DevelopmentSite> */
    private function desiredSites(Node $node): Collection
    {
        return $this->sites
            ->forNode($node)
            ->filter(
                static fn (DevelopmentSite $site): bool => (
                    $site->phpVersion !== null
                    && ! $site->isProxy()
                    && ! $site->usesDedicatedPhpRuntime()
                ),
            )
            ->values();
    }

    /**
     * @param  Collection<int, DevelopmentSite>  $sites
     * @return list<string>
     */
    private function workingDirectories(Collection $sites): array
    {
        return array_values(array_unique($sites
            ->map(static fn (DevelopmentSite $site): string => $site->phpWorkingDirectory())
            ->all()));
    }

    /**
     * Reads every installed `orbit-scopes.conf` and reports, as root, which of the given working
     * directories and the `chdir` of every installed pool are missing. It changes nothing on the Node.
     *
     * @param  list<string>  $directories
     */
    private function installedProjection(
        Node $node,
        ManagedUserAccount $account,
        array $directories,
        ?float $commandTimeout = null,
    ): PhpFpmInstalledProjection {
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
                    ...$directories,
                ],
                input: <<<'BASH'
                    php_root=$1
                    managed_user=$2
                    managed_group=$3
                    managed_home=$4
                    shift 4
                    directories=("$@")
                    for path in "$php_root"/*/fpm/pool.d/orbit-scopes.conf; do
                        if [ -e "$path" ]; then
                            version=$(basename "$(dirname "$(dirname "$(dirname "$path")")")")
                            printf '%s\t' "$version"
                            base64 --wrap=0 -- "$path"
                            printf '\n'
                            while IFS= read -r directory; do
                                directories+=("$directory")
                            done < <(sed -n 's/^chdir = //p' -- "$path")
                        fi
                    done
                    if [ "${#directories[@]}" -gt 0 ]; then
                        printf '%s\0' "${directories[@]}" \
                            | sudo xargs -0 -r -n 1 sh -c 'test -d "$1" || printf "missing-directory\t%s\n" "$1"' sh
                    fi
                    BASH,
            ),
            step: 'php-fpm-discover',
            errorCode: 'app-dev.php_fpm_discovery_failed',
            commandTimeout: $commandTimeout,
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
                sudo systemctl enable "php\$version-fpm"
                if ! sudo systemctl is-active --quiet "php\$version-fpm"; then
                    sudo systemctl restart "php\$version-fpm"
                fi
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
