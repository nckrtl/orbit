<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\InstanceSettings;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeSettings;
use Orbit\Sdk\Responses\Nodes\WorktreeSettings;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class UpdateNodeSettingsRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::PATCH;

    public function __construct(
        private readonly int $nodeId,
        private readonly bool $hasInstance = false,
        private readonly ?InstanceSettings $instance = null,
        private readonly bool $hasWorktree = false,
        private readonly ?WorktreeSettings $worktree = null,
    ) {}

    public static function fromSettings(int $nodeId, NodeSettings $settings): self
    {
        return new self(
            nodeId: $nodeId,
            hasInstance: true,
            instance: $settings->instance,
            hasWorktree: true,
            worktree: $settings->worktree,
        );
    }

    public function resolveEndpoint(): string
    {
        return "/api/v1/nodes/{$this->nodeId}/settings";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): NodeResponse
    {
        $data = $this->unwrapData($response);
        $requestId = $this->successRequestId($response);

        return NodeResponse::fromGatewayData($data, $requestId);
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        $body = [];

        if ($this->hasInstance) {
            $body['instance'] = $this->instance?->toArray();
        }

        if ($this->hasWorktree) {
            $body['worktree'] = $this->worktree?->toArray();
        }

        return $body;
    }
}
