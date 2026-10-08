<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

final readonly class NativeGatewayFpmConverger
{
    private const string LIVE_POOL = '/etc/php/8.5/fpm/pool.d/orbit-gateway.conf';

    public function __construct(
        private ProcessRunner $processes,
    ) {}

    public function converge(string $generatedPool): void
    {
        $version = bin2hex(random_bytes(8));
        $candidateDirectory = "/etc/php/8.5/fpm/orbit-candidates/{$version}";
        $candidateMain = $candidateDirectory.'/php-fpm.conf';
        $candidatePool = $candidateDirectory.'/pool.d/orbit-gateway.conf';

        try {
            $this->stage($generatedPool, $candidateDirectory, $candidateMain, $candidatePool);
            $this->run(
                step: 'gateway-fpm-validate',
                errorCode: 'gateway.fpm_config_invalid',
                arguments: ['sudo', 'php-fpm8.5', '--test', '--fpm-config', $candidateMain],
            );
            $this->run(
                step: 'gateway-fpm-install',
                errorCode: 'gateway.fpm_config_install_failed',
                arguments: ['sudo', 'mv', '-f', '--', $candidatePool, self::LIVE_POOL],
            );
            $this->cleanup($candidateDirectory);
        } catch (NodeProvisioningException $exception) {
            $this->cleanup($candidateDirectory);

            throw $exception;
        }

        $this->installCleanupStartup();
        // A running distro FPM service can activate the Gateway pool with a reload, which skips ExecStartPre.
        $this->run(
            step: 'gateway-fpm-cleanup-invalidate',
            errorCode: 'gateway.fpm_start_failed',
            arguments: ['sudo', GatewayCleanupStartupRenderer::HOOK_PATH],
        );
        $this->run(
            step: 'gateway-fpm-enable',
            errorCode: 'gateway.fpm_start_failed',
            arguments: ['sudo', 'systemctl', 'enable', 'php8.5-fpm'],
        );
        $this->run(
            step: 'gateway-fpm-reload',
            errorCode: 'gateway.fpm_start_failed',
            arguments: ['sudo', 'systemctl', 'reload-or-restart', 'php8.5-fpm'],
        );
    }

    private function stage(
        string $generatedPool,
        string $candidateDirectory,
        string $candidateMain,
        string $candidatePool,
    ): void {
        $this->run(
            step: 'gateway-fpm-stage',
            errorCode: 'gateway.fpm_config_install_failed',
            arguments: [
                'sudo',
                'bash',
                '-seu',
                '--',
                $generatedPool,
                $candidateDirectory,
                $candidateMain,
                $candidatePool,
            ],
            input: <<<'BASH'
                replacement=$1
                candidate_directory=$2
                candidate_main=$3
                candidate_pool=$4
                candidates_directory=$(dirname "$candidate_directory")
                candidate_pool_directory=$(dirname "$candidate_pool")
                live_main=/etc/php/8.5/fpm/php-fpm.conf
                install -d -o root -g root -m 0755 "$candidates_directory" "$candidate_directory" "$candidate_pool_directory"

                for pool in /etc/php/8.5/fpm/pool.d/*.conf; do
                    if [ ! -e "$pool" ]; then
                        continue
                    fi

                    pool_name=$(basename "$pool")
                    if [ "$pool_name" = orbit-gateway.conf ]; then
                        continue
                    fi

                    cp --preserve=mode,ownership -- "$pool" "$candidate_pool_directory/$pool_name"
                done

                install -o root -g root -m 0644 -- "$replacement" "$candidate_pool"
                awk -v candidate_pool_pattern="$candidate_pool_directory/*.conf" '
                    BEGIN { replacement_count = 0 }
                    /^[[:space:]]*include[[:space:]]*=[[:space:]]*\/etc\/php\/8\.5\/fpm\/pool\.d\/\*\.conf[[:space:]]*$/ {
                        print "include = " candidate_pool_pattern
                        replacement_count++
                        next
                    }
                    { print }
                    END { if (replacement_count != 1) exit 1 }
                ' "$live_main" > "$candidate_main"
                chown root:root "$candidate_main"
                chmod 0644 "$candidate_main"
                BASH,
        );
    }

    private function installCleanupStartup(): void
    {
        $renderer = new GatewayCleanupStartupRenderer;
        $hook = $renderer->renderHook(config()->string('orbit.gateway_checkout'), config()->string('orbit.home'));
        $dropIn = $renderer->renderDropIn();
        $program = <<<'BASH'
            install -d -o root -g root -m 0755 /etc/orbit /etc/systemd/system/php8.5-fpm.service.d
            hook=$(mktemp /etc/orbit/.document-cleanup-start.XXXXXX)
            unit=$(mktemp /etc/systemd/system/php8.5-fpm.service.d/.document-cleanup.XXXXXX)
            trap 'rm -f -- "$hook" "$unit"' EXIT
            BASH;
        $program .= "\nprintf '%s' ".escapeshellarg($hook).' > "$hook"';
        $program .= "\nprintf '%s' ".escapeshellarg($dropIn).' > "$unit"';
        $program .= <<<'BASH'

            chown root:root "$hook" "$unit"
            chmod 0755 "$hook"
            chmod 0644 "$unit"
            bash -n "$hook"
            mv -f -- "$hook" /etc/orbit/project-document-cleanup-start
            mv -f -- "$unit" /etc/systemd/system/php8.5-fpm.service.d/orbit-document-cleanup.conf
            systemctl daemon-reload
            BASH;
        $this->run(
            step: 'gateway-fpm-cleanup-startup',
            errorCode: 'gateway.fpm_config_install_failed',
            arguments: ['sudo', 'bash', '-seu'],
            input: $program,
        );
    }

    private function cleanup(string $candidateDirectory): void
    {
        $this->processes->run(new ProcessInvocation(['sudo', 'rm', '-rf', '--', $candidateDirectory]));
    }

    /** @param non-empty-list<string> $arguments */
    private function run(string $step, string $errorCode, array $arguments, ?string $input = null): void
    {
        $result = $this->processes->run(new ProcessInvocation(
            arguments: $arguments,
            timeout: 60.0,
            input: $input,
        ));

        if (! $result->succeeded()) {
            throw new NodeProvisioningException(
                step: $step,
                errorCode: $errorCode,
                message: "Gateway FPM convergence step [{$step}] failed.",
                result: $result,
            );
        }
    }
}
