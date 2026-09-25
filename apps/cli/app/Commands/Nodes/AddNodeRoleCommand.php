<?php

declare(strict_types=1);

namespace App\Commands\Nodes;

use App\Repositories\GatewayConfigRepository;
use App\Support\Console\ConsoleWriter;
use App\Services\GatewayConnectorFactory;
use Orbit\Sdk\GatewayConnector;
use Orbit\Sdk\Requests\Nodes\AddNodeRoleRequest;
use Orbit\Sdk\Requests\Processes\ListProcessesRequest;
use Orbit\Sdk\Responses\Nodes\NodeRoleMutationResponse;
use Orbit\Sdk\Responses\Processes\ProcessesResponse;

final class AddNodeRoleCommand extends NodeCommand
{
    #[\Override]
    protected $signature = 'node:role:add
        {node : Node ID or name}
        {role : Role name}
        {--converge : Converge an existing assignment}
        {--postgres-process= : For the analytics role: ID of its PostgreSQL 16 Process on a database Node}
        {--clickhouse-process= : For the analytics role: ID of its ClickHouse Process on a database Node}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'Add one role assignment to a node.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $role = $this->stringArgument('role', 'Role', 'node_role.role_required');

        if ($role === null) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $nodeId = $this->resolveNodeId($connector, $this->argument('node'));

        if ($nodeId === null) {
            return self::FAILURE;
        }

        $storage = $role === 'analytics' ? $this->analyticsStorage($connector) : [null, null];

        if ($storage === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new AddNodeRoleRequest($nodeId, $role, $this->option('converge') === true, ...$storage),
            NodeRoleMutationResponse::class,
            ['Add Node role', 'Adding Node role', 'Added Node role'],
            NodeOutput::mutationState(...),
        );

        if (! $response instanceof NodeRoleMutationResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $this->writeHumanMessage("Role [{$response->role}] added to node [{$response->nodeName}] (#{$response->nodeId}).");
        ConsoleWriter::write($this->output, NodeOutput::followUpWarning($this->consoleMode(), $response->followUp));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    /**
     * The analytics role names its two storage Processes. An omitted one is picked from the Docker
     * Processes that run the right image; `--json` and `--no-interaction` refuse instead.
     *
     * @return array{int, int}|null
     */
    private function analyticsStorage(GatewayConnector $connector): ?array
    {
        $ids = [];
        $candidates = null;

        foreach ([
            ['postgres-process', 'PostgreSQL Process', '/(?:^|\/)postgres:16(?:[.\-@]|$)/'],
            ['clickhouse-process', 'ClickHouse Process', '/(?:^|\/)clickhouse\/clickhouse-server(?::|@|$)/'],
        ] as [$option, $label, $image]) {
            $value = $this->option($option);

            if (is_string($value) && preg_match('/\A[1-9][0-9]*\z/D', $value) === 1) {
                $ids[] = (int) $value;

                continue;
            }

            if ($value !== null || ! $this->consoleMode()->mayPrompt) {
                $this->renderGatewayFailure(
                    'node_role.storage_process_required',
                    "--{$option} takes the numeric ID of a {$label}.",
                    details: ['field' => $option],
                );

                return null;
            }

            $candidates ??= $this->sendWithProgress(
                $connector,
                new ListProcessesRequest,
                ProcessesResponse::class,
                ['List Processes', 'Loading Processes', 'Loaded Processes'],
            );

            if (! $candidates instanceof ProcessesResponse) {
                return null;
            }

            $rows = [];

            foreach ($candidates->processes as $process) {
                $declared = $process->runtimeConfig['image'] ?? null;

                if ($process->targetType === 'node' && is_string($declared) && preg_match($image, $declared) === 1) {
                    $rows[$process->id] = [(string) $process->id, $process->name, $declared];
                }
            }

            if ($rows === []) {
                $this->renderGatewayFailure(
                    'node_role.storage_process_required',
                    "No {$label} exists yet. Create one on a database Node with process:create first.",
                    details: ['field' => $option],
                );

                return null;
            }

            $selected = $this->commandPrompts()->selectEntity($label, ['ID', 'Name', 'Image'], $rows);

            if (! is_int($selected)) {
                return null;
            }

            $ids[] = $selected;
        }

        return [$ids[0], $ids[1]];
    }
}
