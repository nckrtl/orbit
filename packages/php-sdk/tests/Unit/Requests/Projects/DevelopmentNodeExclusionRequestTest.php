<?php

declare(strict_types=1);

use Orbit\Sdk\Requests\Nodes\AddNodeExcludedProjectRequest;
use Orbit\Sdk\Requests\Nodes\ListNodeExcludedProjectsRequest;
use Orbit\Sdk\Requests\Nodes\RemoveNodeExcludedProjectRequest;
use Orbit\Sdk\Requests\Projects\AddProjectExcludedNodeRequest;
use Orbit\Sdk\Requests\Projects\ListProjectExcludedNodesRequest;
use Orbit\Sdk\Requests\Projects\RemoveProjectExcludedNodeRequest;
use Orbit\Sdk\Responses\Projects\DevelopmentNodeExclusionResponse;
use Saloon\Enums\Method;

describe('development node exclusion transport', function (): void {
    it('addresses the same pair from the project and from the node', function (): void {
        expect((new AddProjectExcludedNodeRequest(4, 9))->resolveEndpoint())
            ->toBe('/api/v1/projects/4/excluded-nodes/9')
            ->and((new AddProjectExcludedNodeRequest(4, 9))->getMethod())->toBe(Method::POST)
            ->and((new RemoveProjectExcludedNodeRequest(4, 9))->getMethod())->toBe(Method::DELETE)
            ->and((new ListProjectExcludedNodesRequest(4))->resolveEndpoint())->toBe('/api/v1/projects/4/excluded-nodes')
            ->and((new AddNodeExcludedProjectRequest(9, 4))->resolveEndpoint())->toBe('/api/v1/nodes/9/excluded-projects/4')
            ->and((new RemoveNodeExcludedProjectRequest(9, 4))->getMethod())->toBe(Method::DELETE)
            ->and((new ListNodeExcludedProjectsRequest(9))->resolveEndpoint())->toBe('/api/v1/nodes/9/excluded-projects');
    });

    it('reads the exclusion payload and treats a missing already_exists flag as false', function (): void {
        $created = DevelopmentNodeExclusionResponse::fromGatewayData([
            'project_id' => 4,
            'project_slug' => 'orbit',
            'node_id' => 9,
            'node_name' => 'sabre',
            'development_instance_count' => 2,
            'already_exists' => false,
        ], 'req-1');
        $listed = DevelopmentNodeExclusionResponse::fromGatewayData([
            'project_id' => 4,
            'project_slug' => 'orbit',
            'node_id' => 9,
            'node_name' => 'sabre',
            'development_instance_count' => 2,
        ], 'req-1');

        expect($created->alreadyExists)->toBeFalse()
            ->and($created->developmentInstanceCount)->toBe(2)
            ->and($listed->alreadyExists)->toBeFalse()
            ->and($listed->toArray()['node_name'])->toBe('sabre');
    });
});
