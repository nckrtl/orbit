<?php

declare(strict_types=1);

use App\Data\Activities\ActivityData;
use App\Data\Annotations\AnnotationData;
use App\Data\Metrics\MetricsAssignmentData;
use App\Data\Metrics\MetricsExporterData;
use App\Data\Metrics\MetricsMutationData;
use App\Domain\Metrics\ExporterDegradationReason;
use App\Domain\Metrics\ExporterSelectionReason;
use App\Domain\Metrics\MetricsPublicationCleanup;

it('publishes data class schemas with the keys those classes produce', function (): void {
    expect(openapi_schema_property_names('Activity'))->toBe(array_keys(new ActivityData(
        id: 1,
        requestId: '9d6b1e5c-4a3f-4a1b-9f0e-6d2c3f5a7b81',
        command: 'node:list',
        callerNodeId: 2,
        targetNodeId: null,
        callerIp: '10.44.0.2',
        status: 'succeeded',
        durationMs: 12,
        exitCode: 0,
        errorCode: null,
        subjectType: 'node',
        subjectId: 7,
        properties: [],
        occurredAt: '2026-09-01T00:00:00+00:00',
    )->toArray()))
        ->and(openapi_schema_property_names('MetricsAssignment'))->toBe(array_keys(new MetricsAssignmentData(
            id: 1,
            nodeId: 7,
            nodeName: 'beast',
            status: 'active',
            failedStep: null,
            errorCode: null,
        )->toArray()))
        ->and(openapi_schema_property_names('MetricsMutation'))->toBe(array_keys(new MetricsMutationData(
            nodeId: 7,
            status: 'active',
            publication: MetricsPublicationCleanup::Cleaned,
        )->toArray()))
        ->and(openapi_schema_property_names('MetricsExporter'))->toBe(array_keys(new MetricsExporterData(
            id: 1,
            name: 'node',
            desired: true,
            actual: 'up',
            reason: ExporterSelectionReason::RoleDefault,
            degradation: ExporterDegradationReason::Unreachable,
        )->toArray()));
});

it('keeps the annotation schema in the camelCase AnnotationData sends', function (): void {
    $source = file_get_contents(new ReflectionClass(AnnotationData::class)->getFileName());
    preg_match_all("/^\\s*'([A-Za-z][A-Za-z0-9_]*)'\\s*=>/m", (string) $source, $matches);
    $properties = openapi_schema_properties('Annotation');

    expect($matches[1])->not->toBeEmpty();

    foreach ($matches[1] as $key) {
        expect($properties)->toHaveKey($key);

        if (preg_match('/[a-z][A-Z]/', $key) === 1) {
            $snake = strtolower((string) preg_replace('/(?<!^)(?=[A-Z])/', '_', $key));

            expect($properties)->not->toHaveKey($snake);
        }
    }
});

/**
 * @return list<string>
 */
function openapi_schema_property_names(string $name): array
{
    return array_keys(openapi_schema_properties($name));
}

/**
 * @return array<string, mixed>
 */
function openapi_schema_properties(string $name): array
{
    $path = dirname(__DIR__, 4).'/docs/openapi.json';
    $decoded = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($decoded)) {
        throw new RuntimeException('OpenAPI document is not an object.');
    }

    $schemas = $decoded['components']['schemas'] ?? null;
    $schema = is_array($schemas) ? ($schemas[$name] ?? null) : null;
    $properties = is_array($schema) ? ($schema['properties'] ?? null) : null;

    if (! is_array($properties)) {
        throw new RuntimeException("OpenAPI schema {$name} has no properties.");
    }

    /** @var array<string, mixed> $properties */
    return $properties;
}
