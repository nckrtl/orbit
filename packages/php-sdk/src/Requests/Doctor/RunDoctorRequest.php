<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Doctor;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Doctor\DoctorReportResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasStringBody;

final class RunDoctorRequest extends GatewayRequest implements HasBody
{
    use HasStringBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly ?int $nodeId = null,
        #[\SensitiveParameter]
        private readonly ?array $families = null,
    ) {}

    public function resolveEndpoint(): string
    {
        return '/api/v1/doctor';
    }

    protected function defaultHeaders(): array
    {
        return ['Content-Type' => 'application/json'];
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): DoctorReportResponse
    {
        return DoctorReportResponse::fromGatewayData($this->unwrapData($response), $this->successRequestId($response));
    }

    protected function defaultBody(): ?string
    {
        $body = array_filter(
            ['node_id' => $this->nodeId, 'families' => $this->families],
            static fn (mixed $value): bool => $value !== null,
        );

        return json_encode($body === [] ? (object) [] : $body, JSON_THROW_ON_ERROR);
    }
}
