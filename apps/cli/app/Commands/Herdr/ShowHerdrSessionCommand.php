<?php

declare(strict_types=1);

namespace App\Commands\Herdr;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\Requests\Herdr\ShowHerdrSessionRequest;
use Orbit\Sdk\Responses\Herdr\HerdrSessionResponse;

final class ShowHerdrSessionCommand extends HerdrSessionCommand
{
    #[\Override]
    protected $signature = 'herdr:session:show
        {session : Named Herdr session}
        {--node= : Node ID or registered name}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Show one named Herdr session on a managed Node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        if (($failure = $this->guardExtension()) !== null) {
            return $failure;
        }

        $name = $this->sessionName();

        if ($name === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->requiredNodeId($connector);

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $listed = $this->resolveSession($connector, $nodeId, $name);

        if ($listed === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            new ShowHerdrSessionRequest($listed->id),
            HerdrSessionResponse::class,
        );

        if (! $response instanceof HerdrSessionResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($this->sanitizedSessionPayload($response));

            return self::SUCCESS;
        }

        $data = $response->toArray();
        unset($data['request_id']);
        $this->table(
            ['Field', 'Value'],
            array_map(
                static fn (string $key, mixed $value): array => [
                    $key,
                    is_array($value) ? json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES) : (string) ($value ?? ''),
                ],
                array_keys($data),
                array_values($data),
            ),
        );
        $this->line("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }
}
