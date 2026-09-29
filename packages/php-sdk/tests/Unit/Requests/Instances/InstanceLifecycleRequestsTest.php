<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Deployments\ListInstanceReleasesRequest;
use Orbit\Sdk\Requests\Instances\CloneInstanceRequest;
use Orbit\Sdk\Requests\Instances\CreateInstanceRequest;
use Orbit\Sdk\Requests\Instances\DestroyInstanceRequest;
use Saloon\Enums\Method;

describe('Instance lifecycle requests', function (): void {
    it('uses create, destroy, and list verbs on the existing methods and paths', function (): void {
        $create = new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'main');
        $destroy = new DestroyInstanceRequest(7, force: true);
        $list = new ListInstanceReleasesRequest(17);

        expect($create->getMethod())
            ->toBe(Method::POST)
            ->and($create->resolveEndpoint())
            ->toBe('/api/v1/instances')
            ->and($destroy->getMethod())
            ->toBe(Method::DELETE)
            ->and($destroy->resolveEndpoint())
            ->toBe('/api/v1/instances/7')
            ->and($destroy->body()->all())
            ->toBe(['force' => true])
            ->and($list->getMethod())
            ->toBe(Method::GET)
            ->and($list->resolveEndpoint())
            ->toBe('/api/v1/instances/17/releases');
    });

    it('does not promise direct production creation on the ordinary create request', function (): void {
        $create = new CreateInstanceRequest(projectId: 3, nodeId: 4, name: 'main');
        $clone = new CloneInstanceRequest(11, 7, 'production', 'shop.com');

        expect($create->body()->all())
            ->toBe(['project_id' => 3, 'node_id' => 4, 'name' => 'main'])
            ->and($create->body()->all())
            ->not->toHaveKey('environment')
            ->and($create->resolveEndpoint())
            ->toBe('/api/v1/instances')
            ->and($clone->resolveEndpoint())
            ->toBe('/api/v1/instances/11/clone')
            ->and($clone->body()->all())
            ->toBe([
                'node_id' => 7,
                'name' => 'production',
                'preview_name' => 'shop.com',
            ]);
    });

    it('does not keep the replaced destroy class name', function (): void {
        expect(class_exists('Orbit\\Sdk\\Requests\\Instances\\RemoveInstanceRequest'))->toBeFalse();
    });
});
