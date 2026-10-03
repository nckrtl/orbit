<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Instances;

use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;
use Orbit\Sdk\Responses\Nodes\NodeIdentityResponse;
use Orbit\Sdk\Responses\Projects\ProjectIdentityResponse;
use Orbit\Sdk\Responses\Routes\RouteResponse;
use SensitiveParameter;

/**
 * @phpstan-type InstanceRecordFields array{
 *     annotator_port: int|null,
 *     annotator_url: string|null,
 *     id: int,
 *     project_id: int,
 *     node_id: int,
 *     project: array{id: int, name: string, slug: string}|null,
 *     node: array{id: int, name: string}|null,
 *     vite_port: int|null,
 *     name: string,
 *     source_layout: string,
 *     checkout_path: string,
 *     production_user: string|null,
 *     production_home: string|null,
 *     root: string|null,
 *     effective_root: string|null,
 *     selected_branch: string|null,
 *     branch_override: string|null,
 *     starting_commit: string|null,
 *     seed_path: string|null,
 *     seed_commit: string|null,
 *     detached: bool,
 *     status: string,
 *     route: array<string, int|string|null|array{id: int, instance_id: int, position: int}|list<array{id: int, instance_id: int, position: int}>>|null,
 *     domain: string|null,
 *     url: string|null,
 *     removal: array<string, bool|int|string|null>|null,
 *     transfer: array<string, mixed>|null,
 *     deploy_steps: list<array{name: string, phase: string, command: string, timeout_seconds: int}>
 * }
 * @phpstan-type InstanceRecord array{
 *     annotator_port: int|null,
 *     annotator_url: string|null,
 *     id: int,
 *     project_id: int,
 *     node_id: int,
 *     project: array{id: int, name: string, slug: string}|null,
 *     node: array{id: int, name: string}|null,
 *     vite_port: int|null,
 *     name: string,
 *     source_layout: string,
 *     checkout_path: string,
 *     production_user: string|null,
 *     production_home: string|null,
 *     root: string|null,
 *     effective_root: string|null,
 *     selected_branch: string|null,
 *     branch_override: string|null,
 *     starting_commit: string|null,
 *     seed_path: string|null,
 *     seed_commit: string|null,
 *     detached: bool,
 *     status: string,
 *     route: array<string, int|string|null|array{id: int, instance_id: int, position: int}|list<array{id: int, instance_id: int, position: int}>>|null,
 *     domain: string|null,
 *     url: string|null,
 *     removal: array<string, bool|int|string|null>|null,
 *     transfer: array<string, mixed>|null,
 *     deploy_steps: list<array{name: string, phase: string, command: string, timeout_seconds: int}>,
 *     request_id: string
 * }
 */
final readonly class InstanceResponse
{
    public function __construct(
        public int $id,
        public int $projectId,
        public int $nodeId,
        public ?ProjectIdentityResponse $project,
        public ?NodeIdentityResponse $node,
        public string $name,
        public string $sourceLayout,
        public string $checkoutPath,
        public ?string $productionUser,
        public ?string $productionHome,
        public ?string $root,
        public ?string $effectiveRoot,
        public ?string $selectedBranch,
        public ?string $branchOverride,
        public ?string $startingCommit,
        public bool $detached,
        public string $status,
        public ?RouteResponse $route,
        public ?string $domain,
        public ?string $url,
        public ?InstanceRemovalProgressResponse $removal,
        public ?InstanceTransferProgressResponse $transfer,
        /** @var list<DeploymentStepResponse> */
        public array $deploySteps,
        public string $requestId,
        public ?int $vitePort = null,
        public ?string $seedPath = null,
        public ?string $seedCommit = null,
        public ?int $annotatorPort = null,
        public ?string $annotatorUrl = null,
    ) {}

    /** @param array<string, mixed> $data */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        return new self(
            id: is_int($data['id'] ?? null) ? $data['id'] : 0,
            projectId: is_int($data['project_id'] ?? null) ? $data['project_id'] : 0,
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            project: ProjectIdentityResponse::tryFromGatewayData($data['project'] ?? null),
            node: NodeIdentityResponse::tryFromGatewayData($data['node'] ?? null),
            vitePort: is_int($data['vite_port'] ?? null) && $data['vite_port'] >= 1024 && $data['vite_port'] <= 65535 ? $data['vite_port'] : null,
            seedPath: is_string($data['seed_path'] ?? null) ? $data['seed_path'] : null,
            seedCommit: is_string($data['seed_commit'] ?? null) ? $data['seed_commit'] : null,
            annotatorPort: is_int($data['annotator_port'] ?? null) && $data['annotator_port'] >= 1024 && $data['annotator_port'] <= 65535 ? $data['annotator_port'] : null,
            annotatorUrl: is_string($data['annotator_url'] ?? null) ? $data['annotator_url'] : null,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            sourceLayout: is_string($data['source_layout'] ?? null) ? $data['source_layout'] : '',
            checkoutPath: is_string($data['checkout_path'] ?? null) ? $data['checkout_path'] : '',
            productionUser: is_string($data['production_user'] ?? null) ? $data['production_user'] : null,
            productionHome: is_string($data['production_home'] ?? null) ? $data['production_home'] : null,
            root: is_string($data['root'] ?? null) ? $data['root'] : null,
            effectiveRoot: is_string($data['effective_root'] ?? null) ? $data['effective_root'] : null,
            selectedBranch: is_string($data['selected_branch'] ?? null) ? $data['selected_branch'] : null,
            branchOverride: is_string($data['branch_override'] ?? null) ? $data['branch_override'] : null,
            startingCommit: is_string($data['starting_commit'] ?? null) ? $data['starting_commit'] : null,
            detached: ($data['detached'] ?? null) === true,
            status: is_string($data['status'] ?? null) ? $data['status'] : '',
            route: self::route($data['route'] ?? null, $requestId),
            domain: is_string($data['domain'] ?? null) ? $data['domain'] : null,
            url: is_string($data['url'] ?? null) ? $data['url'] : null,
            removal: self::removal($data['removal'] ?? null),
            transfer: self::transfer($data['transfer'] ?? null),
            deploySteps: self::parseDeploySteps($data['deploy_steps'] ?? []),
            requestId: $requestId,
        );
    }

    /** @return InstanceRecord */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'project_id' => $this->projectId,
            'node_id' => $this->nodeId,
            'project' => $this->project?->toArray(),
            'node' => $this->node?->toArray(),
            'vite_port' => $this->vitePort,
            'annotator_port' => $this->annotatorPort,
            'annotator_url' => $this->annotatorUrl,
            'name' => $this->name,
            'source_layout' => $this->sourceLayout,
            'checkout_path' => $this->checkoutPath,
            'production_user' => $this->productionUser,
            'production_home' => $this->productionHome,
            'root' => $this->root,
            'effective_root' => $this->effectiveRoot,
            'selected_branch' => $this->selectedBranch,
            'branch_override' => $this->branchOverride,
            'starting_commit' => $this->startingCommit,
            'seed_path' => $this->seedPath,
            'seed_commit' => $this->seedCommit,
            'detached' => $this->detached,
            'status' => $this->status,
            'route' => $this->route?->toArray(),
            'domain' => $this->domain,
            'url' => $this->url,
            'removal' => $this->removal?->toArray(),
            'transfer' => $this->transfer?->toArray(),
            'deploy_steps' => array_map(
                static fn (DeploymentStepResponse $step): array => $step->toArray(),
                $this->deploySteps,
            ),
            'request_id' => $this->requestId,
        ];
    }

    private static function route(#[SensitiveParameter] mixed $value, string $requestId): ?RouteResponse
    {
        if (! is_array($value)) {
            return null;
        }

        $route = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $route[$key] = $item;
        }

        return RouteResponse::fromGatewayData($route, $requestId);
    }

    /** @return list<DeploymentStepResponse> */
    private static function parseDeploySteps(#[SensitiveParameter] mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            return [];
        }

        $steps = [];

        foreach ($value as $step) {
            if (
                ! is_array($step)
                || ! is_string($step['name'] ?? null)
                || ! is_string($step['phase'] ?? null)
                || ! is_string($step['command'] ?? null)
                || ! is_int($step['timeout_seconds'] ?? null)
            ) {
                continue;
            }

            $steps[] = new DeploymentStepResponse(
                $step['name'],
                $step['phase'],
                $step['command'],
                $step['timeout_seconds'],
            );
        }

        return $steps;
    }

    private static function removal(#[SensitiveParameter] mixed $value): ?InstanceRemovalProgressResponse
    {
        if (! is_array($value)) {
            return null;
        }

        $removal = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $removal[$key] = $item;
        }

        return InstanceRemovalProgressResponse::fromGatewayData($removal);
    }

    private static function transfer(#[SensitiveParameter] mixed $value): ?InstanceTransferProgressResponse
    {
        if (! is_array($value)) {
            return null;
        }

        $transfer = [];

        foreach ($value as $key => $item) {
            if (! is_string($key)) {
                continue;
            }

            $transfer[$key] = $item;
        }

        return InstanceTransferProgressResponse::fromGatewayData($transfer);
    }
}
