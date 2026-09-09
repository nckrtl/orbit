<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\ComposerSourceClassifier;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

/** @mago-expect lint:cyclomatic-complexity The adapter keeps every fail-closed production source gate explicit. */
final readonly class RemoteProductionAppInstanceSourceLifecycle implements ProductionAppInstanceSourceLifecycle
{
    public function __construct(
        private AppProdSshExecutor $ssh,
        private ComposerSourceClassifier $classifier,
    ) {}

    public function prepareUser(AppInstance $appInstance): void
    {
        $appInstance->loadMissing('node');
        [$user, $home] = $this->identity($appInstance);
        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $user, $home],
                input: <<<'BASH'
                    user=$1
                    home=$2
                    test "$home" = "/home/$user"
                    test "${#user}" -le 32
                    printf '%s' "$user" | grep -Eq '^orbit-app-[1-9][0-9]*$'

                    if getent passwd "$user" >/dev/null; then
                        entry=$(getent passwd "$user")
                        test "$(printf '%s' "$entry" | cut -d: -f6)" = "$home"
                        test "$(printf '%s' "$entry" | cut -d: -f7)" = /usr/sbin/nologin
                        test "$(id -gn "$user")" = "$user"
                        test "$(id -u "$user")" -lt 1000
                    else
                        test ! -e "$home"
                        test ! -L "$home"
                        sudo useradd --system --user-group --home-dir "$home" --shell /usr/sbin/nologin -- "$user"
                    fi

                    if [ -e "$home" ] || [ -L "$home" ]; then
                        test -d "$home"
                        test ! -L "$home"
                        test "$(stat -c %U -- "$home")" = "$user"
                        test "$(stat -c %G -- "$home")" = "$user"
                    else
                        sudo install -d -o "$user" -g "$user" -m 0700 -- "$home"
                    fi
                    BASH,
            ),
            step: 'production-user',
            errorCode: 'app-prod.user_conflict',
        );
    }

    public function prepareSource(AppInstance $appInstance, bool $allowExisting): void
    {
        $appInstance->loadMissing(['app', 'node']);
        [$user, $home] = $this->identity($appInstance);
        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $appInstance->app->repository_url,
                    $user,
                    $home,
                    $allowExisting ? '1' : '0',
                ],
                input: <<<'BASH'
                    set -o pipefail
                    repository=$1
                    user=$2
                    home=$3
                    allow_existing=$4
                    test "$home" = "/home/$user"
                    sudo test -d "$home"
                    sudo test ! -L "$home"
                    test "$(sudo stat -c %U -- "$home")" = "$user"
                    test "$(sudo stat -c %G -- "$home")" = "$user"
                    unexpected_user=$(sudo find -P "$home" -xdev ! -user "$user" -print -quit)
                    test -z "$unexpected_user"
                    unexpected_group=$(sudo find -P "$home" -xdev ! -group "$user" -print -quit)
                    test -z "$unexpected_group"

                    if sudo -u "$user" -H test -e "$home/.git" || sudo -u "$user" -H test -L "$home/.git"; then
                        test "$allow_existing" = 1
                        sudo -u "$user" -H test -d "$home/.git"
                        sudo -u "$user" -H test ! -L "$home/.git"
                        test "$(sudo -u "$user" -H git -C "$home" rev-parse --is-inside-work-tree)" = true
                        actual=$(sudo -u "$user" -H git -C "$home" config --null --get remote.origin.url | base64 --wrap=0)
                        expected=$(printf '%s\0' "$repository" | base64 --wrap=0)
                        test "$actual" = "$expected"
                        exit 0
                    fi

                    unexpected_entry=$(sudo find -P "$home" -mindepth 1 -maxdepth 1 -print -quit)
                    test -z "$unexpected_entry"
                    sudo -u "$user" -H git clone --no-checkout --origin origin -- "$repository" "$home"
                    BASH,
            ),
            step: 'production-source-prepare',
            errorCode: 'instance.clone_failed',
        );
    }

    public function resolve(AppInstance $appInstance): DevelopmentSourceResolution
    {
        $appInstance->loadMissing(['app', 'node']);
        [$user, $home] = $this->identity($appInstance);
        $branch = $appInstance->branch_override ?? $appInstance->app->default_branch;

        if (! is_string($branch)) {
            throw $this->failure('production-source-resolve', 'instance.branch_resolution_failed');
        }

        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $user, $home, $branch],
                input: <<<'BASH'
                    user=$1
                    home=$2
                    branch=$3
                    source_ref="refs/remotes/origin/$branch"
                    sudo -u "$user" -H git -C "$home" fetch --prune -- origin
                    sudo -u "$user" -H git -C "$home" show-ref --verify --quiet "$source_ref"
                    sudo -u "$user" -H git -C "$home" checkout -B "$branch" "$source_ref" >/dev/null
                    sudo -u "$user" -H git -C "$home" branch --set-upstream-to="origin/$branch" "$branch" >/dev/null
                    commit=$(sudo -u "$user" -H git -C "$home" rev-parse --verify HEAD)
                    printf '%s\t%s\n' "$branch" "$commit"
                    BASH,
            ),
            step: 'production-source-resolve',
            errorCode: 'instance.branch_resolution_failed',
        );
        $parts = explode("\t", trim($result->stdout), 2);

        if (count($parts) !== 2) {
            throw $this->failure('production-source-resolve', 'instance.source_identity_invalid');
        }

        return new DevelopmentSourceResolution($parts[0], $parts[1]);
    }

    public function inspectProfile(AppInstance $appInstance): DevelopmentSourceProfile
    {
        $appInstance->loadMissing(['app', 'node']);
        [$user, $home] = $this->identity($appInstance);
        $root = $appInstance->root ?? $appInstance->app->root;

        if (! is_string($root)) {
            throw $this->failure('production-source-classification', 'app-prod.source_metadata_unsafe');
        }

        $result = $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $home, $user, $root],
                input: <<<'BASH'
                    home=$1
                    user=$2
                    relative_root=$3
                    composer="$home/composer.json"
                    artisan="$home/artisan"
                    candidate="$home/$relative_root"
                    resolved=$(sudo -u "$user" -H realpath -m -- "$candidate")

                    case "$resolved" in
                        "$home"/*) ;;
                        *) printf 'UNSAFE\n'; exit 0 ;;
                    esac
                    unexpected_user=$(sudo -u "$user" -H find -P "$home" -xdev ! -user "$user" -print -quit)
                    test -z "$unexpected_user" || { printf 'UNSAFE\n'; exit 0; }
                    unexpected_group=$(sudo -u "$user" -H find -P "$home" -xdev ! -group "$user" -print -quit)
                    test -z "$unexpected_group" || { printf 'UNSAFE\n'; exit 0; }
                    if sudo -u "$user" -H test -e "$resolved" || sudo -u "$user" -H test -L "$resolved"; then
                        test "$(sudo -u "$user" -H stat -c %U -- "$resolved")" = "$user" || { printf 'UNSAFE\n'; exit 0; }
                        test "$(sudo -u "$user" -H stat -c %G -- "$resolved")" = "$user" || { printf 'UNSAFE\n'; exit 0; }
                    fi

                    if sudo -u "$user" -H test -L "$composer" || { sudo -u "$user" -H test -e "$composer" && ! sudo -u "$user" -H test -f "$composer"; }; then
                        printf 'UNSAFE\n'
                        exit 0
                    fi
                    if ! sudo -u "$user" -H test -e "$composer"; then
                        if sudo -u "$user" -H test -e "$artisan" || sudo -u "$user" -H test -L "$artisan"; then printf 'PARTIAL\n'; else printf 'NONE\n'; fi
                        exit 0
                    fi
                    test "$(sudo -u "$user" -H stat -c %U -- "$composer")" = "$user" || { printf 'UNSAFE\n'; exit 0; }
                    if sudo -u "$user" -H test -L "$artisan"; then artisan_kind=unsafe
                    elif sudo -u "$user" -H test -f "$artisan"; then artisan_kind=regular
                    elif sudo -u "$user" -H test -e "$artisan"; then artisan_kind=unsafe
                    else artisan_kind=absent
                    fi
                    printf 'COMPOSER\t%s\t' "$artisan_kind"
                    sudo -u "$user" -H base64 --wrap=0 -- "$composer"
                    printf '\n'
                    BASH,
            ),
            step: 'production-source-classification',
            errorCode: 'app-prod.source_classification_failed',
        );

        return $this->profile(trim($result->stdout));
    }

    public function prepareCaddyAccess(AppInstance $appInstance): void
    {
        $appInstance->loadMissing(['app', 'node']);
        [$user, $home] = $this->identity($appInstance);
        $root = $appInstance->root ?? $appInstance->app->root;

        if (! is_string($root)) {
            throw $this->failure('production-caddy-access', 'app-prod.source_metadata_unsafe');
        }

        $this->ssh->execute(
            $appInstance->node,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $home, $user, $root],
                input: <<<'BASH'
                    home=$1
                    user=$2
                    relative_root=$3
                    document_root="$home/$relative_root"
                    test "$home" = "/home/$user"
                    sudo -u "$user" -H test -d "$home"
                    sudo -u "$user" -H test ! -L "$home"
                    home_real=$(sudo -u "$user" -H realpath -e -- "$home")
                    test "$home_real" = "$home"
                    document_root_real=$(sudo -u "$user" -H realpath -m -- "$document_root")
                    case "$document_root_real" in
                        "$home"|"$home"/*) ;;
                        *) exit 1 ;;
                    esac
                    document_root_exists=0
                    if sudo -u "$user" -H test -e "$document_root" || sudo -u "$user" -H test -L "$document_root"; then
                        sudo -u "$user" -H test -d "$document_root"
                        sudo -u "$user" -H test ! -L "$document_root"
                        test "$(sudo -u "$user" -H realpath -e -- "$document_root")" = "$document_root_real"
                        unexpected_symlink=$(sudo find -P "$document_root_real" -type l -print -quit)
                        test -z "$unexpected_symlink"
                        document_root_exists=1
                    fi
                    unexpected_user=$(sudo find -P "$home" -xdev ! -user "$user" -print -quit)
                    test -z "$unexpected_user"
                    unexpected_group=$(sudo find -P "$home" -xdev ! -group "$user" -print -quit)
                    test -z "$unexpected_group"
                    sudo setfacl -P -R -m u:caddy:--- "$home"
                    sudo find -P "$home" -type d -exec setfacl -m d:u:caddy:--- -- {} +
                    sudo setfacl -m u:caddy:--x /home "$home"
                    if [ "$document_root_exists" = 1 ]; then
                        sudo setfacl -P -R -m u:caddy:r-X "$document_root_real"
                        sudo find -P "$document_root_real" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                    fi
                    BASH,
            ),
            step: 'production-caddy-access',
            errorCode: 'app-prod.source_metadata_unsafe',
        );
    }

    /** @return array{string, string} */
    private function identity(AppInstance $appInstance): array
    {
        if (! is_string($appInstance->production_user) || ! is_string($appInstance->production_home)) {
            throw $this->failure('production-identity', 'app-prod.identity_missing');
        }

        return [$appInstance->production_user, $appInstance->production_home];
    }

    private function profile(string $result): DevelopmentSourceProfile
    {
        if ($result === 'NONE') {
            return new DevelopmentSourceProfile(null, false);
        }

        if ($result === 'UNSAFE' || $result === 'PARTIAL' || ! str_starts_with($result, "COMPOSER\t")) {
            throw $this->failure('production-source-classification', 'app-prod.source_metadata_unsafe');
        }

        $parts = explode("\t", $result, 3);
        $json = isset($parts[2]) ? base64_decode($parts[2], true) : false;

        if (! is_string($json)) {
            throw $this->failure('production-source-classification', 'app-prod.php_version_unsupported');
        }

        try {
            return $this->classifier->classify($json, $parts[1] ?? 'absent');
        } catch (RuntimeConvergenceException $exception) {
            throw $this->failure(
                'production-source-classification',
                str_replace('app-dev.', 'app-prod.', $exception->errorCode),
                $exception,
            );
        }
    }

    private function failure(
        string $step,
        string $errorCode,
        ?\Throwable $previous = null,
    ): RuntimeConvergenceException {
        return new RuntimeConvergenceException(
            step: $step,
            errorCode: $errorCode,
            message: 'Production AppInstance source preparation failed.',
            previous: $previous,
        );
    }
}
