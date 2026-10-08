<?php

declare(strict_types=1);

describe('generated Project Document content responses', function (): void {
    it('includes unconfigured storage in the read and download OpenAPI and web contracts', function (string $operation, string $suffix): void {
        $root = dirname(base_path(), 2);
        $spec = json_decode((string) file_get_contents($root.'/docs/openapi.json'), true, flags: JSON_THROW_ON_ERROR);
        $responses = $spec['paths']['/api/v1/projects/{project}/documents/{entry}/'.$suffix]['get']['responses'];
        expect($responses['409']['description'])->toContain('project_documents.storage_not_configured');
        expect($responses['409']['content']['application/json']['schema']['$ref'])->toBe('#/components/schemas/Error');
        $types = (string) file_get_contents($root.'/apps/web/src/api/schema.d.ts');
        $body = explode('    "'.$operation.'": {', $types, 2)[1];
        $body = preg_split('/^    ["a-zA-Z]/m', $body, 2)[0];
        expect($body)->toContain('409:', 'project_documents.storage_not_configured', 'components["schemas"]["Error"]');
    })->with([
        ['project-document-read', 'content'],
        ['project-document-download', 'download'],
    ]);
});
