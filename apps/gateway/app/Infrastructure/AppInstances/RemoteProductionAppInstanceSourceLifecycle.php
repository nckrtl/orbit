<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\AppInstances\ComposerSourceClassifier;
use App\Domain\AppInstances\DevelopmentSourceProfile;
use App\Domain\AppInstances\DevelopmentSourceResolution;
use App\Domain\AppInstances\ProductionAppInstanceSourceLifecycle;
use App\Domain\AppInstances\ProductionReleaseLayout;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;

final readonly class RemoteProductionAppInstanceSourceLifecycle implements ProductionAppInstanceSourceLifecycle, ProductionReleaseLayout
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
                    (string) $appInstance->id,
                    $allowExisting ? '1' : '0',
                ],
                input: <<<'BASH'
                    set -o pipefail
                    repository=$1
                    user=$2
                    home=$3
                    instance=$4
                    allow_existing=$5
                    state_root=/var/lib/orbit/app-instance-sources
                    state_directory="$state_root/$instance"
                    marker="$state_directory/initial-clone"
                    layout_marker="$state_directory/release-layout"
                    releases="$home/releases"
                    release="$releases/initial"
                    environment="$home/.env"
                    test "$home" = "/home/$user"
                    case "$instance" in ''|*[!0-9]*) exit 1 ;; esac
                    test "$instance" -ge 1
                    sudo test -d "$home"
                    sudo test ! -L "$home"
                    test "$(sudo stat -c %U -- "$home")" = "$user"
                    test "$(sudo stat -c %G -- "$home")" = "$user"
                    unexpected_user=$(sudo find -P "$home" -xdev ! -user "$user" -print -quit)
                    test -z "$unexpected_user"
                    unexpected_group=$(sudo find -P "$home" -xdev ! -group "$user" -print -quit)
                    test -z "$unexpected_group"

                    if sudo -u "$user" -H test -e "$release/.git" || sudo -u "$user" -H test -L "$release/.git"; then
                        test "$allow_existing" = 1
                        sudo test -d "$state_root"
                        sudo test ! -L "$state_root"
                        test "$(sudo stat -c %U:%G -- "$state_root")" = root:root
                        sudo test -d "$state_directory"
                        sudo test ! -L "$state_directory"
                        test "$(sudo stat -c %U:%G -- "$state_directory")" = root:root
                        test "$(sudo stat -c %a -- "$state_directory")" = 700
                        sudo test -f "$marker"
                        sudo test ! -L "$marker"
                        test "$(sudo stat -c %U:%G -- "$marker")" = root:root
                        test "$(sudo stat -c %a -- "$marker")" = 600
                        actual_marker=$(sudo base64 --wrap=0 -- "$marker")
                        expected_marker=$(printf '%s\0%s\0%s\0' "$repository" "$user" "$home" | base64 --wrap=0)
                        test "$actual_marker" = "$expected_marker"
                        sudo test -f "$layout_marker"
                        sudo test ! -L "$layout_marker"
                        test "$(sudo stat -c %U:%G -- "$layout_marker")" = root:root
                        test "$(sudo stat -c %a -- "$layout_marker")" = 600
                        actual_layout=$(sudo base64 --wrap=0 -- "$layout_marker")
                        expected_layout=$(printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial | base64 --wrap=0)
                        test "$actual_layout" = "$expected_layout"
                        sudo -u "$user" -H test -d "$releases"
                        sudo -u "$user" -H test ! -L "$releases"
                        sudo -u "$user" -H test -f "$environment"
                        sudo -u "$user" -H test ! -L "$environment"
                        test "$(sudo -u "$user" -H stat -c %a -- "$environment")" = 600
                        sudo -u "$user" -H test ! -e "$home/current"
                        sudo -u "$user" -H test ! -L "$home/current"
                        sudo -u "$user" -H test -d "$release/.git"
                        sudo -u "$user" -H test ! -L "$release/.git"
                        test "$(sudo -u "$user" -H git -C "$release" rev-parse --is-inside-work-tree)" = true
                        actual=$(sudo -u "$user" -H git -C "$release" config --null --get remote.origin.url | base64 --wrap=0)
                        expected=$(printf '%s\0' "$repository" | base64 --wrap=0)
                        test "$actual" = "$expected"
                        exit 0
                    fi

                    unexpected_entry=$(sudo find -P "$home" -mindepth 1 -maxdepth 1 -print -quit)
                    test -z "$unexpected_entry"
                    if sudo test -e "$state_directory" || sudo test -L "$state_directory"; then exit 1; fi
                    sudo -u "$user" -H install -d -m 0700 -- "$releases"
                    sudo -u "$user" -H install -m 0600 /dev/null "$environment"
                    sudo -u "$user" -H git clone --no-checkout --origin origin -- "$repository" "$release"
                    sudo install -d -o root -g root -m 0700 -- "$state_root" "$state_directory"
                    sudo test ! -L "$state_root"
                    test "$(sudo stat -c %U:%G -- "$state_root")" = root:root
                    sudo test ! -L "$state_directory"
                    test "$(sudo stat -c %U:%G -- "$state_directory")" = root:root
                    test "$(sudo stat -c %a -- "$state_directory")" = 700
                    temporary=$(sudo mktemp "$state_directory/.initial-clone.XXXXXX")
                    cleanup_marker() { sudo rm -f -- "$temporary"; }
                    trap cleanup_marker EXIT
                    printf '%s\0%s\0%s\0' "$repository" "$user" "$home" | sudo tee "$temporary" >/dev/null
                    sudo chown root:root -- "$temporary"
                    sudo chmod 0600 -- "$temporary"
                    sudo mv -- "$temporary" "$marker"
                    temporary=
                    layout_temporary=$(sudo mktemp "$state_directory/.release-layout.XXXXXX")
                    cleanup_layout_marker() { sudo rm -f -- "$layout_temporary"; }
                    trap cleanup_layout_marker EXIT
                    printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial | sudo tee "$layout_temporary" >/dev/null
                    sudo chown root:root -- "$layout_temporary"
                    sudo chmod 0600 -- "$layout_temporary"
                    sudo mv -- "$layout_temporary" "$layout_marker"
                    layout_temporary=
                    trap - EXIT
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
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $appInstance->app->repository_url,
                    $user,
                    $home,
                    $branch,
                    (string) $appInstance->id,
                ],
                input: <<<'BASH'
                    repository=$1
                    user=$2
                    home=$3
                    branch=$4
                    instance=$5
                    release="$home/releases/initial"
                    environment="$home/.env"
                    release_environment="$release/.env"
                    layout_marker="/var/lib/orbit/app-instance-sources/$instance/release-layout"
                    actual_layout=$(sudo base64 --wrap=0 -- "$layout_marker")
                    expected_layout=$(printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial | base64 --wrap=0)
                    test "$actual_layout" = "$expected_layout"
                    sudo -u "$user" -H test -f "$environment"
                    sudo -u "$user" -H test ! -L "$environment"
                    sudo -u "$user" -H test ! -e "$home/current"
                    sudo -u "$user" -H test ! -L "$home/current"
                    source_ref="refs/remotes/origin/$branch"
                    sudo -u "$user" -H git -C "$release" fetch --prune -- origin
                    sudo -u "$user" -H git -C "$release" show-ref --verify --quiet "$source_ref"
                    sudo -u "$user" -H git -C "$release" checkout -B "$branch" "$source_ref" >/dev/null
                    sudo -u "$user" -H git -C "$release" branch --set-upstream-to="origin/$branch" "$branch" >/dev/null
                    if sudo -u "$user" -H test -e "$release_environment" || sudo -u "$user" -H test -L "$release_environment"; then
                        sudo -u "$user" -H test -L "$release_environment"
                        test "$(sudo -u "$user" -H readlink -- "$release_environment")" = ../../.env
                    else
                        sudo -u "$user" -H ln -s ../../.env "$release_environment"
                    fi
                    test "$(sudo -u "$user" -H realpath -e -- "$release_environment")" = "$environment"
                    commit=$(sudo -u "$user" -H git -C "$release" rev-parse --verify HEAD)
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
                    release="$home/releases/initial"
                    composer="$release/composer.json"
                    artisan="$release/artisan"
                    candidate="$release/$relative_root"
                    resolved=$(sudo -u "$user" -H realpath -m -- "$candidate")

                    case "$resolved" in
                        "$release"/*) ;;
                        *) printf 'UNSAFE\n'; exit 0 ;;
                    esac
                    unexpected_user=$(sudo -u "$user" -H find -P "$release" -xdev ! -user "$user" -print -quit)
                    test -z "$unexpected_user" || { printf 'UNSAFE\n'; exit 0; }
                    unexpected_group=$(sudo -u "$user" -H find -P "$release" -xdev ! -group "$user" -print -quit)
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
                    releases="$home/releases"
                    current="$home/current"
                    document_root="$current/$relative_root"
                    test "$home" = "/home/$user"
                    sudo -u "$user" -H test -d "$home"
                    sudo -u "$user" -H test ! -L "$home"
                    home_real=$(sudo -u "$user" -H realpath -e -- "$home")
                    test "$home_real" = "$home"
                    document_root_exists=0
                    ancestor_paths=()
                    case "$relative_root" in ''|/*|..|../*|*/../*|*/..) exit 1 ;; esac
                    if sudo -u "$user" -H test -e "$current" || sudo -u "$user" -H test -L "$current"; then
                        sudo -u "$user" -H test -L "$current"
                        test "$(sudo -u "$user" -H stat -c %U -- "$current")" = "$user"
                        test "$(sudo -u "$user" -H stat -c %G -- "$current")" = "$user"
                        releases_real=$(sudo -u "$user" -H realpath -e -- "$releases")
                        test "$releases_real" = "$releases"
                        selected=$(sudo -u "$user" -H realpath -e -- "$current")
                        case "$selected" in "$releases"/*) ;; *) exit 1 ;; esac
                        sudo -u "$user" -H test -d "$selected"
                        document_root_real=$(sudo -u "$user" -H realpath -m -- "$document_root")
                        case "$document_root_real" in "$selected"|"$selected"/*) ;; *) exit 1 ;; esac
                        sudo -u "$user" -H test -d "$document_root"
                        test "$(sudo -u "$user" -H realpath -e -- "$document_root")" = "$document_root_real"
                        unexpected_symlink=$(sudo find -P "$document_root_real" -type l -print -quit)
                        test -z "$unexpected_symlink"
                        ancestor="$document_root_real"
                        while [ "$ancestor" != "$home" ]; do
                            ancestor=${ancestor%/*}
                            if [ "$ancestor" = "$home" ]; then break; fi
                            case "$ancestor" in "$home"/*) ;; *) exit 1 ;; esac
                            sudo test -d "$ancestor"
                            sudo test ! -L "$ancestor"
                            ancestor_paths+=("$ancestor")
                        done
                        document_root_exists=1
                    fi
                    unexpected_user=$(sudo find -P "$home" -xdev ! -user "$user" -print -quit)
                    test -z "$unexpected_user"
                    unexpected_group=$(sudo find -P "$home" -xdev ! -group "$user" -print -quit)
                    test -z "$unexpected_group"
                    sudo setfacl -n -P -R -m u:caddy:--- "$home"
                    sudo find -P "$home" -type d -exec setfacl -m d:u:caddy:--- -- {} +
                    sudo setfacl -m u:caddy:--x /home "$home"
                    if [ "$document_root_exists" = 1 ]; then
                        for ancestor in "${ancestor_paths[@]}"; do
                            sudo setfacl -m u:caddy:--x "$ancestor"
                        done
                        sudo setfacl -P -R -m u:caddy:r-X "$document_root_real"
                        sudo find -P "$document_root_real" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                    fi
                    BASH,
            ),
            step: 'production-caddy-access',
            errorCode: 'app-prod.source_metadata_unsafe',
        );
    }

    public function validateCurrent(AppInstance $appInstance): void
    {
        $this->releaseLayout($appInstance, false);
    }

    public function clearCurrent(AppInstance $appInstance): void
    {
        $this->releaseLayout($appInstance, true);
    }

    private function releaseLayout(AppInstance $appInstance, bool $clearCurrent): void
    {
        if (! $appInstance->usesProductionReleaseLayout()) {
            return;
        }

        $appInstance->loadMissing(['app', 'node']);
        [$user, $home] = $this->identity($appInstance);
        $root = $appInstance->root ?? $appInstance->app->root;

        if (! is_string($root)) {
            throw $this->failure('production-release-layout', 'app-prod.source_metadata_unsafe');
        }

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
                    (string) $appInstance->id,
                    $root,
                    $clearCurrent ? '1' : '0',
                ],
                input: <<<'BASH'
                    repository=$1
                    user=$2
                    home=$3
                    instance=$4
                    relative_root=$5
                    clear_current=$6
                    state_directory="/var/lib/orbit/app-instance-sources/$instance"
                    marker="$state_directory/release-layout"
                    releases="$home/releases"
                    release="$releases/initial"
                    environment="$home/.env"
                    release_environment="$release/.env"
                    current="$home/current"

                    sudo test -d "$state_directory"
                    sudo test ! -L "$state_directory"
                    test "$(sudo stat -c %U:%G -- "$state_directory")" = root:root
                    test "$(sudo stat -c %a -- "$state_directory")" = 700
                    sudo test -f "$marker"
                    sudo test ! -L "$marker"
                    test "$(sudo stat -c %U:%G -- "$marker")" = root:root
                    test "$(sudo stat -c %a -- "$marker")" = 600
                    actual=$(sudo base64 --wrap=0 -- "$marker")
                    expected=$(printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial | base64 --wrap=0)
                    test "$actual" = "$expected"

                    test "$home" = "/home/$user"
                    sudo -u "$user" -H test -d "$home"
                    sudo -u "$user" -H test ! -L "$home"
                    test "$(sudo -u "$user" -H realpath -e -- "$home")" = "$home"
                    sudo -u "$user" -H test -d "$releases"
                    sudo -u "$user" -H test ! -L "$releases"
                    sudo -u "$user" -H test -d "$release"
                    sudo -u "$user" -H test ! -L "$release"
                    sudo -u "$user" -H test -f "$environment"
                    sudo -u "$user" -H test ! -L "$environment"
                    test "$(sudo -u "$user" -H stat -c %a -- "$environment")" = 600
                    sudo -u "$user" -H test -L "$release_environment"
                    test "$(sudo -u "$user" -H readlink -- "$release_environment")" = ../../.env
                    test "$(sudo -u "$user" -H realpath -e -- "$release_environment")" = "$environment"
                    unexpected_user=$(sudo find -P "$home" -xdev ! -user "$user" -print -quit)
                    test -z "$unexpected_user"
                    unexpected_group=$(sudo find -P "$home" -xdev ! -group "$user" -print -quit)
                    test -z "$unexpected_group"
                    case "$relative_root" in ''|/*|..|../*|*/../*|*/..) exit 1 ;; esac

                    if ! sudo -u "$user" -H test -e "$current" && ! sudo -u "$user" -H test -L "$current"; then
                        exit 0
                    fi

                    sudo -u "$user" -H test -L "$current"
                    test "$(sudo -u "$user" -H stat -c %U -- "$current")" = "$user"
                    test "$(sudo -u "$user" -H stat -c %G -- "$current")" = "$user"
                    selected=$(sudo -u "$user" -H realpath -e -- "$current")
                    case "$selected" in "$releases"/*) ;; *) exit 1 ;; esac
                    sudo -u "$user" -H test -d "$selected"
                    selected_environment="$selected/.env"
                    sudo -u "$user" -H test -L "$selected_environment"
                    test "$(sudo -u "$user" -H realpath -e -- "$selected_environment")" = "$environment"
                    resolved_root=$(sudo -u "$user" -H realpath -m -- "$current/$relative_root")
                    case "$resolved_root" in "$selected"|"$selected"/*) ;; *) exit 1 ;; esac

                    if [ "$clear_current" = 1 ]; then
                        sudo -u "$user" -H rm -- "$current"
                    fi
                    BASH,
            ),
            step: 'production-release-layout',
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
            return $this->classifier->classify($json, $parts[1]);
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
