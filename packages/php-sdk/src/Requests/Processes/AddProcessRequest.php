<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Processes;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Processes\ProcessResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

/** @mago-expect lint:excessive-parameter-list */
final class AddProcessRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * @param list<string> $command
     * @param array<string, string>|null $environment
     * @param list<string>|null $ports
     * @param list<array{source: string, target: string, read_only?: bool}>|null $volumes
     */
    public function __construct(
        private readonly string $targetType,
        private readonly int $targetId,
        private readonly string $name,
        private readonly string $runtime,
        private readonly array $command,
        private readonly ?string $image = null,
        private readonly ?string $workingDirectory = null,
        #[\SensitiveParameter]
        private readonly ?array $environment = null,
        private readonly ?array $ports = null,
        private readonly ?array $volumes = null,
        private readonly string $restartPolicy = 'never',
        private readonly bool $start = false,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/processes';
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ProcessResponse
    {
        return ProcessResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array<string, mixed> */
    protected function defaultBody(): array
    {
        $body = [
            'target_type' => $this->targetType,
            'target_id' => $this->targetId,
            'name' => $this->name,
            'runtime' => $this->runtime,
            'command' => $this->command,
            'restart_policy' => $this->restartPolicy,
            'start' => $this->start,
        ];

        if ($this->environment !== null) {
            $body['environment'] = $this->environment;
        }

        if ($this->ports !== null) {
            $body['ports'] = $this->ports;
        }

        if ($this->volumes !== null) {
            $body['volumes'] = $this->volumes;
        }

        if ($this->image !== null) {
            $body['image'] = $this->image;
        }

        if ($this->workingDirectory !== null) {
            $body['working_directory'] = $this->workingDirectory;
        }

        return $body;
    }
}
