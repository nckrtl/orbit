<?php

declare(strict_types=1);

namespace App\Commands\Activities;

use App\Commands\GatewayCommand;
use App\Repositories\GatewayConfigRepository;
use App\Services\GatewayConnectorFactory;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Requests\Activities\ListActivitiesRequest;
use Orbit\Sdk\Responses\Activities\ActivitiesResponse;

final class ListActivitiesCommand extends GatewayCommand
{
    private const string REQUEST_ID_PATTERN = '/\A[0-9a-f]{8}-[0-9a-f]{4}-[1-8][0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}\z/iD';

    /** @var list<string> */
    private const array STATUSES = ['running', 'succeeded', 'failed'];

    #[\Override]
    protected $signature = 'activity:list
        {--limit=25 : Maximum activity rows, from 1 through 200}
        {--request-id= : Exact API request UUID}
        {--before-id= : Positive integer; return rows with a smaller id}
        {--status= : Only running, succeeded, or failed}
        {--command= : Exact command name, from 1 to 255 characters}
        {--caller= : Caller Node id; a null caller does not match}
        {--target= : Target Node id; a null target does not match}
        {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List recent gateway command activity.';

    public function handle(
        GatewayConfigRepository $repository,
        GatewayConnectorFactory $connectors,
    ): int {
        $limit = filter_var(
            $this->option('limit'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1, 'max_range' => 200]],
        );

        if (! is_int($limit)) {
            return $this->renderGatewayFailure(
                'activity.limit_invalid',
                'Limit must be between 1 and 200.',
            );
        }

        $requestId = $this->input->getOption('request-id');

        if (
            $requestId !== null
            && (! is_string($requestId)
            || preg_match(self::REQUEST_ID_PATTERN, $requestId) !== 1)
        ) {
            return $this->renderGatewayFailure(
                'activity.request_id_invalid',
                'Request ID must be a UUID.',
            );
        }

        $beforeId = $this->optionalPositiveInt(
            'before-id',
            'activity.before_id_invalid',
            'Before ID must be a positive integer.',
        );

        if ($beforeId === false) {
            return self::FAILURE;
        }

        $status = $this->optionalStatus();

        if ($status === false) {
            return self::FAILURE;
        }

        $command = $this->optionalCommand();

        if ($command === false) {
            return self::FAILURE;
        }

        $callerNodeId = $this->optionalPositiveInt(
            'caller',
            'activity.caller_invalid',
            'Caller must be a positive integer.',
        );

        if ($callerNodeId === false) {
            return self::FAILURE;
        }

        $targetNodeId = $this->optionalPositiveInt(
            'target',
            'activity.target_invalid',
            'Target must be a positive integer.',
        );

        if ($targetNodeId === false) {
            return self::FAILURE;
        }

        $connector = $this->gatewayConnector($repository, $connectors);

        if ($connector === null) {
            return self::FAILURE;
        }

        $response = $this->sendWithProgress(
            $connector,
            new ListActivitiesRequest(
                limit: $limit,
                requestId: is_string($requestId) ? $requestId : null,
                beforeId: $beforeId,
                status: $status,
                command: $command,
                callerNodeId: $callerNodeId,
                targetNodeId: $targetNodeId,
            ),
            ActivitiesResponse::class,
            ['List activities', 'Loading activities', 'Loaded activities'],
        );

        if (! $response instanceof ActivitiesResponse) {
            return self::FAILURE;
        }

        if ($this->option('json') === true) {
            $this->writeJson($response->toArray());

            return self::SUCCESS;
        }

        $rows = [];

        foreach ($response->activities as $activity) {
            $rows[] = [
                $activity->id,
                $activity->occurredAt,
                $activity->command,
                $activity->status,
                $activity->callerNodeId === null ? '—' : (string) $activity->callerNodeId,
                $activity->targetNodeId === null ? '—' : (string) $activity->targetNodeId,
                $activity->errorCode ?? '—',
            ];
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->table(['ID', 'Time', 'Command', 'Status', 'Caller', 'Target', 'Error'], $rows, 'No activities found.'));
        $this->writeHumanMessage("Request ID: {$response->requestId}");

        return self::SUCCESS;
    }

    private function optionalPositiveInt(string $option, string $code, string $message): int|false|null
    {
        $value = $this->input->getOption($option);

        if ($value === null) {
            return null;
        }

        $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (! is_int($id)) {
            $this->renderGatewayFailure($code, $message);

            return false;
        }

        return $id;
    }

    private function optionalStatus(): string|false|null
    {
        $status = $this->input->getOption('status');

        if ($status === null) {
            return null;
        }

        if (! is_string($status) || ! in_array($status, self::STATUSES, true)) {
            $this->renderGatewayFailure(
                'activity.status_invalid',
                'Status must be running, succeeded, or failed.',
            );

            return false;
        }

        return $status;
    }

    private function optionalCommand(): string|false|null
    {
        $command = $this->input->getOption('command');

        if ($command === null) {
            return null;
        }

        if (! is_string($command) || mb_strlen($command) < 1 || mb_strlen($command) > 255) {
            $this->renderGatewayFailure(
                'activity.command_invalid',
                'Command must be between 1 and 255 characters.',
            );

            return false;
        }

        return $command;
    }
}
