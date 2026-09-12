<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\AppInstances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\AppInstances\AppInstanceResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class CloneAppInstanceRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $candidateId,
        private readonly int $nodeId,
        private readonly string $name,
        private readonly string $previewName,
        private readonly ?string $branch = null,
        #[\SensitiveParameter]
        private readonly ?string $sqliteSourcePath = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/instances/{$this->candidateId}/clone";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): AppInstanceResponse
    {
        return AppInstanceResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array<string, int|string> */
    protected function defaultBody(): array
    {
        $body = [
            'node_id' => $this->nodeId,
            'name' => $this->name,
            'preview_name' => $this->previewName,
        ];

        if ($this->branch !== null) {
            $body['branch'] = $this->branch;
        }

        if ($this->sqliteSourcePath !== null) {
            $body['sqlite_source_path'] = $this->sqliteSourcePath;
        }

        return $body;
    }
}
