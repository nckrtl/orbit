<?php

declare(strict_types=1);

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\DestroyInstanceRequest;
use Orbit\Sdk\Requests\Instances\ListInstancesRequest;
use Orbit\Sdk\Requests\Instances\ShowInstanceRequest;

describe('retired workspace requests', function (): void {
    it('keeps no Workspace request or response class', function (): void {
        $root = dirname(__DIR__, levels: 4);
        $retiredPaths = [
            'src/Requests/Workspaces/CreateWorkspaceRequest.php',
            'src/Requests/Workspaces/ListWorkspacesRequest.php',
            'src/Responses/Workspaces/WorkspaceResponse.php',
            'src/Responses/Workspaces/WorkspacesResponse.php',
        ];

        foreach ($retiredPaths as $relative) {
            $contents = (string) file_get_contents("{$root}/{$relative}");

            expect($contents)
                ->not
                ->toMatch('/\b(?:class|interface|trait|enum)\s+[A-Za-z_]/');
        }

        expect(file_exists("{$root}/src/Requests/Workspaces/RemoveWorkspaceRequest.php"))
            ->toBeFalse()
            ->and(file_exists("{$root}/src/Requests/Workspaces/ShowWorkspaceRequest.php"))
            ->toBeFalse()
            ->and(file_exists("{$root}/src/Requests/Workspaces/UpdateWorkspacePhpRequest.php"))
            ->toBeFalse();

        $requestDirectory = "{$root}/src/Requests";
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($requestDirectory, FilesystemIterator::SKIP_DOTS),
        );

        foreach ($files as $file) {
            if (! $file instanceof SplFileInfo || $file->getExtension() !== 'php') {
                continue;
            }

            $contents = (string) file_get_contents($file->getPathname());

            if (preg_match('/\b(?:class|interface|trait|enum)\s+[A-Za-z_]/', $contents) !== 1) {
                continue;
            }

            $relative = str_replace(
                search: $requestDirectory.'/',
                replace: '',
                subject: $file->getPathname(),
            );
            $class = 'Orbit\\Sdk\\Requests\\'.str_replace(['/', '.php'], ['\\', ''], $relative);

            expect($class)
                ->not
                ->toStartWith('Orbit\\Sdk\\Requests\\Workspaces\\');

            if (class_exists($class)) {
                $reflection = new ReflectionClass($class);

                expect($class)
                    ->not
                    ->toStartWith('Orbit\\Sdk\\Requests\\Workspaces\\')
                    ->and($reflection->isSubclassOf(GatewayRequest::class) && str_starts_with($class, 'Orbit\\Sdk\\Requests\\Workspaces\\'))
                    ->toBeFalse();
            }
        }
    });

    it('keeps supported Instance request verbs on the concise instance routes', function (): void {
        expect((new ListInstancesRequest)->resolveEndpoint())
            ->toBe('/api/v1/instances')
            ->and((new ShowInstanceRequest(7))->resolveEndpoint())
            ->toBe('/api/v1/instances/7')
            ->and((new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'default'))->resolveEndpoint())
            ->toBe('/api/v1/instances')
            ->and((new DestroyInstanceRequest(7))->resolveEndpoint())
            ->toBe('/api/v1/instances/7');
    });
});
