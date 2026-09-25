<?php

declare(strict_types=1);

use App\Domain\Certificates\GatewayCertificatePaths;
use App\Infrastructure\Metrics\MetricsCertificatePublisher;
use App\Infrastructure\Metrics\MetricsPublicationReceipt;
use App\Infrastructure\Processes\CommandResult;
use App\Infrastructure\Processes\ProcessInvocation;
use App\Infrastructure\Processes\ProcessRunner;

it('publishes the Metrics certificate through a protected versioned local operation', function (): void {
    $processes = new MetricsLocalPublicationProcessRunner;

    new MetricsCertificatePublisher($processes)->publish(new GatewayCertificatePaths(
        privateKeyPath: '/var/lib/orbit/ca/metrics.key',
        certificatePath: '/var/lib/orbit/ca/metrics.pem',
    ));

    expect($processes->invocations)
        ->toHaveCount(1)
        ->and($processes->invocations[0]->arguments)
        ->toContain('/var/lib/orbit/ca/metrics.key', '/var/lib/orbit/ca/metrics.pem')
        ->and($processes->invocations[0]->input)
        ->toContain('orbit-metrics-cert-versions')
        ->toContain('.orbit-owner')
        ->toContain('mv -fT');
});

it('reloads Caddy after swapping in a renewed Metrics certificate', function (): void {
    // The Metrics site names fixed paths, so a renewal never changes the Gateway's render and its
    // build does not reload. Caddy reads `tls` files once at load, so unless the certificate
    // publisher reloads, Caddy keeps serving the certificate that just expired.
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
    $processes = new MetricsLocalPublicationProcessRunner([
        metrics_local_publication_receipt($certificateTarget),
    ]);

    $certificateReceipt = new MetricsCertificatePublisher($processes)->publish(new GatewayCertificatePaths(
        privateKeyPath: '/var/lib/orbit/ca/new-private-key',
        certificatePath: '/var/lib/orbit/ca/new-certificate',
    ));

    expect($certificateReceipt->previousPublication())->toBe($certificateTarget);
    expect($certificateReceipt->previousPublication())
        ->not
        ->toContain('new-private-key', 'new-certificate');
});

it('restores certificate pointers through a locked operation', function (): void {
    $previousTarget = '/etc/caddy/orbit-metrics-cert-versions/previous';
    $processes = new MetricsLocalPublicationProcessRunner;

    new MetricsCertificatePublisher($processes)->restore(MetricsPublicationReceipt::replaced($previousTarget));

    expect($processes->invocations)->toHaveCount(1);
    expect($processes->invocations[0]->arguments)->toContain('replaced', $previousTarget);
    expect($processes->invocations[0]->input)
        ->toContain('published_target=$(readlink "$current")')
        ->toContain('ln -s -- "$published_target" "$link"')
        ->toContain('systemctl reload-or-restart caddy || true');
});

it('removes a newly created certificate publication during restoration', function (): void {
    $processes = new MetricsLocalPublicationProcessRunner;

    new MetricsCertificatePublisher($processes)->restore(MetricsPublicationReceipt::created());

    expect($processes->invocations)->toHaveCount(1);
    expect($processes->invocations[0]->arguments)->toContain('created', '');
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

it('removes only Metrics-owned certificate state', function (): void {
    $processes = new MetricsLocalPublicationProcessRunner;

    new MetricsCertificatePublisher($processes)->remove();

    expect($processes->invocations)
        ->toHaveCount(1)
        ->and($processes->invocations[0]->input)
        ->toContain('orbit-metrics-cert-current')
        ->toContain('orbit-metrics-cert-versions')
        ->toContain('test "$(cat -- "$owner")" = metrics-certificate');
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

        return
            array_shift($this->results) ?? new CommandResult(
                0,
                "orbit-metrics-publication:created\n",
                '',
                1,
                false,
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
