<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Nodes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Nodes\NodeResponse;
use Orbit\Sdk\Responses\Nodes\NodeSettings;
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
        private readonly ?string $instancePath = null,
        private readonly bool $hasWorktree = false,
        private readonly ?string $worktreePath = null,
    ) {}

    public static function fromSettings(int $nodeId, NodeSettings $settings): self
    {
        return new self(
            nodeId: $nodeId,
            hasInstance: true,
            instancePath: $settings->instancePath,
            hasWorktree: true,
            worktreePath: $settings->worktreePath,
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
            $body['instance'] = $this->instancePath === null ? null : ['path' => $this->instancePath];
        }

        if ($this->hasWorktree) {
            $body['worktree'] = $this->worktreePath === null ? null : ['path' => $this->worktreePath];
        }

        return $body;
    }
}
