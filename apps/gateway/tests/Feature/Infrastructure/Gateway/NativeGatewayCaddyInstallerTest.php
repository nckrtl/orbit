<?php

declare(strict_types=1);

use App\Domain\Nodes\NodeProvisioningException;
use App\Infrastructure\Gateway\NativeGatewayCaddyInstaller;
use App\Infrastructure\Nodes\CaddyPackageSourceProgram;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;
use App\Infrastructure\Processes\SystemdVpnOrderingDropIn;

describe('Gateway Caddy installer', function (): void {
    it('runs the pinned source program and then the WireGuard ordering drop-in as root', function (): void {
        $processes = gateway_caddy_installer_processes();

        new NativeGatewayCaddyInstaller($processes)->install();

        $dropIn = new SystemdVpnOrderingDropIn;

        expect($processes->calls)->toHaveCount(2)
            ->and($processes->calls[0]->arguments)
            ->toBe(['sudo', 'bash', '-seu', '--', ...CaddyPackageSourceProgram::arguments()])
            ->and($processes->calls[0]->input)
            ->toBe(CaddyPackageSourceProgram::render())
            ->and($processes->calls[1]->arguments)
            ->toBe($dropIn->arguments('caddy'))
            ->and($processes->calls[1]->input)
            ->toBe($dropIn->script());
    });

    it('maps each failed step to its step name and error code', function (int $failingCall, string $step, string $errorCode, int $calls): void {
        $processes = gateway_caddy_installer_processes(failingCall: $failingCall);

        expect(fn () => new NativeGatewayCaddyInstaller($processes)->install())
            ->toThrow(function (NodeProvisioningException $exception) use ($step, $errorCode): void {
                expect($exception->step)->toBe($step)
                    ->and($exception->errorCode)->toBe($errorCode)
                    ->and($exception->result?->exitCode)->toBe(1);
            })
            ->and($processes->calls)->toHaveCount($calls);
    })->with([
        'package source or install' => [0, 'gateway-caddy-install', 'gateway.caddy_install_failed', 1],
        'unit ordering' => [1, 'gateway-caddy-ordering', 'gateway.caddy_start_failed', 2],
    ]);
});

function gateway_caddy_installer_processes(?int $failingCall = null): ProcessRunner
{
    return new class($failingCall) implements ProcessRunner
    {
        /** @var list<ProcessInvocation> */
        public array $calls = [];

        public function __construct(
            private readonly ?int $failingCall,
        ) {}

        public function run(ProcessInvocation $invocation): CommandResult
        {
            $index = count($this->calls);
            $this->calls[] = $invocation;

            return $index === $this->failingCall
                ? new CommandResult(1, '', 'failed', 2, false)
                : new CommandResult(0, '', '', 2, false);
        }
    };
}
