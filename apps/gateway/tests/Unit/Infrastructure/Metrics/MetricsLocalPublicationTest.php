<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsCaddyPublisher;
use App\Infrastructure\Metrics\MetricsCertificatePublisher;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

it('publishes the Metrics certificate and Caddy fragment through protected versioned local operations', function (): void {
    $processes = new MetricsLocalPublicationProcessRunner;
    $certificate = new MetricsCertificatePublisher($processes);
    $caddy = new MetricsCaddyPublisher($processes);

    $certificate->publish(new GatewayCertificatePaths(
        privateKeyPath: '/var/lib/orbit/ca/metrics.key',
        certificatePath: '/var/lib/orbit/ca/metrics.pem',
    ));
    $caddy->publish("metrics.orbit {\n}\n");

    expect($processes->invocations)
        ->toHaveCount(2)
        ->and($processes->invocations[0]->arguments)
        ->toContain('/var/lib/orbit/ca/metrics.key', '/var/lib/orbit/ca/metrics.pem')
        ->and($processes->invocations[0]->input)
        ->toContain('orbit-metrics-cert-versions')
        ->toContain('.orbit-owner')
        ->toContain('mv -fT')
        ->and($processes->invocations[1]->arguments)
        ->toContain('metrics.caddy')
        ->and($processes->invocations[1]->input)
        ->toContain('grep -Fqx -- "# Managed by Orbit: metrics"')
        ->toContain('caddy validate')
        ->toContain('systemctl reload-or-restart')
        ->toContain('rollback');
});

it('removes only Metrics-owned local publication state', function (): void {
    $processes = new MetricsLocalPublicationProcessRunner;

    new MetricsCaddyPublisher($processes)->remove();
    new MetricsCertificatePublisher($processes)->remove();

    expect($processes->invocations)
        ->toHaveCount(2)
        ->and($processes->invocations[0]->arguments)
        ->toContain('metrics.caddy')
        ->and($processes->invocations[0]->input)
        ->not
        ->toContain('\\"')
        ->and($processes->invocations[1]->input)
        ->toContain('orbit-metrics-cert-current')
        ->toContain('orbit-metrics-cert-versions')
        ->toContain('test "$(cat -- "$owner")" = metrics-certificate');
});

it('returns a stable error when local Caddy activation fails', function (): void {
    $processes = new MetricsLocalPublicationProcessRunner([
        new CommandResult(1, '', 'secret remote detail', 1, false),
    ]);

    expect(fn () => new MetricsCaddyPublisher($processes)->publish("metrics.orbit {\n}\n"))
        ->toThrow(ResourceOperationException::class, 'Metrics Caddy publication did not complete.');
});

final class MetricsLocalPublicationProcessRunner implements ProcessRunner
{
    /** @var list<ProcessInvocation> */
    public array $invocations = [];

    /** @param list<CommandResult> $results */
    public function __construct(
        private array $results = [],
    ) {}

    public function run(ProcessInvocation $invocation): CommandResult
    {
        $this->invocations[] = $invocation;

        return array_shift($this->results) ?? new CommandResult(0, '', '', 1, false);
    }
}
