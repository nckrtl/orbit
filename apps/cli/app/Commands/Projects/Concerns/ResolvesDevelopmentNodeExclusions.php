<?php

declare(strict_types=1);

namespace App\Commands\Projects\Concerns;

use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\ListNodesRequest;
use Orbit\Sdk\Requests\Projects\ListProjectsRequest;
use Orbit\Sdk\Responses\Nodes\NodesResponse;
use Orbit\Sdk\Responses\Projects\ProjectsResponse;

trait ResolvesDevelopmentNodeExclusions
{
    protected function validExclusionProjectInput(mixed $value): bool
    {
        if ($value === null && $this->consoleMode()->mayPrompt) {
            return true;
        }

        if (! is_int(filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]))) {
            $this->renderGatewayFailure('project.id_invalid', 'Project ID must be a positive integer.');

            return false;
        }

        return true;
    }

    protected function resolveExclusionProjectId(GatewayConnector $connector, mixed $value): ?int
    {
        if ($value !== null) {
            $projectId = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

            if (! is_int($projectId)) {
                $this->renderGatewayFailure('project.id_invalid', 'Project ID must be a positive integer.');

                return null;
            }

            return $projectId;
        }

        $projects = $this->sendWithProgress(
            $connector,
            new ListProjectsRequest,
            ProjectsResponse::class,
            ['List Projects', 'Loading Projects', 'Loaded Projects'],
            dismiss: true,
        );

        if (! $projects instanceof ProjectsResponse) {
            return null;
        }

        $rows = [];
        foreach ($projects->projects as $project) {
            $rows[$project->id] = [(string) $project->id, $project->name, $project->slug];
        }

        return (int) $this->commandPrompts()->selectEntity('Project', ['ID', 'Name', 'Slug'], $rows);
    }

    protected function resolveExclusionNodeId(GatewayConnector $connector, mixed $value): ?int
    {
        if ($value !== null || ! $this->consoleMode()->mayPrompt) {
            return $this->resolveNodeId($connector, $value);
        }

        $nodes = $this->sendWithProgress(
            $connector,
            new ListNodesRequest,
            NodesResponse::class,
            ['List Nodes', 'Loading Nodes', 'Loaded Nodes'],
            dismiss: true,
        );

        if (! $nodes instanceof NodesResponse) {
            return null;
        }

        $rows = [];
        foreach ($nodes->nodes as $node) {
            $rows[$node->id] = [(string) $node->id, $node->name, implode(', ', $node->roles)];
        }

        return (int) $this->commandPrompts()->selectEntity('Node', ['ID', 'Name', 'Roles'], $rows);
    }
}
