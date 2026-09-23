<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tasks\ListTaskAgentsRequest;
use Orbit\Sdk\Responses\Tasks\TaskAgentsResponse;

final class ListTaskAgentsCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:agents
        {group? : Numeric task group ID}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List a task group\'s agent threads.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $groupId = $this->idArgument('group', 'Task group', 'group');

        if ($groupId === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $groupId ??= $this->selectGroup($connector);

        if ($groupId === null) {
            return self::FAILURE;
        }

        $agents = $this->sendWithProgress($connector, new ListTaskAgentsRequest($groupId), TaskAgentsResponse::class, ['List agent threads', 'Loading agent threads', 'Loaded agent threads']);

        if (! $agents instanceof TaskAgentsResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($agents->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(
            ['ID', 'Role', 'Subtask', 'Driver', 'Model', 'State', 'Tokens', 'Line diff', 'Observed'],
            array_map(self::agentRow(...), $agents->agents),
            'No agent threads.',
        ));

        foreach ($agents->agents as $agent) {
            $error = $agent->error ?? $agent->observationError;

            if ($error !== null) {
                $this->writeHumanMessage("Thread {$agent->id}: {$error}");
            }
        }

        $this->writeHumanMessage("Request ID: {$agents->requestId}");

        return self::SUCCESS;
    }
}
