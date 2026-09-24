<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\SystemdVpnOrderingDropIn;

/**
 * Installs Caddy on the Gateway machine from the pinned Caddy apt source, the same way role
 * convergence installs it on every other Node, and orders the Caddy unit after the managed
 * WireGuard interface. It runs before any Gateway web step that needs the `caddy` group or
 * validates a Caddyfile. ADR 0100 and ADR 0140 record the decision.
 *
 * Both steps are idempotent. The source program upgrades an archive Caddy in place and keeps the
 * Orbit-owned Caddyfile; the ordering drop-in changes nothing when it already matches.
 */
final readonly class NativeGatewayCaddyInstaller
{
    public function __construct(
        private ProcessRunner $processes,
        private SystemdVpnOrderingDropIn $vpnOrdering = new SystemdVpnOrderingDropIn,
    ) {}

    public function install(): void
    {
        $this->run(
            step: 'gateway-caddy-install',
            errorCode: 'gateway.caddy_install_failed',
            invocation: new ProcessInvocation(
                arguments: ['sudo', 'bash', '-seu', '--', ...CaddyPackageSourceProgram::arguments()],
                timeout: 900.0,
                input: CaddyPackageSourceProgram::render(),
            ),
        );
        $this->run(
            step: 'gateway-caddy-ordering',
            errorCode: 'gateway.caddy_start_failed',
            invocation: $this->vpnOrdering->invocation('caddy'),
        );
    }

    private function run(string $step, string $errorCode, ProcessInvocation $invocation): void
    {
        $result = $this->processes->run($invocation);

        if (! $result->succeeded()) {
            throw new NodeProvisioningException(
                step: $step,
                errorCode: $errorCode,
                message: "Gateway Caddy installation step [{$step}] failed.",
                result: $result,
            );
        }
    }
}
