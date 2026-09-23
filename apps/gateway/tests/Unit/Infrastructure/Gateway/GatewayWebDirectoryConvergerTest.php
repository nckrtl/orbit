<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\GatewayWebDirectoryConverger;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

function recordingWebDirectoryRunner(int $exitCode = 0): ProcessRunner
{
    return new class($exitCode) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function __construct(private readonly int $exitCode) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $this->calls[] = $invocation;

            return new CommandResult($this->exitCode, '', '', 1, false);
        }
    };
}

it('creates the web and releases directories for the caddy group without following links', function (): void {
    $processes = recordingWebDirectoryRunner();

    new GatewayWebDirectoryConverger($processes, '/home/orbit/web')->converge();

    expect($processes->calls)->toHaveCount(1)
        ->and($processes->calls[0]->arguments)->toBe(['sudo', 'bash', '-seu', '--', '/home/orbit/web'])
        ->and($processes->calls[0]->input)->toContain(
            'test ! -L "$web"',
            'test ! -L "$web/releases"',
            'install -d -o orbit -g caddy -m 0750 -- "$web" "$web/releases"',
        );
});

it('refuses a web directory outside the orbit home before running anything', function (string $webRoot): void {
    $processes = recordingWebDirectoryRunner();

    expect(fn () => new GatewayWebDirectoryConverger($processes, $webRoot)->converge())
        ->toThrow(NodeProvisioningException::class, 'must be a direct child of /home/orbit')
        ->and($processes->calls)->toBe([]);
})->with(['/var/www', '/home/orbit', '/home/orbit/', '/home/orbit/..', '/home/orbit/a/b', '/home/orbit/.hidden', "/home/orbit/web\nx", "/home/orbit/web\n"]);

it('reports a failed directory step with its error code', function (): void {
    expect(fn () => new GatewayWebDirectoryConverger(recordingWebDirectoryRunner(1), '/home/orbit/web')->converge())
        ->toThrow(NodeProvisioningException::class, 'Gateway web directory convergence failed.');
});
