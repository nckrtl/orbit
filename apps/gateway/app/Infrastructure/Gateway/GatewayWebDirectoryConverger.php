<?php

declare(strict_types=1);

namespace App\Infrastructure\Gateway;

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

/**
 * Prepares the web app release directory that the Gateway site serves. Releases arrive through
 * `bin/web-deploy`; this only guarantees a real directory Caddy can read, one level below the
 * `orbit` home that the checkout convergence already opens to the `caddy` group.
 */
final readonly class GatewayWebDirectoryConverger
{
    public function __construct(
        private ProcessRunner $processes,
        private string $webRoot,
    ) {}

    public function converge(): void
    {
        if (preg_match('#^/home/orbit/[A-Za-z0-9_-][A-Za-z0-9._-]*\z#', $this->webRoot) !== 1) {
            throw new NodeProvisioningException(
                step: 'gateway-web-validate',
                errorCode: 'gateway.web_directory_invalid',
                message: "Gateway web directory [{$this->webRoot}] must be a direct child of /home/orbit.",
            );
        }

        $result = $this->processes->run(new ProcessInvocation(
            arguments: ['sudo', 'bash', '-seu', '--', $this->webRoot],
            timeout: 60.0,
            input: <<<'BASH'
                web=$1
                test ! -L "$web"
                test ! -L "$web/releases"
                install -d -o orbit -g caddy -m 0750 -- "$web" "$web/releases"
                BASH,
        ));

        if (! $result->succeeded()) {
            throw $this->failure($result);
        }
    }

    private function failure(CommandResult $result): NodeProvisioningException
    {
        return new NodeProvisioningException(
            step: 'gateway-web-directory',
            errorCode: 'gateway.web_directory_failed',
            message: 'Gateway web directory convergence failed.',
            result: $result,
        );
    }
}
