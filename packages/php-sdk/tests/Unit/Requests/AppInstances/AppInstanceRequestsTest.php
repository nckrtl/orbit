<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\AppInstances\CreateAppInstanceRequest;
use Orbit\Sdk\Requests\AppInstances\DestroyAppInstanceRequest;
use Orbit\Sdk\Requests\Deployments\ListAppInstanceReleasesRequest;
use Saloon\Enums\Method;

describe('AppInstance lifecycle requests', function (): void {
    it('uses create, destroy, and list verbs on the existing methods and paths', function (): void {
        $create = new CreateAppInstanceRequest(appId: 3, nodeId: 4, name: 'main');
        $destroy = new DestroyAppInstanceRequest(7, force: true);
        $list = new ListAppInstanceReleasesRequest(17);

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

    it('does not keep the replaced destroy class name', function (): void {
        expect(class_exists('Orbit\\Sdk\\Requests\\AppInstances\\RemoveAppInstanceRequest'))->toBeFalse();
    });
});
