<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\AppInstances;

use Orbit\Sdk\Responses\Apps\AppIdentityResponse;
use Orbit\Sdk\Responses\Deployments\DeploymentStepResponse;
use Orbit\Sdk\Responses\Nodes\NodeIdentityResponse;
use Orbit\Sdk\Responses\Routes\RouteResponse;
use SensitiveParameter;

final readonly class AppInstanceResponse
{
    public function __construct(
        public int $id,
        public int $appId,
        public int $nodeId,
        public ?AppIdentityResponse $app,
        public ?NodeIdentityResponse $node,
        public string $name,
        public string $environment,
        public string $sourceLayout,
        public string $checkoutPath,
        public ?string $productionUser,
        public ?string $productionHome,
        public ?string $root,
        public ?string $effectiveRoot,
        public ?string $selectedBranch,
        public ?string $branchOverride,
        public bool $migrationRequired,
        public ?string $startingCommit,
        public bool $detached,
        public string $status,
        public ?RouteResponse $route,
        public ?string $domain,
        public ?string $url,
        public ?AppInstanceRemovalProgressResponse $removal,
        public ?AppInstanceTransferProgressResponse $transfer,
        /** @var list<DeploymentStepResponse> */
        public array $deploySteps,
        public string $requestId,
        public ?int $vitePort = null,
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
            appId: is_int($data['app_id'] ?? null) ? $data['app_id'] : 0,
            nodeId: is_int($data['node_id'] ?? null) ? $data['node_id'] : 0,
            app: AppIdentityResponse::tryFromGatewayData($data['app'] ?? null),
            node: NodeIdentityResponse::tryFromGatewayData($data['node'] ?? null),
            vitePort: is_int($data['vite_port'] ?? null) && $data['vite_port'] >= 1024 && $data['vite_port'] <= 65535 ? $data['vite_port'] : null,
            name: is_string($data['name'] ?? null) ? $data['name'] : '',
            environment: is_string($data['environment'] ?? null) ? $data['environment'] : '',
            sourceLayout: is_string($data['source_layout'] ?? null) ? $data['source_layout'] : '',
            checkoutPath: is_string($data['checkout_path'] ?? null) ? $data['checkout_path'] : '',
            productionUser: is_string($data['production_user'] ?? null) ? $data['production_user'] : null,
            productionHome: is_string($data['production_home'] ?? null) ? $data['production_home'] : null,
            root: is_string($data['root'] ?? null) ? $data['root'] : null,
            effectiveRoot: is_string($data['effective_root'] ?? null) ? $data['effective_root'] : null,
            selectedBranch: is_string($data['selected_branch'] ?? null) ? $data['selected_branch'] : null,
            branchOverride: is_string($data['branch_override'] ?? null) ? $data['branch_override'] : null,
            migrationRequired: ($data['migration_required'] ?? null) === true,
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

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'app_id' => $this->appId,
            'node_id' => $this->nodeId,
            'app' => $this->app?->toArray(),
            'node' => $this->node?->toArray(),
            'vite_port' => $this->vitePort,
            'name' => $this->name,
            'environment' => $this->environment,
            'source_layout' => $this->sourceLayout,
            'checkout_path' => $this->checkoutPath,
            'production_user' => $this->productionUser,
            'production_home' => $this->productionHome,
            'root' => $this->root,
            'effective_root' => $this->effectiveRoot,
            'selected_branch' => $this->selectedBranch,
            'branch_override' => $this->branchOverride,
            'migration_required' => $this->migrationRequired,
            'starting_commit' => $this->startingCommit,
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

    private static function removal(#[SensitiveParameter] mixed $value): ?AppInstanceRemovalProgressResponse
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

        return AppInstanceRemovalProgressResponse::fromGatewayData($removal);
    }

    private static function transfer(#[SensitiveParameter] mixed $value): ?AppInstanceTransferProgressResponse
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

        return AppInstanceTransferProgressResponse::fromGatewayData($transfer);
    }
}
