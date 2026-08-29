<?php

declare(strict_types=1);

namespace Orbit\Sdk\Responses\Doctor;

use InvalidArgumentException;
use Orbit\Sdk\Support\GatewayRequestId;
use SensitiveParameter;

final readonly class DoctorReportResponse
{
    /**
     * @param list<DoctorNodeResponse> $nodes
     * @param array{nodes:int,families:int,checks:int,drift:int,unverifiable:int} $summary
     */
    private function __construct(
        public bool $healthy,
        public array $nodes,
        public array $summary,
        public string $requestId,
    ) {}

    /**
     * @mago-expect analysis:mixed-assignment Gateway report values remain mixed until validated.
     * @param array<array-key, mixed> $data
     */
    public static function fromGatewayData(
        #[SensitiveParameter]
        array $data,
        #[SensitiveParameter]
        string $requestId,
    ): self {
        $healthy = $data['healthy'] ?? null;
        $nodeData = $data['nodes'] ?? null;
        $summaryData = $data['summary'] ?? null;

        if (! is_bool($healthy) || ! is_array($nodeData) || ! array_is_list($nodeData) || ! is_array($summaryData)) {
            throw new InvalidArgumentException('Invalid Doctor report response.');
        }

        $summary = [];
        foreach (['nodes', 'families', 'checks', 'drift', 'unverifiable'] as $key) {
            $value = $summaryData[$key] ?? null;
            if (! is_int($value) || $value < 0) {
                throw new InvalidArgumentException('Invalid Doctor report response.');
            }

            $summary[$key] = $value;
        }

        $nodes = [];
        foreach ($nodeData as $nodeDatum) {
            if (! is_array($nodeDatum)) {
                continue;
            }

            $node = DoctorNodeResponse::fromGatewayData($nodeDatum);
            if ($node !== null) {
                $nodes[] = $node;
            }
        }

        return new self(
            healthy: $healthy,
            nodes: $nodes,
            summary: $summary,
            requestId: GatewayRequestId::fromTransport($requestId) ?? '',
        );
    }

    /** @return array{healthy:bool,nodes:list<array{node_id:int,node_name:string,healthy:bool,families:list<array{family:string,status:string,checked:int,issues:list<array{code:string,kind:string,resource_type:string,resource_id:int|string|null,resource_name:string|null,summary:string,expected:bool|string|null,observed:bool|string|null}>}>}>,summary:array{nodes:int,families:int,checks:int,drift:int,unverifiable:int},request_id:string} */
    public function toArray(): array
    {
        return [
            'healthy' => $this->healthy,
            'nodes' => array_map(static fn (DoctorNodeResponse $node): array => $node->toArray(), $this->nodes),
            'summary' => $this->summary,
            'request_id' => $this->requestId,
        ];
    }
}
