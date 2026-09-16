<?php

declare(strict_types=1);

namespace App\Commands\Concerns;

use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionsResponse;

trait RendersAppRuntimeDefinitions
{
    protected function renderDefinition(AppRuntimeDefinitionResponse $definition, string $label): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($definition->toArray());

            return self::SUCCESS;
        }

        $safe = $this->commandSafeDefinition($definition);
        ConsoleWriter::write($this->output, $this->humanRenderer()->detail("{$label} definition [{$definition->name}].", [
            'ID' => $definition->id,
            'App ID' => $definition->appId,
            'Environments' => implode(', ', $definition->environments),
            'Specification' => json_encode($safe['spec'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'Request ID' => $definition->requestId,
        ]));

        return self::SUCCESS;
    }

    protected function renderDefinitions(AppRuntimeDefinitionsResponse $response): int
    {
        $definitions = array_map($this->commandSafeDefinition(...), $response->definitions);

        if ($this->option('json') === true) {
            $this->writeJson([
                'definitions' => $definitions,
                'request_id' => $response->requestId,
            ]);

            return self::SUCCESS;
        }

        $rows = array_map(
            static fn (array $definition): array => [
                $definition['id'],
                $definition['name'],
                implode(', ', $definition['environments']),
                json_encode($definition['spec'], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            ],
            $definitions,
        );

        if ($rows === []) {
            $this->writeHumanMessage('No matching records found.');
            $this->writeHumanMessage("Request ID: {$response->requestId}");

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'Name', 'Environments', 'Specification'],
            $rows,
        ));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    /** @return array{id: string, app_id: int, name: string, environments: list<string>, spec: array<string, mixed>} */
    private function commandSafeDefinition(AppRuntimeDefinitionResponse $definition): array
    {
        $data = $definition->toArray();
        unset($data['request_id'], $data['spec']['command']);

        return $data;
    }
}
