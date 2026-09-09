<?php

declare(strict_types=1);

use App\Infrastructure\Metrics\MetricsPublicationReceipt;

it('represents unchanged and created publications without a previous value', function (): void {
    $unchanged = MetricsPublicationReceipt::fromProcessOutput("service output\norbit-metrics-publication:unchanged\n");
    $created = MetricsPublicationReceipt::fromProcessOutput("orbit-metrics-publication:created\n");

    expect($unchanged->isUnchanged())->toBeTrue();
    expect($created->isUnchanged())->toBeFalse();
    expect($created->wasCreated())->toBeTrue();
});

it('decodes the exact previous publication for replacement receipts', function (): void {
    $previous = "# Managed by Orbit: metrics\nmetrics.orbit {\n}\n";

    $receipt = MetricsPublicationReceipt::fromProcessOutput(
        "validation output\norbit-metrics-publication:replaced:".base64_encode($previous)."\n",
    );

    expect($receipt->isUnchanged())->toBeFalse();
    expect($receipt->wasCreated())->toBeFalse();
    expect($receipt->previousPublication())->toBe($previous);
});

it('rejects missing or malformed process receipts', function (string $output): void {
    expect(fn (): MetricsPublicationReceipt => MetricsPublicationReceipt::fromProcessOutput($output))
        ->toThrow(
            InvalidArgumentException::class,
            'Metrics publication output did not contain a valid change receipt.',
        );
})->with([
    'missing marker' => 'changed',
    'unknown change' => 'orbit-metrics-publication:deleted',
    'invalid replacement encoding' => 'orbit-metrics-publication:replaced:***',
    'empty replacement' => 'orbit-metrics-publication:replaced:',
]);
