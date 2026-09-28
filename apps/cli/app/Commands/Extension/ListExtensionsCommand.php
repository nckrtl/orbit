<?php

declare(strict_types=1);

namespace App\Commands\Extension;

use App\Commands\GatewayCommand;
use App\Services\Extensions\GatewayExtensionState;
use App\Support\Console\ConsoleWriter;

final class ListExtensionsCommand extends GatewayCommand
{
    #[\Override]
    protected $signature = 'extension:list {--json : Return machine-readable JSON}';

    #[\Override]
    protected $description = 'List known extensions and their Gateway state.';

    public function handle(GatewayExtensionState $state): int
    {
        $discovery = $state->discover();
        $detail = $discovery->stateDetail();
        $rows = [];
        $humanRows = [];

        foreach (GatewayExtensionState::EXTENSIONS as $extension) {
            $enabled = $discovery->isEnabled($extension);
            $row = ['extension' => $extension, 'enabled' => $enabled];
            $humanRows[] = [
                $extension,
                $enabled === null ? 'UNKNOWN' : ($enabled ? 'enabled' : 'disabled'),
                $detail === null ? '' : $this->stateSummary($detail),
            ];

            if ($detail !== null) {
                $row['state'] = $detail;
            }

            $rows[] = $row;
        }

        if ($this->option('json') === true) {
            $this->writeJson(['extensions' => $rows]);
        } else {
            ConsoleWriter::write($this->output, $this->humanRenderer()->table(
                ['Extension', 'State', 'Detail'],
                $humanRows,
                'No extensions found.',
            ));
        }

        return self::SUCCESS;
    }

    /** @param array{code: string, message: string, request_id: ?string, details?: array<string, mixed>} $detail */
    private function stateSummary(array $detail): string
    {
        $summary = $detail['code'].': '.$detail['message'];

        return $detail['request_id'] === null
            ? $summary
            : $summary.' Request ID: '.$detail['request_id'];
    }
}
