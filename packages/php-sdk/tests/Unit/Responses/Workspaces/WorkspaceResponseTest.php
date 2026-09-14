<?php

declare(strict_types=1);

it('keeps no Workspace response class on the retired fingerprint path', function (): void {
    $path = dirname(__DIR__, levels: 4).'/src/Responses/Workspaces/WorkspaceResponse.php';

    expect((string) file_get_contents($path))
        ->not
        ->toMatch('/\b(?:class|interface|trait|enum)\s+[A-Za-z_]/')
        ->and(class_exists('Orbit\\Sdk\\Responses\\Workspaces\\WorkspaceResponse'))
        ->toBeFalse();
});
