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

    it('keeps the documented MCP selector aligned with the generated tool schema', function (): void {
        $root = dirname(base_path(), 2);
        $catalogue = json_decode((string) file_get_contents($root.'/apps/gateway/resources/mcp/tools.json'), true, flags: JSON_THROW_ON_ERROR);
        $tool = collect($catalogue['tools'])->firstWhere('name', 'instance-rename');
        $schema = $tool['input_schema'];

        expect($tool['path_inputs'])->toBe(['instance'])
            ->and($schema['required'])->toBe(['instance'])
            ->and(array_keys($schema['properties']))->toBe(['instance', 'branch', 'domain'])
            ->and($schema['additionalProperties'])->toBeFalse();

        $selector = $tool['path_inputs'][0];
        $adr = (string) file_get_contents($root.'/docs/decisions/0193-record-development-branch-renames.md');
        $reference = (string) file_get_contents($root.'/docs/reference/mcp.mdx');

        expect($adr)->toContain("- MCP: `instance-rename`, with `{$selector}` and optional `branch` and `domain`.")
            ->and($reference)->toContain("Send `{$selector}` and at least one of `branch` or `domain`.");
    });
});
