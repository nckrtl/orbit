<?php

declare(strict_types=1);

namespace Orbit\Sdk\Requests\Herdr;

use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Responses\Herdr\ObservationGrantResponse;
use Saloon\Contracts\Body\HasBody;
use Saloon\Enums\Method;
use Saloon\Http\Response;
use Saloon\Traits\Body\HasJsonBody;

final class IssueObservationGrantRequest extends GatewayRequest implements HasBody
{
    use HasJsonBody;

    #[\Override]
    protected Method $method = Method::POST;

    public function __construct(
        private readonly int $sessionId,
        private readonly string $pane,
        private readonly string $terminal,
        private readonly int $cols,
        private readonly int $rows,
        private readonly string $origin,
    ) {}

    public function resolveEndpoint(): string
    {
        return "/api/v1/herdr/sessions/{$this->sessionId}/observation-grants";
    }

    public function createDtoFromResponse(#[\SensitiveParameter] Response $response): ObservationGrantResponse
    {
        return ObservationGrantResponse::fromGatewayData(
            $this->unwrapData($response),
            $this->successRequestId($response),
        );
    }

    /** @return array{pane: string, terminal: string, cols: int, rows: int, origin: string} */
    protected function defaultBody(): array
    {
        return [
            'pane' => $this->pane,
            'terminal' => $this->terminal,
            'cols' => $this->cols,
            'rows' => $this->rows,
            'origin' => $this->origin,
        ];
    }
}
