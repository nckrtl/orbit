<?php

declare(strict_types=1);

namespace App\Infrastructure\Instances;

use App\Domain\AppDev\RuntimeConvergenceException;
use App\Domain\Instances\InstanceSandboxGuard;
use App\Domain\Instances\ProductionWebRootManager;
use App\Domain\Routes\RouteWebRoot;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\AppProd\ProductionSshExecutor;
use App\Infrastructure\Ssh\RemoteCommand;
use App\Models\Instance;

final readonly class RemoteProductionWebRootManager implements ProductionWebRootManager
{
    public function __construct(
        private ProductionSshExecutor $ssh,
    ) {}

    public function prepare(Instance $instance): void
    {
        InstanceSandboxGuard::assertHostOperation($instance);
        $instance->loadMissing(['project', 'node']);
        $applications = RouteWebRoot::servedApplications($instance);
        $user = $instance->production_user;
        $home = $instance->production_home;

        if ($applications === []) {
            return;
        }

        if (! $instance->placedOnAppProd() || ! is_string($user) || ! is_string($home) || $home !== "/home/{$user}") {
            throw new ResourceOperationException('app-prod.web_root_invalid', 'The production Instance identity is incomplete.', 409);
        }

        try {
            $this->ssh->execute(
                $instance->node,
                new RemoteCommand(
                    arguments: ['bash', '-seu', '--', $user, $home, ProductionWebRootProgram::entries($applications)],
                    input: ProductionWebRootProgram::functions()."\n".<<<'BASH'
                        user=$1
                        home=$2
                        served_web_roots=$3
                        printf '%s' "$user" | grep -Eq '^[a-z_][a-z0-9_-]{0,31}$'
                        test "$home" = "/home/$user"
                        sudo -u "$user" -H test -d "$home"
                        sudo -u "$user" -H test ! -L "$home"
                        test "$(sudo -u "$user" -H realpath -e -- "$home")" = "$home"
                        current="$home/current"
                        if ! sudo -u "$user" -H test -L "$current"; then exit 3; fi
                        selected=$(sudo -u "$user" -H realpath -e -- "$current")
                        case "$selected" in "$home/releases/"*) ;; *) exit 1 ;; esac
                        printf '%s' "${selected#"$home/releases/"}" | grep -Eq '^[A-Za-z0-9][A-Za-z0-9._-]{0,127}$'
                        link_served_environments "$selected"
                        grant_served_web_roots "$selected"
                        BASH,
                    maxOutputBytes: 4096,
                ),
                'app-prod-web-roots',
                'app-prod.web_root_invalid',
            );
        } catch (RuntimeConvergenceException $exception) {
            if ($exception->result?->exitCode === 3) {
                throw new ResourceOperationException(
                    errorCode: 'route.web_root_release_missing',
                    message: 'Deploy the production Instance before a Route with a web root serves it.',
                    status: 409,
                    previous: $exception,
                );
            }

            throw $exception;
        }
    }
}
