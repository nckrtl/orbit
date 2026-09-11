<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Commands\GatewayCommand;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionsResponse;

abstract class AppRuntimeDefinitionCommand extends GatewayCommand
{
    abstract protected function definitionLabel(): string;

    protected function renderDefinition(AppRuntimeDefinitionResponse $definition): int
    {
        if ($this->option('json') === true) {
            $this->writeJson($definition->toArray());

            return self::SUCCESS;
        }

        $this->info("{$this->definitionLabel()} definition [{$definition->name}] ({$definition->id})");
        $this->line("App ID: {$definition->appId}");
        $this->line('Environments: '.implode(', ', $definition->environments));
        $this->line('Specification: '.json_encode($definition->spec, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        $this->line("Request ID: {$definition->requestId}");

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

        $this->table(['ID', 'Name', 'Environments', 'Specification'], $rows);
        $this->line("Request ID: {$response->requestId}");

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
