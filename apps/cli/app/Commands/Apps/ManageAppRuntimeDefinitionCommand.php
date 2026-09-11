<?php

declare(strict_types=1);

namespace App\Commands\Apps;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Str;
use Orbit\Sdk\GatewayRequest;
use Orbit\Sdk\Requests\Apps\CreateProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\CreateScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Apps\RemoveProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\RemoveScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ReplaceProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ReplaceScheduleDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ShowProcessDefinitionRequest;
use Orbit\Sdk\Requests\Apps\ShowScheduleDefinitionRequest;
use Orbit\Sdk\Responses\Apps\AppRuntimeDefinitionResponse;
use Throwable;

abstract class ManageAppRuntimeDefinitionCommand extends AppRuntimeDefinitionCommand
{
    /** @return 'process'|'schedule' */
    abstract protected function definitionKind(): string;

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
        Filesystem $files,
    ): int {
        $appId = $this->positiveId('app', 'App', 'app.id_invalid');

        if ($appId === null) {
            return self::FAILURE;
        }

        $definitionId = $this->stringOption('id');
        $file = $this->stringOption('file');
        $remove = $this->option('remove') === true;
        $operation = $this->operation($definitionId, $file, $remove);

        if ($operation === null) {
            return self::FAILURE;
        }

        if ($definitionId !== null && ! Str::isUuid($definitionId)) {
            return $this->renderGatewayFailure(
                'app_definition.id_invalid',
                'Definition ID must be a UUID.',
            );
        }

        $definition = $file === null ? null : $this->definitionContents($files, $file);

        if ($file !== null && $definition === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->send(
            $connector,
            $this->request($operation, $appId, $definitionId, $definition),
            AppRuntimeDefinitionResponse::class,
        );

        return $response instanceof AppRuntimeDefinitionResponse
            ? $this->renderDefinition($response)
            : self::FAILURE;
    }

    /** @return 'show'|'create'|'replace'|'remove'|null */
    private function operation(?string $definitionId, ?string $file, bool $remove): ?string
    {
        $operation = match (true) {
            $definitionId !== null && $file === null && ! $remove => 'show',
            $definitionId === null && $file !== null && ! $remove => 'create',
            $definitionId !== null && $file !== null && ! $remove => 'replace',
            $definitionId !== null && $file === null && $remove => 'remove',
            default => null,
        };

        if ($operation === null) {
            $this->renderGatewayFailure(
                'app_definition.operation_invalid',
                'Use --id to show, --file to create, both to replace, or --id with --remove to delete a definition.',
            );
        }

        return $operation;
    }

    private function definitionContents(Filesystem $files, string $path): ?string
    {
        if (! $files->isFile($path) || ! is_readable($path)) {
            $this->renderGatewayFailure(
                'app_definition.file_invalid',
                'Definition file is not readable.',
            );

            return null;
        }

        try {
            return $files->get($path);
        } catch (Throwable) {
            $this->renderGatewayFailure(
                'app_definition.file_invalid',
                'Definition file is not readable.',
            );

            return null;
        }
    }

    /** @param 'show'|'create'|'replace'|'remove' $operation */
    private function request(
        string $operation,
        int $appId,
        ?string $definitionId,
        ?string $definition,
    ): GatewayRequest {
        return match ([$this->definitionKind(), $operation]) {
            ['process', 'show'] => new ShowProcessDefinitionRequest($appId, $definitionId ?? ''),
            ['process', 'create'] => new CreateProcessDefinitionRequest($appId, $definition ?? ''),
            ['process', 'replace'] => new ReplaceProcessDefinitionRequest(
                $appId,
                $definitionId ?? '',
                $definition ?? '',
            ),
            ['process', 'remove'] => new RemoveProcessDefinitionRequest($appId, $definitionId ?? ''),
            ['schedule', 'show'] => new ShowScheduleDefinitionRequest($appId, $definitionId ?? ''),
            ['schedule', 'create'] => new CreateScheduleDefinitionRequest($appId, $definition ?? ''),
            ['schedule', 'replace'] => new ReplaceScheduleDefinitionRequest(
                $appId,
                $definitionId ?? '',
                $definition ?? '',
            ),
            ['schedule', 'remove'] => new RemoveScheduleDefinitionRequest($appId, $definitionId ?? ''),
        };
    }
}
