<?php

declare(strict_types=1);

namespace App\Commands\Tasks;

use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Tasks\ShowTasksStatusRequest;
use Orbit\Sdk\Responses\Tasks\TaskAssistanceResponse;
use Orbit\Sdk\Responses\Tasks\TasksStatusResponse;

final class ShowTasksStatusCommand extends TaskCommand
{
    #[\Override]
    protected $signature = 'tasks:status
        {--json : Return machine-readable JSON}';

    #[\Override]
    public function isHidden(): bool
    {
        return false;
    }

    public function extensionSlug(): ?string
    {
        return null;
    }

    #[\Override]
    protected $description = 'Show whether the Gateway tasks extension is on and which groups are asking for assistance.';

    public function handle(GatewayConfigRepository $repository, GatewayConnectorFactory $connectors): int
    {
        $connector = $this->coreGatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $status = $this->sendWithProgress($connector, new ShowTasksStatusRequest, TasksStatusResponse::class, ['Show tasks status', 'Loading tasks status', 'Loaded tasks status']);

        if (! $status instanceof TasksStatusResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($status->toArray());

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail('Extension: tasks', [
            'Status' => $status->enabled ? 'enabled' : 'disabled',
            'Last tick' => $status->lastTickAt ?? 'not recorded',
        ]));
        $this->renderAssistance($status->assistance ?? []);
        $this->writeHumanMessage("Request ID: {$status->requestId}");

        return self::SUCCESS;
    }

    /** @param list<TaskAssistanceResponse> $groups */
    private function renderAssistance(array $groups): void
    {
        $direction = [];
        $failures = [];

        foreach ($groups as $group) {
            if ($group->assistanceKind === 'direction') {
                $direction[] = $group;
            } else {
                $failures[] = $group;
            }
        }

        if ($direction === [] && $failures === []) {
            $this->writeHumanMessage('No groups are asking for assistance.');

            return;
        }

        if ($direction !== []) {
            $this->writeSection('Needs your direction');
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Group', 'Title', 'Status', 'Question'],
                array_map(static fn (TaskAssistanceResponse $group): array => [
                    $group->reference(),
                    $group->title,
                    $group->status,
                    $group->assistanceQuestion,
                ], $direction),
            ));
        }

        if ($failures !== []) {
            $this->writeSection('Failures');
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Group', 'Title', 'Status', 'Reason'],
                array_map(static fn (TaskAssistanceResponse $group): array => [
                    $group->reference(),
                    $group->title,
                    $group->status,
                    $group->assistanceReason,
                ], $failures),
            ));
        }
    }

    private function writeSection(string $heading): void
    {
        $this->writeHumanMessage($heading);
        ConsoleWriter::write($this->output, "\n");
    }
}
