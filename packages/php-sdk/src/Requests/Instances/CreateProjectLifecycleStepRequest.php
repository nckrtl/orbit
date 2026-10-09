<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Instances;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Instances\LifecycleStepResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;
use SensitiveParameter;

final class CreateProjectLifecycleStepRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    /**
     * @param  list<array{name: string, timeout_seconds: int}>|null  $rebalance  New timeouts for other steps, set in the same write.
     */
    public function __construct(
        private readonly int $projectId,
        private readonly string $collection,
        private readonly string $name,
        #[SensitiveParameter]
        private readonly string $command,
        private readonly ?int $timeoutSeconds = null,
        private readonly ?string $before = null,
        private readonly ?string $after = null,
        private readonly ?array $rebalance = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/projects/{$this->projectId}/{$this->collection}";
    }

    public function createDtoFromResponse(#[SensitiveParameter] Response $response): LifecycleStepResponse
    {
        return LifecycleStepResponse::fromData($this->unwrapData($response), $this->successRequestId($response));
    }

    /** @return array<string, int|string|list<array{name: string, timeout_seconds: int}>> */
    protected function defaultBody(): array
    {
        $body = [
            'name' => $this->name,
            'command' => $this->command,
        ];

        if ($this->timeoutSeconds !== null) {
            $body['timeout_seconds'] = $this->timeoutSeconds;
        }

        if ($this->before !== null) {
            $body['before'] = $this->before;
        }

        if ($this->after !== null) {
            $body['after'] = $this->after;
        }

        if ($this->rebalance !== null) {
            $body['rebalance'] = $this->rebalance;
        }

        return $body;
    }
}
