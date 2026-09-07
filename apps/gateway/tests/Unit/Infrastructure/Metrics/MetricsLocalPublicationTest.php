<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Domain\Shared\ResourceOperationException;
use App\Infrastructure\Metrics\MetricsCaddyPublisher;
use App\Infrastructure\Metrics\MetricsCertificatePublisher;
use App\Infrastructure\Metrics\MetricsPublicationReceipt;
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

it('reloads Caddy after swapping in a renewed Metrics certificate', function (): void {
    // The Caddy fragment names fixed paths, so a renewal never changes it and MetricsCaddyPublisher
    // short-circuits without reloading. Caddy reads `tls` files once at load, so unless the
    // certificate publisher reloads, Caddy keeps serving the certificate that just expired.
    $processes = new MetricsLocalPublicationProcessRunner;

    new MetricsCertificatePublisher($processes)->publish(new GatewayCertificatePaths(
        privateKeyPath: '/var/lib/orbit/ca/metrics.key',
        certificatePath: '/var/lib/orbit/ca/metrics.pem',
    ));

    $script = (string) $processes->invocations[0]->input;
    $swap = strpos($script, 'mv -fT -- "$link" "$current"');
    $reload = strpos($script, 'systemctl reload-or-restart caddy');

    expect($script)
        ->toContain('systemctl is-active --quiet caddy')
        ->and($swap)
        ->toBeInt()
        ->and($reload)
        ->toBeInt()
        ->toBeGreaterThan($swap)
        ->toBeLessThan(strrpos($script, "printf 'orbit-metrics-publication:"));
});

it('returns replacement receipts without copying certificate key content', function (): void {
    $certificateTarget = '/etc/caddy/orbit-metrics-cert-versions/previous';
    $caddyConfiguration = "# Managed by Orbit: metrics\nmetrics.orbit {\n    respond old\n}\n";
    $processes = new MetricsLocalPublicationProcessRunner([
        metrics_local_publication_receipt($certificateTarget),
        metrics_local_publication_receipt($caddyConfiguration),
    ]);

    $certificateReceipt = new MetricsCertificatePublisher($processes)->publish(new GatewayCertificatePaths(
        privateKeyPath: '/var/lib/orbit/ca/new-private-key',
        certificatePath: '/var/lib/orbit/ca/new-certificate',
    ));
    $caddyReceipt = new MetricsCaddyPublisher($processes)->publish("# Managed by Orbit: metrics\nnew\n");

    expect($certificateReceipt->previousPublication())->toBe($certificateTarget);
    expect($caddyReceipt->previousPublication())->toBe($caddyConfiguration);
    expect($certificateReceipt->previousPublication())
        ->not
        ->toContain('new-private-key', 'new-certificate');
});

it('restores certificate pointers and Caddy fragments through locked component operations', function (): void {
    $previousTarget = '/etc/caddy/orbit-metrics-cert-versions/previous';
    $previousConfiguration = "# Managed by Orbit: metrics\nmetrics.orbit {\n    respond old\n}\n";
    $processes = new MetricsLocalPublicationProcessRunner;

    new MetricsCertificatePublisher($processes)->restore(MetricsPublicationReceipt::replaced($previousTarget));
    new MetricsCaddyPublisher($processes)->restore(MetricsPublicationReceipt::replaced($previousConfiguration));

    expect($processes->invocations)->toHaveCount(2);
    expect($processes->invocations[0]->arguments)->toContain('replaced', $previousTarget);
    expect($processes->invocations[0]->input)
        ->toContain('published_target=$(readlink "$current")')
        ->toContain('ln -s -- "$published_target" "$link"')
        ->toContain('systemctl reload-or-restart caddy || true');
    expect($processes->invocations[1]->input)
        ->toContain(base64_encode($previousConfiguration))
        ->toContain('for fragment in "$current_fragments"/*.caddy')
        ->toContain('cp --preserve=mode,ownership -- "$fragment" "$candidate/fragments/"');
});

it('removes a newly created component publication during restoration', function (): void {
    $processes = new MetricsLocalPublicationProcessRunner;

    new MetricsCaddyPublisher($processes)->restore(MetricsPublicationReceipt::created());
    new MetricsCertificatePublisher($processes)->restore(MetricsPublicationReceipt::created());

    expect($processes->invocations)->toHaveCount(2);
    expect($processes->invocations[0]->arguments)->toContain('metrics.caddy');
    expect($processes->invocations[1]->arguments)->toContain('created', '');
});

it('recovers the previous certificate pointer when publication reload fails', function (): void {
    $processes = new MetricsLocalPublicationProcessRunner;

    new MetricsCertificatePublisher($processes)->publish(new GatewayCertificatePaths(
        privateKeyPath: '/var/lib/orbit/ca/metrics.key',
        certificatePath: '/var/lib/orbit/ca/metrics.pem',
    ));

    expect($processes->invocations[0]->input)
        ->toContain('if ! systemctl reload-or-restart caddy; then')
        ->toContain('ln -s -- "$previous_target" "$link"')
        ->toContain('rm -f -- "$current"')
        ->toContain('rm -rf -- "$directory"');
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

        return (
            array_shift($this->results) ?? new CommandResult(
                0,
                "orbit-metrics-publication:created\n",
                '',
                1,
                false,
            )
        );
    }
}

function metrics_local_publication_receipt(string $previous): CommandResult
{
    return new CommandResult(
        0,
        'orbit-metrics-publication:replaced:'.base64_encode($previous)."\n",
        '',
        1,
        false,
    );
}
