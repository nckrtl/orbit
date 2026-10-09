<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\Deployment\DeploymentRelease;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\ProductionRepositoryBinding;
use App\Domain\Instances\ProductionRepositoryRecord;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

final readonly class RemoteProductionRepositoryBinding implements ProductionRepositoryBinding
{
    public function __construct(
        private ProductionSshExecutor $ssh,
    ) {}

    public function inspect(Instance $instance): ProductionRepositoryRecord
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        [$user, $home] = $this->identity($instance);
        $result = $this->execute(
            $instance,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $user, $home, (string) $instance->id],
                input: <<<'BASH'
                    # find must restore its working directory after sudo changes users.
                    cd /
                    user=$1
                    home=$2
                    instance=$3
                    test "$home" = "/home/$user"
                    case "$instance" in ''|*[!0-9]*) exit 1 ;; esac
                    state_directory="/var/lib/orbit/app-instance-sources/$instance"
                    releases="$home/releases"

                    if sudo test -e "$state_directory" || sudo test -L "$state_directory"; then
                        sudo test -d "$state_directory"
                        sudo test ! -L "$state_directory"
                        test "$(sudo stat -c %U:%G:%a -- "$state_directory")" = root:root:700
                        for kind in release-layout initial-clone; do
                            marker="$state_directory/$kind"
                            if sudo test -e "$marker" || sudo test -L "$marker"; then
                                sudo test -f "$marker"
                                sudo test ! -L "$marker"
                                test "$(sudo stat -c %U:%G:%a -- "$marker")" = root:root:600
                                printf 'MARKER\t%s\t%s\n' "$kind" "$(sudo base64 --wrap=0 -- "$marker")"
                            fi
                        done
                    fi

                    if sudo -u "$user" -H test -d "$releases" && sudo -u "$user" -H test ! -L "$releases"; then
                        while IFS= read -r -d '' name; do
                            printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$' || continue
                            release="$releases/$name"
                            sudo -u "$user" -H test ! -L "$release" || continue
                            sudo -u "$user" -H test -d "$release/.git" || continue
                            sudo -u "$user" -H test ! -L "$release/.git" || continue
                            origin=$(sudo -u "$user" -H git -C "$release" config --null --get remote.origin.url | base64 --wrap=0)
                            printf 'RELEASE\t%s\t%s\n' "$name" "$origin"
                        done < <(sudo -u "$user" -H find -P "$releases" -mindepth 1 -maxdepth 1 -type d -printf '%f\0' | sort -z)
                    fi
                    BASH,
                maxOutputBytes: 65536,
            ),
            'production-repository-inspect',
        );

        return $this->record($result, $user, $home);
    }

    public function rebind(Instance $instance, ProductionRepositoryRecord $expected, ProductionRepositoryRecord $target): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        [$user, $home] = $this->identity($instance);
        $changes = [];

        foreach ($expected->releases as $name => $url) {
            $changes[] = ['release', $name, $url, $target->releases[$name] ?? throw $this->failure()];
        }

        if ($expected->initial !== null) {
            $changes[] = ['initial-clone', '-', $expected->initial, $target->initial ?? throw $this->failure()];
        }

        // The release layout marker is the binding that deployment checks first, so it changes last.
        if ($expected->layout !== null) {
            $changes[] = ['release-layout', '-', $expected->layout, $target->layout ?? throw $this->failure()];
        }

        if ($changes === []) {
            return;
        }

        $this->execute(
            $instance,
            new RemoteCommand(
                arguments: ['bash', '-seu', '--', $user, $home, (string) $instance->id, ...array_merge(...$changes)],
                input: <<<'BASH'
                    cd /
                    user=$1
                    home=$2
                    instance=$3
                    shift 3
                    test "$home" = "/home/$user"
                    case "$instance" in ''|*[!0-9]*) exit 1 ;; esac
                    state_directory="/var/lib/orbit/app-instance-sources/$instance"
                    releases="$home/releases"
                    temporary=
                    trap 'if [ -n "$temporary" ]; then sudo rm -f -- "$temporary"; fi' EXIT

                    marker_content() {
                        case "$1" in
                            release-layout) printf '%s\0%s\0%s\0%s\0' "$2" "$user" "$home" initial ;;
                            initial-clone) printf '%s\0%s\0%s\0' "$2" "$user" "$home" ;;
                        esac
                    }

                    rebind_marker() {
                        local kind=$1 expected=$2 target=$3 marker actual wanted
                        marker="$state_directory/$kind"
                        sudo test -d "$state_directory"
                        sudo test ! -L "$state_directory"
                        test "$(sudo stat -c %U:%G:%a -- "$state_directory")" = root:root:700
                        sudo test -f "$marker"
                        sudo test ! -L "$marker"
                        test "$(sudo stat -c %U:%G:%a -- "$marker")" = root:root:600
                        actual=$(sudo base64 --wrap=0 -- "$marker")
                        wanted=$(marker_content "$kind" "$target" | base64 --wrap=0)
                        if [ "$actual" = "$wanted" ]; then return 0; fi
                        test "$actual" = "$(marker_content "$kind" "$expected" | base64 --wrap=0)"
                        temporary=$(sudo mktemp "$state_directory/.$kind.XXXXXX")
                        marker_content "$kind" "$target" | sudo tee "$temporary" >/dev/null
                        sudo chown root:root -- "$temporary"
                        sudo chmod 0600 -- "$temporary"
                        sudo mv -T -- "$temporary" "$marker"
                        temporary=
                        test "$(sudo base64 --wrap=0 -- "$marker")" = "$wanted"
                    }

                    rebind_release() {
                        local name=$1 expected=$2 target=$3 release actual wanted
                        printf '%s' "$name" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                        release="$releases/$name"
                        sudo -u "$user" -H test ! -L "$release"
                        sudo -u "$user" -H test -d "$release/.git"
                        sudo -u "$user" -H test ! -L "$release/.git"
                        test "$(sudo -u "$user" -H realpath -e -- "$release")" = "$release"
                        actual=$(sudo -u "$user" -H git -C "$release" config --null --get remote.origin.url | base64 --wrap=0)
                        wanted=$(printf '%s\0' "$target" | base64 --wrap=0)
                        if [ "$actual" = "$wanted" ]; then return 0; fi
                        test "$actual" = "$(printf '%s\0' "$expected" | base64 --wrap=0)"
                        sudo -u "$user" -H git -C "$release" remote set-url origin -- "$target"
                        test "$(sudo -u "$user" -H git -C "$release" config --null --get remote.origin.url | base64 --wrap=0)" = "$wanted"
                    }

                    while [ "$#" -gt 0 ]; do
                        test "$#" -ge 4
                        case "$1" in
                            release) rebind_release "$2" "$3" "$4" ;;
                            release-layout|initial-clone) rebind_marker "$1" "$3" "$4" ;;
                            *) exit 1 ;;
                        esac
                        shift 4
                    done
                    BASH,
            ),
            'production-repository-rebind',
        );
    }

    /** @return array{string, string} */
    private function identity(Instance $instance): array
    {
        $user = $instance->production_user;
        $home = $instance->production_home;

        if (! $instance->usesProductionReleaseLayout() || ! is_string($user) || ! is_string($home)) {
            throw $this->failure();
        }

        return [$user, $home];
    }

    private function execute(Instance $instance, RemoteCommand $command, string $step): CommandResult
    {
        try {
            return $this->ssh->execute(
                $instance->loadMissing('node')->node,
                $command,
                $step,
                'project.production_rebind_failed',
            );
        } catch (RuntimeConvergenceException $exception) {
            throw $this->failure($exception);
        }
    }

    private function record(CommandResult $result, string $user, string $home): ProductionRepositoryRecord
    {
        if ($result->truncated || $result->stderr !== '') {
            throw $this->failure();
        }

        $layout = null;
        $initial = null;
        $releases = [];

        foreach (explode("\n", rtrim($result->stdout, "\n")) as $line) {
            if ($line === '') {
                continue;
            }

            $parts = explode("\t", $line);

            if (count($parts) !== 3) {
                throw $this->failure();
            }

            $value = base64_decode($parts[2], true);

            if (! is_string($value)) {
                throw $this->failure();
            }

            if ($parts[0] === 'MARKER' && $parts[1] === 'release-layout' && $layout === null) {
                $layout = $this->markerRepository($value, [$user, $home, 'initial']);
            } elseif ($parts[0] === 'MARKER' && $parts[1] === 'initial-clone' && $initial === null) {
                $initial = $this->markerRepository($value, [$user, $home]);
            } elseif ($parts[0] === 'RELEASE' && DeploymentRelease::isValidName($parts[1]) && ! isset($releases[$parts[1]])) {
                // A release without a readable origin is partial. Deployment skips it, so a rebind does too.
                if ($value === '') {
                    continue;
                }

                if (preg_match('/\A([^\0]+)\0\z/', $value, $matches) !== 1) {
                    throw $this->failure();
                }

                $releases[$parts[1]] = $matches[1];
            } else {
                throw $this->failure();
            }
        }

        ksort($releases, SORT_STRING);

        return new ProductionRepositoryRecord($layout, $initial, $releases);
    }

    /** @param list<string> $fields */
    private function markerRepository(string $value, array $fields): string
    {
        $parts = explode("\0", $value);

        if (
            count($parts) !== count($fields) + 2
            || $parts[0] === ''
            || array_pop($parts) !== ''
            || array_slice($parts, 1) !== $fields
        ) {
            throw $this->failure();
        }

        return $parts[0];
    }

    private function failure(?\Throwable $previous = null): ResourceOperationException
    {
        return new ResourceOperationException(
            errorCode: 'project.production_rebind_failed',
            message: 'Orbit could not read or re-bind the repository of a production Instance.',
            status: 409,
            previous: $previous,
        );
    }
}
