<?php

declare(strict_types=1);

describe('generated Instance rename contract', function (): void {
    it('requires at least one nonempty branch or domain and returns an Instance', function (): void {
        $spec = json_decode((string) file_get_contents(dirname(base_path(), 2).'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        $operation = $spec['paths']['/api/v1/instances/{instance}/rename']['post'];
        $body = $operation['requestBody'];
        $schema = $body['content']['application/json']['schema'];

        expect($operation['operationId'])->toBe('instance-rename')
            ->and($body['required'])->toBeTrue()
            ->and($schema['minProperties'])->toBe(1)
            ->and($schema['additionalProperties'])->toBeFalse()
            ->and(array_keys($schema['properties']))->toBe(['branch', 'domain']);
        foreach ($schema['properties'] as $property) {
            expect($property['type'])->toBe('string')->and($property['minLength'])->toBe(1);
        }
        expect($operation['responses']['200']['content']['application/json']['schema']['properties']['data']['$ref'])
            ->toBe('#/components/schemas/Instance');
        $sample = collect($operation['x-codeSamples'])->firstWhere('lang', 'php');
        expect($sample['source'])->toContain("branch: 't3code/login-redirect'");
    });
});
