<?php

declare(strict_types=1);

namespace App\Commands\Tools;

use App\Commands\GatewayCommand;
use App\Support\Console\ConsoleWriter;
use Orbit\Sdk\Responses\Tools\ToolResponse;

abstract class ToolCommand extends GatewayCommand
{
    protected function nodeId(): ?int
    {
        $node = filter_var($this->option('node'), FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);

        if (is_int($node)) {
            return $node;
        }
        $this->renderGatewayFailure('tool.node_id_invalid', 'Node ID must be a positive integer.');

        return null;
    }

    protected function toolId(): ?int
    {
        return $this->positiveId('tool', 'Tool', 'tool.id_invalid');
    }

    protected function writeToolJson(ToolResponse $response): void
    {
        $this->writeJson($response->toArray());
    }

    protected function renderTool(ToolResponse $tool, string $message): int
    {
        if ($this->option('json') === true) {
            $this->writeToolJson($tool);

            return self::SUCCESS;
        }

        ConsoleWriter::write($this->output, $this->humanRenderer()->detail($message, [
            'ID' => $tool->id,
            'Node ID' => $tool->nodeId,
            'Manager' => $tool->manager,
            'Package' => $tool->package,
            'Constraint' => $tool->versionConstraint,
            'Protected' => $tool->protected,
            'Status' => $tool->status,
            'Installed version' => $tool->installedVersion,
            'Failed operation' => $tool->failedOperation,
            'Error code' => $tool->errorCode,
            'Outcome' => $tool->outcome,
            'Request ID' => $tool->requestId,
        ]));

        return self::SUCCESS;
    }
}
