<?php

declare(strict_types=1);

namespace App\Infrastructure\AppInstances;

use App\Domain\AppInstances\AppInstanceState;
use App\Domain\AppInstances\Deployment\DeploymentEvent;
use App\Domain\AppInstances\Deployment\DeploymentOutputStream;
use App\Domain\AppInstances\Deployment\DeploymentRelease;
use App\Domain\AppInstances\Deployment\DeploymentReleaseState;
use App\Domain\AppInstances\Deployment\DeploymentRequest;
use App\Domain\AppInstances\Deployment\DeploymentStep;
use App\Domain\AppInstances\Deployment\ProductionDeployment;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\AppProdSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessOutput;
use App\Infrastructure\Processes\ProcessOutputStream;
use App\Infrastructure\Processes\ProtectedInput;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\AppInstance;
use Closure;

final readonly class RemoteProductionDeployment implements ProductionDeployment
{
    /** @var Closure(): string */
    private Closure $releaseName;

    /** @param (Closure(): string)|null $releaseName */
    public function __construct(
        private AppProdSshExecutor $ssh,
        ?Closure $releaseName = null,
    ) {
        $this->releaseName = $releaseName ?? static fn (): string => gmdate('YmdHis').'-'.bin2hex(random_bytes(8));
    }

    public function prepare(AppInstance $appInstance, string $branch): DeploymentRelease
    {
        [$repository, $user, $home, $root] = $this->identity($appInstance);
        $name = ($this->releaseName)();
        $this->assertReleaseName($name);

        $result = $this->execute(
            $appInstance,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $repository,
                    $user,
                    $home,
                    (string) $appInstance->id,
                    $branch,
                    $name,
                    $root,
                ],
                input: <<<'BASH'
                    repository=$1
                    user=$2
                    home=$3
                    instance=$4
                    branch=$5
                    name=$6
                    relative_root=$7
                    state_directory="/var/lib/orbit/app-instance-sources/$instance"
                    marker="$state_directory/release-layout"
                    releases="$home/releases"
                    release="$releases/$name"
                    environment="$home/.env"
                    release_environment="$release/.env"
                    current="$home/current"

                    test "$home" = "/home/$user"
                    printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                    case "$relative_root" in ''|/*|..|../*|*/../*|*/..) exit 1 ;; esac
                    sudo test -d "$state_directory"
                    sudo test ! -L "$state_directory"
                    test "$(sudo stat -c %U:%G:%a -- "$state_directory")" = root:root:700
                    sudo test -f "$marker"
                    sudo test ! -L "$marker"
                    test "$(sudo stat -c %U:%G:%a -- "$marker")" = root:root:600
                    actual=$(sudo base64 --wrap=0 -- "$marker")
                    expected=$(printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial | base64 --wrap=0)
                    test "$actual" = "$expected"
                    sudo -u "$user" -H test -d "$home"
                    sudo -u "$user" -H test ! -L "$home"
                    test "$(sudo -u "$user" -H realpath -e -- "$home")" = "$home"
                    sudo -u "$user" -H test -d "$releases"
                    sudo -u "$user" -H test ! -L "$releases"
                    sudo -u "$user" -H test -f "$environment"
                    sudo -u "$user" -H test ! -L "$environment"
                    sudo -u "$user" -H test ! -e "$release"
                    sudo -u "$user" -H test ! -L "$release"

                    sudo -u "$user" -H git clone --no-checkout --origin origin -- "$repository" "$release" >/dev/null 2>&1
                    source_ref="refs/remotes/origin/$branch"
                    sudo -u "$user" -H git -C "$release" fetch --prune -- origin >/dev/null 2>&1
                    sudo -u "$user" -H git -C "$release" show-ref --verify --quiet "$source_ref"
                    sudo -u "$user" -H git -C "$release" checkout --detach "$source_ref" >/dev/null 2>&1
                    sudo -u "$user" -H ln -s ../../.env "$release_environment"
                    test "$(sudo -u "$user" -H realpath -e -- "$release_environment")" = "$environment"
                    selected_root=$(sudo -u "$user" -H realpath -m -- "$release/$relative_root")
                    case "$selected_root" in "$release"|"$release"/*) ;; *) exit 1 ;; esac
                    sudo -u "$user" -H test -d "$selected_root"
                    unexpected_symlink=$(sudo find -P "$selected_root" -type l -print -quit)
                    test -z "$unexpected_symlink"
                    unexpected_user=$(sudo find -P "$release" -xdev ! -user "$user" -print -quit)
                    test -z "$unexpected_user"
                    unexpected_group=$(sudo find -P "$release" -xdev ! -group "$user" -print -quit)
                    test -z "$unexpected_group"
                    commit=$(sudo -u "$user" -H git -C "$release" rev-parse --verify HEAD)
                    printf '%s\t%s\n' "$name" "$commit"
                    BASH,
                maxOutputBytes: 4096,
            ),
            'deployment-prepare',
            'deployment.prepare_failed',
        );

        [$reportedName, $commit] = $this->parseReleaseResult($result);

        if ($reportedName !== $name) {
            throw $this->invalidReceipt();
        }

        return new DeploymentRelease($name, "{$home}/releases/{$name}", $commit);
    }

    public function executeStep(
        AppInstance $appInstance,
        DeploymentRelease $release,
        DeploymentStep $step,
        DeploymentRequest $request,
    ): CommandResult {
        [, $user, $home] = $this->identity($appInstance);
        $this->assertRelease($release, $home);
        $stepName = $step->name;
        $protectedInput = ProtectedInput::fromString(str_replace(
            '__COMMAND__',
            base64_encode($step->command),
            <<<'BASH'
                user=$1
                home=$2
                release=$3
                test "$home" = "/home/$user"
                releases="$home/releases"
                case "$release" in "$releases"/*) ;; *) exit 1 ;; esac
                sudo -u "$user" -H test -d "$release"
                sudo -u "$user" -H test ! -L "$release"
                test "$(sudo -u "$user" -H realpath -e -- "$release")" = "$release"
                command_file=$(sudo -u "$user" -H mktemp "$home/.orbit-deploy.XXXXXXXX")
                supervisor=
                process_group=
                watchdog=
                expected_uid=$(id -u "$user")
                discover_process_group() {
                    for attempt in $(seq 1 100); do
                        candidate=$(pgrep -P "$supervisor" 2>/dev/null || true)
                        case "$candidate" in ''|*[!0-9]*) ;; *)
                            identity=$(ps -o ppid=,pgid=,uid= -p "$candidate" 2>/dev/null || true)
                            set -- $identity
                            if [ "$#" -eq 3 ] && [ "$1" = "$supervisor" ] && [ "$2" = "$candidate" ] && [ "$3" = "$expected_uid" ]; then
                                printf '%s' "$candidate"
                                return 0
                            fi
                        esac
                        if ! kill -0 "$supervisor" 2>/dev/null; then return 1; fi
                        sleep 0.01
                    done
                    return 1
                }
                terminate_step() {
                    if [ -z "$process_group" ]; then return; fi
                    sudo -u "$user" -H kill -TERM -- "-$process_group" 2>/dev/null || true
                    sleep 0.1
                    sudo -u "$user" -H kill -KILL -- "-$process_group" 2>/dev/null || true
                }
                cleanup() {
                    status=$?
                    terminate_step
                    if [ -n "$supervisor" ]; then
                        wait "$supervisor" 2>/dev/null || true
                    fi
                    if [ -n "$watchdog" ]; then
                        wait "$watchdog" 2>/dev/null || true
                    fi
                    sudo -u "$user" -H rm -f -- "$command_file"
                    exit "$status"
                }
                trap cleanup EXIT HUP INT TERM
                printf '%s' '__COMMAND__' | base64 --decode | sudo -u "$user" -H tee "$command_file" >/dev/null
                sudo -u "$user" -H chmod 0600 -- "$command_file"
                owner=$PPID
                sudo -u "$user" -H setsid --wait bash -eu -c 'umask 077; cd -- "$1"; exec bash -eu "$2"' bash "$release" "$command_file" &
                supervisor=$!
                (
                    watched_group=$(discover_process_group) || exit 0
                    while kill -0 "$owner" 2>/dev/null && kill -0 "$supervisor" 2>/dev/null; do sleep 0.1; done
                    if kill -0 "$owner" 2>/dev/null; then exit 0; fi
                    sudo -u "$user" -H kill -TERM -- "-$watched_group" 2>/dev/null || true
                    sleep 0.1
                    sudo -u "$user" -H kill -KILL -- "-$watched_group" 2>/dev/null || true
                ) &
                watchdog=$!
                process_group=$(discover_process_group) || true
                if wait "$supervisor"; then status=0; else status=$?; fi
                supervisor=
                process_group=
                wait "$watchdog" 2>/dev/null || true
                watchdog=
                exit "$status"
                BASH,
        ));

        return $this->execute(
            $appInstance,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $user, $home, $release->path],
                protectedInput: $protectedInput,
                output: static function (ProcessOutput $output) use ($request, $stepName): void {
                    $request->emit(new DeploymentEvent(
                        step: $stepName,
                        stream: $output->stream === ProcessOutputStream::Stdout
                            ? DeploymentOutputStream::Stdout
                            : DeploymentOutputStream::Stderr,
                        value: $output->value,
                    ));
                },
                cancelled: $request->cancellation->requested(...),
                timeout: $step->timeoutSeconds,
            ),
            "deployment-step-{$stepName}",
            'deployment.step_failed',
        );
    }

    public function activate(AppInstance $appInstance, DeploymentRelease $release): DeploymentRelease
    {
        [$repository, $user, $home, $root] = $this->identity($appInstance);
        $this->assertRelease($release, $home);

        $result = $this->execute(
            $appInstance,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $repository,
                    $user,
                    $home,
                    (string) $appInstance->id,
                    $release->name,
                    $release->commit,
                    $root,
                ],
                input: <<<'BASH'
                    repository=$1
                    user=$2
                    home=$3
                    instance=$4
                    name=$5
                    expected_commit=$6
                    relative_root=$7
                    state_directory="/var/lib/orbit/app-instance-sources/$instance"
                    marker="$state_directory/release-layout"
                    releases="$home/releases"
                    release="$releases/$name"
                    current="$home/current"
                    environment="$home/.env"
                    release_environment="$release/.env"
                    printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                    case "$relative_root" in ''|/*|..|../*|*/../*|*/..) exit 1 ;; esac
                    sudo test -f "$marker"
                    sudo test ! -L "$marker"
                    test "$(sudo stat -c %U:%G:%a -- "$marker")" = root:root:600
                    actual_marker=$(sudo base64 --wrap=0 -- "$marker")
                    expected_marker=$(printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial | base64 --wrap=0)
                    test "$actual_marker" = "$expected_marker"
                    sudo -u "$user" -H test -d "$release/.git"
                    sudo -u "$user" -H test ! -L "$release"
                    test "$(sudo -u "$user" -H realpath -e -- "$release")" = "$release"
                    actual_repository=$(sudo -u "$user" -H git -C "$release" config --null --get remote.origin.url | base64 --wrap=0)
                    expected_repository=$(printf '%s\0' "$repository" | base64 --wrap=0)
                    test "$actual_repository" = "$expected_repository"
                    test "$(sudo -u "$user" -H git -C "$release" rev-parse --verify HEAD)" = "$expected_commit"
                    sudo -u "$user" -H test -L "$release_environment"
                    test "$(sudo -u "$user" -H realpath -e -- "$release_environment")" = "$environment"
                    selected_root=$(sudo -u "$user" -H realpath -m -- "$release/$relative_root")
                    case "$selected_root" in "$release"|"$release"/*) ;; *) exit 1 ;; esac
                    sudo -u "$user" -H test -d "$selected_root"
                    unexpected_symlink=$(sudo find -P "$selected_root" -type l -print -quit)
                    test -z "$unexpected_symlink"
                    unexpected_user=$(sudo find -P "$release" -xdev ! -user "$user" -print -quit)
                    test -z "$unexpected_user"
                    unexpected_group=$(sudo find -P "$release" -xdev ! -group "$user" -print -quit)
                    test -z "$unexpected_group"
                    ancestor=$(dirname -- "$selected_root")
                    while [ "$ancestor" != "$release" ]; do
                        case "$ancestor" in "$release"/*) ;; *) exit 1 ;; esac
                        sudo test -d "$ancestor"
                        sudo test ! -L "$ancestor"
                        sudo setfacl -m u:caddy:--x "$ancestor"
                        ancestor=$(dirname -- "$ancestor")
                    done
                    sudo setfacl -m u:caddy:--x "$release"
                    sudo setfacl -P -R -m u:caddy:r-X "$selected_root"
                    sudo find -P "$selected_root" -type d -exec setfacl -m d:u:caddy:r-x -- {} +
                    temporary="$home/.current.$$.tmp"
                    trap 'sudo -u "$user" -H rm -f -- "$temporary"' EXIT
                    sudo -u "$user" -H ln -s "releases/$name" "$temporary"
                    sudo -u "$user" -H mv -Tf -- "$temporary" "$current"
                    trap - EXIT
                    printf '%s\t%s\n' "$name" "$expected_commit"
                    BASH,
                maxOutputBytes: 4096,
            ),
            'deployment-activate',
            'deployment.activation_failed',
        );
        [$name, $commit] = $this->parseReleaseResult($result);

        if ($name !== $release->name || $commit !== $release->commit) {
            throw $this->invalidReceipt();
        }

        return $release;
    }

    public function selected(AppInstance $appInstance): ?DeploymentRelease
    {
        [$repository, $user, $home, $root] = $this->identity($appInstance);
        $result = $this->execute(
            $appInstance,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $repository, $user, $home, (string) $appInstance->id, $root],
                input: $this->inspectionScript(selectCurrent: true),
                maxOutputBytes: 4096,
            ),
            'deployment-selected-release',
            'deployment.selection_invalid',
        );

        if (! $result->truncated && $result->stderr === '' && $result->stdout === "NONE\n") {
            return null;
        }

        return $this->releaseFromResult($result, $home);
    }

    public function retained(AppInstance $appInstance, string $name): DeploymentRelease
    {
        $this->assertReleaseName($name);
        [$repository, $user, $home, $root] = $this->identity($appInstance);
        $result = $this->execute(
            $appInstance,
            new RemoteCommand(
                arguments: [
                    'bash',
                    '-seu',
                    '--',
                    $repository,
                    $user,
                    $home,
                    (string) $appInstance->id,
                    $root,
                    $name,
                ],
                input: $this->inspectionScript(selectCurrent: false),
                maxOutputBytes: 4096,
            ),
            'rollback-retained-release',
            'rollback.release_invalid',
        );

        return $this->releaseFromResult($result, $home);
    }

    public function releases(AppInstance $appInstance): DeploymentReleaseState
    {
        [$repository, $user, $home, $root] = $this->identity($appInstance);
        $result = $this->execute(
            $appInstance,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $repository, $user, $home, (string) $appInstance->id, $root],
                input: <<<'BASH'
                    repository=$1
                    user=$2
                    home=$3
                    instance=$4
                    relative_root=$5
                    state_directory="/var/lib/orbit/app-instance-sources/$instance"
                    marker="$state_directory/release-layout"
                    releases="$home/releases"
                    environment="$home/.env"
                    current="$home/current"
                    test "$home" = "/home/$user"
                    case "$relative_root" in ''|/*|..|../*|*/../*|*/..) exit 1 ;; esac
                    sudo test -f "$marker"
                    sudo test ! -L "$marker"
                    test "$(sudo stat -c %U:%G:%a -- "$marker")" = root:root:600
                    actual_marker=$(sudo base64 --wrap=0 -- "$marker")
                    expected_marker=$(printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial | base64 --wrap=0)
                    test "$actual_marker" = "$expected_marker"
                    sudo -u "$user" -H test -d "$releases"
                    sudo -u "$user" -H test ! -L "$releases"

                    selected=
                    if sudo -u "$user" -H test -e "$current" || sudo -u "$user" -H test -L "$current"; then
                        sudo -u "$user" -H test -L "$current"
                        selected_path=$(sudo -u "$user" -H realpath -e -- "$current")
                        case "$selected_path" in "$releases"/*) ;; *) exit 1 ;; esac
                        selected=${selected_path#"$releases"/}
                        case "$selected" in */*) exit 1 ;; esac
                        printf '%s' "$selected" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                    fi
                    printf 'SELECTED\t%s\n' "$selected"

                    while IFS= read -r -d '' name; do
                        printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                        release="$releases/$name"
                        sudo -u "$user" -H test -d "$release/.git"
                        sudo -u "$user" -H test ! -L "$release"
                        test "$(sudo -u "$user" -H realpath -e -- "$release")" = "$release"
                        actual_repository=$(sudo -u "$user" -H git -C "$release" config --null --get remote.origin.url | base64 --wrap=0)
                        expected_repository=$(printf '%s\0' "$repository" | base64 --wrap=0)
                        test "$actual_repository" = "$expected_repository"
                        release_environment="$release/.env"
                        sudo -u "$user" -H test -L "$release_environment"
                        test "$(sudo -u "$user" -H realpath -e -- "$release_environment")" = "$environment"
                        selected_root=$(sudo -u "$user" -H realpath -m -- "$release/$relative_root")
                        case "$selected_root" in "$release"|"$release"/*) ;; *) exit 1 ;; esac
                        sudo -u "$user" -H test -d "$selected_root"
                        unexpected_symlink=$(sudo find -P "$selected_root" -type l -print -quit)
                        test -z "$unexpected_symlink"
                        unexpected_user=$(sudo find -P "$release" -xdev ! -user "$user" -print -quit)
                        test -z "$unexpected_user"
                        unexpected_group=$(sudo find -P "$release" -xdev ! -group "$user" -print -quit)
                        test -z "$unexpected_group"
                        commit=$(sudo -u "$user" -H git -C "$release" rev-parse --verify HEAD)
                        printf 'RELEASE\t%s\t%s\n' "$name" "$commit"
                    done < <(sudo -u "$user" -H find -P "$releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\0' | sort -z)
                    BASH,
                maxOutputBytes: 65536,
            ),
            'deployment-release-list',
            'deployment.release_inspection_failed',
        );

        return $this->releaseStateFromResult($result);
    }

    /** @return array{string, string, string, string} */
    private function identity(AppInstance $appInstance): array
    {
        $appInstance->loadMissing(['app', 'node']);
        $repository = $appInstance->app->repository_url;
        $user = $appInstance->production_user;
        $home = $appInstance->production_home;
        $root = $appInstance->root ?? $appInstance->app->root;

        if (
            $appInstance->environment !== 'production'
            || $appInstance->status !== AppInstanceState::Active
            || $appInstance->migration_required
            || $appInstance->provisioning_step !== 'active'
            || ! $appInstance->usesProductionReleaseLayout()
            || ! is_string($user)
            || ! is_string($home)
            || ! is_string($root)
        ) {
            throw new ResourceOperationException(
                'deployment.identity_invalid',
                'The production deployment identity is invalid.',
                409,
            );
        }

        return [$repository, $user, $home, $root];
    }

    private function inspectionScript(bool $selectCurrent): string
    {
        $selection = $selectCurrent
            ? <<<'BASH'
                current="$home/current"
                if ! sudo -u "$user" -H test -e "$current" && ! sudo -u "$user" -H test -L "$current"; then
                    printf 'NONE\n'
                    exit 0
                fi
                sudo -u "$user" -H test -L "$current"
                release=$(sudo -u "$user" -H realpath -e -- "$current")
                case "$release" in "$releases"/*) ;; *) exit 1 ;; esac
                name=${release#"$releases"/}
                case "$name" in */*) exit 1 ;; esac
                BASH
            : <<<'BASH'
                name=$6
                printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                release="$releases/$name"
                BASH;

        $script = <<<'BASH'
            repository=$1
            user=$2
            home=$3
            instance=$4
            relative_root=$5
            state_directory="/var/lib/orbit/app-instance-sources/$instance"
            marker="$state_directory/release-layout"
            releases="$home/releases"
            environment="$home/.env"
            test "$home" = "/home/$user"
            case "$relative_root" in ''|/*|..|../*|*/../*|*/..) exit 1 ;; esac
            sudo test -f "$marker"
            sudo test ! -L "$marker"
            test "$(sudo stat -c %U:%G:%a -- "$marker")" = root:root:600
            actual_marker=$(sudo base64 --wrap=0 -- "$marker")
            expected_marker=$(printf '%s\0%s\0%s\0%s\0' "$repository" "$user" "$home" initial | base64 --wrap=0)
            test "$actual_marker" = "$expected_marker"
            sudo -u "$user" -H test -d "$releases"
            sudo -u "$user" -H test ! -L "$releases"
            __SELECTION__
            printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
            sudo -u "$user" -H test -d "$release/.git"
            sudo -u "$user" -H test ! -L "$release"
            test "$(sudo -u "$user" -H realpath -e -- "$release")" = "$release"
            actual_repository=$(sudo -u "$user" -H git -C "$release" config --null --get remote.origin.url | base64 --wrap=0)
            expected_repository=$(printf '%s\0' "$repository" | base64 --wrap=0)
            test "$actual_repository" = "$expected_repository"
            release_environment="$release/.env"
            sudo -u "$user" -H test -L "$release_environment"
            test "$(sudo -u "$user" -H realpath -e -- "$release_environment")" = "$environment"
            selected_root=$(sudo -u "$user" -H realpath -m -- "$release/$relative_root")
            case "$selected_root" in "$release"|"$release"/*) ;; *) exit 1 ;; esac
            sudo -u "$user" -H test -d "$selected_root"
            unexpected_symlink=$(sudo find -P "$selected_root" -type l -print -quit)
            test -z "$unexpected_symlink"
            unexpected_user=$(sudo find -P "$release" -xdev ! -user "$user" -print -quit)
            test -z "$unexpected_user"
            unexpected_group=$(sudo find -P "$release" -xdev ! -group "$user" -print -quit)
            test -z "$unexpected_group"
            commit=$(sudo -u "$user" -H git -C "$release" rev-parse --verify HEAD)
            printf '%s\t%s\n' "$name" "$commit"
            BASH;

        return str_replace('__SELECTION__', $selection, $script);
    }

    private function execute(
        AppInstance $appInstance,
        RemoteCommand $command,
        string $step,
        string $errorCode,
    ): CommandResult {
        return $this->ssh->execute($appInstance->node, $command, $step, $errorCode);
    }

    /** @return array{string, string} */
    private function parseReleaseIdentity(string $receipt): array
    {
        $parts = explode("\t", trim($receipt), 2);

        if (count($parts) !== 2 || preg_match('/\A[0-9a-f]{40,64}\z/', $parts[1]) !== 1) {
            throw $this->invalidReceipt();
        }

        $this->assertReleaseName($parts[0]);

        return [$parts[0], $parts[1]];
    }

    /** @return array{string, string} */
    private function parseReleaseResult(CommandResult $result): array
    {
        if ($result->truncated || $result->stderr !== '') {
            throw $this->invalidReceipt();
        }

        return $this->parseReleaseIdentity($result->stdout);
    }

    private function releaseFromResult(CommandResult $result, string $home): DeploymentRelease
    {
        [$name, $commit] = $this->parseReleaseResult($result);

        return new DeploymentRelease($name, "{$home}/releases/{$name}", $commit);
    }

    private function assertRelease(DeploymentRelease $release, string $home): void
    {
        $this->assertReleaseName($release->name);

        if ($release->path !== "{$home}/releases/{$release->name}") {
            throw new ResourceOperationException(
                'deployment.release_invalid',
                'The deployment release is invalid.',
                409,
            );
        }
    }

    private function assertReleaseName(string $name): void
    {
        if (! DeploymentRelease::isValidName($name)) {
            throw new ResourceOperationException(
                'rollback.release_invalid',
                'The retained release name is invalid.',
                422,
            );
        }
    }

    private function releaseStateFromResult(CommandResult $result): DeploymentReleaseState
    {
        if ($result->truncated || $result->stderr !== '') {
            throw $this->invalidReceipt();
        }

        $lines = explode("\n", rtrim($result->stdout, "\n"));
        $selection = array_shift($lines);

        if (! str_starts_with($selection, "SELECTED\t")) {
            throw $this->invalidReceipt();
        }

        $selected = substr($selection, strlen("SELECTED\t"));
        $selected = $selected === '' ? null : $selected;

        if ($selected !== null) {
            $this->assertReleaseName($selected);
        }

        $releases = [];

        foreach ($lines as $line) {
            $parts = explode("\t", $line);

            if (
                count($parts) !== 3
                || $parts[0] !== 'RELEASE'
                || preg_match('/\A[0-9a-f]{40,64}\z/', $parts[2]) !== 1
            ) {
                throw $this->invalidReceipt();
            }

            $this->assertReleaseName($parts[1]);
            $releases[] = $parts[1];
        }

        if (count($releases) !== count(array_unique($releases))) {
            throw $this->invalidReceipt();
        }

        sort($releases);

        if ($selected !== null && ! in_array($selected, $releases, strict: true)) {
            throw $this->invalidReceipt();
        }

        return new DeploymentReleaseState($releases, $selected);
    }

    private function invalidReceipt(): ResourceOperationException
    {
        return new ResourceOperationException(
            'deployment.receipt_invalid',
            'The production deployment returned an invalid receipt.',
            409,
        );
    }
}
