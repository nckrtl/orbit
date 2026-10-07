<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Gateway;

use InvalidArgumentException;

/** What every machine in the fleet should run for the Gateway's commit (ADR 0202). */
final readonly class DesiredFleetStateResponse
{
    public function __construct(
        public ?string $commit,
        public DesiredCliReleaseResponse $cli,
        public DesiredAgentResponse $agent,
        public string $requestId,
    ) {}

    /**
     * @param  array<array-key, mixed>  $data
     *
     * @throws InvalidArgumentException
     */
    public static function fromGatewayData(array $data, string $requestId): self
    {
        $commit = $data['commit'] ?? null;

        if ($commit !== null && (! is_string($commit) || preg_match('/\A[0-9a-f]{40}\z/D', $commit) !== 1)) {
            throw new InvalidArgumentException('The desired fleet state commit is invalid.');
        }

        return new self(
            commit: $commit,
            cli: DesiredCliReleaseResponse::fromGatewayData($data['cli'] ?? null),
            agent: DesiredAgentResponse::fromGatewayData($data['agent'] ?? null),
            requestId: $requestId,
        );
    }

    /** @return array{commit: ?string, cli: array<string, mixed>, agent: array<string, mixed>} */
    public function toArray(): array
    {
        return ['commit' => $this->commit, 'cli' => $this->cli->toArray(), 'agent' => $this->agent->toArray()];
    }
}
